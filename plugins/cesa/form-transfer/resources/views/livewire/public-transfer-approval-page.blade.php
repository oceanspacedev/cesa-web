<div class="pf-page">
    <div class="mx-auto max-w-2xl">
            @php
                $statusColor = $summary['status_color'] ?? 'gray';
                $currentStatusValue = strtolower($currentApproval['status'] ?? 'pending');
                $currentStatusEnum = \Cesa\FormTransfer\Enums\ApprovalStatus::tryFrom($currentStatusValue);
                $currentStatusLabel = $currentStatusEnum ? $currentStatusEnum->getLabel() : ucfirst($currentStatusValue);
                $currentStatusColor = $currentStatusEnum?->getColor() ?? 'gray';
                $formTitle = $summary['title'] ?? ($transferRequest->formTransfer?->name ?? 'Form Transfer');
                $requesterName = $summary['requester_name'] ?? ($transferRequest->requester_name ?? 'User');
                $uid = $summary['uid'] ?? null;
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
                        'value' => 'Rp '.($summary['transfer_amount'] ?? '0'),
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
                    <h1 class="text-[32px] font-normal text-gray-900 leading-tight">
                        {{ __('form-transfer::public.form.actions.heading', ['form' => $formTitle]) }}
                    </h1>
                    <p class="pf-hint mt-2">
                        {{ __('form-transfer::public.form.actions.subheading', ['requester' => $requesterName]) }}
                    </p>
                </div>
                <div class="border-t border-gray-200 px-6 py-3">
                    <div class="flex flex-wrap items-center gap-2 text-xs text-gray-600">
                        <span>{{ __('form-transfer::public.approval.submission_status_label') }}</span>
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
                        <span>{{ __('form-transfer::public.approval.your_approval_status') }}</span>
                        @php
                            $badgeColorClass = match($currentStatusColor ?? 'gray') {
                                'success' => 'bg-green-50 text-green-700 ring-green-600/20',
                                'danger' => 'bg-red-50 text-red-700 ring-red-600/10',
                                'warning' => 'bg-yellow-50 text-yellow-800 ring-yellow-600/20',
                                'primary' => 'bg-blue-50 text-blue-700 ring-blue-700/10',
                                default => 'bg-gray-50 text-gray-600 ring-gray-500/10',
                            };
                        @endphp
                        <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset {{ $badgeColorClass }}">
                            {{ $currentStatusLabel }}
                        </span>
                        @if (!empty($uid) && $uid !== '-')
                            <span class="text-gray-300">|</span>
                            <span class="font-mono text-gray-400">{{ $uid }}</span>
                        @endif
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
                        <h2 class="text-lg font-medium">{{ __('form-transfer::public.approval.submission_summary') }}</h2>
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
                                <div class="text-base font-medium text-gray-900 break-words">
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

                <div x-data="{ expanded: true }" class="overflow-hidden pf-card">
                    <button
                        type="button"
                        @click="expanded = ! expanded"
                        :aria-expanded="expanded.toString()"
                        class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                    >
                        <h2 class="text-lg font-medium">{{ __('form-transfer::public.approval.approval_flow') }}</h2>
                        <x-filament::icon
                            icon="heroicon-m-chevron-down"
                            class="h-5 w-5 transform transition-transform duration-200"
                            ::class="{ 'rotate-180': expanded }"
                        />
                    </button>

                    <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                        <ol class="space-y-4">
                        @foreach ($approvals as $index => $approval)
                            @php
                                $approvalStatusValue = strtolower($approval['status'] ?? 'pending');
                                $statusEnum = \Cesa\FormTransfer\Enums\ApprovalStatus::tryFrom($approvalStatusValue);
                                $approvalStatusLabel = $statusEnum ? $statusEnum->getLabel() : ucfirst($approvalStatusValue);
                                $approvalStatusColor = $statusEnum?->getColor() ?? 'gray';
                            @endphp
                            <li @class([
                                'rounded-lg border p-4',
                                'cesa-primary-border cesa-primary-soft' => $index === $currentApprovalIndex && in_array($approvalStatusValue, ['pending', 'waiting']),
                                'border-green-600 bg-green-50' => $index === $currentApprovalIndex && $approvalStatusValue === 'approved',
                                'border-red-600 bg-red-50' => $index === $currentApprovalIndex && in_array($approvalStatusValue, ['ditolak', 'rejected']),
                                'border-yellow-600 bg-yellow-50' => $index === $currentApprovalIndex && $approvalStatusValue === 'revisi',
                                'border-gray-200' => $index !== $currentApprovalIndex || !in_array($approvalStatusValue, ['pending', 'waiting', 'approved', 'ditolak', 'rejected', 'revisi']),
                            ])>
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-gray-900">
                                            {{ $approval['name'] ?? '-' }}
                                        </p>
                                        <p class="text-sm text-gray-600">
                                            @if (!empty($approval['title'])) {{ $approval['title'] }} @endif
                                        </p>
                                    </div>
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
                                </div>

                                @if (!empty($approval['comments']))
                                    <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50 p-4">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                            {{ __('form-transfer::public.form.actions.comments') }}
                                        </p>
                                        <p class="mt-1 text-sm leading-relaxed text-gray-700">
                                            {{ $approval['comments'] }}
                                        </p>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                        </ol>
                    </div>
                </div>

                @if ($this->isPendingApproval() && ! $actionTaken)
                    <div x-data="{ expanded: true }" class="overflow-hidden pf-card">
                        <button
                            type="button"
                            @click="expanded = ! expanded"
                            :aria-expanded="expanded.toString()"
                            class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                        >
                            <h2 class="text-lg font-medium">{{ __('form-transfer::public.approval.actions') }}</h2>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="h-5 w-5 transform transition-transform duration-200"
                                ::class="{ 'rotate-180': expanded }"
                            />
                        </button>

                        <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                            <form class="space-y-6">
                                {{ $this->form }}

                                <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-end">
                                    {{ $this->confirmRejectAction() }}
                                    {{ $this->confirmApproveAction() }}
                                </div>
                            </form>
                        </div>
                    </div>
                @else
                    <div x-data="{ expanded: true }" class="overflow-hidden pf-card">
                        <button
                            type="button"
                            @click="expanded = ! expanded"
                            :aria-expanded="expanded.toString()"
                            class="cesa-primary-bg cesa-primary-bg-hover flex w-full cursor-pointer items-center justify-between px-6 py-4 text-left text-white transition-colors"
                        >
                            <h2 class="text-lg font-medium">{{ __('form-transfer::public.approval.information') }}</h2>
                            <x-filament::icon
                                icon="heroicon-m-chevron-down"
                                class="h-5 w-5 transform transition-transform duration-200"
                                ::class="{ 'rotate-180': expanded }"
                            />
                        </button>

                        <div x-show="expanded" x-collapse class="border-t border-blue-100 px-6 py-5">
                            <p class="text-sm text-gray-600">
                                {{ __('form-transfer::public.approval.completed_info') }}
                            </p>
                        </div>
                    </div>
                @endif
            </div>
            <x-filament-actions::modals />
    </div>
</div>
