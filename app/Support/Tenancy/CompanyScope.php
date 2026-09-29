<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * @implements Scope<Model>
 */
class CompanyScope implements Scope
{
    protected static bool $bypass = false;

    /**
     * Execute a callback with the company scope temporarily bypassed.
     */
    public static function executeWithoutScope(callable $callback): mixed
    {
        $previous = static::$bypass;
        static::$bypass = true;

        try {
            return $callback();
        } finally {
            static::$bypass = $previous;
        }
    }

    /**
     * Check if the company scope is currently bypassed.
     */
    public static function isBypassed(): bool
    {
        return static::$bypass;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (static::$bypass) {
            return;
        }

        $context = app(CompanyContext::class);

        if ($context->hasCompany()) {
            $builder->where($model->qualifyColumn('company_id'), $context->companyId());
        } else {
            // Fail closed when context is absent
            $builder->whereRaw('1 = 0');
        }
    }
}
