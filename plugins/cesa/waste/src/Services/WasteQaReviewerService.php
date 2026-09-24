<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Models\WasteBrand;
use Illuminate\Support\Str;
use RuntimeException;
use Webkul\Security\Models\User;

class WasteQaReviewerService
{
    public const EMAIL = 'qa-waste-mis@local.invalid';

    public const NAME = 'QA Waste MIS (simulasi)';

    public function ensure(): User
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Akun MIS QA hanya boleh dibuat di lingkungan lokal atau pengujian.');
        }

        $reviewer = User::withTrashed()->where('email', self::EMAIL)->first();

        if ($reviewer && ($reviewer->trashed() || $reviewer->name !== self::NAME || $reviewer->is_active)) {
            throw new RuntimeException('Alamat email MIS QA telah dipakai akun yang tidak sesuai.');
        }

        if (! $reviewer) {
            $reviewer = User::query()->createQuietly([
                'name'      => self::NAME,
                'email'     => self::EMAIL,
                'password'  => Str::random(64),
                'is_active' => false,
            ]);
        }

        foreach (WasteBrand::query()->whereIn('code', ['JCHICKEN', 'LUUCA', 'MOMOYO'])->get() as $brand) {
            $brand->users()->syncWithoutDetaching([$reviewer->getKey()]);
        }

        return $reviewer;
    }
}
