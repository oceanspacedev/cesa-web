<?php

namespace Cesa\FormTransfer\Tests\Feature;

use Cesa\FormTransfer\Jobs\SendWhatsAppNotification;
use Cesa\FormTransfer\Livewire\PublicTransferRequestForm;
use Cesa\FormTransfer\Models\FormTransfer;
use Cesa\FormTransfer\Models\TransferBank;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use Cesa\FormTransfer\Tests\FormTransferTestCase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionProperty;

class PublicTransferRequesterWhatsappTest extends FormTransferTestCase
{
    protected FormTransfer $formTransfer;

    protected TransferBank $bank;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Route::has('form-transfer.public.form')) {
            require base_path('plugins/cesa/form-transfer/routes/web.php');
        }

        if (! Route::has('form-transfer.api.transfer-requests.index')) {
            require base_path('plugins/cesa/form-transfer/routes/api.php');
        }

        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        config()->set('form-transfer.notifications.mail.enabled', false);
        config()->set('form-transfer.notifications.whatsapp.enabled', true);
        config()->set('form-transfer.notifications.whatsapp.endpoint', 'https://waghub.example.com');
        config()->set('form-transfer.notifications.whatsapp.api_key', 'test-api-key');
        config()->set('form-transfer.notifications.whatsapp.throttle.enabled', false);
        config()->set('form-transfer.security.recaptcha.enabled', false);

        Queue::fake();

        $this->formTransfer = FormTransfer::factory()->create([
            'creator_id' => null,
            'company_id' => null,
            'is_active'  => true,
            'code'       => 'PT_CSN',
            'uid_prefix' => 'CSN',
        ]);
        $this->bank = TransferBank::factory()->create([
            'is_active' => true,
        ]);
    }

    public function test_public_form_requires_requester_whatsapp(): void
    {
        $this->submitPublicForm(null)
            ->assertHasErrors(['data.requester_whatsapp']);

        $this->assertSame(0, TransferRequest::query()->count());
    }

    public function test_public_form_rejects_invalid_requester_whatsapp(): void
    {
        $component = $this->submitPublicForm('12345')
            ->assertHasErrors(['data.requester_whatsapp']);

        $this->assertSame(
            __('form-transfer::filament/resources/transfer-request/validation.requester_whatsapp_invalid'),
            $component->errors()->first('data.requester_whatsapp'),
        );
        $this->assertSame(0, TransferRequest::query()->count());
    }

    public function test_public_form_saves_normalized_whatsapp_and_sends_status_to_requester(): void
    {
        $this->submitPublicForm('0812-3456-7890')
            ->assertHasNoErrors();

        $transferRequest = TransferRequest::query()->sole();

        $this->assertSame('6281234567890', $transferRequest->requester_whatsapp);

        Queue::assertPushed(SendWhatsAppNotification::class, function (SendWhatsAppNotification $job) use ($transferRequest): bool {
            return $this->jobProperty($job, 'phone') === '6281234567890'
                && str_contains($this->jobProperty($job, 'message'), $transferRequest->uid)
                && str_contains($this->jobProperty($job, 'message'), 'Halo Andi Saputra,');
        });
    }

    public function test_public_form_page_remembers_requester_identity_in_browser(): void
    {
        $this->get(route('form-transfer.public.form', $this->formTransfer->code))
            ->assertOk()
            ->assertSee("const requesterStorageKey = 'cesa.form-transfer.requester';", false)
            ->assertSee("whatsapp: 'data.requester_whatsapp'", false)
            ->assertSee('this.rememberRequester();', false)
            ->assertSee('autocomplete="tel"', false);
    }

    public function test_requester_status_update_is_sent_via_whatsapp_even_without_email(): void
    {
        config()->set('form-transfer.notifications.mail.enabled', true);

        $transferRequest = TransferRequest::factory()
            ->for($this->formTransfer, 'formTransfer')
            ->withRequesterWhatsapp('6281234567890')
            ->create([
                'email'              => null,
                'status_response_id' => (string) Str::uuid(),
            ]);

        app(TransferApprovalNotificationService::class)->notifyRequester($transferRequest, 'Disetujui');

        Queue::assertPushed(SendWhatsAppNotification::class, function (SendWhatsAppNotification $job): bool {
            return $this->jobProperty($job, 'phone') === '6281234567890'
                && str_contains($this->jobProperty($job, 'message'), 'Status pengajuan transfer Anda: *Disetujui*');
        });
    }

    public function test_requester_status_update_skips_whatsapp_without_number(): void
    {
        $transferRequest = TransferRequest::factory()
            ->for($this->formTransfer, 'formTransfer')
            ->create([
                'requester_whatsapp' => null,
                'status_response_id' => (string) Str::uuid(),
            ]);

        app(TransferApprovalNotificationService::class)->notifyRequester($transferRequest, 'Disetujui');

        Queue::assertNotPushed(SendWhatsAppNotification::class);
    }

    public function test_public_api_accepts_optional_requester_whatsapp_in_normalized_format(): void
    {
        $this->postJson('/api/form-transfer/transfer-requests/PT_CSN', $this->apiPayload([
            'requester_whatsapp' => '+62 812-3456-7890',
        ]))->assertCreated();

        $this->assertDatabaseHas('form_transfer_requests', [
            'uid'                => 'CSN-00001',
            'requester_whatsapp' => '6281234567890',
        ]);
    }

    public function test_public_api_rejects_invalid_requester_whatsapp(): void
    {
        $this->postJson('/api/form-transfer/transfer-requests/PT_CSN', $this->apiPayload([
            'requester_whatsapp' => '12345',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['requester_whatsapp']);

        $this->assertSame(0, TransferRequest::query()->count());
    }

    protected function submitPublicForm(?string $whatsapp): Testable
    {
        return Livewire::test(PublicTransferRequestForm::class, [
            'formTransfer' => $this->formTransfer->code,
        ])
            ->set('data.email', 'andi@example.com')
            ->set('data.requester_name', 'Andi Saputra')
            ->set('data.requester_whatsapp', $whatsapp)
            ->set('data.bank_id', $this->bank->getKey())
            ->set('data.account_number', '1234567890')
            ->set('data.account_name', 'Andi Saputra')
            ->set('data.transfer_amount', '100000')
            ->set('data.purpose', 'Operational expense')
            ->set('data.reference_note', 'Urgent transfer')
            ->call('submit');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function apiPayload(array $overrides = []): array
    {
        return array_merge([
            'email'           => 'andi@example.com',
            'requester_name'  => 'Andi Saputra',
            'bank_id'         => $this->bank->getKey(),
            'account_number'  => '1234567890',
            'account_name'    => 'Andi Saputra',
            'transfer_amount' => '100000',
            'purpose'         => 'Operational expense',
            'reference_note'  => 'Urgent transfer',
        ], $overrides);
    }

    protected function jobProperty(SendWhatsAppNotification $job, string $property): mixed
    {
        return (new ReflectionProperty($job, $property))->getValue($job);
    }
}
