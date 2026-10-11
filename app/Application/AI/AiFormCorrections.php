<?php

declare(strict_types=1);

namespace App\Application\AI;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Infrastructure\AI\DirectTextProvider;
use App\Models\AssistantRequest;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Sales\SalesDocumentRules;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AiFormCorrections
{
    /** No draft or financial writer is called here.
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function apply(Company $company, User $actor, string $token, string $session, string $formKey, int $revision,
        array $payload, string $text, string $requestKey, ?string $connectionId = null, ?string $audioToken = null): array
    {
        $editing = app(AiFormEditing::class);
        $revision = $editing->synchronize($company, $actor, $token, $session, $formKey, $revision, $payload);
        $state = app(AiFormState::class)->read($company, $actor, $token, $session, $formKey);
        $intent = $state['intent'];
        $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
        if (! $policy->follow_up_enabled || trim($text) === '' || mb_strlen($text) > (int) config('ai_assistant.max_text_characters')) {
            throw new AssistantUnavailable('input_invalid');
        }
        $connections = $state['data']['connections'];
        if ($audioToken !== null) {
            $audio = app(AiFormState::class)->read($company, $actor, $audioToken, $session, $formKey);
            $connections = [...$connections, ...($audio['data']['connections'] ?? [])];
        }
        $schema = app(FormPatchSchema::class);
        $proposal = $schema->deterministic($text, count($payload['lines']));
        $routes = $proposal === null ? app(AiProviderRoutes::class)->select($company, $actor, $intent, 'text_structured', $connectionId) : [];
        $hash = hash_hmac('sha256', json_encode(['text' => $text, 'form' => $formKey, 'revision' => $revision,
            'payload' => $editing->hash($payload), 'session' => hash('sha256', $session)], JSON_THROW_ON_ERROR), (string) config('app.key'));
        $reservation = app(AiRequestBudget::class)->reserve($company, $actor, $requestKey, $formKey, 'patch', $intent, $hash, $routes);
        $request = $reservation['request'];
        if (! $reservation['created']) {
            throw new AssistantUnavailable('request_processed');
        }
        try {
            if ($proposal === null) {
                // Minimal current-form references: no party directory, financial totals or unrelated costs.
                $input = 'Current intent: '.$intent.'. Current line count: '.count($payload['lines']).'. Line ordinals are one-based. '
                    .'Apply only the following instruction, never regenerate the form: '.$text;
                foreach ($routes as $index => $connection) {
                    app(AiRequestBudget::class)->beginAttempt($company, $actor, $request, $connection);
                    $grant = app(AiGovernance::class)->dispatchConnection($company, $actor, $connection->public_id, $intent, 'text_structured', (int) $connection->revision);
                    try {
                        $response = app(DirectTextProvider::class)->extract($connection->provider, $connection->model, $connection->region,
                            $grant['credential'], $input, 'patch', $schema->providerSchema());
                        $proposal = $response['proposal'];
                        app(AiRequestBudget::class)->recordUsage($company, $actor, $request, $response['usage']);
                        break;
                    } catch (AssistantUnavailable $e) {
                        if ($e->reason !== 'provider_outage' || $index !== 0 || count($routes) !== 2) {
                            throw $e;
                        }
                    }
                }
            }
            if ($proposal === null) {
                throw new AssistantUnavailable('provider_outage');
            }
            $operations = $schema->validate($proposal, $text, count($payload['lines']));
            $result = DB::transaction(function () use ($company, $actor, $token, $session, $formKey, $revision, $payload, $operations, $routes, $connections, $request, $intent): array {
                $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $intent);
                $fresh = AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)->lockForUpdate()->firstOrFail();
                if (! $policy->follow_up_enabled || (int) $policy->revision !== (int) $fresh->policy_revision || $fresh->status !== 'pending') {
                    throw new AssistantUnavailable('proposal_stale');
                }
                $state = app(AiFormState::class)->read($company, $actor, $token, $session, $formKey);
                if ($state['revision'] !== $revision || ! hash_equals($state['data']['payload_hash'], app(AiFormEditing::class)->hash($payload))) {
                    throw new AssistantUnavailable('form_changed');
                }
                $data = $state['data'];
                foreach ($routes as $connection) {
                    $connections[] = ['public_id' => $connection->public_id, 'revision' => (int) $connection->revision, 'capability' => 'text_structured'];
                }
                foreach ($connections as $connection) {
                    app(AiGovernance::class)->dispatchConnection($company, $actor, $connection['public_id'], $intent, $connection['capability'], $connection['revision']);
                }
                [$next, $changed] = $this->edit($company, $actor, $intent, $payload, $operations);
                $data['undo'] = ['payload' => $payload, 'origins' => $data['resolved']['origins'],
                    'unresolved' => $data['resolved']['unresolved']];
                $data['payload'] = $next;
                $data['payload_hash'] = app(AiFormEditing::class)->hash($next);
                $data['connections'] = $connections;
                $data['changed_fields'] = $changed;
                $data['last_change'] = 'assistant';
                foreach ($changed as $field) {
                    $data['resolved']['origins'][$field] = 'spoken';
                }
                $newRevision = app(AiFormState::class)->replace($company, $actor, $token, $session, $formKey, $revision, $data);
                $fresh->update(['status' => 'form_edited', 'approved_command' => ['version' => 1, 'operations' => $operations]]);

                return ['payload' => $next, 'revision' => $newRevision, 'changed_fields' => $changed, 'can_undo' => true];
            });
            if ($audioToken !== null) {
                app(AiFormState::class)->forget($company, $actor, $audioToken, $session);
            }

            return $result;
        } catch (Throwable $e) {
            AssistantRequest::where('company_id', $company->id)->where('user_id', $actor->id)->whereKey($request->id)
                ->where('status', 'pending')->update(['status' => 'failed', 'failure_code' => $e instanceof AssistantUnavailable ? $e->reason : 'unavailable']);
            throw $e;
        }
    }

    /** Undo cannot overwrite an intervening human edit.
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function undo(Company $company, User $actor, string $token, string $session, string $formKey, int $revision, array $payload): array
    {
        return DB::transaction(function () use ($company, $actor, $token, $session, $formKey, $revision, $payload): array {
            $state = app(AiFormState::class)->read($company, $actor, $token, $session, $formKey);
            $policy = app(AiGovernance::class)->authorizeUse($company, $actor, $state['intent']);
            $data = $state['data'];
            if (! $policy->follow_up_enabled || $state['revision'] !== $revision || ! isset($data['undo'])
                || ! hash_equals($data['payload_hash'], app(AiFormEditing::class)->hash($payload))) {
                throw new AssistantUnavailable('form_changed');
            }
            $data['payload'] = $data['undo']['payload'];
            $data['payload_hash'] = app(AiFormEditing::class)->hash($data['payload']);
            $data['resolved']['origins'] = $data['undo']['origins'];
            $data['resolved']['unresolved'] = $data['undo']['unresolved'];
            $data['changed_fields'] = [];
            $data['last_change'] = 'undo';
            unset($data['undo']);
            $nextRevision = app(AiFormState::class)->replace($company, $actor, $token, $session, $formKey, $revision, $data);

            return ['payload' => $data['payload'], 'revision' => $nextRevision, 'changed_fields' => [], 'can_undo' => false];
        });
    }

    /** @param array<string, mixed> $payload
     * @param  list<array<string, mixed>>  $operations
     * @return array{array<string, mixed>, list<string>}
     */
    private function edit(Company $company, User $actor, string $intent, array $payload, array $operations): array
    {
        $purchase = $intent === 'purchase';
        $changed = [];
        $removed = [];
        foreach ($operations as $op) {
            $index = $op['line'] === null ? null : $op['line'] - 1;
            if ($op['op'] === 'remove_line') {
                $removed[] = $index;
                $changed[] = 'lines';

                continue;
            }
            if ($op['op'] === 'add_line') {
                $partyClass = $purchase ? Vendor::class : Customer::class;
                $party = $partyClass::where('company_id', $company->id)->findOrFail($payload[$purchase ? 'vendor_id' : 'customer_id']);
                $proposal = ['version' => 2, 'intent' => $intent, 'party' => $party->code ?? $party->name_en ?? $party->name_ar,
                    'currency' => $payload['currency_code'], 'exchange_rate' => $payload['exchange_rate'], 'warehouse' => null,
                    'date' => $payload[$purchase ? 'purchase_date' : 'issue_date'], 'due_date' => $payload['due_date'] ?: null,
                    'vendor_invoice_number' => $payload['vendor_invoice_number'] ?? null, 'notes' => null,
                    'lines' => [['product' => $op['product'], 'unit' => $op['unit'], 'quantity' => $op['value'],
                        'unit_price' => $purchase ? null : $op['amount'], 'unit_cost' => $purchase ? $op['amount'] : null,
                        'discount_type' => null, 'discount_value' => null, 'tax' => null, 'lots' => []]]];
                $resolved = app(TransactionProposalResolver::class)->resolve($company, $actor, $proposal,
                    ['party' => (int) $party->id, 'warehouse' => (int) $payload['warehouse_id']]);
                if ($resolved['questions'] !== [] || count($payload['lines']) >= (int) config('ai_assistant.max_lines')) {
                    throw new AssistantUnavailable('choice_invalid');
                }
                $payload['lines'][] = $resolved['payload']['lines'][0];
                $changed[] = 'lines';

                continue;
            }
            $field = $op['field'];
            $value = $op['value'];
            if (in_array($field, ['notes', 'vendor_invoice_number', 'date', 'exchange_rate'], true)) {
                if ($field === 'vendor_invoice_number' && ! $purchase) {
                    throw new AssistantUnavailable('intent_mismatch');
                }
                $field = $field === 'date' ? ($purchase ? 'purchase_date' : 'issue_date') : $field;
                $payload[$field] = $value;
                $changed[] = $field;

                continue;
            }
            if (! isset($payload['lines'][$index]) || in_array($index, $removed, true)) {
                throw new AssistantUnavailable('choice_invalid');
            }
            $line = &$payload['lines'][$index];
            $product = Product::where('company_id', $company->id)->where('active', true)->findOrFail($line['product_id']);
            if ($field === 'unit') {
                $units = ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->where('active', true)
                    ->whereHas('unit', fn ($query) => $query->where('company_id', $company->id)->where('active', true)
                        ->where(fn ($query) => $query->where('code', $value)->orWhere('name_ar', $value)->orWhere('name_en', $value)))->limit(2)->get();
                if ($units->count() !== 1) {
                    throw new AssistantUnavailable('choice_invalid');
                }
                $line['product_unit_id'] = (int) $units->first()->id;
                $changed[] = 'lines.'.$index.'.product_unit_id';
            } elseif ($field === 'tax') {
                $taxes = TaxRate::where('company_id', $company->id)->where('active', true)
                    ->where(fn ($query) => $query->where('name_ar', $value)->orWhere('name_en', $value))->limit(2)->get();
                if ($taxes->count() !== 1) {
                    throw new AssistantUnavailable('choice_invalid');
                }
                $line['tax_rate_id'] = (int) $taxes->first()->id;
                $changed[] = 'lines.'.$index.'.tax_rate_id';
            } elseif (in_array($field, ['discount_percent', 'discount_fixed'], true)) {
                if (! $purchase && ! $actor->hasPermissionTo('sales.invoice.change_discount')) {
                    throw new AuthorizationException('Discount permission is required.');
                }
                $line['discount_type'] = $field === 'discount_percent' ? 'percent' : 'fixed';
                $line['discount_value'] = $value;
                $changed[] = 'lines.'.$index.'.discount_value';
            } else {
                if (($field === 'unit_cost' && ! $purchase) || ($field === 'unit_price' && $purchase)) {
                    throw new AssistantUnavailable('intent_mismatch');
                }
                $line[$field] = $value;
                $changed[] = 'lines.'.$index.'.'.$field;
            }
            unset($line);
        }
        foreach ($removed as $index) {
            unset($payload['lines'][$index]);
        }
        $payload['lines'] = array_values($payload['lines']);
        if ($payload['lines'] === []) {
            throw new AssistantUnavailable('choice_invalid');
        }
        $rules = app(SalesDocumentRules::class);
        $rate = BigDecimal::of($payload['exchange_rate']);
        $rules->header($company, $payload['currency_code'], $rate, $payload[$purchase ? 'purchase_date' : 'issue_date'], $payload['document_locale']);
        foreach ($payload['lines'] as $line) {
            $product = Product::where('company_id', $company->id)->where('active', true)->findOrFail($line['product_id']);
            if ($purchase) {
                app(PurchaseDocumentRules::class)->selectedUnit($product, $line['product_unit_id'], $line['quantity']);
            } else {
                $unit = $rules->selectedUnit($product, $line['product_unit_id'], Quantity::of($line['quantity']));
                $rules->price($product, $unit, $actor, $rate, $payload['currency_code'], BigDecimal::of($line['unit_price']));
            }
            if ($line['tax_rate_id'] !== null && ! TaxRate::where('company_id', $company->id)->where('active', true)->whereKey($line['tax_rate_id'])->exists()) {
                throw new AssistantUnavailable('choice_invalid');
            }
        }

        return [$payload, array_values(array_unique($changed))];
    }
}
