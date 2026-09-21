<?php

namespace Cesa\IdCard\Database\Factories;

use Cesa\IdCard\Enums\BusinessEntity;
use Cesa\IdCard\Enums\Position;
use Cesa\IdCard\Models\IdCardRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IdCardRequest> */
class IdCardRequestFactory extends Factory
{
    protected $model = IdCardRequest::class;

    public function definition(): array
    {
        return [
            'full_name'        => fake()->name(),
            'shipping_address' => fake()->address(),
            'business_entity'  => fake()->randomElement(BusinessEntity::cases()),
            'position'         => fake()->randomElement(Position::cases()),
            'photo'            => 'id-card/photos/'.fake()->uuid().'.jpg',
            'phone'            => fake()->numerify('0812########'),
            'creator_id'       => null,
        ];
    }
}
