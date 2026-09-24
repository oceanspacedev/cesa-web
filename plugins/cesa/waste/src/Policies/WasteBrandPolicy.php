<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteBrandPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function view(User $user, WasteBrand $brand): bool
    {
        return app(WasteAccessService::class)->canManageBrand($user, $brand);
    }

    public function create(User $user): bool
    {
        return $user->can('view_any_waste_waste::report');
    }

    public function update(User $user, WasteBrand $brand): bool
    {
        return $this->view($user, $brand);
    }

    public function delete(User $user, WasteBrand $brand): bool
    {
        return $user->can('view_any_waste_waste::report');
    }
}
