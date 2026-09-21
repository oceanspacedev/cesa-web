<?php

use Symfony\Component\Process\Process;

it('searches complete recruitment collections without changing existing filters', function (string $scenario): void {
    $setup = <<<'JS'
import assert from 'node:assert/strict';
import { filterManPowerRequests, filterJobApplications } from './plugins/cesa/rekrutmen/resources/js/lib/collectionFilters.js';
const ids = rows => rows.map(row => row.id);
JS;

    $process = new Process(
        ['node', '--input-type=module', '-e', $setup."\n".$scenario."\nconsole.log('passed');"],
        dirname(__DIR__, 2),
    );
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('passed');
})->with([
    'empty collections rows and queries are safe' => <<<'JS'
for (const filter of [filterManPowerRequests, filterJobApplications]) {
  assert.deepEqual(filter(null), []);
  assert.deepEqual(filter(undefined), []);
  const rows = [{ id: 1 }, { id: 2, full_name: null, posisi_dibutuhkan: undefined }];
  for (const search of ['', null, undefined, '  ', '\n\t']) {
    assert.deepEqual(filter([null, ...rows, undefined], { search }), rows);
  }
  assert.deepEqual(filter(rows, { search: 'null' }), []);
  assert.deepEqual(filter(rows, { search: 'undefined' }), []);
}
JS,
    'manpower search includes request numbers aliases and nested divisions' => <<<'JS'
const rows = [{
  id: 12, request_number: 'FPTK/2026/123', posisi_dibutuhkan: 'Sales', position_name: 'Promotor', position_title: 'Konsultan',
  division_name: 'Retail', department: 'Operasional', division: { name: 'Distribusi' },
  business_entity_name: 'SMI', company_name: 'MSI', lokasi_penempatan: 'Bandung', branch: 'Bogor', location: 'Cirebon', nama_pengaju: 'Andi',
}, { id: 13, posisi_dibutuhkan: 98765 }];
for (const search of ['12', 'fptk/2026/123', 'sales', 'promotor', 'konsultan', 'retail', 'operasional', 'distribusi', 'smi', 'msi', 'bandung', 'bogor', 'cirebon', 'andi']) {
  assert.deepEqual(ids(filterManPowerRequests(rows, { search: `  ${search.toUpperCase()}  ` })), [12], search);
}
assert.deepEqual(ids(filterManPowerRequests(rows, { search: 98765 })), [13]);
JS,
    'manpower search combines with pending and approved statuses' => <<<'JS'
const rows = [
  { id: 1, posisi_dibutuhkan: 'Sales', raw_status: 'pending', status: 'Menunggu' },
  { id: 2, posisi_dibutuhkan: 'Sales', raw_status: 'approved', status: 'Disetujui' },
  { id: 3, posisi_dibutuhkan: 'Kurir', status: 'Menunggu' },
  { id: 4, posisi_dibutuhkan: 'Sales', raw_status: 'rejected' },
];
assert.deepEqual(ids(filterManPowerRequests(rows, { search: ' SALES ', status: 'pending' })), [1]);
assert.deepEqual(ids(filterManPowerRequests(rows, { search: ' SALES ', status: 'approved' })), [2]);
assert.deepEqual(ids(filterManPowerRequests(rows, { status: 'pending' })), [1, 3]);
JS,
    'applicant search safely covers contact and related job fields' => <<<'JS'
const rows = [
  { id: 1, full_name: 'Andi Saputra', email: 'andi@example.test', phone: 8123456, whatsapp_number: 628123456, job_posting: { title: 'Sales Consultant', company_name: 'SMI' } },
  { id: 2, full_name: null, email: undefined, job_posting: null },
];
for (const search of ['andi saputra', 'andi@example.test', '8123456', '628123456', 'sales consultant', 'smi']) {
  assert.deepEqual(ids(filterJobApplications(rows, { search: `  ${search.toUpperCase()}  ` })), [1], search);
}
assert.deepEqual(filterJobApplications(rows, { search: 'a name not in the collection' }), []);
JS,
    'applicant search combines with job stage rejection and score filters' => <<<'JS'
const rows = [
  { id: 1, full_name: 'Andi', job_posting_id: 10, current_stage_id: 2, ai_screening_status: 'completed', ai_match_score: 75 },
  { id: 2, full_name: 'Andi', job_posting: { id: '10' }, stage: { id: '2' }, ai_screening_status: 'completed', ai_match_score: 50 },
  { id: 3, full_name: 'Andi', job_posting_id: 10, current_stage_id: 2, ai_screening_status: 'completed', ai_match_score: 49 },
  { id: 4, full_name: 'Andi', job_posting_id: 10, current_stage_id: 2, ai_screening_status: 'processing', ai_match_score: 99 },
  { id: 5, full_name: 'Andi', job_posting_id: 10, current_stage_id: 2, ai_screening_status: 'completed', ai_match_score: 90, status: 'rejected' },
  { id: 6, full_name: 'Andi', job_posting_id: 20, current_stage_id: 2, ai_screening_status: 'completed', ai_match_score: 85 },
  { id: 7, full_name: 'Budi', job_posting_id: 10, current_stage_id: 2, ai_screening_status: 'completed', ai_match_score: 90 },
];
const filters = { search: ' ANDI ', jobId: '10', stage: '2' };
assert.deepEqual(ids(filterJobApplications(rows, { ...filters, match: 'recommended' })), [1]);
assert.deepEqual(ids(filterJobApplications(rows, { ...filters, match: 'considered' })), [2]);
assert.deepEqual(ids(filterJobApplications(rows, { ...filters, match: 'not_suitable' })), [3]);
assert.deepEqual(ids(filterJobApplications(rows, { ...filters, stage: 'rejected', match: 'recommended' })), [5]);
assert.deepEqual(ids(filterJobApplications(rows, { ...filters, jobId: 20, match: 'recommended' })), [6]);
assert.deepEqual(ids(filterJobApplications([{ id: 8 }], { stage: '1' })), [8]);
JS,
    'search reaches records beyond the old first-page limits' => <<<'JS'
const requests = Array.from({ length: 151 }, (_, index) => ({ id: index + 1, posisi_dibutuhkan: `Posisi ${index + 1}` }));
const applications = Array.from({ length: 351 }, (_, index) => ({ id: index + 1, full_name: `Kandidat ${index + 1}` }));
assert.deepEqual(ids(filterManPowerRequests(requests, { search: 'posisi 151' })), [151]);
assert.deepEqual(ids(filterJobApplications(applications, { search: 'kandidat 351' })), [351]);
assert.deepEqual(filterManPowerRequests(requests, { search: 'not present' }), []);
assert.deepEqual(filterJobApplications(applications, { search: 'not present' }), []);
JS,
]);
