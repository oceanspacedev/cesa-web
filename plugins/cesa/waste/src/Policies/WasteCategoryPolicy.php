<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteCategory;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function view(User $user, WasteCategory $category): bool
    {
        return app(WasteAccessService::class)->canManageCategory($user, $category);
    }

    public function create(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function update(User $user, WasteCategory $category): bool
    {
        return $this->view($user, $category);
    }

    public function delete(User $user, WasteCategory $category): bool
    {
        return $this->view($user, $category);
    }
}
