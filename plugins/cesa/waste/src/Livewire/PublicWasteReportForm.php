<?php

namespace Cesa\Waste\Livewire;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteReport;
use Cesa\Waste\Services\WasteNotificationService;
use Cesa\Waste\Services\WasteReportService;
use Filament\Pages\SimplePage;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

class PublicWasteReportForm extends SimplePage
{
    use WithFileUploads;

    protected static string $layout = 'waste::layouts.form';

    protected string $view = 'waste::livewire.public-waste-report-form';

    #[Locked]
    public array $brandData = [];

    #[Locked]
    public array $outletData = [];

    public array $items = [];

    public array $itemUnits = [];

    public array $itemUnitOptions = [];

    public array $pipItems = [];

    public array $referenceItems = [];

    public array $categories = [];

    public array $sections = [];

    public array $data = [];

    public array $photos = [];

    #[Locked]
    public array $existingEvidenceIds = [];

    #[Locked]
    public bool $isRevision = false;

    #[Locked]
    public ?string $manageToken = null;

    #[Locked]
    public string $submissionKey = '';

    public int $currentStep = 1;

    public int $structureVersion = 0;

    public function mount(?string $brand = null, ?string $outlet = null, ?string $manageToken = null): void
    {
        abort_unless(filled($brand) && filled($outlet), 404);

        if ($manageToken) {
            $report = app(WasteReportService::class)->reportForManageToken($manageToken);
            abort_unless($report->brand->is_active && $report->outlet->is_active, 404);
            abort_unless(Str::lower($report->brand->code) === Str::lower($brand), 404);
            abort_unless(Str::lower($report->outlet->code) === Str::lower($outlet) || Str::lower($report->outlet->slug) === Str::lower($outlet), 404);
            $this->initializeFromReport($report, $manageToken);

            return;
        }

        $brandModel = WasteBrand::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereRaw('LOWER(code) = ?', [Str::lower($brand)])->orWhereRaw('LOWER(name) = ?', [Str::lower(str_replace('-', ' ', $brand))]))
            ->firstOrFail();
        $outletModel = WasteOutlet::query()
            ->where('brand_id', $brandModel->getKey())
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereRaw('LOWER(code) = ?', [Str::lower($outlet)])->orWhereRaw('LOWER(slug) = ?', [Str::lower($outlet)]))
            ->firstOrFail();

        $this->initialize($brandModel, $outletModel);
    }

    protected function initializeFromReport(WasteReport $report, string $manageToken): void
    {
        $this->initialize($report->brand, $report->outlet);
        $this->manageToken = $manageToken;
        $this->isRevision = true;
        $version = $report->latestVersion;

        $this->data = [
            'event_date'     => optional($report->event_date)->format('Y-m-d'),
            'reporter_name'  => $report->reporter_name,
            'reporter_phone' => $report->reporter_phone,
            'reporter_email' => $report->reporter_email,
            'events'         => $version?->events->map(fn ($event): array => [
                'source_event_id' => $event->getKey(),
                'section'         => $event->section,
                'category_id'     => $event->category_id,
                'category_name'   => $event->category_name,
                'reason'          => $event->reason,
                'pip_item_id'     => $event->pip_item_id,
                'pip_quantity'    => $event->pip_quantity,
                'lines'           => $event->lines->map(fn ($line): array => [
                    'item_id'  => $line->item_id,
                    'quantity' => $line->quantity,
                    'unit'     => $line->unit,
                ])->all(),
            ])->all() ?? [],
        ];

        $this->existingEvidenceIds = $version?->events
            ->mapWithKeys(fn ($event): array => [$event->getKey() => $event->evidences->pluck('id')->all()])
            ->all() ?? [];
        $this->photos = [];
    }

    protected function initialize(WasteBrand $brand, WasteOutlet $outlet): void
    {
        $this->submissionKey = (string) Str::uuid();
        $this->brandData = ['id' => $brand->getKey(), 'name' => $brand->name, 'code' => $brand->code];
        $this->outletData = ['id' => $outlet->getKey(), 'name' => $outlet->name, 'code' => $outlet->code, 'slug' => $outlet->slug];
        $activeItems = $brand->items()
            ->with('alternateUnits')
            ->where('is_active', true)
            ->whereNotNull('unit')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'unit', 'item_type']);
        $this->items = $activeItems
            ->mapWithKeys(fn ($item): array => [
                $item->id => sprintf('%s — %s (%s)', $item->name, $item->code, $item->unit),
            ])->all();
        $this->itemUnits = $activeItems
            ->mapWithKeys(fn ($item): array => [$item->id => (string) $item->unit])
            ->all();
        $this->itemUnitOptions = $activeItems
            ->mapWithKeys(fn ($item): array => [
                $item->id => array_values(array_unique(array_merge(
                    [(string) $item->unit],
                    $item->alternateUnits->where('is_active', true)->pluck('code')->all(),
                ))),
            ])
            ->all();
        $this->pipItems = $activeItems
            ->filter(fn ($item): bool => Str::upper((string) $item->item_type) === 'PIP')
            ->mapWithKeys(fn ($item): array => [
                $item->id => sprintf('%s — %s (%s)', $item->name, $item->code, $item->unit),
            ])->all();
        $this->referenceItems = Str::upper($brand->code) === 'MOMOYO' ? $this->items : $this->pipItems;
        $this->categories = $brand->categories()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
        $this->sections = $brand->sections()
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('name')
            ->all();

        if ($this->data === []) {
            $this->data = [
                'event_date'     => now()->timezone($outlet->timezone ?: 'Asia/Jakarta')->format('Y-m-d'),
                'reporter_name'  => '',
                'reporter_phone' => '',
                'reporter_email' => '',
                'events'         => [$this->emptyEvent()],
            ];
        }
    }

    public function addEvent(): void
    {
        if ($this->currentStep >= $this->totalSteps()) {
            $this->validateReportStep();
        }

        $this->data['events'][] = $this->emptyEvent();
    }

    public function removePhoto(int $eventIndex, int $photoIndex): void
    {
        if (! isset($this->photos[$eventIndex][$photoIndex])) {
            return;
        }

        unset($this->photos[$eventIndex][$photoIndex]);
        $this->photos[$eventIndex] = array_values($this->photos[$eventIndex]);
    }

    public function photoPreviewUrl(int $eventIndex, int $photoIndex): ?string
    {
        $photo = $this->photos[$eventIndex][$photoIndex] ?? null;

        if (! $photo instanceof TemporaryUploadedFile) {
            return null;
        }

        try {
            return $photo->temporaryUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    public function removeEvent(int $index): void
    {
        if (count($this->data['events'] ?? []) <= 1 || ! array_key_exists($index, $this->data['events'])) {
            return;
        }

        $remainingEvents = [];
        $remainingPhotos = [];

        foreach ($this->data['events'] as $eventIndex => $event) {
            if ($eventIndex === $index) {
                continue;
            }

            $newIndex = count($remainingEvents);
            $remainingEvents[] = $event;

            if (isset($this->photos[$eventIndex])) {
                $remainingPhotos[$newIndex] = $this->photos[$eventIndex];
            }
        }

        $this->data['events'] = $remainingEvents;
        $this->photos = $remainingPhotos;
        $this->structureVersion++;
        $this->currentStep = min($this->currentStep, $this->totalSteps());
    }

    public function totalSteps(): int
    {
        return 2;
    }

    /**
     * @return array{kind: string, eventIndex: int|null}
     */
    public function currentPage(): array
    {
        if ($this->currentStep <= 1) {
            return ['kind' => 'start', 'eventIndex' => 0];
        }

        return ['kind' => 'report', 'eventIndex' => null];
    }

    public function stepTitle(): string
    {
        return $this->currentPage()['kind'] === 'report'
            ? __('waste::waste.steps.report')
            : __('waste::waste.steps.start');
    }

    public function stepHint(): string
    {
        if ($this->currentPage()['kind'] !== 'report') {
            return __('waste::waste.step_hints.start');
        }

        return match (strtoupper((string) ($this->brandData['code'] ?? ''))) {
            'MOMOYO' => __('waste::waste.step_hints.momoyo'),
            'LUUCA'  => __('waste::waste.step_hints.luuca'),
            default  => __('waste::waste.step_hints.report'),
        };
    }

    public function nextStep(): void
    {
        $this->validateCurrentStep();

        if ($this->currentStep < $this->totalSteps()) {
            $this->currentStep++;
            $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');
        }
    }

    public function previousStep(): void
    {
        if ($this->currentStep > 1) {
            $this->currentStep--;
            $this->resetErrorBag();
            $this->js('window.scrollTo({ top: 0, behavior: "smooth" })');
        }
    }

    public function updatedCurrentStep(): void
    {
        $this->currentStep = min(max(1, $this->currentStep), $this->totalSteps());
    }

    public function addLine(int $eventIndex): void
    {
        $this->data['events'][$eventIndex]['lines'][] = ['item_id' => null, 'quantity' => null, 'unit' => null];
    }

    public function removeLine(int $eventIndex, int $lineIndex): void
    {
        if (count($this->data['events'][$eventIndex]['lines'] ?? []) <= 1) {
            return;
        }

        unset($this->data['events'][$eventIndex]['lines'][$lineIndex]);
        $this->data['events'][$eventIndex]['lines'] = array_values($this->data['events'][$eventIndex]['lines']);
        $this->structureVersion++;
    }

    public function submit(WasteReportService $reportService, WasteNotificationService $notificationService): void
    {
        $key = 'waste:submit:'.request()->ip().':'.$this->outletData['id'];
        $maxAttempts = max(1, (int) config('waste.submissions.max_attempts', 5));
        $decay = max(1, (int) config('waste.submissions.decay_seconds', 60));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages(['data' => 'Terlalu banyak pengiriman. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }

        $this->validateHeader();
        $this->validateReportStep();
        RateLimiter::hit($key, $decay);

        $brand = WasteBrand::query()->findOrFail($this->brandData['id']);
        $outlet = WasteOutlet::query()->whereKey($this->outletData['id'])->where('brand_id', $brand->getKey())->firstOrFail();
        $this->data['submission_key'] = $this->submissionKey;
        $uploadedPhotos = $this->uploadedPhotos();
        $result = $this->isRevision && $this->manageToken
            ? $reportService->revise($reportService->reportForManageToken($this->manageToken), $this->data, $uploadedPhotos)
            : $reportService->submit($brand, $outlet, $this->data, $uploadedPhotos);

        $notificationService->queueSubmission($result['report'], $result['progress_token'], $result['manage_token'], $result['approval_tokens']);

        $this->redirectRoute('waste.public.progress', [
            'token' => $result['progress_token'],
        ]);
    }

    public function getTitle(): string
    {
        return __('waste::waste.public_title');
    }

    protected function validateHeader(): void
    {
        $validator = validator($this->data, [
            'event_date'     => ['required', 'date'],
            'reporter_name'  => ['required', 'string', 'max:255'],
            'reporter_phone' => ['required', 'string', 'max:40'],
            'reporter_email' => ['nullable', 'email', 'max:255'],
            'events'         => ['required', 'array', 'min:1'],
        ]);

        $validator->validate();
    }

    protected function validateCurrentStep(): void
    {
        if ($this->currentStep <= 1) {
            $this->validate([
                'data.event_date'     => ['required', 'date'],
                'data.reporter_name'  => ['required', 'string', 'max:255'],
                'data.reporter_phone' => ['required', 'string', 'max:40'],
                'data.reporter_email' => ['nullable', 'email', 'max:255'],
            ], [], [
                'data.event_date'     => __('waste::waste.fields.event_date'),
                'data.reporter_name'  => __('waste::waste.fields.reporter_name'),
                'data.reporter_phone' => __('waste::waste.fields.reporter_phone'),
                'data.reporter_email' => __('waste::waste.fields.reporter_email'),
            ]);

            return;
        }

        $this->validateReportStep();
    }

    protected function validateReportStep(): void
    {
        $this->normalizeDecimalInputs();

        foreach (array_keys($this->data['events'] ?? []) as $index) {
            $index = (int) $index;

            $rules = [
                "data.events.{$index}.category_id" => ['required'],
                "data.events.{$index}.reason"      => [strtoupper((string) ($this->brandData['code'] ?? '')) === 'MOMOYO' ? 'nullable' : 'required', 'string', 'max:2000'],
            ];

            $rules["data.events.{$index}.pip_quantity"] = [
                filled($this->data['events'][$index]['pip_item_id'] ?? null) ? 'required' : 'nullable',
                'numeric',
                'gt:0',
                'regex:/^\d{1,14}(?:\.\d{1,4})?$/',
            ];

            $this->validate($rules, [], [
                "data.events.{$index}.category_id"  => __('waste::waste.fields.category'),
                "data.events.{$index}.reason"       => __('waste::waste.fields.reason'),
                "data.events.{$index}.pip_quantity" => __('waste::waste.fields.pip_quantity'),
            ]);

            $this->validate([
                "data.events.{$index}.lines"            => ['required', 'array', 'min:1'],
                "data.events.{$index}.lines.*.item_id"  => ['required'],
                "data.events.{$index}.lines.*.quantity" => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
                "data.events.{$index}.lines.*.unit"     => ['nullable', 'string', 'max:32'],
            ], [], [
                "data.events.{$index}.lines.*.item_id"  => __('waste::waste.choose_item'),
                "data.events.{$index}.lines.*.quantity" => __('waste::waste.fields.quantity'),
            ]);

            $this->validateEventPhotos($index);
        }
    }

    protected function normalizeDecimalInputs(): void
    {
        foreach ($this->data['events'] ?? [] as $eventIndex => $event) {
            if (is_string($event['pip_quantity'] ?? null)) {
                $this->data['events'][$eventIndex]['pip_quantity'] = str_replace(',', '.', trim($event['pip_quantity']));
            }

            foreach ($event['lines'] ?? [] as $lineIndex => $line) {
                if (is_string($line['quantity'] ?? null)) {
                    $this->data['events'][$eventIndex]['lines'][$lineIndex]['quantity'] = str_replace(',', '.', trim($line['quantity']));
                }
            }
        }
    }

    protected function validatePhotos(): void
    {
        foreach (array_keys($this->data['events'] ?? []) as $index) {
            $this->validateEventPhotos((int) $index);
        }
    }

    protected function validateEventPhotos(int $index): void
    {
        $photos = array_values($this->photos[$index] ?? []);
        $sourceEventId = $this->data['events'][$index]['source_event_id'] ?? null;
        if ($photos === [] && $this->isRevision && ! empty($this->existingEvidenceIds[$sourceEventId])) {
            return;
        }

        if (count($photos) < 1 || count($photos) > (int) config('waste.attachments.max_files', 5)) {
            throw ValidationException::withMessages(["photos.{$index}" => 'Setiap kejadian wajib memiliki 1 sampai 5 foto kamera.']);
        }

        foreach ($photos as $photo) {
            if (! $photo instanceof TemporaryUploadedFile || ! $photo->isValid()) {
                throw ValidationException::withMessages(["photos.{$index}" => 'Foto kamera tidak valid.']);
            }

            validator(['photo' => $photo], [
                'photo' => ['image', 'mimetypes:image/jpeg,image/png,image/webp,image/gif', 'max:'.config('waste.attachments.max_size', 5120)],
            ])->validate();
        }
    }

    /**
     * @return array<int, array<int, TemporaryUploadedFile>>
     */
    protected function uploadedPhotos(): array
    {
        return collect($this->photos)->map(fn ($photos): array => array_values(array_filter(
            is_array($photos) ? $photos : [$photos],
            fn ($photo): bool => $photo instanceof TemporaryUploadedFile,
        )))->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyEvent(): array
    {
        return [
            'source_event_id' => null,
            'section'         => '',
            'category_id'     => null,
            'category_name'   => '',
            'reason'          => '',
            'pip_item_id'     => null,
            'pip_quantity'    => null,
            'lines'           => [['item_id' => null, 'quantity' => null, 'unit' => null]],
        ];
    }
}
