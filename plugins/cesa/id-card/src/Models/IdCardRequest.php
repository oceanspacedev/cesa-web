<?php

namespace Cesa\IdCard\Models;

use Cesa\IdCard\Database\Factories\IdCardRequestFactory;
use Cesa\IdCard\Enums\BusinessEntity;
use Cesa\IdCard\Enums\Position;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Webkul\Security\Traits\HasNullableCreator;

class IdCardRequest extends Model
{
    use HasFactory, HasNullableCreator, SoftDeletes;

    protected $fillable = [
        'full_name',
        'shipping_address',
        'business_entity',
        'position',
        'photo',
        'phone',
        'creator_id',
    ];

    protected function casts(): array
    {
        return [
            'business_entity' => BusinessEntity::class,
            'position'        => Position::class,
        ];
    }

    protected static function booted(): void
    {
        static::forceDeleted(function (self $request): void {
            if (filled($request->photo) && str_starts_with($request->photo, 'id-card/photos/') && ! str_contains($request->photo, '..')) {
                Storage::disk('local')->delete($request->photo);
            }
        });
    }

    protected static function newFactory(): IdCardRequestFactory
    {
        return IdCardRequestFactory::new();
    }
}
