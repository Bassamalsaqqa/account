<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateDocumentSequenceAction
{
    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, int $id, array $data): DocumentSequence
    {
        return DB::transaction(function () use ($company, $actor, $id, $data): DocumentSequence {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'settings.sequences.manage');
            $values = Validator::make($data, [
                'prefix' => ['required', 'string', 'max:32'],
                'padding' => ['required', 'integer', 'between:1,10'],
                'reset_policy' => ['required', Rule::in(['yearly', 'never'])],
            ])->validate();
            if (trim($values['prefix']) === '') {
                throw new \InvalidArgumentException('Sequence prefix must not be blank.');
            }
            $sequence = DocumentSequence::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            $before = $sequence->only(array_keys($values));
            $values['prefix'] = strtoupper(trim($values['prefix']));
            $sequence->update($values);
            app(AuditService::class)->log((int) $company->id, 'settings.sequence.updated', 'Document numbering configuration updated', $actor->id, $sequence, $before, $values);

            return $sequence;
        });
    }
}
