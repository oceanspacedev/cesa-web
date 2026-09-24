<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteSection;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteSectionPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function view(User $user, WasteSection $section): bool
    {
        return app(WasteAccessService::class)->canManageSection($user, $section);
    }

    public function create(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function update(User $user, WasteSection $section): bool
    {
        return $this->view($user, $section);
    }

    public function delete(User $user, WasteSection $section): bool
    {
        return $this->view($user, $section);
    }
}
