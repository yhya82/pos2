<?php

namespace App\Livewire\Concerns;

/**
 * The sidebar and route middleware only gate the "view" permission for
 * getting to a screen at all — module×action is more granular than that
 * (a role can have products.view without products.create/update/delete).
 * Every manager component's mutating methods call this before doing
 * anything, so a permission gap can't be reached just by knowing the
 * Livewire method name.
 */
trait AuthorizesModuleActions
{
    protected function authorizeAction(string $module, string $action): void
    {
        abort_unless(auth()->user()->hasPermission($module, $action), 403);
    }
}
