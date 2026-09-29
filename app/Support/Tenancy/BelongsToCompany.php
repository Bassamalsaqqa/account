<?php

namespace App\Support\Tenancy;

use App\Exceptions\CompanyReassignmentException;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (self $model) {
            if (CompanyScope::isBypassed()) {
                return;
            }

            $context = app(CompanyContext::class);

            if (! $context->hasCompany()) {
                throw new NoActiveCompanyException('Cannot create company-owned model ['.static::class.'] without active company context.');
            }

            $activeId = $context->companyId();

            if (! empty($model->company_id) && (int) $model->company_id !== $activeId) {
                throw new CompanyReassignmentException("Mismatched company_id [{$model->company_id}] provided for active company [{$activeId}].");
            }

            if (empty($model->company_id)) {
                $model->company_id = $activeId;
            }
        });

        static::updating(function (self $model) {
            if (CompanyScope::isBypassed()) {
                return;
            }

            if ($model->isDirty('company_id') && $model->getOriginal('company_id') !== null) {
                throw new CompanyReassignmentException('Reassigning company ownership is prohibited.');
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
