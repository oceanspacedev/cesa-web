<?php

namespace Cesa\Waste\Policies;

use Cesa\Waste\Models\WasteItemUnitCandidate;
use Cesa\Waste\Services\WasteAccessService;
use Webkul\Security\Models\User;

class WasteItemUnitCandidatePolicy
{
    public function viewAny(User $user): bool
    {
        return app(WasteAccessService::class)->canManageAnyBrand($user);
    }

    public function view(User $user, WasteItemUnitCandidate $candidate): bool
    {
        return app(WasteAccessService::class)->canManageItem($user, $candidate->item);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WasteItemUnitCandidate $candidate): bool
    {
        return $this->view($user, $candidate);
    }
}
