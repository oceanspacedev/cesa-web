<?php

namespace App\Filament\Admin\Auth\Concerns;

use App\Support\WhatsAppNumber;
use Filament\Facades\Filament;
use Webkul\Security\Models\User;

trait InteractsWithWhatsAppLogin
{
    protected function normalizeWhatsAppNumber(mixed $value): ?string
    {
        $number = WhatsAppNumber::normalize(is_scalar($value) ? (string) $value : null);

        return WhatsAppNumber::isValid($number) ? $number : null;
    }

    /**
     * Cari user aktif berdasarkan nomor WhatsApp; hanya nomor dengan tepat satu akun yang boleh masuk.
     */
    protected function findEligibleUserByWhatsApp(string $number): ?User
    {
        $localNumber = WhatsAppNumber::toLocal($number);

        if (! $localNumber) {
            return null;
        }

        $matches = User::query()
            ->where('phone', $localNumber)
            ->limit(2)
            ->get();

        if ($matches->count() !== 1) {
            return null;
        }

        $user = $matches->first();
        $panel = Filament::getCurrentPanel() ?? Filament::getPanel('admin');

        if (! $user || ! $user->canAccessPanel($panel)) {
            return null;
        }

        return $user;
    }

    protected function userMatchesWhatsAppNumber(User $user, string $number): bool
    {
        return filled($user->phone)
            && WhatsAppNumber::normalize((string) $user->phone) === $number;
    }

    protected function unavailableWhatsAppMessage(): string
    {
        return 'No. HP belum terdaftar untuk login WhatsApp.';
    }
}
