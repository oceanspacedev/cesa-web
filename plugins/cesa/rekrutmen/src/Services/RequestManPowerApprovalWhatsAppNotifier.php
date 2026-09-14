<?php

namespace Cesa\Rekrutmen\Services;

use Cesa\Rekrutmen\Models\NotificationDelivery;
use Cesa\Rekrutmen\Models\RequestManPower;
use Cesa\Rekrutmen\Models\RequestManPowerApproval;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Log;

class RequestManPowerApprovalWhatsAppNotifier
{
    public function send(RequestManPower $requestManPower, RequestManPowerApproval $approval): void
    {
        $approval->loadMissing('approver');

        $phone = $approval->approver?->phone;

        $gateway = app(WhatsAppGateway::class);
        $enabled = $gateway->isEnabled();
        $account = WhatsAppAccount::resolveForSend();
        $formattedPhone = $gateway->formatPhone((string) ($phone ?? ''));

        if (! $formattedPhone) {
            Log::warning('Recruitment WhatsApp approval notification skipped due to invalid phone.', [
                'approval_id' => $approval->getKey(),
                'phone'       => $phone,
            ]);

        }

        $message = $this->buildApprovalRequestMessage($requestManPower, $approval);
        $delivery = NotificationDelivery::query()->firstOrCreate([
            'request_key' => hash('sha256', 'approval:'.$approval->getKey().':'.$approval->action_token),
        ], [
            'approval_id'         => $approval->getKey(),
            'channel'             => 'whatsapp',
            'recipient'           => $formattedPhone,
            'recipient_name'      => $approval->approver_name,
            'whatsapp_account_id' => $account?->getKey(),
            'payload'             => ['text' => $message],
            'status'              => ! $enabled || ! $formattedPhone ? NotificationDelivery::STATUS_SKIPPED : ($account ? NotificationDelivery::STATUS_PENDING : NotificationDelivery::STATUS_FAILED),
            'error_message'       => ! $enabled ? 'Pengiriman WhatsApp rekrutmen sedang nonaktif.' : (! $formattedPhone ? 'Nomor WhatsApp pemberi persetujuan tidak valid.' : ($account ? null : 'Nomor WhatsApp pengirim tidak tersedia.')),
            'available_at'        => now(),
        ]);

        app(NotificationDeliveryService::class)->dispatch($delivery);
    }

    protected function buildApprovalRequestMessage(RequestManPower $requestManPower, RequestManPowerApproval $approval): string
    {
        $summaryFields = __('rekrutmen::mail/request-man-power-approval-request.summary_fields');

        return implode("\n", [
            '*📣 PERMINTAAN TENAGA KERJA BARU*',
            '',
            '*'.$summaryFields['submission_date'].':* '.$requestManPower->getTanggalPengajuanFormattedAttribute(),
            '*'.$summaryFields['applicant'].':* '.($requestManPower->nama_pengaju ?? '-'),
            '*'.$summaryFields['position'].':* '.($requestManPower->posisi_dibutuhkan ?? '-'),
            '*'.$summaryFields['requirement'].':* '.($requestManPower->status_kebutuhan?->getLabel() ?? '-'),
            '*'.$summaryFields['division'].':* '.($requestManPower->division_name ?? '-'),
            '*'.$summaryFields['business_entity'].':* '.($requestManPower->business_entity_name ?? '-'),
            '*'.$summaryFields['estimated_join'].':* '.$requestManPower->getEstimasiTanggalJoinFormattedAttribute(),
            '',
            '*Tautan persetujuan:*',
            $approval->buildApprovalUrl(),
        ]);
    }

    protected function formatPhone(string $phone): ?string
    {
        return app(WhatsAppGateway::class)->formatPhone($phone);
    }
}
