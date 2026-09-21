<?php

namespace Cesa\IdCard\Policies;

use Cesa\IdCard\Models\IdCardRequest;
use Illuminate\Auth\Access\HandlesAuthorization;
use Webkul\Security\Models\User;
use Webkul\Security\Traits\HasScopedPermissions;

class IdCardRequestPolicy
{
    use HandlesAuthorization, HasScopedPermissions;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_id_card_id::card::request');
    }

    public function view(User $user, IdCardRequest $idCardRequest): bool
    {
        if (! $user->can('view_id_card_id::card::request')) {
            return false;
        }

        return $this->hasAccess($user, $idCardRequest, 'creator');
    }

    public function create(User $user): bool
    {
        return $user->can('create_id_card_id::card::request');
    }

    public function update(User $user, IdCardRequest $idCardRequest): bool
    {
        if (! $user->can('update_id_card_id::card::request')) {
            return false;
        }

        return $this->hasAccess($user, $idCardRequest, 'creator');
    }

    public function delete(User $user, IdCardRequest $idCardRequest): bool
    {
        if (! $user->can('delete_id_card_id::card::request')) {
            return false;
        }

        return $this->hasAccess($user, $idCardRequest, 'creator');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_id_card_id::card::request');
    }

    public function forceDelete(User $user, IdCardRequest $idCardRequest): bool
    {
        if (! $user->can('force_delete_id_card_id::card::request')) {
            return false;
        }

        return $this->hasAccess($user, $idCardRequest, 'creator');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_id_card_id::card::request');
    }

    public function restore(User $user, IdCardRequest $idCardRequest): bool
    {
        if (! $user->can('restore_id_card_id::card::request')) {
            return false;
        }

        return $this->hasAccess($user, $idCardRequest, 'creator');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_id_card_id::card::request');
    }
}
