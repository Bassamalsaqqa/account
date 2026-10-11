<?php

declare(strict_types=1);

namespace App\Application\AI;

use Brick\Math\BigDecimal;

final class TransactionProposalSchema
{
    /** @return array<string, mixed> */
    public function providerSchema(): array
    {
        $reference = ['type' => ['string', 'null'], 'maxLength' => 255];
        $decimal = ['type' => ['string', 'null'], 'pattern' => '^[0-9]{1,10}(\\.[0-9]{1,6})?$'];
        $lot = ['type' => 'object', 'additionalProperties' => false,
            'properties' => ['quantity' => $decimal, 'lot_number' => $reference, 'expires_at' => $reference],
            'required' => ['quantity', 'lot_number', 'expires_at']];
        $line = ['type' => 'object', 'additionalProperties' => false, 'properties' => [
            'product' => $reference, 'unit' => $reference, 'quantity' => $decimal,
            'unit_price' => $decimal, 'unit_cost' => $decimal,
            'discount_type' => ['type' => ['string', 'null'], 'enum' => [null, 'fixed', 'percent']],
            'discount_value' => $decimal, 'tax' => $reference,
            'lots' => ['type' => 'array', 'maxItems' => 20, 'items' => $lot],
        ]];
        $line['required'] = array_keys($line['properties']);
        $properties = [
            'version' => ['type' => 'integer', 'enum' => [2]],
            'intent' => ['type' => 'string', 'enum' => ['sales', 'purchase', 'unsupported', 'uncertain']],
            'party' => $reference, 'currency' => $reference, 'warehouse' => $reference,
            'exchange_rate' => ['type' => ['string', 'null'], 'pattern' => '^[0-9]{1,10}(\\.[0-9]{1,10})?$'],
            'date' => $reference, 'due_date' => $reference, 'vendor_invoice_number' => $reference,
            'notes' => ['type' => ['string', 'null'], 'maxLength' => 2000],
            'lines' => ['type' => 'array', 'maxItems' => (int) config('ai_assistant.max_lines'), 'items' => $line],
            // A fixed list supports strict OpenAI schemas; local code constructs the path map.
            'evidence' => ['type' => 'array', 'maxItems' => 240, 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'properties' => ['path' => ['type' => 'string', 'maxLength' => 100],
                    'quote' => ['type' => 'string', 'maxLength' => 500]],
                'required' => ['path', 'quote'],
            ]],
        ];

        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties,
            'required' => array_keys($properties)];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validate(array $input, string $text, string $context): array
    {
        if (($input['version'] ?? null) !== 2
            || ! in_array($input['intent'] ?? null, ['sales', 'purchase', 'unsupported', 'uncertain'], true)
            || ! in_array($context, ['auto', 'sales', 'purchase'], true)
            || array_diff(array_keys($input), array_keys($this->providerSchema()['properties'])) !== []) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        if (in_array($input['intent'], ['unsupported', 'uncertain'], true)) {
            // Unsupported intents have no financial payload and cannot choose a writer.
            return ['version' => 2, 'intent' => $input['intent']];
        }
        if ($context !== 'auto' && $input['intent'] !== $context) {
            throw new AssistantUnavailable('intent_mismatch');
        }
        $purchase = $input['intent'] === 'purchase';
        if (! is_array($input['lines'] ?? null) || ! array_is_list($input['lines'])
            || count($input['lines']) > (int) config('ai_assistant.max_lines')) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        $evidence = $this->evidence($input['evidence'] ?? [], $text);
        $input['evidence'] = $evidence;
        foreach (['party', 'currency', 'warehouse', 'date', 'due_date', 'vendor_invoice_number', 'notes'] as $field) {
            $input[$field] = $this->reference($input[$field] ?? null, $field === 'notes' ? 2000 : 255);
            $this->prove($field, $input[$field], $evidence, $text);
        }
        $input['exchange_rate'] = $this->decimal($input['exchange_rate'] ?? null, 10, true);
        $this->prove('exchange_rate', $input['exchange_rate'], $evidence, $text, true);
        if (! $purchase && $input['vendor_invoice_number'] !== null) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        foreach ($input['lines'] as $index => &$line) {
            if (! is_array($line) || array_diff(array_keys($line), array_keys($this->providerSchema()['properties']['lines']['items']['properties'])) !== []) {
                throw new AssistantUnavailable('proposal_invalid');
            }
            foreach (['product', 'unit', 'tax'] as $field) {
                $line[$field] = $this->reference($line[$field] ?? null);
                $this->prove('lines.'.$index.'.'.$field, $line[$field], $evidence, $text);
            }
            foreach (['quantity', 'unit_price', 'unit_cost', 'discount_value'] as $field) {
                $line[$field] = $this->decimal($line[$field] ?? null, 6, $field === 'quantity');
                $this->prove('lines.'.$index.'.'.$field, $line[$field], $evidence, $text, true);
            }
            if (($purchase && $line['unit_price'] !== null) || (! $purchase && $line['unit_cost'] !== null)) {
                throw new AssistantUnavailable('proposal_invalid');
            }
            $line['discount_type'] ??= null;
            if (! in_array($line['discount_type'], [null, 'fixed', 'percent'], true)) {
                throw new AssistantUnavailable('proposal_invalid');
            }
            $line['lots'] ??= [];
            if (! is_array($line['lots']) || ! array_is_list($line['lots']) || count($line['lots']) > 20
                || (! $purchase && $line['lots'] !== [])) {
                throw new AssistantUnavailable('proposal_invalid');
            }
            foreach ($line['lots'] as $lotIndex => &$lot) {
                if (! is_array($lot) || array_diff(array_keys($lot), ['quantity', 'lot_number', 'expires_at']) !== []) {
                    throw new AssistantUnavailable('proposal_invalid');
                }
                $lot['quantity'] = $this->decimal($lot['quantity'] ?? null, 6, true);
                $lot['lot_number'] = $this->reference($lot['lot_number'] ?? null);
                $lot['expires_at'] = $this->reference($lot['expires_at'] ?? null);
                foreach ($lot as $field => $value) {
                    $this->prove('lines.'.$index.'.lots.'.$lotIndex.'.'.$field, $value, $evidence, $text, $field === 'quantity');
                }
            }
            unset($lot);
        }
        unset($line);

        return $input;
    }

    /** @return array<string, string> */
    private function evidence(mixed $input, string $text): array
    {
        if (! is_array($input) || count($input) > 240) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        if (array_is_list($input)) {
            $map = [];
            foreach ($input as $item) {
                if (! is_array($item) || array_diff(array_keys($item), ['path', 'quote']) !== []
                    || ! is_string($item['path'] ?? null) || isset($map[$item['path']])) {
                    throw new AssistantUnavailable('proposal_invalid');
                }
                $map[$item['path']] = $item['quote'] ?? null;
            }
            $input = $map;
        }
        $normalized = app(SalesProposalSchema::class)->normalizeDigits($text);
        foreach ($input as $path => $quote) {
            if (! is_string($path) || strlen($path) > 100 || ! is_string($quote) || trim($quote) === ''
                || mb_strlen($quote) > 500
                || ! str_contains(mb_strtolower($normalized), mb_strtolower(app(SalesProposalSchema::class)->normalizeDigits($quote)))) {
                throw new AssistantUnavailable('evidence_invalid');
            }
        }

        return $input;
    }

    /** @param array<string, string> $evidence */
    private function prove(string $path, ?string $value, array $evidence, string $text, bool $number = false): void
    {
        if ($value === null) {
            return;
        }
        $quote = $evidence[$path] ?? null;
        if ($quote === null) {
            throw new AssistantUnavailable('evidence_invalid');
        }
        $quote = app(SalesProposalSchema::class)->normalizeDigits(trim($quote));
        if ($number) {
            if (! preg_match('/\A\d{1,10}(?:\.\d{1,10})?\z/', $quote)
                || ! BigDecimal::of($quote)->isEqualTo(BigDecimal::of($value))) {
                throw new AssistantUnavailable('evidence_invalid');
            }
            // Boundaries prevent e.g. 45 being evidence for 145 or negative -45.
            if (! preg_match('/(?<![\p{N}.\-])'.preg_quote($quote, '/').'(?![\p{N}.])/u', app(SalesProposalSchema::class)->normalizeDigits($text))) {
                throw new AssistantUnavailable('evidence_invalid');
            }
        } elseif (mb_strtolower($quote) !== mb_strtolower($value)) {
            // Semantic aliases require local clarification; model confidence is not authority.
            throw new AssistantUnavailable('evidence_invalid');
        }
    }

    private function reference(mixed $value, int $max = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/u', $value)) {
            throw new AssistantUnavailable('proposal_invalid');
        }

        return trim($value) ?: null;
    }

    private function decimal(mixed $value, int $scale, bool $positive): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new AssistantUnavailable('proposal_invalid');
        }
        $value = app(SalesProposalSchema::class)->normalizeDigits(trim($value));
        if (! preg_match('/\A\d{1,10}(?:\.\d{1,'.$scale.'})?\z/', $value)
            || ($positive && ! BigDecimal::of($value)->isPositive())) {
            throw new AssistantUnavailable('proposal_invalid');
        }

        return (string) BigDecimal::of($value)->strippedOfTrailingZeros();
    }
}
