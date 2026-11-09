<?php

namespace Cesa\FormTransfer\Tests\Feature;

use Cesa\FormTransfer\Enums\TransferRequestApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestRealizationStatus;
use Cesa\FormTransfer\Enums\TransferRequestSubmissionStatus;
use Cesa\FormTransfer\Http\Requests\StoreTransferRequestRequest;
use Cesa\FormTransfer\Http\Requests\UpdateTransferRequestRequest;
use Cesa\FormTransfer\Livewire\PublicTransferRequestForm;
use Cesa\FormTransfer\Models\FormTransfer;
use Cesa\FormTransfer\Models\TransferBank;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Tests\FormTransferTestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Livewire\Livewire;

class PublicTransferRequestFormValidationFeedbackTest extends FormTransferTestCase
{
    public function test_public_transfer_request_accepts_formatted_transfer_amount_above_one_thousand(): void
    {
        config()->set('form-transfer.notifications.mail.enabled', false);
        config()->set('form-transfer.notifications.whatsapp.enabled', false);

        $formTransfer = FormTransfer::factory()->create([
            'creator_id' => null,
            'company_id' => null,
            'is_active'  => true,
            'code'       => 'PT_CSN',
        ]);
        $bank = TransferBank::factory()->create([
            'is_active' => true,
        ]);

        if (! Route::has('form-transfer.public.progress')) {
            Route::get('/test-transfer-progress/{response}', fn (string $response): string => $response)
                ->name('form-transfer.public.progress');
        }

        Livewire::test(PublicTransferRequestForm::class, [
            'formTransfer' => $formTransfer->code,
        ])
            ->set('data.email', 'andi@example.com')
            ->set('data.requester_name', 'Andi Saputra')
            ->set('data.requester_whatsapp', '081234567890')
            ->set('data.bank_id', $bank->getKey())
            ->set('data.account_number', '1234567890')
            ->set('data.account_name', 'Andi Saputra')
            ->set('data.transfer_amount', '1.250.000')
            ->set('data.purpose', 'Operational expense')
            ->set('data.reference_note', 'Urgent transfer')
            ->call('submit')
            ->assertHasNoErrors();

        $transferRequest = TransferRequest::query()->sole();

        $this->assertSame('1250000.00', $transferRequest->transfer_amount);
    }

    public function test_public_transfer_request_transfer_amount_mask_does_not_truncate_thousands(): void
    {
        $method = new \ReflectionMethod(PublicTransferRequestForm::class, 'transferAmountMaskAlpineExpression');
        $method->setAccessible(true);

        $expression = $method->invoke(null);

        $this->assertStringNotContainsString("split('.')", $expression);
        $this->assertStringContainsString("replace(/\\D/g, '')", $expression);
    }

    public function test_public_transfer_request_validation_dispatches_feedback_events(): void
    {
        $formTransfer = FormTransfer::factory()->create([
            'creator_id' => null,
            'company_id' => null,
            'is_active'  => true,
            'code'       => 'PT_CSN',
        ]);
        $bank = TransferBank::factory()->create([
            'is_active' => true,
        ]);

        Livewire::test(PublicTransferRequestForm::class, [
            'formTransfer' => $formTransfer->code,
        ])
            ->set('data.email', 'andi@example.com')
            ->set('data.requester_name', 'Andi Saputra')
            ->set('data.requester_whatsapp', '081234567890')
            ->set('data.bank_id', $bank->getKey())
            ->set('data.account_name', 'Andi Saputra')
            ->set('data.transfer_amount', '100000')
            ->set('data.purpose', 'Operational expense')
            ->set('data.reference_note', 'Urgent transfer')
            ->call('submit')
            ->assertHasErrors([
                'data.account_number',
            ])
            ->assertDispatched('form-errors-presented')
            ->assertDispatched('form-processing-finished');
    }

    public function test_public_transfer_request_without_division_uses_legacy_default_submission_status(): void
    {
        config()->set('form-transfer.notifications.mail.enabled', false);
        config()->set('form-transfer.notifications.whatsapp.enabled', false);

        $formTransfer = FormTransfer::factory()->create([
            'creator_id' => null,
            'company_id' => null,
            'is_active'  => true,
            'code'       => 'PT_CSN',
        ]);
        $bank = TransferBank::factory()->create([
            'is_active' => true,
        ]);

        Livewire::test(PublicTransferRequestForm::class, [
            'formTransfer' => $formTransfer->code,
        ])
            ->set('data.email', 'andi@example.com')
            ->set('data.requester_name', 'Andi Saputra')
            ->set('data.requester_whatsapp', '081234567890')
            ->set('data.bank_id', $bank->getKey())
            ->set('data.account_number', '1234567890')
            ->set('data.account_name', 'Andi Saputra')
            ->set('data.transfer_amount', '100000')
            ->set('data.purpose', 'Operational expense')
            ->set('data.reference_note', 'Urgent transfer')
            ->set('data.submission_status', TransferRequestSubmissionStatus::REVISI->value)
            ->call('submit')
            ->assertHasNoErrors();

        $transferRequest = TransferRequest::query()->sole();

        $this->assertNull($transferRequest->division_id);
        $this->assertNull($transferRequest->division_name);
        $this->assertSame(TransferRequestSubmissionStatus::BARU, $transferRequest->submission_status);
    }

    public function test_store_transfer_request_request_requires_requester_email_and_approver_emails(): void
    {
        $formTransfer = FormTransfer::factory()->create();
        $bank = TransferBank::factory()->create();

        $validator = Validator::make([
            'form_transfer_id' => $formTransfer->getKey(),
            'requester_name'   => 'Andi Saputra',
            'bank_id'          => $bank->getKey(),
            'account_number'   => '1234567890',
            'account_name'     => 'Andi Saputra',
            'transfer_amount'  => '100000',
            'purpose'          => 'Operational expense',
            'approvals'        => [
                [
                    'label'  => 'Manager Approval',
                    'status' => 'pending',
                ],
            ],
        ], (new StoreTransferRequestRequest)->rules(), (new StoreTransferRequestRequest)->messages());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('email'));
        $this->assertTrue($validator->errors()->has('approvals.0.email'));
    }

    public function test_update_transfer_request_request_requires_requester_email_and_approver_emails(): void
    {
        $formTransfer = FormTransfer::factory()->create();
        $bank = TransferBank::factory()->create();

        $validator = Validator::make([
            'form_transfer_id'   => $formTransfer->getKey(),
            'requester_name'     => 'Andi Saputra',
            'bank_id'            => $bank->getKey(),
            'account_number'     => '1234567890',
            'account_name'       => 'Andi Saputra',
            'transfer_amount'    => '100000',
            'submission_status'  => TransferRequestSubmissionStatus::BARU->value,
            'approval_status'    => TransferRequestApprovalStatus::PENDING->value,
            'realization_status' => TransferRequestRealizationStatus::PENDING->value,
            'approvals'          => [
                [
                    'label'  => 'Manager Approval',
                    'status' => 'pending',
                ],
            ],
        ], (new UpdateTransferRequestRequest)->rules(), (new UpdateTransferRequestRequest)->messages());

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('email'));
        $this->assertTrue($validator->errors()->has('approvals.0.email'));
    }
}
