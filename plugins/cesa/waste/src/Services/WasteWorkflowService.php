<?php

namespace Cesa\Waste\Services;

use Cesa\Waste\Models\WasteBrand;
use Cesa\Waste\Models\WasteOutlet;
use Cesa\Waste\Models\WasteWorkflow;
use Illuminate\Validation\ValidationException;

class WasteWorkflowService
{
    public function resolve(WasteBrand $brand, WasteOutlet $outlet): ?WasteWorkflow
    {
        $outletWorkflow = WasteWorkflow::query()
            ->where('brand_id', $brand->getKey())
            ->where('outlet_id', $outlet->getKey())
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if ($outletWorkflow) {
            return $outletWorkflow;
        }

        return WasteWorkflow::query()
            ->where('brand_id', $brand->getKey())
            ->whereNull('outlet_id')
            ->where('is_active', true)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<int, array{label: string, name: string, email: ?string, phone: ?string, sort_order: int}>
     */
    public function snapshotOrEmpty(?WasteWorkflow $workflow): array
    {
        if (! $workflow) {
            return [];
        }

        return $this->snapshot($workflow);
    }

    /**
     * @return array<int, array{label: string, name: string, email: ?string, phone: ?string, sort_order: int}>
     */
    public function snapshot(WasteWorkflow $workflow): array
    {
        $steps = collect($workflow->steps ?? [])
            ->values()
            ->map(function (mixed $step, int $index): array {
                if (! is_array($step) || blank($step['name'] ?? null)) {
                    throw ValidationException::withMessages([
                        'workflow' => 'Workflow waste memiliki approver yang belum lengkap.',
                    ]);
                }

                if (blank($step['phone'] ?? null) && blank($step['email'] ?? null)) {
                    throw ValidationException::withMessages([
                        'workflow' => 'Setiap approver harus memiliki nomor WhatsApp atau email.',
                    ]);
                }

                return [
                    'label'      => trim((string) ($step['label'] ?? 'Approval '.($index + 1))),
                    'name'       => trim((string) $step['name']),
                    'email'      => filled($step['email'] ?? null) ? trim((string) $step['email']) : null,
                    'phone'      => filled($step['phone'] ?? null) ? trim((string) $step['phone']) : null,
                    'sort_order' => $index + 1,
                ];
            })
            ->all();

        if ($steps === []) {
            throw ValidationException::withMessages([
                'workflow' => 'Outlet belum memiliki workflow approval yang aktif.',
            ]);
        }

        return $steps;
    }
}
