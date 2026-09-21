<?php

use Symfony\Component\Process\Process;

function assertRecruitmentCollectionScenario(string $collection, string $scenario): void
{
    $setup = <<<'JS'
import assert from 'node:assert/strict';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { useRekrutmenStore } from './plugins/cesa/rekrutmen/resources/js/stores/rekrutmen.js';

setActivePinia(createPinia());
const store = useRekrutmenStore();
const copy = value => JSON.parse(JSON.stringify(value));
const endpoint = `/rekrutmen/api/${collection}`;
const fetchCollection = (query, force = false) => collection === 'requests'
  ? store.fetchRequests(query, force)
  : store.fetchApplications(query, force);
const rows = Array.from({ length: 451 }, (_, index) => ({
  id: index + 1,
  full_name: `Candidate ${index + 1}`,
  nama_pengaju: `Requester ${index + 1}`,
  posisi_dibutuhkan: `Sales ${index + 1}`,
  job_posting_id: 7,
}));
const stages = [{ id: 1, name: 'Screening', rekrutmen_pipeline_id: 1 }];
const response = (records, page, metadata = {}) => ({ data: {
  [collection === 'requests' ? 'data' : 'applications']: copy(records.slice((page - 1) * 100, page * 100)),
  current_page: page,
  last_page: Math.max(1, Math.ceil(records.length / 100)),
  per_page: 100,
  total: records.length,
  stages: copy(stages),
  active_job: null,
  ...metadata,
} });
axios.get = async url => { throw new Error(`Unexpected GET ${url}`); };
axios.post = async url => { throw new Error(`Unexpected POST ${url}`); };
JS;

    $process = new Process([
        'node',
        '--input-type=module',
        '-e',
        'const collection = '.json_encode($collection, JSON_THROW_ON_ERROR).";\n".$setup."\n".$scenario."\nconsole.log('passed');",
    ], dirname(__DIR__, 2));
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('passed');
}

it('keeps recruitment collections complete across loading and query changes', function (string $collection, string $scenario): void {
    assertRecruitmentCollectionScenario($collection, $scenario);
})->with(['requests', 'applications'])->with([
    'null and blank searches load all 451 rows and share a complete cache' => <<<'JS'
const pages = [];
axios.get = async (url, { params }) => {
  assert.equal(url, endpoint);
  assert.equal(params.search, '');
  assert.equal(params.per_page, 100);
  assert.equal(store.loading[collection], true);
  assert.deepEqual(copy(store[collection]), []);
  if (collection === 'applications') assert.deepEqual(copy(store.stages), []);
  pages.push(params.page);
  return response(rows, params.page);
};

assert.deepEqual(copy(await fetchCollection(null)), rows);
assert.deepEqual(pages, [1, 2, 3, 4, 5]);
assert.equal(store[collection].find(row => row.full_name === 'Candidate 451').id, 451);
for (const query of [undefined, '', '   ', null]) {
  assert.deepEqual(copy(await fetchCollection(query)), rows);
}
assert.deepEqual(pages, [1, 2, 3, 4, 5]);
assert.equal(store.errors[collection], '');
assert.equal(store.loading[collection], false);
if (collection === 'applications') assert.deepEqual(copy(store.stages), stages);
JS,
    'an empty completed collection is cached' => <<<'JS'
let calls = 0;
axios.get = async (url, { params }) => {
  calls++;
  return response([], params.page);
};

assert.deepEqual(copy(await fetchCollection(null)), []);
assert.deepEqual(copy(await fetchCollection()), []);
assert.deepEqual(copy(await fetchCollection('   ')), []);
assert.equal(calls, 1);
assert.equal(store.loading[collection], false);
assert.equal(store.errors[collection], '');
JS,
    'filtered results are cleared on query change and never reused as the full collection' => <<<'JS'
const calls = [];
const matchingRows = rows.slice(0, 151);
axios.get = async (url, { params }) => {
  calls.push([params.search, params.page]);
  assert.deepEqual(copy(store[collection]), []);
  return response(params.search === 'Sales' ? matchingRows : rows, params.page);
};

assert.deepEqual(copy(await fetchCollection('  Sales  ')), matchingRows);
assert.deepEqual(copy(await fetchCollection('Sales')), matchingRows);
assert.deepEqual(copy(await fetchCollection()), rows);
assert.deepEqual(calls, [
  ['Sales', 1], ['Sales', 2], ['', 1], ['', 2], ['', 3], ['', 4], ['', 5],
]);
JS,
    'later page failure preserves complete same-query data and retries without forcing' => <<<'JS'
axios.get = async (url, { params }) => response(rows, params.page);
await fetchCollection();
const previousRows = copy(store[collection]);
const previousStages = copy(store.stages);
const replacementRows = rows.map(row => ({ ...row, full_name: `Refreshed ${row.id}` }));
const replacementStages = [{ id: 2, name: 'Interview', rekrutmen_pipeline_id: 2 }];
let shouldFail = true;
let pages = [];
axios.get = async (url, { params }) => {
  pages.push(params.page);
  if (shouldFail && params.page === 2) throw new Error('Page two is unavailable');
  return response(replacementRows, params.page, { stages: replacementStages });
};

await assert.rejects(fetchCollection(undefined, true), /Page two is unavailable/);
assert.deepEqual(copy(store[collection]), previousRows);
assert.deepEqual(copy(store.stages), previousStages);
assert.deepEqual(pages, [1, 2]);
assert.equal(typeof store.errors[collection], 'string');
assert.ok(store.errors[collection].length > 0);
assert.equal(store.loading[collection], false);

shouldFail = false;
pages = [];
assert.deepEqual(copy(await fetchCollection()), replacementRows);
assert.deepEqual(pages, [1, 2, 3, 4, 5]);
assert.equal(store.errors[collection], '');
if (collection === 'applications') assert.deepEqual(copy(store.stages), replacementStages);
JS,
    'simultaneous requests for an equivalent query share one pagination sequence' => <<<'JS'
let completeFirstPage;
const pages = [];
axios.get = async (url, { params }) => {
  pages.push(params.page);
  if (params.page === 1) return new Promise(resolve => { completeFirstPage = resolve; });
  return response(rows, params.page);
};

const firstLoad = fetchCollection(null);
const secondLoad = fetchCollection('   ');
assert.deepEqual(pages, [1]);
completeFirstPage(response(rows, 1));
const results = await Promise.all([firstLoad, secondLoad]);

assert.deepEqual(copy(results[0]), rows);
assert.deepEqual(copy(results[1]), rows);
assert.deepEqual(pages, [1, 2, 3, 4, 5]);
JS,
    'superseded requests stop paging and cannot overwrite the latest query' => <<<'JS'
let completeOldPage;
const calls = [];
const latestRows = [rows[450]];
const latestStages = [{ id: 2, name: 'Latest Stage', rekrutmen_pipeline_id: 2 }];
axios.get = async (url, { params }) => {
  calls.push([params.search, params.page]);
  if (params.search === 'Old') return new Promise(resolve => { completeOldPage = resolve; });
  assert.equal(params.search, 'New');
  return response(latestRows, params.page, { stages: latestStages });
};

const oldLoad = fetchCollection('Old');
await fetchCollection('New');
completeOldPage(response(rows, 1));
await oldLoad;

assert.deepEqual(calls, [['Old', 1], ['New', 1]]);
assert.deepEqual(copy(store[collection]), latestRows);
assert.equal(store.errors[collection], '');
assert.equal(store.loading[collection], false);
if (collection === 'applications') assert.deepEqual(copy(store.stages), latestStages);
JS,
    'failed changes of query never leave the old context displayed' => <<<'JS'
axios.get = async (url, { params }) => response(rows, params.page, {
  active_job: collection === 'applications' ? { id: 7, title: 'Old Job' } : null,
});
await fetchCollection();
axios.get = async (url, { params }) => {
  assert.deepEqual(copy(store[collection]), []);
  if (collection === 'applications') {
    assert.deepEqual(copy(store.stages), []);
    assert.equal(store.activeJob, null);
  }
  throw new Error('Changed query is unavailable');
};

await assert.rejects(fetchCollection('New query'), /Changed query is unavailable/);
assert.deepEqual(copy(store[collection]), []);
assert.ok(store.errors[collection].length > 0);
assert.equal(store.loading[collection], false);
JS,
]);

it('keeps application job and pipeline query contexts separate', function (): void {
    assertRecruitmentCollectionScenario('applications', <<<'JS'
const calls = [];
axios.get = async (url, { params }) => {
  calls.push(copy(params));
  const records = String(params.job_id) === '7' ? [rows[0]] : (String(params.pipeline_id) === '2' ? [rows[1]] : rows);
  return response(records, params.page, {
    active_job: params.job_id ? { id: Number(params.job_id), title: 'Sales' } : null,
  });
};

await store.fetchApplications({ search: null, job_id: 7, pipeline_id: 1 });
assert.deepEqual(copy(store.applications), [rows[0]]);
assert.equal(store.activeJob.id, 7);
await store.fetchApplications(null);
assert.deepEqual(copy(store.applications), rows);
assert.equal(store.activeJob, null);
await store.fetchApplications({ pipeline_id: 2 });
assert.deepEqual(copy(store.applications), [rows[1]]);
assert.equal(calls.length, 7);
assert.equal(String(calls[0].job_id), '7');
assert.equal(String(calls[0].pipeline_id), '1');
assert.ok(calls.slice(1, 6).every(params => !params.job_id && !params.pipeline_id));
assert.equal(String(calls[6].pipeline_id), '2');
JS);
});

it('keeps the application context when refreshing after cv synchronization', function (): void {
    assertRecruitmentCollectionScenario('applications', <<<'JS'
const calls = [];
axios.get = async (url, { params }) => {
  calls.push(copy(params));
  return response([rows[0]], params.page, { active_job: { id: 7, title: 'Sales' } });
};
axios.post = async url => {
  assert.equal(url, '/rekrutmen/api/applications/sync-cvs');
  return { data: { success: true, synced: 1 } };
};

await store.fetchApplications({ search: 'Candidate', job_id: 7, pipeline_id: 1 });
assert.deepEqual(await store.syncCandidateCvs(), { success: true, synced: 1 });
assert.equal(calls.length, 2);
assert.deepEqual(calls[1], calls[0]);
assert.equal(store.activeJob.id, 7);
JS);
});

it('preserves successful manpower writes when refreshing fails', function (string $action, string $suffix, array $arguments): void {
    $scenario = 'const action = '.json_encode($action, JSON_THROW_ON_ERROR).";\n"
        .'const suffix = '.json_encode($suffix, JSON_THROW_ON_ERROR).";\n"
        .'const args = '.json_encode($arguments, JSON_THROW_ON_ERROR).";\n";

    assertRecruitmentCollectionScenario('requests', $scenario.<<<'JS'
axios.get = async (url, { params }) => response(rows, params.page);
await store.fetchRequests();
let writeCalls = 0;
axios.post = async (url, payload) => {
  assert.equal(url, `/rekrutmen/api/requests/1/${suffix}`);
  if (suffix === 'hold') assert.deepEqual(payload, { reason: 'Waiting for budget' });
  writeCalls++;
  return { data: { success: true, message: 'Saved' } };
};
let shouldFail = true;
let pages = [];
axios.get = async (url, { params }) => {
  pages.push(params.page);
  if (shouldFail && params.page === 2) throw new Error('Refresh page two is unavailable');
  return response(rows, params.page);
};

const result = await store[action](...args);
assert.equal(result.success, true);
assert.equal(result.message, 'Saved');
assert.equal(typeof result.refresh_warning, 'string');
assert.ok(result.refresh_warning.length > 0);
assert.deepEqual(pages, [1, 2]);
assert.deepEqual(copy(store.requests), rows);
assert.ok(store.errors.requests.length > 0);

shouldFail = false;
pages = [];
await store.fetchRequests();
assert.deepEqual(pages, [1, 2, 3, 4, 5]);
assert.equal(store.errors.requests, '');
assert.equal(writeCalls, 1);
JS);
})->with([
    ['approveRequest', 'approve', [1]],
    ['rejectRequest', 'reject', [1]],
    ['holdRequest', 'hold', [1, 'Waiting for budget']],
]);

it('still rejects failed manpower writes without refreshing a collection', function (): void {
    assertRecruitmentCollectionScenario('requests', <<<'JS'
axios.get = async (url, { params }) => response(rows, params.page);
await store.fetchRequests();
let refreshCalls = 0;
axios.get = async () => { refreshCalls++; throw new Error('Unexpected refresh'); };
axios.post = async () => { throw new Error('Approval was rejected'); };

await assert.rejects(store.approveRequest(1), /Approval was rejected/);
assert.equal(refreshCalls, 0);
assert.deepEqual(copy(store.requests), rows);
JS);
});
