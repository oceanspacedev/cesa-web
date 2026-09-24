<?php

use Filament\Facades\Filament;
use Webkul\Security\Bouncer;
use Webkul\Security\Enums\PermissionType;
use Webkul\Security\Models\User;

require_once __DIR__.'/../../plugins/webkul/support/tests/Helpers/TestBootstrapHelper.php';

beforeEach(function (): void {
    TestBootstrapHelper::ensureERPInstalled();
    Filament::setCurrentPanel('admin');
});

it('limits a group user without a team to their own records', function (): void {
    $user = User::factory()->create([
        'is_active'           => true,
        'resource_permission' => PermissionType::GROUP,
    ]);
    $otherUser = User::factory()->create(['is_active' => true]);

    $this->actingAs($user);

    expect(bouncer()->getAuthorizedUserIds())->toBe([$user->id])
        ->and(User::query()->applyPermissionScope()->pluck('id')->all())->toBe([$user->id])
        ->and(User::query()->applyPermissionScope()->whereKey($otherUser->id)->exists())->toBeFalse();

    $bouncer = Mockery::mock(Bouncer::class);
    $bouncer->shouldReceive('getAuthorizedUserIds')->once()->andReturn([]);
    app()->instance('bouncer', $bouncer);

    expect(User::query()->applyPermissionScope()->exists())->toBeFalse();
});
