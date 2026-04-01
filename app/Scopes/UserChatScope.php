<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that automatically filters ChatSession queries
 * to only return records belonging to the currently authenticated user.
 *
 * This is a safety-net layer on top of the explicit controller scoping —
 * even if a developer forgets to add ->where('user_id', Auth::id()),
 * this scope ensures no other user's session can leak through.
 *
 * The scope is bypassed automatically in console commands (no auth context)
 * and can be removed explicitly with ->withoutGlobalScope(UserChatScope::class)
 * in admin contexts.
 */
class UserChatScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     * @param  Model  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        // Only apply when a user is authenticated (not in CLI/admin contexts)
        if (Auth::check()) {
            $builder->where($builder->getModel()->getTable() . '.user_id', Auth::id());
        }
    }
}
