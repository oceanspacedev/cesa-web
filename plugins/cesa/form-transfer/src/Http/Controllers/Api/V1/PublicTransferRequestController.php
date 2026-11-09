<?php

namespace Cesa\FormTransfer\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Cesa\FormTransfer\Enums\TransferRequestSubmissionStatus;
use Cesa\FormTransfer\Http\Requests\Api\V1\PublicTransferProgressLookupRequest;
use Cesa\FormTransfer\Http\Requests\Api\V1\StorePublicTransferRequestRequest;
use Cesa\FormTransfer\Http\Resources\V1\PublicFormTransferResource;
use Cesa\FormTransfer\Http\Resources\V1\PublicTransferRequestResource;
use Cesa\FormTransfer\Models\FormTransfer;
use Cesa\FormTransfer\Models\TransferApprovalWorkflow;
use Cesa\FormTransfer\Models\TransferBank;
use Cesa\FormTransfer\Models\TransferDivision;
use Cesa\FormTransfer\Models\TransferReferenceNote;
use Cesa\FormTransfer\Models\TransferRequest;
use Cesa\FormTransfer\Services\RecaptchaValidator;
use Cesa\FormTransfer\Services\TransferApprovalNotificationService;
use Cesa\FormTransfer\Services\TransferRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublicTransferRequestController extends Controller
{
    public function __construct(
        protected TransferRequestService $transferRequestService,
        protected TransferApprovalNotificationService $notificationService,
    ) {}

    public function affiliates(): AnonymousResourceCollection
    {
        return $this->catalog(FormTransfer::PUBLIC_INDEX_AFFILIATES, 'affiliate');
    }

    public function index(): AnonymousResourceCollection
    {
        return $this->catalog(FormTransfer::PUBLIC_INDEX_TRANSFER_REQUESTS, 'transfer_request');
    }

    public function catalogBySlug(string $publicIndexSlug): AnonymousResourceCollection|JsonResponse
    {
        $slug = FormTransfer::normalizePublicIndexSlug($publicIndexSlug);

        if (
            ! FormTransfer::isAllowedPublicIndexSlug($slug)
            || (
                ! FormTransfer::isBuiltInPublicIndexSlug($slug)
                && ! FormTransfer::hasPublicIndexSlug($slug)
            )
        ) {
            return response()->json([
                'message' => 'Katalog form transfer tidak ditemukan.',
            ], 404);
        }

        return $this->catalog($slug, $slug);
    }

    public function show(string $formTransfer, Request $request): JsonResponse
    {
        $formTransferModel = $this->findInternalFormTransfer($formTransfer);

        $formTransferModel->load([
            'publicCategories',
            'divisions' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('name'),
            'referenceNotes' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('label'),
            'approvalWorkflows' => fn ($query) => $query
                ->where('is_active', true)
                ->orderBy('division_id')
                ->orderBy('id'),
        ]);

        $data = PublicFormTransferResource::make($formTransferModel)->resolve($request);
        $data['references']['banks'] = TransferBank::query()
            ->active()
            ->ordered()
            ->get()
            ->map(fn (TransferBank $bank): array => [
                'id'           => $bank->id,
                'code'         => $bank->code,
                'name'         => $bank->name,
                'short_name'   => $bank->short_name,
                'display_name' => $bank->display_name,
            ])
            ->values()
            ->all();
        $data['requirements'] = [
            'division_required' => $formTransferModel->divisions->isNotEmpty(),
            'reference_note'    => [
                'required'          => true,
                'restricted'        => $formTransferModel->referenceNotes->isNotEmpty(),
                'accepts_free_text' => $formTransferModel->referenceNotes->isEmpty(),
            ],
            'attachments' => [
                'invoice_path' => [
                    'required'     => false,
                    'multiple'     => true,
                    'max_size_kb'  => 5120,
                    'mimes'        => ['pdf', 'jpg', 'jpeg', 'png', 'xls', 'xlsx'],
                ],
                'account_attachment_path' => [
                    'required'     => false,
                    'multiple'     => true,
                    'max_size_kb'  => 5120,
                    'mimes'        => ['pdf', 'jpg', 'jpeg', 'png'],
                ],
            ],
            'recaptcha' => app(RecaptchaValidator::class)->getConfig(),
        ];

        return response()->json([
            'data' => $data,
        ]);
    }

    public function store(StorePublicTransferRequestRequest $request, string $formTransfer): JsonResponse
    {
        $formTransferModel = $request->formTransfer();
        $validated = $request->validated();

        try {
            $transferRequest = DB::transaction(function () use ($request, $formTransferModel, $validated): TransferRequest {
                $division = $this->resolveDivision($formTransferModel, $validated['division_id'] ?? null);
                $bank = TransferBank::query()
                    ->active()
                    ->findOrFail((int) $validated['bank_id']);
                $workflow = $this->resolveWorkflow($formTransferModel, $division?->getKey());
                $approvals = $workflow
                    ? $this->transferRequestService->prepareApprovalsFromWorkflow($workflow->getKey())
                    : [];

                $transferRequest = TransferRequest::query()->create([
                    'form_transfer_id'        => $formTransferModel->getKey(),
                    'requester_name'          => $validated['requester_name'],
                    'division_name'           => $division?->name,
                    'division_id'             => $division?->getKey(),
                    'email'                   => $validated['email'],
                    'requester_whatsapp'      => $validated['requester_whatsapp'] ?? null,
                    'account_number'          => $validated['account_number'],
                    'account_name'            => $validated['account_name'],
                    'bank_id'                 => $bank->getKey(),
                    'transfer_amount'         => $validated['transfer_amount'],
                    'purpose'                 => $validated['purpose'],
                    'reference_note'          => $this->resolveReferenceNoteText($formTransferModel, $validated),
                    'invoice_path'            => $this->storeUploadedFiles($request->file('invoice_path'), 'form-transfer/invoices'),
                    'account_attachment_path' => $this->storeUploadedFiles($request->file('account_attachment_path'), 'form-transfer/account-attachments'),
                    'approval_workflow_id'    => $workflow?->getKey(),
                    'approvals'               => $approvals,
                    'submission_status'       => TransferRequestSubmissionStatus::BARU->value,
                ]);

                return $transferRequest->refresh();
            });

            $transferRequest->load(['formTransfer', 'bank', 'realizations']);
            $approvalsState = $transferRequest->approvals ?? [];
            $firstApproval = $approvalsState[0] ?? null;

            if ($firstApproval) {
                $this->notificationService->notifyApprover($transferRequest, $firstApproval, $approvalsState);
            }

            $this->notificationService->notifyRequesterWithCurrentStatus($transferRequest);

            return PublicTransferRequestResource::make($transferRequest)
                ->additional([
                    'message' => 'Pengajuan transfer berhasil dibuat.',
                ])
                ->response()
                ->setStatusCode(201);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Pengajuan transfer gagal diproses. Silakan coba lagi.',
            ], 500);
        }
    }

    public function lookupProgress(PublicTransferProgressLookupRequest $request): JsonResponse|AnonymousResourceCollection
    {
        $validated = $request->validated();

        if (filled($validated['reference'] ?? null)) {
            $transferRequest = $this->transferRequestService->findByPublicLookup(
                $validated['reference'],
                $validated['email'],
            );

            if (! $transferRequest || blank($transferRequest->status_response_id)) {
                return response()->json([
                    'message' => 'Pengajuan transfer tidak ditemukan.',
                ], 404);
            }

            $transferRequest->load(['formTransfer', 'bank', 'realizations']);

            return PublicTransferRequestResource::make($transferRequest)->response();
        }

        $transferRequests = $this->transferRequestService->findByPublicEmail($validated['email']);

        return PublicTransferRequestResource::collection($transferRequests)
            ->additional([
                'meta' => [
                    'count' => $transferRequests->count(),
                ],
            ]);
    }

    public function showProgress(string $response): JsonResponse
    {
        $transferRequest = $this->transferRequestService->findByStatusResponseId($response);

        if (! $transferRequest) {
            return response()->json([
                'message' => 'Pengajuan transfer tidak ditemukan.',
            ], 404);
        }

        $transferRequest->load(['formTransfer', 'bank', 'realizations']);

        return PublicTransferRequestResource::make($transferRequest)->response();
    }

    protected function catalog(string $publicIndexSlug, string $mode): AnonymousResourceCollection
    {
        $forms = FormTransfer::query()
            ->with('publicCategories')
            ->where('is_active', true)
            ->shownOnPublicIndex($publicIndexSlug)
            ->orderBy('public_sort_order')
            ->orderBy('name')
            ->get();

        return PublicFormTransferResource::collection($forms)
            ->additional([
                'meta' => [
                    'mode'              => $mode,
                    'public_index_slug' => $publicIndexSlug,
                    'count'             => $forms->count(),
                ],
            ]);
    }

    protected function findInternalFormTransfer(string $identifier): FormTransfer
    {
        return FormTransfer::query()
            ->internalEntry()
            ->where('is_active', true)
            ->where(function ($query) use ($identifier): void {
                $query->where('code', $identifier);

                if (ctype_digit($identifier)) {
                    $query->orWhereKey((int) $identifier);
                }
            })
            ->firstOrFail();
    }

    protected function resolveDivision(FormTransfer $formTransfer, mixed $divisionId): ?TransferDivision
    {
        if (! is_numeric($divisionId)) {
            return null;
        }

        return TransferDivision::query()
            ->where('form_transfer_id', $formTransfer->getKey())
            ->where('is_active', true)
            ->find((int) $divisionId);
    }

    protected function resolveWorkflow(FormTransfer $formTransfer, ?int $divisionId): ?TransferApprovalWorkflow
    {
        return TransferApprovalWorkflow::query()
            ->where('form_transfer_id', $formTransfer->getKey())
            ->where('is_active', true)
            ->when(
                $divisionId,
                fn ($query): mixed => $query->where(function ($query) use ($divisionId): void {
                    $query->whereNull('division_id')
                        ->orWhere('division_id', $divisionId);
                }),
                fn ($query): mixed => $query->whereNull('division_id')
            )
            ->orderByRaw('division_id is null asc')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function resolveReferenceNoteText(FormTransfer $formTransfer, array $validated): ?string
    {
        if (filled($validated['reference_note_id'] ?? null)) {
            return TransferReferenceNote::query()
                ->where('form_transfer_id', $formTransfer->getKey())
                ->where('is_active', true)
                ->find((int) $validated['reference_note_id'])?->label;
        }

        return filled($validated['reference_note'] ?? null)
            ? (string) $validated['reference_note']
            : null;
    }

    /**
     * @return array<int, string>
     */
    protected function storeUploadedFiles(mixed $files, string $directory): array
    {
        return collect(Arr::wrap($files))
            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
            ->map(fn (UploadedFile $file): string => $file->store($directory, $this->attachmentDisk()))
            ->values()
            ->all();
    }

    protected function attachmentDisk(): string
    {
        return (string) (config('filament.default_filesystem_disk') ?: config('filesystems.default', 'local'));
    }
}
