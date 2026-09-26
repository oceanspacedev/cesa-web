<div class="pf-page">
    <div class="mx-auto max-w-xl">
            @php
                $page = $this->currentPage();
                $totalSteps = $this->totalSteps();
                $firstEvent = $data['events'][0] ?? null;
            @endphp
            <form id="form" x-on:submit.prevent="$wire.currentStep < {{ $totalSteps }} ? $wire.nextStep() : $wire.submit()">
                <div class="pf-header-card mb-4">
                    <div class="px-6 pb-5 pt-6">
                        <h1 class="pf-title">{{ __('waste::waste.form_heading') }} - {{ $brandData['name'] ?? '' }} / {{ $outletData['name'] ?? '' }}</h1>
                        <p class="pf-hint mt-3">{{ $this->stepHint() }}</p>
                        <p class="pf-hint">{{ __('waste::waste.monthly_flow_hint') }}</p>
                    </div>
                    <div class="border-t border-gray-200 px-6 py-3">
                        <p class="pf-required-note">{{ __('waste::waste.required_hint') }}</p>
                    </div>
                </div>

                <div class="pf-card p-6">
                    <div class="pf-section-bar -mx-6 -mt-6 mb-6 px-6 py-3">
                        <h2 class="text-lg font-medium">{{ $this->stepTitle() }}</h2>
                    </div>

                    @if ($page['kind'] === 'start' && is_array($firstEvent))
                        <div class="space-y-6" x-data="wasteReporter" x-init="restore()">
                            <x-waste::public-field :label="__('waste::waste.fields.event_date')" :required="true" :error="$errors->first('data.event_date')">
                                <input type="date" wire:model="data.event_date" class="fi-input" required>
                            </x-waste::public-field>

                            <div class="space-y-6">
                                <x-waste::public-field :label="__('waste::waste.fields.reporter_name')" :required="true" :error="$errors->first('data.reporter_name')">
                                    <input type="text" wire:model="data.reporter_name" data-waste-reporter="name" x-on:input="remember()" class="fi-input" placeholder="{{ __('waste::waste.placeholders.reporter_name') }}" required>
                                </x-waste::public-field>

                                <x-waste::public-field :label="__('waste::waste.fields.reporter_phone')" :required="true" :error="$errors->first('data.reporter_phone')">
                                    <input type="tel" wire:model="data.reporter_phone" data-waste-reporter="phone" x-on:input="remember()" class="fi-input" placeholder="{{ __('waste::waste.placeholders.reporter_phone') }}" required>
                                </x-waste::public-field>
                            </div>

                            <x-waste::public-field :label="__('waste::waste.fields.reporter_email')" :error="$errors->first('data.reporter_email')">
                                <input type="email" wire:model="data.reporter_email" data-waste-reporter="email" x-on:input="remember()" class="fi-input" placeholder="{{ __('waste::waste.placeholders.reporter_email') }}">
                            </x-waste::public-field>
                        </div>
                    @else
                        <div class="space-y-8">
                            @foreach (($data['events'] ?? []) as $eventIndex => $event)
                                <div wire:key="report-event-{{ $this->structureVersion }}-{{ $eventIndex }}" class="space-y-6">
                                    @if (count($data['events']) > 1)
                                        <div class="flex items-center justify-between gap-3">
                                            <h3 class="text-sm font-medium text-gray-900">{{ __('waste::waste.event_title', ['number' => $eventIndex + 1]) }}</h3>
                                            <button type="button" wire:click="removeEvent({{ $eventIndex }})" class="text-sm font-medium text-red-600 hover:text-red-700 hover:underline">
                                                {{ __('waste::waste.remove') }}
                                            </button>
                                        </div>
                                    @endif

                                    <div class="space-y-4">
                                        @foreach (($event['lines'] ?? []) as $lineIndex => $line)
                                            @php
                                                $lineItemModel = 'data.events.'.$eventIndex.'.lines.'.$lineIndex.'.item_id';
                                                $lineUnitModel = 'data.events.'.$eventIndex.'.lines.'.$lineIndex.'.unit';
                                            @endphp
                                            <div
                                                wire:key="line-{{ $this->structureVersion }}-{{ $eventIndex }}-{{ $lineIndex }}"
                                                x-data="wasteLineUnits(@js($this->itemUnitOptions), @js($line['item_id'] ?? ''), @js($line['unit'] ?? ''), @js($lineUnitModel))"
                                                x-on:waste-item-selected="if ($event.detail.model === @js($lineItemModel)) selectItem($event.detail.id)"
                                                class="space-y-4 pf-card p-4"
                                            >
                                                <div class="flex items-center justify-between gap-3">
                                                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">{{ __('waste::waste.line_title', ['number' => $lineIndex + 1]) }}</span>
                                                    @if (count($event['lines']) > 1)
                                                        <button type="button" wire:click="removeLine({{ $eventIndex }}, {{ $lineIndex }})" class="text-sm font-medium text-red-600 hover:text-red-700 hover:underline">
                                                            {{ __('waste::waste.remove') }}
                                                        </button>
                                                    @endif
                                                </div>

                                                <x-waste::public-select
                                                    :label="__('waste::waste.choose_item')"
                                                    :options="$items"
                                                    :model="'data.events.'.$eventIndex.'.lines.'.$lineIndex.'.item_id'"
                                                    :value="$line['item_id'] ?? ''"
                                                    :required="true"
                                                    :placeholder="__('waste::waste.choose_item')"
                                                    :error="$errors->first('data.events.'.$eventIndex.'.lines.'.$lineIndex.'.item_id')"
                                                />

                                                <x-waste::public-field :label="__('waste::waste.fields.quantity')" :required="true" :error="$errors->first('data.events.'.$eventIndex.'.lines.'.$lineIndex.'.quantity') ?: $errors->first($lineUnitModel)">
                                                    <div class="flex w-full items-center">
                                                        <input type="text" inputmode="decimal" wire:model="data.events.{{ $eventIndex }}.lines.{{ $lineIndex }}.quantity" class="fi-input min-w-0 flex-1" placeholder="{{ __('waste::waste.placeholders.quantity') }}" required>
                                                        <span x-show="availableUnits.length <= 1" class="shrink-0 pe-2 text-xs font-medium text-gray-500" data-waste-quantity-unit="{{ $line['unit'] ?? ($this->itemUnits[$line['item_id']] ?? '') }}" x-text="selectedUnit">{{ $line['unit'] ?? ($this->itemUnits[$line['item_id']] ?? '') }}</span>
                                                        <select x-show="availableUnits.length > 1" x-model="selectedUnit" x-on:change="selectUnit($event.target.value)" x-bind:disabled="availableUnits.length <= 1" aria-label="{{ __('waste::waste.fields.unit') }}" class="shrink-0 border-0 bg-transparent pe-2 text-xs font-medium text-gray-700 focus:ring-0">
                                                            <template x-for="unit in availableUnits" :key="unit">
                                                                <option :value="unit" x-text="unit"></option>
                                                            </template>
                                                        </select>
                                                    </div>
                                                </x-waste::public-field>

                                                <p x-show="availableUnits.length > 1" x-cloak class="text-xs text-gray-600">{{ __('waste::waste.alternate_unit_hint') }}</p>
                                            </div>
                                        @endforeach

                                        <button type="button" wire:click="addLine({{ $eventIndex }})" class="w-full rounded-lg border border-dashed border-gray-300 bg-white px-4 py-3 text-sm font-medium text-primary-600 hover:border-primary-300 hover:bg-primary-50">
                                            + {{ __('waste::waste.add_line') }}
                                        </button>
                                    </div>

                                    @include('waste::livewire.partials.event-details', ['eventIndex' => $eventIndex, 'event' => $event])

                                    @php
                                        $storedPhotos = $photos[$eventIndex] ?? [];
                                        $photoKeys = array_keys($storedPhotos);
                                        $photoCount = count($photoKeys);
                                        $photoMax = (int) config('waste.attachments.max_files', 5);
                                        $existingPhotoIds = $isRevision ? ($existingEvidenceIds[$event['source_event_id'] ?? ''] ?? []) : [];
                                    @endphp
                                    <div
                                        wire:key="waste-camera-{{ $eventIndex }}-{{ $photoCount }}"
                                        x-data="wasteCamera({{ $eventIndex }}, {{ $photoCount }}, {{ $photoMax }})"
                                        x-init="init()"
                                        class="space-y-4 border-t border-gray-200 pt-6"
                                    >
                                        <div class="space-y-1">
                                            <p class="text-sm font-medium text-gray-950">
                                                {{ __('waste::waste.camera_title') }}
                                                <sup class="text-[#D93025]">*</sup>
                                            </p>
                                            <p class="text-base font-medium text-gray-950">{{ __('waste::waste.camera_progress', ['count' => $photoCount, 'max' => $photoMax]) }}</p>
                                            <p class="pf-hint">
                                                @if ($photoCount >= $photoMax)
                                                    {{ __('waste::waste.camera_full', ['max' => $photoMax]) }}
                                                @elseif ($photoCount > 0)
                                                    {{ __('waste::waste.camera_can_add', ['remaining' => $photoMax - $photoCount]) }}
                                                @elseif ($existingPhotoIds !== [])
                                                    {{ __('waste::waste.camera_revision_keep') }}
                                                @else
                                                    {{ __('waste::waste.camera_need_more') }}
                                                @endif
                                            </p>
                                            <p class="pf-hint">{{ __('waste::waste.camera_hint') }}</p>
                                            @if ($existingPhotoIds !== [] && $photoCount > 0)
                                                <p class="pf-hint">{{ __('waste::waste.camera_revision_replace') }}</p>
                                            @endif
                                        </div>

                                        @if ($existingPhotoIds !== [] && $photoCount === 0 && $manageToken)
                                            <div class="space-y-2">
                                                <p class="text-sm font-medium text-gray-800">{{ __('waste::waste.camera_previous') }}</p>
                                                <ol class="waste-camera-slots">
                                                    @foreach ($existingPhotoIds as $existingPhotoIndex => $evidenceId)
                                                        <li>
                                                            <img
                                                                src="{{ route('waste.public.evidence', ['evidence' => $evidenceId, 'token' => $manageToken]) }}"
                                                                alt="{{ __('waste::waste.camera_previous_number', ['number' => $existingPhotoIndex + 1]) }}"
                                                                class="aspect-square w-full rounded-lg object-cover ring-1 ring-gray-200"
                                                            >
                                                        </li>
                                                    @endforeach
                                                </ol>
                                            </div>
                                        @endif

                                        <ol class="waste-camera-slots">
                                            @for ($slot = 0; $slot < $photoMax; $slot++)
                                                <li wire:key="waste-photo-{{ $eventIndex }}-{{ $photoKeys[$slot] ?? 'empty' }}-{{ $photoCount }}">
                                                    @if (isset($photoKeys[$slot]))
                                                        <div class="relative">
                                                            @if ($preview = $this->photoPreviewUrl($eventIndex, (int) $photoKeys[$slot]))
                                                                <img src="{{ $preview }}" alt="{{ __('waste::waste.camera_title') }} {{ $slot + 1 }}" class="aspect-square w-full rounded-lg object-cover ring-1 ring-gray-200">
                                                            @else
                                                                <div class="flex aspect-square items-center justify-center rounded-lg bg-gray-100 text-xs font-medium text-gray-500 ring-1 ring-gray-200">{{ $slot + 1 }}</div>
                                                            @endif
                                                            <button
                                                                type="button"
                                                                wire:click="removePhoto({{ $eventIndex }}, {{ (int) $photoKeys[$slot] }})"
                                                                class="absolute right-1 top-1 flex items-center justify-center rounded-full bg-gray-950 text-xs font-medium leading-none text-white shadow-sm" style="width: 1.5rem; height: 1.5rem"
                                                                aria-label="{{ __('waste::waste.camera_remove', ['number' => $slot + 1]) }}"
                                                            >
                                                                <span aria-hidden="true">&times;</span>
                                                            </button>
                                                        </div>
                                                    @else
                                                        <div class="flex aspect-square items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 text-xs font-medium text-gray-400" aria-hidden="true">{{ $slot + 1 }}</div>
                                                    @endif
                                                </li>
                                            @endfor
                                        </ol>

                                        <button
                                            type="button"
                                            x-show="!stream && !previewUrl && saved < maxPhotos"
                                            x-cloak
                                            @click="start()"
                                            class="waste-camera-launch flex w-full flex-col items-center justify-center gap-2 rounded-xl px-4 py-8 text-center"
                                        >
                                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-700 text-white" aria-hidden="true">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="h-7 w-7">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 8.5h3.2l1.2-2h7.2l1.2 2H20a1.5 1.5 0 0 1 1.5 1.5v8A1.5 1.5 0 0 1 20 19.5H4A1.5 1.5 0 0 1 2.5 18v-8A1.5 1.5 0 0 1 4 8.5Z" />
                                                    <circle cx="12" cy="13.5" r="3.2" />
                                                </svg>
                                            </span>
                                            <span class="text-base font-medium text-gray-950">{{ __('waste::waste.camera_start') }}</span>
                                            <span class="text-sm text-gray-600">{{ __('waste::waste.camera_open_hint') }}</span>
                                        </button>

                                        <div x-show="stream && !previewUrl" x-cloak class="space-y-3">
                                            <video x-ref="video" autoplay playsinline class="aspect-[4/3] w-full rounded-xl bg-black object-cover"></video>
                                            <div class="flex items-center gap-3">
                                                <button type="button" @click="close()" class="min-h-12 shrink-0 rounded-lg px-3 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                                    {{ __('waste::waste.camera_close') }}
                                                </button>
                                                <button type="button" @click="capture()" class="min-h-12 flex-1 rounded-full bg-primary-700 px-6 text-base font-medium text-white shadow-sm hover:bg-primary-800">
                                                    {{ __('waste::waste.camera_capture') }}
                                                </button>
                                            </div>
                                        </div>

                                        <div x-show="previewUrl" x-cloak class="space-y-3">
                                            <img x-ref="preview" :src="previewUrl" alt="{{ __('waste::waste.camera_title') }}" class="aspect-[4/3] w-full rounded-xl border border-gray-200 bg-gray-950 object-contain">
                                            <p class="text-sm leading-relaxed text-gray-700">{{ __('waste::waste.camera_review') }}</p>
                                            <div class="grid grid-cols-2 gap-3">
                                                <button type="button" @click="retake()" :disabled="uploading" class="min-h-12 rounded-lg border border-gray-300 bg-white px-3 text-sm font-medium text-gray-900 hover:bg-gray-50 disabled:opacity-60">
                                                    {{ __('waste::waste.camera_retake') }}
                                                </button>
                                                <button type="button" @click="usePhoto()" :disabled="uploading" class="min-h-12 rounded-lg bg-primary-700 px-3 text-sm font-medium text-white shadow-sm hover:bg-primary-800 disabled:opacity-60">
                                                    {{ __('waste::waste.camera_use') }}
                                                </button>
                                            </div>
                                            <p x-show="uploading" x-cloak class="text-sm text-gray-600">{{ __('waste::waste.camera_uploading') }}</p>
                                        </div>

                                        <canvas x-ref="canvas" class="hidden"></canvas>
                                        <p x-show="error" x-cloak x-text="error" class="text-sm text-red-600"></p>
                                        @if ($errors->first('photos.'.$eventIndex))
                                            <p class="text-sm text-red-600">{{ $errors->first('photos.'.$eventIndex) }}</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach

                            <button type="button" wire:click="addEvent" class="text-sm font-medium text-primary-600 hover:text-primary-700 hover:underline">
                                {{ __('waste::waste.add_event') }}
                            </button>
                        </div>
                    @endif
                </div>

                @if ($errors->any())
                    <div role="alert" class="mt-4 rounded-lg border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-800">
                        <p class="font-medium">{{ __('waste::waste.validation_summary') }}</p>
                        <ul class="mt-2 list-disc space-y-1 ps-5">
                            @foreach (array_unique($errors->all()) as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-4 flex items-center justify-between gap-3 px-1">
                    <p class="text-xs text-gray-600">{{ __('waste::waste.page_of', ['current' => $this->currentStep, 'total' => $totalSteps]) }}</p>

                    <div class="flex items-center gap-2">
                        @if ($this->currentStep > 1)
                            <x-filament::button
                                type="button"
                                color="gray"
                                outlined
                                wire:click="previousStep"
                                wire:loading.attr="disabled"
                                wire:target="previousStep,nextStep,submit"
                            >
                                {{ __('waste::waste.back') }}
                            </x-filament::button>
                        @endif

                        @if ($this->currentStep < $totalSteps)
                            <x-filament::button
                                type="button"
                                wire:click="nextStep"
                                wire:loading.attr="disabled"
                                wire:target="previousStep,nextStep,submit"
                                class="!bg-primary-700 !text-white shadow-sm hover:!bg-primary-800 hover:!text-white focus-visible:!ring-primary-300"
                            >
                                {{ __('waste::waste.next') }}
                            </x-filament::button>
                        @else
                            <x-filament::button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="photos,submit"
                                class="!bg-primary-700 !text-white shadow-sm hover:!bg-primary-800 hover:!text-white focus-visible:!ring-primary-300"
                            >
                                {{ $isRevision ? __('waste::waste.resubmit') : __('waste::waste.submit') }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </form>
    </div>
</div>

@script
<script>
    Alpine.data('wasteCamera', (eventIndex, startingCount, maxPhotos) => ({
        stream: null,
        nextPhoto: startingCount,
        saved: startingCount,
        maxPhotos,
        previewBlob: null,
        previewUrl: '',
        uploading: false,
        error: '',
        init() {},
        destroy() {
            this.close();
        },
        close() {
            this.stream?.getTracks().forEach((track) => track.stop());
            this.stream = null;
        },
        async start() {
            if (this.previewUrl || this.uploading || this.saved >= this.maxPhotos) {
                return;
            }
            this.error = '';
            this.previewBlob = null;
            this.previewUrl = '';
            if (! window.isSecureContext || ! navigator.mediaDevices?.getUserMedia) {
                this.error = @js(__('waste::waste.camera_https_error'));
                return;
            }
            try {
                this.close();
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
                await this.$nextTick();
                this.$refs.video.srcObject = this.stream;
                await this.$refs.video.play().catch(() => {});
            } catch (error) {
                this.stream = null;
                this.error = @js(__('waste::waste.camera_permission_error'));
            }
        },
        capture() {
            if (! this.stream) {
                this.error = @js(__('waste::waste.camera_start_first'));
                return;
            }
            if (this.nextPhoto >= this.maxPhotos) {
                this.error = @js(__('waste::waste.camera_max_error'));
                return;
            }
            const video = this.$refs.video;
            const canvas = this.$refs.canvas;
            if (! video.videoWidth || ! video.videoHeight) {
                this.error = @js(__('waste::waste.camera_start_first'));
                return;
            }
            const scale = Math.min(1, {{ (int) config('waste.camera.max_width', 1600) }} / video.videoWidth);
            canvas.width = Math.round(video.videoWidth * scale);
            canvas.height = Math.round(video.videoHeight * scale);
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            canvas.toBlob((blob) => {
                if (! blob) {
                    this.error = @js(__('waste::waste.camera_upload_error'));
                    return;
                }
                this.previewBlob = blob;
                this.previewUrl = URL.createObjectURL(blob);
                this.close();
            }, 'image/jpeg', {{ (float) config('waste.camera.quality', 0.82) }});
        },
        retake() {
            this.previewBlob = null;
            if (this.previewUrl) {
                URL.revokeObjectURL(this.previewUrl);
            }
            this.previewUrl = '';
            this.start();
        },
        usePhoto() {
            if (this.uploading || ! this.previewBlob || this.nextPhoto >= this.maxPhotos) {
                this.error = this.nextPhoto >= this.maxPhotos ? @js(__('waste::waste.camera_max_error')) : '';
                return;
            }
            this.uploading = true;
            this.error = '';
            const file = new File([this.previewBlob], `waste-${Date.now()}.jpg`, { type: 'image/jpeg' });
            this.$wire.upload(`photos.${eventIndex}.${this.nextPhoto}`, file, () => {
                this.uploading = false;
                this.nextPhoto += 1;
                this.saved += 1;
                this.previewBlob = null;
                if (this.previewUrl) {
                    URL.revokeObjectURL(this.previewUrl);
                }
                this.previewUrl = '';
            }, () => {
                this.uploading = false;
                this.error = @js(__('waste::waste.camera_upload_error'));
            });
        },
    }));
</script>
@endscript
