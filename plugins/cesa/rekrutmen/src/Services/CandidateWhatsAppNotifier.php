<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\WhatsAppAccount;

class CandidateWhatsAppNotifier
{
    public function __construct(
        protected WhatsAppGateway $gateway,
    ) {}

    /**
     * Send WhatsApp notification to candidate via the rekrutmen WhatsApp gateway.
     *
     * @param  array<string, mixed>  $data
     * @return array{success: bool, message: string, phone?: string, data?: mixed, account_id?: int|null, route_key?: string}
     */
    public function send(JobApplication $application, array $data): array
    {
        $phone = $this->resolveCandidatePhone($application);

        if (! $phone) {
            return [
                'success' => false,
                'message' => "Kandidat \"{$application->full_name}\" tidak memiliki nomor telepon / WhatsApp yang valid.",
            ];
        }

        $accountId = isset($data['whatsapp_account_id']) && is_numeric($data['whatsapp_account_id'])
            ? (int) $data['whatsapp_account_id']
            : null;

        $account = WhatsAppAccount::resolveForSend($accountId);

        $messageText = $this->buildCandidateMessage($application, $data);

        return $this->gateway->sendText($account, $phone, $messageText, [
            'purpose'          => 'notification',
            'mode'             => 'sync',
            'client_reference' => 'rekrutmen-candidate-'.$application->id,
            'idempotency_key'  => $data['request_key'] ?? $data['idempotency_key'] ?? null,
        ]);
    }

    /**
     * Resolve and normalize phone number from JobApplication.
     */
    public function resolveCandidatePhone(JobApplication $application): ?string
    {
        foreach ([$application->whatsapp_number, $application->active_whatsapp, $application->active_phone, $application->phone] as $raw) {
            if (is_string($raw) && ($phone = $this->gateway->formatPhone($raw))) {
                return $phone;
            }
        }

        return null;
    }

    /**
     * Normalize Indonesian and international phone numbers into standard digits (e.g. 628...).
     */
    public function formatPhone(string $phone): ?string
    {
        return $this->gateway->formatPhone($phone);
    }

    /**
     * Construct formatted WhatsApp message text with clear hierarchy and readable formatting.
     *
     * @param  array<string, mixed>  $data
     */
    public function buildCandidateMessage(JobApplication $application, array $data): string
    {
        $candidateName = $application->full_name;
        $jobTitle = $application->jobPosting?->title ?? ($application->position ?? 'Posisi Lowongan');
        $companyName = 'OCEAN SPACE';
        $location = $application->jobPosting?->location ?? 'Indonesia';

        $actionUrl = trim($data['action_url'] ?? '');
        if (! empty($actionUrl) && ! str_starts_with($actionUrl, 'http://') && ! str_starts_with($actionUrl, 'https://')) {
            $actionUrl = 'https://'.$actionUrl;
        }

        $schedule = trim($data['schedule'] ?? '');
        $venueOrMethod = trim($data['venue_or_method'] ?? '');
        $actionLabel = trim($data['action_label'] ?? '');
        $specialNote = trim($data['special_note'] ?? '');

        $body = $data['body_message'] ?? '';
        $body = str_replace(
            ['{nama_pelamar}', '{posisi}', '{perusahaan}', '{lokasi}', '{link_aksi}', '{jadwal}', '{schedule}'],
            [$candidateName, $jobTitle, $companyName, $location, $actionUrl, $schedule, $schedule],
            $body
        );

        $lines = [];
        $lines[] = "Halo *{$candidateName}*,";
        $lines[] = '';
        $lines[] = $body;

        $details = [];
        $details[] = "• Posisi: {$jobTitle}";
        $details[] = "• Perusahaan: {$companyName}";

        if (! empty($location)) {
            $details[] = "• Penempatan: {$location}";
        }

        if (! empty($schedule)) {
            $details[] = "• Jadwal / Batas: {$schedule}";
        }

        if (! empty($venueOrMethod)) {
            $details[] = "• Lokasi / Media: {$venueOrMethod}";
        }

        if (! empty($actionUrl)) {
            $linkText = ! empty($actionLabel) ? "{$actionLabel}: " : 'Tautan Akses: ';
            $details[] = "• {$linkText}{$actionUrl}";
        }

        if (! empty($details)) {
            $lines[] = '';
            $lines[] = '*Detail Pelaksanaan:*';
            foreach ($details as $d) {
                $lines[] = $d;
            }
        }

        if (! empty($specialNote)) {
            $lines[] = '';
            $lines[] = "*Catatan:* {$specialNote}";
        }

        $lines[] = '';
        $lines[] = 'Terima kasih.';
        $lines[] = '*Tim Rekrutmen Complete Selular*';

        return implode("\n", $lines);
    }
}
