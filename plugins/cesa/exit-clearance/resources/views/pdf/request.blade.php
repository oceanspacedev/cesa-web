<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Exit Clearance {{ $record->form_uid ?? $record->id }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #111;
            line-height: 1.4;
            margin: 24px;
        }

        .header {
            width: 100%;
            border-bottom: 2px solid #111;
            padding-bottom: 10px;
            margin-bottom: 16px;
        }

        .left,
        .right {
            display: inline-block;
            vertical-align: top;
        }

        .left {
            width: 62%;
        }

        .right {
            width: 37%;
            text-align: right;
        }

        .title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
            letter-spacing: 0.4px;
        }

        .subtitle {
            margin: 4px 0 0;
            color: #555;
        }

        .doc-number {
            margin-top: 2px;
            font-size: 12px;
            font-weight: 700;
        }

        .section-title {
            margin: 14px 0 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .box td {
            border: 1px solid #d1d5db;
            padding: 7px 8px;
            vertical-align: top;
        }

        .box td.label {
            width: 34%;
            background: #f5f5f5;
            font-weight: 700;
        }

        .items th,
        .items td {
            border: 1px solid #d1d5db;
            padding: 8px;
            vertical-align: top;
        }

        .items th {
            background: #f5f5f5;
            text-align: left;
            font-weight: 700;
        }

        .signatures {
            margin-top: 18px;
        }

        .signature-box {
            width: 48%;
            display: inline-block;
            vertical-align: top;
        }

        .signature-line {
            margin-top: 42px;
            border-top: 1px solid #555;
            width: 80%;
        }

        .footnote {
            margin-top: 18px;
            font-size: 10px;
            color: #666;
        }
    </style>
</head>
<body>
    @php
        $docNo = $record->form_uid ?? (string) $record->id;
    @endphp

    <div class="header">
        <div class="left">
            <p class="title">EXIT CLEARANCE</p>
            <p class="subtitle">Dokumen Pengajuan Keluar (Exit Clearance)</p>
            <p class="doc-number">No: {{ $docNo }}</p>
        </div>
        <div class="right">
            <div><strong>Tanggal Pengajuan</strong></div>
            <div>{{ $record->request_date?->format('d M Y') ?? '-' }}</div>
            <div style="margin-top: 6px;"><strong>Status</strong></div>
            <div>{{ \Illuminate\Support\Str::headline($record->form_status) ?? '-' }}</div>
        </div>
    </div>

    <div class="section-title">Informasi Karyawan</div>
    <table class="box">
        <tr>
            <td class="label">Nama</td>
            <td>{{ $record->name ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Email</td>
            <td>{{ $record->email ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">No. Telepon</td>
            <td>{{ $record->phone ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Posisi / Jabatan</td>
            <td>{{ $record->position ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">{{ __('exit-clearance::filament/resources/request.fields.company') }}</td>
            <td>{{ $record->company?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="label">Penempatan / Cabang</td>
            <td>{{ $record->placement ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Bergabung</td>
            <td>{{ $record->join_date?->format('d M Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Efektif Keluar</td>
            <td>{{ $record->departure_date?->format('d M Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Alasan Keluar</td>
            <td>{{ $record->reason ?: '-' }}</td>
        </tr>
    </table>

    <div class="section-title">Feedback Wawancara Keluar</div>
    <table class="box">
        <tr>
            <td class="label">Beban Kerja</td>
            <td>{{ $record->workload_feedback ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Pengembangan Karir</td>
            <td>{{ $record->career_growth_feedback ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Fasilitas & Kesejahteraan</td>
            <td>{{ $record->facility_welfare_feedback ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Hubungan Kerja</td>
            <td>{{ $record->work_relationship_feedback ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Kompensasi</td>
            <td>{{ $record->compensation_feedback ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Divisi / Departemen</td>
            <td>{{ $record->division_feedback ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Perusahaan</td>
            <td>{{ $record->company_feedback ?: '-' }}</td>
        </tr>
    </table>

    <div class="section-title">Status Clearance (Checklist)</div>
    <table class="box">
        <tr>
            <td class="label">Kartu Halo / Provider</td>
            <td>{{ $record->clearance_kartu_halo ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Hutang Karyawan</td>
            <td>{{ $record->clearance_employee_debt ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Pengembalian Seragam dan Nametag</td>
            <td>{{ $record->clearance_uniform_return ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Pengembalian Kendaraan</td>
            <td>{{ $record->clearance_vehicle_return ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Pengembalian Inventaris</td>
            <td>{{ $record->clearance_inventory_return ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Penonaktifan Akun</td>
            <td>{{ $record->clearance_account_deactivation ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Data Piutang (AR)</td>
            <td>{{ $record->clearance_receivable_data ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Promotor Internal</td>
            <td>{{ $record->clearance_promotor_internal ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Nota Pending</td>
            <td>{{ $record->clearance_nota_pending ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Stock Opname</td>
            <td>{{ $record->clearance_stock_opname ?: '-' }}</td>
        </tr>
    </table>

    @if ($record->approvers && $record->approvers->count() > 0)
        <div class="section-title">Riwayat Approval</div>
        <table class="items">
            <tr>
                <th style="width: 6%;">#</th>
                <th style="width: 24%;">Approver</th>
                <th style="width: 26%;">Jabatan / Title</th>
                <th style="width: 20%;">Status</th>
                <th>Keterangan</th>
            </tr>
            @foreach ($record->approvers as $index => $approver)
                @php
                    $pivot = $approver->pivot;
                    $statusLabel = \Illuminate\Support\Str::headline($pivot->status);
                    $approverTitle = $approver->title ?: '-';
                @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $approver->name ?: '-' }}</td>
                    <td>{{ $approverTitle }}</td>
                    <td>{{ $statusLabel ?: '-' }}</td>
                    <td>
                        {{ $pivot->notes ?: '-' }}
                        @if ($pivot->approved_at)
                            <br><small>({{ \Carbon\Carbon::parse($pivot->approved_at)->format('d M Y H:i') }})</small>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="signatures">
        <div class="signature-box">
            Disiapkan oleh,
            <div class="signature-line"></div>
            {{ $record->name ?: '-' }}
        </div>
        <div class="signature-box" style="text-align: right;">
            Mengetahui (HR),
            <div class="signature-line" style="margin-left: auto;"></div>
        </div>
    </div>

    <div class="footnote">Dokumen ini dibuat otomatis dari sistem CESA.</div>
</body>
</html>
