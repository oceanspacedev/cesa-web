<?php

namespace Cesa\FormTransfer\Tests\Unit\Models;

use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class TransferRequestWhatsappNumberTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function whatsappNumbers(): array
    {
        return [
            'local with leading zero'  => ['081234567890', '6281234567890'],
            'local with separators'    => ['0812-3456-7890', '6281234567890'],
            'international with plus'  => ['+62 812 3456 7890', '6281234567890'],
            'international with zero'  => ['+62 (0)812 3456 7890', '6281234567890'],
            'without prefix'           => ['81234567890', '6281234567890'],
            'already normalized'       => ['6281234567890', '6281234567890'],
            'blank string'             => ['   ', null],
            'null'                     => [null, null],
        ];
    }

    #[DataProvider('whatsappNumbers')]
    public function test_whatsapp_numbers_are_normalized_to_indonesian_format(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, TransferRequest::normalizeWhatsappNumber($input));
    }

    public function test_only_indonesian_mobile_numbers_are_valid(): void
    {
        $this->assertTrue(TransferRequest::isValidWhatsappNumber('081234567890'));
        $this->assertTrue(TransferRequest::isValidWhatsappNumber('+62 812-3456-7890'));

        $this->assertFalse(TransferRequest::isValidWhatsappNumber('12345'));
        $this->assertFalse(TransferRequest::isValidWhatsappNumber('0215551234'));
        $this->assertFalse(TransferRequest::isValidWhatsappNumber('08123456789012345'));
        $this->assertFalse(TransferRequest::isValidWhatsappNumber(''));
        $this->assertFalse(TransferRequest::isValidWhatsappNumber(null));
    }

    public function test_model_stores_requester_whatsapp_in_normalized_format(): void
    {
        $request = new TransferRequest(['requester_whatsapp' => '0812-3456-7890']);

        $this->assertSame('6281234567890', $request->requester_whatsapp);
    }

    public function test_requester_whatsapp_message_contains_status_and_progress_link(): void
    {
        $service = app(TransferApprovalNotificationService::class);
        $method = new ReflectionMethod($service, 'buildRequesterWhatsappMessage');

        $message = $method->invoke(
            $service,
            [
                'title'           => 'Transfer Operasional',
                'uid'             => 'CSN-00883',
                'requester_name'  => 'Nadya',
                'bank'            => 'BCA',
                'account_number'  => '1234567890',
                'account_name'    => 'Nadya Putri',
                'transfer_amount' => '1.250.000',
                'purpose'         => 'Penggantian biaya operasional',
            ],
            'Disetujui',
            'https://example.com/transfer-requests/progress/token-123',
        );

        $this->assertStringContainsString('📣 TRANSFER OPERASIONAL - CSN-00883', $message);
        $this->assertStringContainsString('Halo Nadya,', $message);
        $this->assertStringContainsString('Status pengajuan transfer Anda: *Disetujui*', $message);
        $this->assertStringContainsString('*Jumlah Transfer:* Rp 1.250.000', $message);
        $this->assertStringEndsWith('https://example.com/transfer-requests/progress/token-123', $message);
    }
}
