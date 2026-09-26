<div class="pf-page">
    <div class="mx-auto max-w-2xl">
        @if (empty($summary))
            <form wire:submit="lookup">
                <div class="pf-header-card mb-4">
                    <div class="px-6 pt-5 pb-6">
                        <h1 class="pf-title-display">
                            {{ __('form-transfer::public.progress.lookup.heading') }}
                        </h1>
                        <p class="pf-hint mt-2">
                            {{ __('form-transfer::public.progress.lookup.description') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <div x-data="{ expanded: true }" class="overflow-hidden pf-card">
                        <button
                            type="button"
                            @click="expanded = ! expanded"
                            :aria-expanded="expanded.toString()"
                            class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                        >
                            <h2 class="text-lg font-medium">{{ __('form-transfer::public.progress.lookup.heading') }}</h2>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="h-5 w-5 transform transition-transform duration-200"
                                ::class="{ 'rotate-180': expanded }"
                            />
                        </button>

                        <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                            {{ $this->form }}
                        </div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-3 px-1">
                    <a
                        href="{{ route('form-transfer.public.index') }}"
                        class="text-sm font-medium text-gray-600 hover:text-gray-900 hover:underline"
                    >
                        &larr; {{ __('form-transfer::public.index.heading') }}
                    </a>

                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="lookup"
                        class="!bg-primary-700 text-white shadow-sm hover:!bg-primary-800 focus-visible:!ring-primary-300"
                    >
                        {{ __('form-transfer::public.progress.lookup.submit') }}
                    </x-filament::button>
                </div>

                @if ($lookupSearched)
                    <div x-data="{ expanded: true }" class="mt-8 overflow-hidden pf-card">
                        <button
                            type="button"
                            @click="expanded = ! expanded"
                            :aria-expanded="expanded.toString()"
                            class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                        >
                            <h2 class="text-lg font-medium">{{ __('form-transfer::public.progress.lookup.results_heading') }}</h2>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="h-5 w-5 transform transition-transform duration-200"
                                ::class="{ 'rotate-180': expanded }"
                            />
                        </button>

                        <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                            <div class="space-y-3">
                                @forelse ($lookupResults as $result)
                                    <a
                                        href="{{ $result['url'] }}"
                                        class="group block rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-600 hover:shadow-md"
                                    >
                                        <div class="flex items-start justify-between gap-4">
                                            <div class="min-w-0">
                                                <p class="font-mono text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                    {{ $result['uid'] }}
                                                </p>
                                                <h3 class="mt-1 text-base font-semibold text-gray-900 group-hover:text-primary-600">
                                                    {{ $result['title'] }}
                                                </h3>
                                                <p class="mt-1 text-sm text-gray-600">
                                                    {{ $result['requester'] }}
                                                </p>
                                            </div>

                                            @php
                                                $badgeColorClass = match($result['status_color'] ?? 'gray') {
                                                    'success' => 'bg-green-50 text-green-700 ring-green-600/20',
                                                    'danger' => 'bg-red-50 text-red-700 ring-red-600/10',
                                                    'warning' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                                                    'primary' => 'bg-blue-50 text-blue-700 ring-blue-700/10',
                                                    default => 'bg-gray-50 text-gray-600 ring-gray-500/10',
                                                };
                                            @endphp
                                            <span class="shrink-0 inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeColorClass }}">
                                                {{ $result['status'] }}
                                            </span>
                                        </div>

                                        <div class="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-sm text-gray-600 sm:grid-cols-3">
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                    {{ __('form-transfer::public.progress.lookup.submitted_at') }}
                                                </p>
                                                <p class="mt-1 text-gray-900">{{ $result['submitted_at'] }}</p>
                                            </div>
                                            <div>
                                                <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                                    {{ __('form-transfer::public.progress.lookup.amount') }}
                                                </p>
                                                <p class="mt-1 text-gray-900">Rp {{ $result['amount'] }}</p>
                                            </div>
                                            <div class="flex items-end sm:justify-end">
                                                <span class="inline-flex items-center gap-1.5 text-sm font-medium text-primary-600">
                                                    {{ __('form-transfer::public.progress.lookup.view_progress') }}
                                                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-4 w-4" />
                                                </span>
                                            </div>
                                        </div>
                                    </a>
                                @empty
                                    <p class="text-sm text-gray-600">
                                        {{ __('form-transfer::public.progress.lookup.empty_state') }}
                                    </p>
                                @endforelse
                            </div>
                        </div>
                    </div>
                @endif
            </form>
        @else
            @php
                $statusColor = $summary['status_color'] ?? 'gray';
                $uid = $summary['uid'] ?? null;
                $requesterName = $summary['requester_name'] ?? 'User';
                $division = $summary['division'] ?? null;
                $summaryItems = [
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.uid'),
                        'value' => $summary['uid'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.email'),
                        'value' => $summary['email'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.requester_name'),
                        'value' => $summary['requester_name'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.division'),
                        'value' => $summary['division'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.account_number'),
                        'value' => $summary['account_number'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.account_name'),
                        'value' => $summary['account_name'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.bank_name'),
                        'value' => $summary['bank'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.transfer_amount'),
                        'value' => 'Rp ' . ($summary['transfer_amount'] ?? '0'),
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.realized_amount'),
                        'value' => 'Rp ' . ($summary['realized_amount'] ?? '0'),
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.remaining_realization_amount'),
                        'value' => 'Rp ' . ($summary['remaining_amount'] ?? '0'),
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.purpose'),
                        'value' => $summary['purpose'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.reference_note'),
                        'value' => $summary['reference_note'] ?? '-',
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.invoice'),
                        'value' => $summary['invoice_links'] ?? ($summary['invoice'] ?? null),
                        'type' => 'link',
                        'link_label' => __('form-transfer::filament/resources/transfer-request/notifications.view_attachment'),
                    ],
                    [
                        'label' => __('form-transfer::filament/resources/transfer-request/fields.account_attachment'),
                        'value' => $summary['account_attachment_links'] ?? ($summary['account_attachment'] ?? null),
                        'type' => 'link',
                        'link_label' => __('form-transfer::filament/resources/transfer-request/notifications.view_attachment'),
                    ],
                ];
            @endphp

            <div class="pf-header-card mb-4">
                <div class="px-6 pt-5 pb-6">
                    <h1 class="pf-title-display">
                        {{ __('form-transfer::public.progress.heading') }}
                    </h1>
                    <p class="pf-hint mt-2">
                        {{ __('form-transfer::public.progress.attn') }} <span class="font-medium text-gray-900">{{ $requesterName }}</span>
                        @if (!empty($division) && $division !== '-')
                            ({{ $division }})
                        @endif
                    </p>
                </div>
                <div class="border-t border-gray-200 px-6 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                        <span>{{ __('form-transfer::public.progress.current_status') }}</span>
                        @php
                            $badgeColorClass = match($statusColor ?? 'gray') {
                                'success' => 'bg-green-50 text-green-700 ring-green-600/20',
                                'danger' => 'bg-red-50 text-red-700 ring-red-600/10',
                                'warning' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                                'primary' => 'bg-blue-50 text-blue-700 ring-blue-700/10',
                                default => 'bg-gray-50 text-gray-600 ring-gray-500/10',
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeColorClass }}">
                            {{ $statusLabel }}
                        </span>
                        <span class="text-gray-300">|</span>
                        <span class="font-mono text-gray-400">{{ $uid ?? '-' }}</span>
                    </div>
                </div>
            </div>

            <div class="space-y-4">
                <div x-data="{ expanded: true }" class="overflow-hidden pf-card">
                    <button
                        type="button"
                        @click="expanded = ! expanded"
                        :aria-expanded="expanded.toString()"
                        class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                    >
                        <h2 class="text-lg font-medium">{{ __('form-transfer::public.progress.submission_summary') }}</h2>
                        <x-filament::icon
                            icon="heroicon-m-chevron-down"
                            class="h-5 w-5 transform transition-transform duration-200"
                            ::class="{ 'rotate-180': expanded }"
                        />
                    </button>

                    <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                        <div class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                            @foreach ($summaryItems as $item)
                                <div class="space-y-1">
                                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        {{ $item['label'] }}
                                    </p>
                                    <div class="break-words text-base font-medium text-gray-900">
                                        @if (($item['type'] ?? null) === 'link')
                                            @if (!empty($item['value']))
                                                @php $links = $item['value']; @endphp
                                                @if (is_array($links))
                                                    <div class="flex flex-col gap-1">
                                                        @foreach ($links as $linkIndex => $link)
                                                            <a class="inline-flex items-center gap-1.5 text-blue-600 hover:text-blue-500 hover:underline" href="{{ $link }}" target="_blank" rel="noopener">
                                                                <x-filament::icon icon="heroicon-m-paper-clip" class="h-4 w-4" />
                                                                {{ ($item['link_label'] ?? __('form-transfer::filament/resources/transfer-request/notifications.view_attachment')) }} {{ $linkIndex + 1 }}
                                                            </a>
                                                        @endforeach
                                                    </div>
                                                @else
                                                    <a class="inline-flex items-center gap-1.5 text-blue-600 hover:text-blue-500 hover:underline" href="{{ $item['value'] }}" target="_blank" rel="noopener">
                                                        <x-filament::icon icon="heroicon-m-paper-clip" class="h-4 w-4" />
                                                        {{ $item['link_label'] ?? __('form-transfer::filament/resources/transfer-request/notifications.view_attachment') }}
                                                    </a>
                                                @endif
                                            @else
                                                -
                                            @endif
                                        @else
                                            {{ $item['value'] ?? '-' }}
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                @if (!empty($summary['realizations']))
                    <div x-data="{ expanded: true }" class="overflow-hidden pf-card">
                        <button
                            type="button"
                            @click="expanded = ! expanded"
                            :aria-expanded="expanded.toString()"
                            class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                        >
                            <h2 class="text-lg font-medium">{{ __('form-transfer::filament/resources/transfer-request/fields.realization_history') }}</h2>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="h-5 w-5 transform transition-transform duration-200"
                                ::class="{ 'rotate-180': expanded }"
                            />
                        </button>

                        <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                            <ol class="space-y-3">
                                @foreach ($summary['realizations'] as $realization)
                                    <li class="rounded-lg border border-gray-200 p-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900">
                                                    Rp {{ $realization['amount'] ?? '0' }}
                                                </p>
                                                <p class="mt-1 text-xs text-gray-500">
                                                    {{ $realization['realized_at'] ? \Carbon\Carbon::parse($realization['realized_at'])->format('d M Y H:i') : '-' }}
                                                </p>
                                            </div>
                                        </div>
                                        @if (!empty($realization['notes']))
                                            <p class="mt-3 text-sm leading-relaxed text-gray-700">
                                                {{ $realization['notes'] }}
                                            </p>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>
                    </div>
                @endif

                <div x-data="{ expanded: true }" class="overflow-hidden pf-card">
                    <button
                        type="button"
                        @click="expanded = ! expanded"
                        :aria-expanded="expanded.toString()"
                        class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                    >
                        <h2 class="text-lg font-medium">{{ __('form-transfer::public.progress.approval_flow') }}</h2>
                        <x-filament::icon
                            icon="heroicon-m-chevron-down"
                            class="h-5 w-5 transform transition-transform duration-200"
                            ::class="{ 'rotate-180': expanded }"
                        />
                    </button>

                    <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                        @if (empty($approvals))
                            <p class="text-sm text-gray-500">{{ __('form-transfer::public.progress.no_approval_yet') }}</p>
                        @else
                            <ol class="space-y-4">
                                @foreach ($approvals as $approval)
                                    @php
                                        $approvalStatusValue = strtolower($approval['status'] ?? 'pending');
                                        $statusEnum = \Cesa\FormTransfer\Enums\ApprovalStatus::tryFrom($approvalStatusValue);
                                        $approvalStatusLabel = $statusEnum ? $statusEnum->getLabel() : ucfirst($approvalStatusValue);
                                        $approvalStatusColor = $statusEnum?->getColor() ?? 'gray';
                                    @endphp
                                    <li class="rounded-lg border border-gray-200 p-4">
                                        <div class="flex items-start justify-between gap-4">
                                            <div>
                                                <p class="text-sm font-semibold text-gray-900">
                                                    {{ $approval['approver_name'] ?? ($approval['name'] ?? '-') }}
                                                </p>
                                                <p class="text-sm text-gray-600">
                                                    @if (!empty($approval['layer_name'])) {{ $approval['layer_name'] }} @elseif(!empty($approval['title'])) {{ $approval['title'] }} @endif
                                                </p>
                                            </div>
                                            <div class="flex flex-col items-end">
                                                @php
                                                    $badgeColorClass = match($approvalStatusColor ?? 'gray') {
                                                        'success' => 'bg-green-50 text-green-700 ring-green-600/20',
                                                        'danger' => 'bg-red-50 text-red-700 ring-red-600/10',
                                                        'warning' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                                                        'primary' => 'bg-blue-50 text-blue-700 ring-blue-700/10',
                                                        default => 'bg-gray-50 text-gray-600 ring-gray-500/10',
                                                    };
                                                @endphp
                                                <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeColorClass }}">
                                                    {{ $approvalStatusLabel }}
                                                </span>
                                                @if (!empty($approval['acted_at']))
                                                    <time class="mt-1 text-[11px] text-gray-400">
                                                        {{ \Carbon\Carbon::parse($approval['acted_at'])->format('d M Y H:i') }}
                                                    </time>
                                                @endif
                                            </div>
                                        </div>

                                        @if (!empty($approval['notes']) && $approval['notes'] !== '-')
                                            <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-4">
                                                <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                                    {{ __('form-transfer::public.form.actions.comments') }}
                                                </p>
                                                <p class="mt-1 text-sm leading-relaxed text-gray-700">
                                                    {{ $approval['notes'] }}
                                                </p>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
