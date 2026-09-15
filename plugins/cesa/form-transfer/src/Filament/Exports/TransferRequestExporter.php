<?php

namespace Cesa\FormTransfer\Filament\Exports;

use Carbon\CarbonInterface;
use Cesa\FormTransfer\Enums\TransferRequestApprovalStatus;
use Cesa\FormTransfer\Enums\TransferRequestRealizationStatus;
use Cesa\FormTransfer\Enums\TransferRequestSubmissionStatus;
use Cesa\FormTransfer\Models\TransferRequest;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\URL;

class TransferRequestExporter extends Exporter
{
    protected static ?string $model = TransferRequest::class;

    public static function modifyQuery(Builder $query): Builder
    {
        return $query
            ->with([
                'formTransfer',
                'company',
                'user',
                'creator',
                'division',
                'bank',
                'realizations',
            ]);
    }

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('realization_status')
                ->label('Status')
                ->formatStateUsing(fn (mixed $state): string => static::formatRealizationStatus($state)),
            ExportColumn::make('realized_at')
                ->label('Tanggal Realisasi')
                ->state(fn (TransferRequest $record): string => static::formatRealizedAtWithNotes($record)),
            ExportColumn::make('realized_amount')
                ->label('Jumlah Terealisasi'),
            ExportColumn::make('remaining_realization_amount')
                ->label('Sisa Realisasi')
                ->state(fn (TransferRequest $record): string => (string) $record->remaining_realization_amount),
            ExportColumn::make('created_at')
                ->label('Tanggal Pengajuan'),
            ExportColumn::make('formTransfer.name')
                ->label('Nama Form Transfer'),
            ExportColumn::make('email')
                ->label('Email Address')
                ->enabledByDefault(false),
            ExportColumn::make('requester_name')
                ->label('Nama'),
            ExportColumn::make('division_name')
                ->label('Divisi'),
            ExportColumn::make('account_number')
                ->label('Nomer Rekening'),
            ExportColumn::make('account_name')
                ->label('Nama Pemilik Rekening'),
            ExportColumn::make('bank_display_name')
                ->label('Bank'),
            ExportColumn::make('transfer_amount')
                ->label('Jumlah Transfer'),
            ExportColumn::make('purpose')
                ->label('Keperluan'),
            ExportColumn::make('reference_note')
                ->label('Reffnote'),
            ExportColumn::make('submission_status')
                ->label('Status Transfer')
                ->formatStateUsing(fn (mixed $state): string => static::formatSubmissionStatus($state)),
            ExportColumn::make('invoice_path')
                ->label('Lampiran Invoice (Jika ada)')
                ->state(fn (TransferRequest $record): string => static::formatAttachmentLinks($record, 'invoice_path', 'invoice')),
            ExportColumn::make('account_attachment_path')
                ->label('Lampiran Nomer Rekening (Jika ada)')
                ->state(fn (TransferRequest $record): string => static::formatAttachmentLinks($record, 'account_attachment_path', 'account-attachment')),
            ExportColumn::make('uid')
                ->label('UID'),
            ExportColumn::make('approval_status')
                ->label('Status Approver')
                ->formatStateUsing(fn (mixed $state): string => static::formatApprovalStatus($state)),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your transfer request export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    protected static function formatSubmissionStatus(mixed $state): string
    {
        $status = $state instanceof TransferRequestSubmissionStatus
            ? $state
            : TransferRequestSubmissionStatus::tryFrom((string) $state);

        return $status?->getLabel() ?? ($state !== null ? (string) $state : '');
    }

    protected static function formatApprovalStatus(mixed $state): string
    {
        $status = $state instanceof TransferRequestApprovalStatus
            ? $state
            : TransferRequestApprovalStatus::tryFrom((string) $state);

        return $status?->getLabel() ?? ($state !== null ? (string) $state : '');
    }

    protected static function formatRealizationStatus(mixed $state): string
    {
        $status = $state instanceof TransferRequestRealizationStatus
            ? $state
            : TransferRequestRealizationStatus::tryFrom((string) $state);

        return $status?->getLabel() ?? ($state !== null ? (string) $state : '');
    }

    protected static function encodeJson(mixed $value): string
    {
        $encoded = json_encode($value ?? []);

        return is_string($encoded) ? $encoded : '';
    }

    protected static function formatRealizedAtWithNotes(TransferRequest $record): string
    {
        $realizedAt = $record->realized_at;
        $notes = is_string($record->realization_notes) ? trim($record->realization_notes) : '';
        $date = '';

        if ($realizedAt instanceof CarbonInterface) {
            $date = $realizedAt->locale(app()->getLocale())->translatedFormat('l, d/m/Y');
        } elseif ($realizedAt instanceof \DateTimeInterface) {
            $date = $realizedAt->format('d/m/Y');
        } elseif ($realizedAt !== null && $realizedAt !== '') {
            $date = (string) $realizedAt;
        }

        return trim($date.' '.$notes);
    }

    protected static function formatAttachmentLinks(
        TransferRequest $record,
        string $attribute,
        string $attachmentType,
    ): string {
        $paths = TransferRequest::normalizeAttachmentPaths($record->{$attribute});

        if ($paths === []) {
            return '';
        }

        $links = [];

        foreach ($paths as $index => $path) {
            $url = static::buildAttachmentUrl($record, $attachmentType, $index);
            $links[] = $url ?? $path;
        }

        return implode(', ', $links);
    }

    protected static function buildAttachmentUrl(
        TransferRequest $record,
        string $attachmentType,
        int $index,
    ): ?string {
        if (blank($record->status_response_id)) {
            return null;
        }

        try {
            return URL::route(
                'form-transfer.public.attachments.download',
                [
                    'statusResponseId' => $record->status_response_id,
                    'attachment'       => $attachmentType,
                    'file'             => $index,
                ],
            );
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
