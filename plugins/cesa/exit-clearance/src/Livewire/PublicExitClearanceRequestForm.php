<?php

namespace Cesa\ExitClearance\Livewire;

use Cesa\ExitClearance\Models\Department;
use Cesa\ExitClearance\Services\ExitClearanceNotificationService;
use Cesa\ExitClearance\Services\ExitClearanceRequestService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;
use Webkul\PluginManager\Package;
use Webkul\Support\Models\Company;

class PublicExitClearanceRequestForm extends Component implements HasForms
{
    use InteractsWithForms;

    protected string $layout = 'exit-clearance::layouts.form';

    protected string $view = 'exit-clearance::livewire.public-exit-clearance-request-form';

    public ?array $data = [];

    public ?array $recentSubmission = null;

    protected ExitClearanceRequestService $requestService;

    protected ExitClearanceNotificationService $notificationService;

    protected bool $recaptchaEnabled = false;

    protected ?string $recaptchaSiteKey = null;

    protected ?string $recaptchaSecretKey = null;

    protected ?string $recaptchaAction = null;

    protected float $recaptchaScoreThreshold = 0.0;

    protected int $recaptchaTimeout = 5;

    public function boot(
        ExitClearanceRequestService $requestService,
        ExitClearanceNotificationService $notificationService,
    ): void {
        $this->requestService = $requestService;
        $this->notificationService = $notificationService;

        $recaptcha = config('exit-clearance.security.recaptcha', []);

        $this->recaptchaSiteKey = Arr::get($recaptcha, 'site_key');
        $this->recaptchaSecretKey = Arr::get($recaptcha, 'secret_key');
        $this->recaptchaAction = Arr::get($recaptcha, 'action', 'exit_clearance_request');
        $this->recaptchaScoreThreshold = (float) Arr::get($recaptcha, 'score_threshold', 0.0);
        $this->recaptchaTimeout = (int) Arr::get($recaptcha, 'timeout', 5);

        $this->recaptchaEnabled = (bool) Arr::get($recaptcha, 'enabled', false)
            && filled($this->recaptchaSiteKey)
            && filled($this->recaptchaSecretKey);
    }

    public function mount(): void
    {
        if (! Package::isPluginInstalled('exit-clearance')) {
            abort(404);
        }

        if ($this->recaptchaEnabled) {
            $this->data['recaptcha_token'] = null;
        }

        $this->form->fill($this->data);
    }

    public function render(): View
    {
        return view($this->view)
            ->layout($this->layout);
    }

    public int $currentStep = 1;

    protected int $totalSteps = 4;

    public function nextStep(): void
    {
        try {
            $this->validateCurrentStep();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->handleValidationError();

            return;
        }

        if ($this->currentStep < $this->totalSteps) {
            $this->currentStep++;
        }
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
        }
    }

    public function validateCurrentStep(): void
    {
        $rules = $this->getRulesForCurrentStep();
        if (! empty($rules)) {
            $this->validate($rules);
        }
    }

    protected function getRulesForCurrentStep(): array
    {
        $rules = [];

        if ($this->currentStep === 1) {
            // Step 1: Resignation Letter
        } elseif ($this->currentStep === 2) {
            // Step 2: Personal Data
            $rules = [
                'data.name'           => 'required',
                'data.email'          => 'required|email',
                'data.phone'          => 'required',
                'data.position'       => 'required',
                'data.placement'      => 'required',
                'data.company_id'     => $this->getCompanyValidationRules(),
                'data.department_id'  => 'required',
                'data.join_date'      => 'required',
                'data.departure_date' => 'required',
            ];
        } elseif ($this->currentStep === 3) {
            // Step 3: Feedback Questions
            $rules = [
                'data.reason'                     => 'required',
                'data.workload_feedback'          => 'required',
                'data.career_growth_feedback'     => 'required',
                'data.facility_welfare_feedback'  => 'required',
                'data.work_relationship_feedback' => 'required',
                'data.compensation_feedback'      => 'required',
                'data.division_feedback'          => 'required',
                'data.company_feedback'           => 'required',
            ];
        } elseif ($this->currentStep === 4) {
            // Step 4: Exit Clearance items
            $rules = [
                'data.clearance_kartu_halo'           => 'required',
                'data.clearance_employee_debt'        => 'required',
                'data.clearance_uniform_return'       => 'required',
                'data.clearance_vehicle_return'       => 'required',
                'data.clearance_inventory_return'     => 'required',
                'data.clearance_account_deactivation' => 'required',
                'data.clearance_receivable_data'      => 'required',
                'data.clearance_promotor_internal'    => 'required',
                'data.clearance_nota_pending'         => 'required',
                'data.clearance_stock_opname'         => 'required',
            ];
        }

        return $rules;
    }

    protected function getCompanyValidationRules(): array
    {
        return ['required', 'integer', Rule::exists(Company::class, 'id')->withoutTrashed()];
    }

    protected function getValidationAttributes(): array
    {
        return [
            'data.name'                           => __('exit-clearance::filament/resources/request.fields.name'),
            'data.email'                          => __('exit-clearance::filament/resources/request.fields.email'),
            'data.phone'                          => __('exit-clearance::filament/resources/request.fields.phone'),
            'data.position'                       => __('exit-clearance::filament/resources/request.fields.position'),
            'data.placement'                      => __('exit-clearance::filament/resources/request.fields.placement'),
            'data.company_id'                     => __('exit-clearance::filament/resources/request.fields.company'),
            'data.department_id'                  => __('exit-clearance::filament/resources/request.fields.department'),
            'data.join_date'                      => __('exit-clearance::filament/resources/request.fields.join_date'),
            'data.departure_date'                 => __('exit-clearance::filament/resources/request.fields.departure_date'),
            'data.reason'                         => __('exit-clearance::filament/resources/request.exit_interview.q1'),
            'data.workload_feedback'              => __('exit-clearance::filament/resources/request.exit_interview.q2'),
            'data.career_growth_feedback'         => __('exit-clearance::filament/resources/request.exit_interview.q3'),
            'data.facility_welfare_feedback'      => __('exit-clearance::filament/resources/request.exit_interview.q4'),
            'data.work_relationship_feedback'     => __('exit-clearance::filament/resources/request.exit_interview.q5'),
            'data.compensation_feedback'          => __('exit-clearance::filament/resources/request.exit_interview.q6'),
            'data.division_feedback'              => __('exit-clearance::filament/resources/request.exit_interview.q7'),
            'data.company_feedback'               => __('exit-clearance::filament/resources/request.exit_interview.q8'),
            'data.clearance_kartu_halo'           => __('exit-clearance::filament/resources/request.clearance.item_1'),
            'data.clearance_employee_debt'        => __('exit-clearance::filament/resources/request.clearance.item_2'),
            'data.clearance_uniform_return'       => __('exit-clearance::filament/resources/request.clearance.item_3'),
            'data.clearance_vehicle_return'       => __('exit-clearance::filament/resources/request.clearance.item_4'),
            'data.clearance_inventory_return'     => __('exit-clearance::filament/resources/request.clearance.item_5'),
            'data.clearance_account_deactivation' => __('exit-clearance::filament/resources/request.clearance.item_6'),
            'data.clearance_receivable_data'      => __('exit-clearance::filament/resources/request.clearance.item_7'),
            'data.clearance_promotor_internal'    => __('exit-clearance::filament/resources/request.clearance.item_8'),
            'data.clearance_nota_pending'         => __('exit-clearance::filament/resources/request.clearance.item_9'),
            'data.clearance_stock_opname'         => __('exit-clearance::filament/resources/request.clearance.item_10'),
        ];
    }

    protected function getMessages(): array
    {
        return [
            'required' => __('exit-clearance::livewire/public-exit-clearance-request-form.messages.required'),
            'email'    => __('exit-clearance::livewire/public-exit-clearance-request-form.messages.email'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                // STEP 1: Surat Resign
                Group::make()
                    ->visible(fn () => $this->currentStep === 1)
                    ->dehydratedWhenHidden()
                    ->schema([
                        FileUpload::make('resignation_letter_url')
                            ->label(__('exit-clearance::filament/resources/request.resignation_letter.info'))
                            ->helperText(
                                __('exit-clearance::filament/resources/request.file_upload.helper_text').' '.
                                __('exit-clearance::filament/resources/request.resignation_letter.not_required')
                            )
                            ->directory('resignation-letters')
                            ->visibility('private')
                            ->downloadable()
                            ->openable()
                            ->maxSize(10240)
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                                'image/jpg',
                            ])
                            ->columnSpanFull(),
                    ]),

                // STEP 2: Exit Interview - Data Diri
                Group::make()
                    ->visible(fn () => $this->currentStep === 2)
                    ->dehydratedWhenHidden()
                    ->schema([
                        TextInput::make('name')
                            ->label(__('exit-clearance::filament/resources/request.fields.name'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label(__('exit-clearance::filament/resources/request.fields.email'))
                            ->email()
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('exit-clearance::filament/resources/request.fields.phone'))
                            ->tel()
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->maxLength(255),
                        TextInput::make('position')
                            ->label(__('exit-clearance::filament/resources/request.fields.position'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->maxLength(255),
                        TextInput::make('placement')
                            ->label(__('exit-clearance::filament/resources/request.fields.placement'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->maxLength(255),
                        Select::make('company_id')
                            ->label(__('exit-clearance::filament/resources/request.fields.company'))
                            ->options(fn (): array => Company::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->rules(fn (): array => $this->getCompanyValidationRules())
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.choose')),
                        Select::make('department_id')
                            ->label(__('exit-clearance::filament/resources/request.fields.department'))
                            ->options(fn (): array => Department::query()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            ->searchable()
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.choose')),
                        DatePicker::make('join_date')
                            ->label(__('exit-clearance::filament/resources/request.fields.join_date'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.date'))
                            ->displayFormat('Y-m-d'),
                        DatePicker::make('departure_date')
                            ->label(__('exit-clearance::filament/resources/request.fields.departure_date'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.date'))
                            ->displayFormat('Y-m-d'),
                    ])->columns(1),

                // STEP 3: Exit Interview - Feedback Questions
                Group::make()
                    ->visible(fn () => $this->currentStep === 3)
                    ->dehydratedWhenHidden()
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q1'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Textarea::make('workload_feedback')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q2'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Textarea::make('career_growth_feedback')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q3'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Textarea::make('facility_welfare_feedback')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q4'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Textarea::make('work_relationship_feedback')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q5'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Textarea::make('compensation_feedback')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q6'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Textarea::make('division_feedback')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q7'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                        Textarea::make('company_feedback')
                            ->label(__('exit-clearance::filament/resources/request.exit_interview.q8'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ])->columns(1),

                // STEP 4: Exit Clearance (Admin Items)
                Group::make()
                    ->visible(fn () => $this->currentStep === 4)
                    ->dehydratedWhenHidden()
                    ->schema([
                        TextInput::make('clearance_kartu_halo')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_1'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_employee_debt')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_2'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_uniform_return')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_3'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_vehicle_return')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_4'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_inventory_return')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_5'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_account_deactivation')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_6'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_receivable_data')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_7'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_promotor_internal')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_8'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_nota_pending')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_9'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                        TextInput::make('clearance_stock_opname')
                            ->label(__('exit-clearance::filament/resources/request.clearance.item_10'))
                            ->required()
                            ->placeholder(__('exit-clearance::livewire/public-exit-clearance-request-form.placeholders.answer')),
                    ])->columns(1),
                Hidden::make('recaptcha_token')
                    ->default('')
                    ->dehydrated(),
            ]);
    }

    public function submit(): mixed
    {
        $this->dispatch('form-processing-started');

        try {
            $this->validate(['data.company_id' => $this->getCompanyValidationRules()]);

            $state = $this->form->getState();
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->handleValidationError();
            $this->dispatch('form-processing-finished');

            return null;
        }

        if ($this->recaptchaEnabled && ! $this->verifyRecaptchaToken($state)) {
            $this->handleValidationError();
            $this->dispatch('form-processing-finished');

            return null;
        }

        $payload = Arr::only($state, [
            'company_id',
            'department_id',
            'name',
            'email',
            'phone',
            'position',
            'placement',
            'join_date',
            'departure_date',
            'reason',
            'workload_feedback',
            'career_growth_feedback',
            'facility_welfare_feedback',
            'work_relationship_feedback',
            'compensation_feedback',
            'division_feedback',
            'company_feedback',
            'clearance_kartu_halo',
            'clearance_employee_debt',
            'clearance_uniform_return',
            'clearance_vehicle_return',
            'clearance_inventory_return',
            'clearance_account_deactivation',
            'clearance_receivable_data',
            'clearance_promotor_internal',
            'clearance_nota_pending',
            'clearance_stock_opname',
            'resignation_letter_url',
        ]);
        $payload['resignation_letter_url'] = $this->normalizeResignationLetterUpload(
            $payload['resignation_letter_url'] ?? null,
        );

        try {
            $request = $this->requestService->createPublicRequest($payload);

            $this->notificationService->notifyApprovers($request);
            $this->notificationService->notifyRequester(
                $request,
                $this->requestService->formatFormStatus($request->form_status)
            );

            $this->recentSubmission = [
                'uid'                => $request->form_uid,
                'status_response_id' => $request->form_response_id,
            ];

            $this->dispatch('form-processing-finished');
            $this->dispatch('submission-success', detail: $this->recentSubmission);

            Notification::make()
                ->title(__('exit-clearance::livewire/public-exit-clearance-request-form.notifications.submitted.title'))
                ->success()
                ->send();

            $this->resetFormAfterSubmission();

            return redirect()->route('exit-clearance.public.progress', [
                'response' => $request->form_response_id,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to submit exit clearance request.', [
                'error' => $exception->getMessage(),
            ]);

            $this->addError('data', __('exit-clearance::livewire/public-exit-clearance-request-form.notifications.submit_failed.body'));
            $this->dispatch('form-errors-presented');
            $this->dispatch('form-processing-finished');

            Notification::make()
                ->title(__('exit-clearance::livewire/public-exit-clearance-request-form.notifications.submit_failed.title'))
                ->body(__('exit-clearance::livewire/public-exit-clearance-request-form.notifications.submit_failed.body'))
                ->danger()
                ->send();

            return null;
        }
    }

    protected function resetFormAfterSubmission(): void
    {
        $this->data = [];

        if ($this->recaptchaEnabled) {
            $this->data['recaptcha_token'] = null;
        }

        $this->form->fill($this->data);
    }

    protected function normalizeResignationLetterUpload(mixed $value): ?string
    {
        if (TemporaryUploadedFile::canUnserialize($value)) {
            $value = TemporaryUploadedFile::unserializeFromLivewireRequest($value);
        }

        if ($value instanceof TemporaryUploadedFile) {
            return $this->storeResignationLetter($value);
        }

        if (is_string($value) && str_starts_with($value, 'livewire-tmp/')) {
            return $this->storeResignationLetter(
                TemporaryUploadedFile::createFromLivewire(
                    Str::after($value, 'livewire-tmp/'),
                ),
            );
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function storeResignationLetter(TemporaryUploadedFile $file): ?string
    {
        try {
            if (! $file->exists()) {
                return null;
            }
        } catch (Throwable $exception) {
            return null;
        }

        $disk = config('filesystems.default', 'local');
        $filename = Str::ulid().'.'.$file->getClientOriginalExtension();

        $storedPath = $file->storeAs('resignation-letters', $filename, $disk);

        $file->delete();

        return $storedPath ?: null;
    }

    protected function handleValidationError(): void
    {
        $this->dispatch('form-errors-presented');

        Notification::make()
            ->title(__('exit-clearance::livewire/public-exit-clearance-request-form.validation.title'))
            ->body(__('exit-clearance::livewire/public-exit-clearance-request-form.validation.body'))
            ->warning()
            ->send();
    }

    public function isRecaptchaEnabled(): bool
    {
        return $this->recaptchaEnabled;
    }

    public function getRecaptchaSiteKey(): ?string
    {
        return $this->recaptchaSiteKey;
    }

    public function getRecaptchaAction(): string
    {
        return $this->recaptchaAction ?? 'exit_clearance_request';
    }

    protected function verifyRecaptchaToken(array $state): bool
    {
        $token = Arr::get($state, 'recaptcha_token');

        if (! $token) {
            $this->addError('data.recaptcha_token', __('exit-clearance::livewire/public-exit-clearance-request-form.recaptcha.required'));

            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout($this->recaptchaTimeout)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret'   => $this->recaptchaSecretKey,
                    'response' => $token,
                    'remoteip' => request()?->ip(),
                ]);

            if (! $response->successful()) {
                Log::warning('reCAPTCHA verification request failed.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                $this->addError('data.recaptcha_token', __('exit-clearance::livewire/public-exit-clearance-request-form.recaptcha.failed'));

                return false;
            }

            $payload = $response->json();

            if (! Arr::get($payload, 'success')) {
                Log::info('reCAPTCHA verification rejected.', [
                    'errors' => Arr::get($payload, 'error-codes'),
                ]);

                $this->addError('data.recaptcha_token', __('exit-clearance::livewire/public-exit-clearance-request-form.recaptcha.failed'));

                return false;
            }

            $score = (float) Arr::get($payload, 'score', 1.0);

            if (
                $this->recaptchaScoreThreshold > 0
                && Arr::has($payload, 'score')
                && $score < $this->recaptchaScoreThreshold
            ) {
                Log::info('reCAPTCHA score below configured threshold.', [
                    'score'     => $score,
                    'threshold' => $this->recaptchaScoreThreshold,
                ]);

                $this->addError('data.recaptcha_token', __('exit-clearance::livewire/public-exit-clearance-request-form.recaptcha.failed'));

                return false;
            }

            $action = Arr::get($payload, 'action');

            if ($action && $this->recaptchaAction && $action !== $this->recaptchaAction) {
                Log::info('reCAPTCHA action mismatch detected.', [
                    'expected' => $this->recaptchaAction,
                    'received' => $action,
                ]);

                $this->addError('data.recaptcha_token', __('exit-clearance::livewire/public-exit-clearance-request-form.recaptcha.failed'));

                return false;
            }
        } catch (Throwable $exception) {
            Log::warning('reCAPTCHA verification failed with exception.', [
                'error' => $exception->getMessage(),
            ]);

            $this->addError('data.recaptcha_token', __('exit-clearance::livewire/public-exit-clearance-request-form.recaptcha.failed'));

            return false;
        }

        return true;
    }
}
