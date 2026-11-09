<?php

namespace Cesa\FormTransfer\Database\Factories;

use App\Models\User;
use Cesa\FormTransfer\Enums\ApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestRealizationStatus;
use Cesa\FormTransfer\Enums\TransferRequestSubmissionStatus;
use Cesa\FormTransfer\Models\FormTransfer;
use Cesa\FormTransfer\Models\TransferBank;
use Cesa\FormTransfer\Models\TransferRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransferRequestFactory extends Factory
{
    protected $model = TransferRequest::class;

    public function definition(): array
    {
        return [
            'form_transfer_id'        => FormTransfer::factory(),
            'user_id'                 => User::factory(),
            'creator_id'              => fn (array $attributes) => $attributes['user_id'],
            'submission_status'       => TransferRequestSubmissionStatus::BARU->value,
            'approval_status'         => TransferRequestApprovalStatus::PENDING->value,
            'realization_status'      => TransferRequestRealizationStatus::PENDING->value,
            'requester_name'          => $this->faker->name(),
            'division_name'           => $this->faker->randomElement(['Finance', 'Operations', 'Marketing']),
            'email'                   => $this->faker->unique()->safeEmail(),
            'account_number'          => $this->faker->numerify('##########'),
            'account_name'            => $this->faker->name(),
            'bank_id'                 => TransferBank::factory(),
            'transfer_amount'         => $this->faker->randomFloat(2, 1_000_000, 150_000_000),
            'realized_amount'         => 0,
            'purpose'                 => $this->faker->sentence(),
            'reference_note'          => $this->faker->optional()->sentence(),
            'invoice_path'            => $this->faker->optional()->lexify('documents/invoices/INV-????.pdf'),
            'account_attachment_path' => $this->faker->optional()->lexify('documents/accounts/ACC-????.pdf'),
            'realized_at'             => $this->faker->optional()->date(),
            'realization_proof_path'  => $this->faker->optional()->lexify('documents/realizations/REAL-????.pdf'),
            'realization_notes'       => $this->faker->optional()->sentence(),
            'approvals'               => $this->buildApprovals(),
        ];
    }

    public function withRequesterWhatsapp(?string $number = null): static
    {
        return $this->state(fn (): array => [
            'requester_whatsapp' => $number ?? '628'.$this->faker->numerify('##########'),
        ]);
    }

    protected function buildApprovals(): array
    {
        $states = collect(ApprovalStatus::cases())
            ->map(fn (ApprovalStatus $status): string => $status->value)
            ->all();
        $steps = $this->faker->numberBetween(1, 3);

        return collect(range(1, $steps))
            ->map(function (int $index) use ($states) {
                $notedAt = $this->faker->optional()->dateTimeBetween('-7 days');

                return [
                    'label'        => 'Approval '.$index,
                    'name'         => $this->faker->name(),
                    'email'        => $this->faker->safeEmail(),
                    'status'       => $index === 1
                        ? ApprovalStatus::PENDING->value
                        : $this->faker->randomElement($states),
                    'noted_at'     => $notedAt?->format('Y-m-d H:i:s'),
                    'notes'        => $this->faker->optional()->paragraph(),
                    'is_mandatory' => $this->faker->boolean(80),
                ];
            })
            ->all();
    }
}
