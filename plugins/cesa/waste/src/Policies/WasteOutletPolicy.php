<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteOutletPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canAccessReports($user);
    }

    public function view(User $user, WasteOutlet $outlet): bool
    {
        return app(WasteAccessService::class)->canManageOutlet($user, $outlet);
    }

    public function create(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function update(User $user, WasteOutlet $outlet): bool
    {
        return $this->view($user, $outlet);
    }

    public function delete(User $user, WasteOutlet $outlet): bool
    {
        return app(WasteAccessService::class)->canManageBrand($user, $outlet->brand);
    }
}
