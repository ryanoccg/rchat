<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    /**
     * Boot the trait to add global scope for company filtering.
     */
    protected static function bootBelongsToCompany(): void
    {
        static::creating(function ($model) {
            if (auth()->check() && !$model->company_id) {
                $model->company_id = auth()->user()->current_company_id;
            }
        });

        // Add global scope to always filter by company
        static::addGlobalScope('company', function (Builder $builder) {
            if (auth()->check() && auth()->user()->current_company_id) {
                $builder->where('company_id', auth()->user()->current_company_id);
            }
        });
    }

    /**
     * Scope to filter by specific company.
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->withoutGlobalScope('company')->where('company_id', $companyId);
    }
}
