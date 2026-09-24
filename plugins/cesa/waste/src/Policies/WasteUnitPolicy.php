<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteUnit;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteUnitPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function view(User $user, WasteUnit $unit): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->can('view_any_waste_waste::report');
    }

    public function update(User $user, WasteUnit $unit): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, WasteUnit $unit): bool
    {
        return false;
    }
}
