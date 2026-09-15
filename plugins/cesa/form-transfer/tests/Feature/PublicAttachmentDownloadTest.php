<?php

namespace Cesa\FormTransfer\Tests\Feature;

use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use Cesa\FormTransfer\Tests\FormTransferTestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PublicAttachmentDownloadTest extends FormTransferTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require base_path('plugins/cesa/form-transfer/routes/web.php');

        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        Storage::fake('local');
        config()->set('filesystems.default', 'local');
        config()->set('filament.default_filesystem_disk', 'local');
    }

    public function test_email_attachment_urls_are_unsigned_and_do_not_expire(): void
    {
        Storage::disk('local')->put('form-transfer/invoices/invoice.pdf', 'invoice-content');

        $request = TransferRequest::factory()->create([
            'invoice_path'            => 'form-transfer/invoices/invoice.pdf',
            'account_attachment_path' => null,
            'realization_proof_path'  => null,
        ]);

        $summary = app(TransferApprovalNotificationService::class)->getRequestSummary($request);
        $invoiceUrl = $summary['invoice'] ?? null;

        $this->assertIsString($invoiceUrl);
        $this->assertStringContainsString('/transfer-requests/files/'.$request->status_response_id.'/invoice', $invoiceUrl);
        $this->assertStringNotContainsString('signature=', $invoiceUrl);
        $this->assertStringNotContainsString('expires=', $invoiceUrl);

        $this->get($invoiceUrl)
            ->assertOk();

        $this->assertSame(
            'invoice-content',
            $this->get($invoiceUrl)->streamedContent()
        );

        Carbon::setTestNow(now()->addYear());

        $this->get($invoiceUrl)
            ->assertOk();

        $this->assertSame(
            'invoice-content',
            $this->get($invoiceUrl)->streamedContent()
        );
    }

    public function test_unsigned_attachment_download_rejects_unknown_tokens(): void
    {
        $this->get(route('form-transfer.public.attachments.download', [
            'statusResponseId' => 'missing-token',
            'attachment'       => 'invoice',
        ]))->assertNotFound();
    }
}
