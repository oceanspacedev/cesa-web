<?php

namespace Cesa\ExitClearance\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Cesa\ExitClearance\Models\Request;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class ExitClearanceRequestPdfService
{
    public function download(Request $record): StreamedResponse
    {
        $pdfOutput = $this->renderPdf($record);

        return response()->streamDownload(function () use ($pdfOutput): void {
            echo $pdfOutput;
        }, $this->getPdfFileName($record));
    }

    public function downloadBulkArchive(Collection $records): BinaryFileResponse
    {
        if ($records->isEmpty()) {
            throw new RuntimeException('No exit clearance requests were selected for PDF download.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'exit_clearance_request_pdf_');

        if ($zipPath === false) {
            throw new RuntimeException('Unable to create a temporary archive for exit clearance PDFs.');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the exit clearance PDF archive.');
        }

        $usedEntryNames = [];

        foreach ($records as $record) {
            if (! $record instanceof Request) {
                continue;
            }

            $entryName = $this->getUniqueZipEntryName($this->getPdfFileName($record), $usedEntryNames);

            $zip->addFromString($entryName, $this->renderPdf($record));
        }

        $zip->close();

        return response()
            ->download($zipPath, $this->getBulkArchiveFileName(), ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }

    public function getPdfFileName(Request $record): string
    {
        return $this->getFilenamePrefix().'-'.($record->form_uid ?: $record->id).'.pdf';
    }

    public function getBulkArchiveFileName(): string
    {
        return $this->getFilenamePrefix().'-bulk.zip';
    }

    protected function renderPdf(Request $record): string
    {
        $record->loadMissing(['company', 'department', 'approvers']);

        return Pdf::loadView('exit-clearance::pdf.request', [
            'record' => $record,
        ])->setPaper('a4', 'portrait')->output();
    }

    /**
     * @param  array<string, int>  $usedEntryNames
     */
    protected function getUniqueZipEntryName(string $entryName, array &$usedEntryNames): string
    {
        if (! array_key_exists($entryName, $usedEntryNames)) {
            $usedEntryNames[$entryName] = 1;

            return $entryName;
        }

        $usedEntryNames[$entryName]++;

        $extension = pathinfo($entryName, PATHINFO_EXTENSION);
        $baseName = pathinfo($entryName, PATHINFO_FILENAME);
        $suffix = '-'.$usedEntryNames[$entryName];

        if ($extension === '') {
            return $baseName.$suffix;
        }

        return $baseName.$suffix.'.'.$extension;
    }

    protected function getFilenamePrefix(): string
    {
        return (string) __('exit-clearance::filament/resources/request.actions.download_pdf_filename_prefix');
    }
}
