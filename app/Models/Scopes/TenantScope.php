<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (TenantContext::isBypassed()) {
            return;
        }

        if (TenantContext::check()) {
            $builder->where($model->getTable() . '.tenant_id', '=', TenantContext::getTenantId());
        } else {
            // If no tenant is resolved in request context, restrict query to zero results to prevent data leaks
            $builder->whereRaw('1 = 0');
        }
    }
}
