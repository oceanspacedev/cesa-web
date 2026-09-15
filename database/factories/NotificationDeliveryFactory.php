<?php

namespace Database\Factories;

use Cesa\Rekrutmen\Models\NotificationDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationDelivery>
 */
class NotificationDeliveryFactory extends Factory
{
    protected $model = NotificationDelivery::class;

    public function definition(): array
    {
        return [
            'request_key'    => (string) Str::uuid(),
            'channel'        => 'whatsapp',
            'recipient'      => '6281234567890',
            'recipient_name' => fake()->name(),
            'payload'        => ['text' => 'Notifikasi rekrutmen untuk pengujian.'],
            'status'         => NotificationDelivery::STATUS_PENDING,
            'available_at'   => now(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn (): array => ['status' => NotificationDelivery::STATUS_SENT, 'sent_at' => now(), 'attempts' => 1]);
    }
}
