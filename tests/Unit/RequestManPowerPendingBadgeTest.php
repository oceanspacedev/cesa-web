<?php

use Symfony\Component\Process\Process;

it('does not match the indonesian pending label with pend or tunggu substrings', function (): void {
    $translations = require dirname(__DIR__, 2).'/plugins/cesa/rekrutmen/resources/lang/id/enums/request-man-power-status.php';
    $label = mb_strtolower((string) $translations['pending']);

    expect($label)->toBe('menunggu')
        ->and(str_contains($label, 'pend'))->toBeFalse()
        ->and(str_contains($label, 'tunggu'))->toBeFalse();
});

it('counts indonesian menunggu rows as pending for the fptk badge', function (): void {
    $process = new Process(
        [
            'node',
            '--input-type=module',
            '-e',
            <<<'JS'
import { isApprovedManPowerRequest, isPendingManPowerRequest } from './plugins/cesa/rekrutmen/resources/js/lib/requestManPowerStatus.js';

const rows = [
  { status: 'Menunggu', approval_status: 'Menunggu', raw_status: 'pending' },
  { status: 'Menunggu', approval_status: 'Menunggu', raw_status: 'pending' },
  { status: 'Disetujui', approval_status: 'Disetujui', raw_status: 'approved' },
  { status: 'Menunggu', approval_status: 'Menunggu' },
];

const pending = rows.filter(isPendingManPowerRequest).length;
const approved = rows.filter(isApprovedManPowerRequest).length;

console.log(JSON.stringify({ pending, approved }));
if (pending !== 3 || approved !== 1) {
  process.exit(1);
}
JS
        ],
        dirname(__DIR__, 2),
    );

    $process->mustRun();

    expect(json_decode($process->getOutput(), true))->toBe([
        'pending'  => 3,
        'approved' => 1,
    ]);
});

it('uses status helpers for the menunggu badge in the request man power view', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/plugins/cesa/rekrutmen/resources/js/views/RequestManPowerView.vue');

    expect($source)
        ->toContain("from '../lib/requestManPowerStatus'")
        ->toContain('isPendingManPowerRequest')
        ->toContain('isApprovedManPowerRequest')
        ->not->toContain("s.includes('tunggu')");
});

it('renders complete hr fields in the fptk detail drawer', function (): void {
    $source = file_get_contents(dirname(__DIR__, 2).'/plugins/cesa/rekrutmen/resources/js/views/RequestManPowerView.vue');

    expect($source)
        ->toContain('Status Kebutuhan')
        ->toContain('status_kebutuhan')
        ->toContain('raw_status_kebutuhan')
        ->toContain('Nama Karyawan yang Akan Digantikan')
        ->toContain('nama_karyawan_replacement')
        ->toContain('Level Pekerjaan')
        ->toContain('level_pekerjaan')
        ->toContain('Estimasi Tanggal Join')
        ->toContain('estimasi_tanggal_join')
        ->toContain('Email Pengaju')
        ->toContain('email_address')
        ->toContain('Kualifikasi yang Dibutuhkan')
        ->toContain('Deskripsi Pekerjaan')
        ->toContain('job_description')
        ->toContain('Keterangan Tambahan');
});
