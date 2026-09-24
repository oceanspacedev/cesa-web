<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteWorkflow;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteWorkflowPolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function view(User $user, WasteWorkflow $workflow): bool
    {
        return app(WasteAccessService::class)->canManageWorkflow($user, $workflow);
    }

    public function create(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function update(User $user, WasteWorkflow $workflow): bool
    {
        return $this->view($user, $workflow);
    }

    public function delete(User $user, WasteWorkflow $workflow): bool
    {
        return $this->view($user, $workflow);
    }
}
