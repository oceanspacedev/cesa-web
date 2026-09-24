<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteItem;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteItemPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function view(User $user, WasteItem $item): bool
    {
        return app(WasteAccessService::class)->canManageItem($user, $item);
    }

    public function create(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function update(User $user, WasteItem $item): bool
    {
        return $this->view($user, $item);
    }

    public function delete(User $user, WasteItem $item): bool
    {
        return $this->view($user, $item);
    }
}
