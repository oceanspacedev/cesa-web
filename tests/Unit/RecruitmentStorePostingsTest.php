<?php

use Symfony\Component\Process\Process;

it('loads the complete job posting collection', function (string $scenario): void {
    $setup = <<<'JS'
import assert from 'node:assert/strict';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { useRekrutmenStore } from './plugins/cesa/rekrutmen/resources/js/stores/rekrutmen.js';

setActivePinia(createPinia());
const store = useRekrutmenStore();
const copy = value => JSON.parse(JSON.stringify(value));
const postings = Array.from({ length: 451 }, (_, index) => ({
  id: index + 1,
  title: `Sales ${index + 1}`,
  is_published: index % 2 === 0,
}));
const companies = [{ id: 1, name: 'Complete Selular' }];
const pipelines = [{ id: 1, name: 'Sales Recruitment' }];
const pageResponse = (records, page, metadata = {}) => ({ data: {
  data: copy(records.slice((page - 1) * 100, page * 100)),
  current_page: page,
  last_page: Math.max(1, Math.ceil(records.length / 100)),
  per_page: 100,
  total: records.length,
  companies: copy(companies),
  pipelines: copy(pipelines),
  ...metadata,
} });
axios.get = async url => { throw new Error(`Unexpected GET ${url}`); };
const assertSuccessfulMutationRefresh = async (action, method, args, expectedUrl) => {
  axios.get = async (url, { params }) => pageResponse(postings, params.page);
  await store.fetchPostings();
  const mutationResult = { success: true, message: 'Changes saved', data: { id: 1 } };
  let mutationCalls = 0;
  let stateBeforeRefresh;
  axios[method] = async url => {
    assert.equal(url, expectedUrl);
    mutationCalls++;
    stateBeforeRefresh = copy(store.postings);
    return { data: copy(mutationResult) };
  };
  let requestedPages = [];
  let shouldFail = true;
  const refreshedPostings = postings.map(posting => ({ ...posting, title: `Refreshed ${posting.id}` }));
  axios.get = async (url, { params }) => {
    requestedPages.push(params.page);
    if (shouldFail && params.page === 2) throw new Error('Mutation refresh page two is unavailable');
    return pageResponse(refreshedPostings, params.page);
  };

  const result = await store[action](...args);

  assert.equal(mutationCalls, 1);
  assert.equal(result.success, true);
  assert.equal(result.message, mutationResult.message);
  assert.deepEqual(result.data, mutationResult.data);
  assert.equal(typeof result.refresh_warning, 'string');
  assert.ok(result.refresh_warning.length > 0);
  assert.deepEqual(requestedPages, [1, 2]);
  assert.deepEqual(copy(store.postings), stateBeforeRefresh);
  assert.equal(store.postingsSearch, null);
  assert.equal(store.loading.postings, false);

  shouldFail = false;
  requestedPages = [];
  await store.fetchPostings();

  assert.deepEqual(requestedPages, [1, 2, 3, 4, 5]);
  assert.deepEqual(copy(store.postings), refreshedPostings);
  assert.equal(mutationCalls, 1);
};
JS;

    $process = new Process(
        ['node', '--input-type=module', '-e', $setup."\n".$scenario."\nconsole.log('passed');"],
        dirname(__DIR__, 2),
    );
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('passed');
})->with([
    'null undefined and blank searches share the complete unfiltered cache' => <<<'JS'
const requestedPages = [];
axios.get = async (url, { params }) => {
  assert.equal(url, '/rekrutmen/api/job-postings');
  assert.equal(params.search, '');
  requestedPages.push(params.page);
  return pageResponse(postings, params.page);
};

assert.deepEqual(copy(await store.fetchPostings(null)), postings);
assert.deepEqual(requestedPages, [1, 2, 3, 4, 5]);

for (const search of [undefined, '', '   ', '\t\n ', null]) {
  assert.deepEqual(copy(await store.fetchPostings(search)), postings);
  assert.deepEqual(requestedPages, [1, 2, 3, 4, 5]);
}

assert.equal(store.postingsSearch, '');
assert.equal(store.postings.at(-1).id, 451);
assert.equal(store.loading.postings, false);
JS,
    'padded search text is trimmed while its original letter case is preserved' => <<<'JS'
let calls = 0;
const matchingPostings = [postings[450]];
axios.get = async (url, { params }) => {
  calls++;
  assert.equal(params.search, 'SaLeS 451');
  return pageResponse(matchingPostings, params.page);
};

assert.deepEqual(copy(await store.fetchPostings('  SaLeS 451 \t')), matchingPostings);
assert.deepEqual(copy(await store.fetchPostings('SaLeS 451')), matchingPostings);
assert.equal(calls, 1);
assert.equal(store.postingsSearch, 'SaLeS 451');
JS,
    'all 451 postings are loaded and search is sent on every page' => <<<'JS'
const requestedPages = [];
store.pipelines = [{ ...pipelines[0], description: 'Existing pipeline details', stages: [{ id: 10 }] }];
axios.get = async (url, { params }) => {
  assert.equal(url, '/rekrutmen/api/job-postings');
  assert.equal(params.search, 'Sales');
  assert.equal(params.per_page, 100);
  assert.equal(store.loading.postings, true);
  assert.deepEqual(copy(store.postings), []);
  requestedPages.push(params.page);
  return pageResponse(postings, params.page);
};

const result = await store.fetchPostings('Sales');

assert.deepEqual(requestedPages, [1, 2, 3, 4, 5]);
assert.deepEqual(copy(result), postings);
assert.deepEqual(copy(store.postings), postings);
assert.equal(new Set(store.postings.map(posting => posting.id)).size, 451);
assert.deepEqual(copy(store.companies), companies);
assert.equal(store.pipelines[0].description, 'Existing pipeline details');
assert.deepEqual(copy(store.pipelines[0].stages), [{ id: 10 }]);
assert.equal(store.loading.postings, false);
JS,
    'complete collections are cached and a forced refresh reads all pages again' => <<<'JS'
let records = postings;
let calls = 0;
axios.get = async (url, { params }) => {
  calls++;
  assert.equal(params.search, '');
  return pageResponse(records, params.page);
};

await store.fetchPostings();
assert.equal(calls, 5);
assert.deepEqual(copy(await store.fetchPostings()), postings);
assert.equal(calls, 5);

records = [...postings, ...Array.from({ length: 50 }, (_, index) => ({ id: 452 + index }))];
await store.fetchPostings('', true);

assert.equal(calls, 11);
assert.deepEqual(copy(store.postings), records);
assert.equal(store.loading.postings, false);
JS,
    'a filtered collection is never reused as the full job posting list' => <<<'JS'
const calls = [];
const filteredPostings = postings.slice(0, 151);
axios.get = async (url, { params }) => {
  calls.push({ ...params });
  return pageResponse(params.search === 'Sales' ? filteredPostings : postings, params.page);
};

await store.fetchPostings('Sales');
assert.deepEqual(copy(store.postings), filteredPostings);
await store.fetchPostings();

assert.deepEqual(copy(store.postings), postings);
assert.deepEqual(calls.map(call => [call.search, call.page]), [
  ['Sales', 1], ['Sales', 2],
  ['', 1], ['', 2], ['', 3], ['', 4], ['', 5],
]);
JS,
    'a later page failure preserves complete data and allows a full retry' => <<<'JS'
axios.get = async (url, { params }) => pageResponse(postings, params.page);
await store.fetchPostings();
const previousState = copy({
  postings: store.postings,
  companies: store.companies,
  pipelines: store.pipelines,
});
const replacementPostings = postings.map(posting => ({ ...posting, title: `Updated ${posting.id}` }));
const replacementCompanies = [{ id: 2, name: 'MSI' }];
let shouldFail = true;
let requestedPages = [];
axios.get = async (url, { params }) => {
  requestedPages.push(params.page);
  if (shouldFail && params.page === 2) throw new Error('Page two is unavailable');
  return pageResponse(replacementPostings, params.page, { companies: replacementCompanies });
};

await assert.rejects(store.fetchPostings('', true), /Page two is unavailable/);

assert.deepEqual(requestedPages, [1, 2]);
assert.deepEqual(copy({
  postings: store.postings,
  companies: store.companies,
  pipelines: store.pipelines,
}), previousState);
assert.equal(store.loading.postings, false);

shouldFail = false;
requestedPages = [];
await store.fetchPostings('', true);

assert.deepEqual(requestedPages, [1, 2, 3, 4, 5]);
assert.deepEqual(copy(store.postings), replacementPostings);
assert.deepEqual(copy(store.companies), replacementCompanies);
assert.equal(store.loading.postings, false);
JS,
    'a failed initial load can retry without treating its first page as cached data' => <<<'JS'
let shouldFail = true;
let requestedPages = [];
axios.get = async (url, { params }) => {
  requestedPages.push(params.page);
  if (shouldFail && params.page === 2) throw new Error('Initial page two is unavailable');
  return pageResponse(postings, params.page);
};

await assert.rejects(store.fetchPostings(), /Initial page two is unavailable/);
assert.deepEqual(copy(store.postings), []);
assert.equal(store.loading.postings, false);

shouldFail = false;
requestedPages = [];
await store.fetchPostings();

assert.deepEqual(requestedPages, [1, 2, 3, 4, 5]);
assert.deepEqual(copy(store.postings), postings);
JS,
    'simultaneous preload and page requests share the same pagination sequence' => <<<'JS'
let completeFirstPage;
const requestedPages = [];
axios.get = async (url, { params }) => {
  requestedPages.push(params.page);
  if (params.page === 1) {
    return new Promise(resolve => { completeFirstPage = resolve; });
  }
  return pageResponse(postings, params.page);
};

const preload = store.fetchPostings();
const pageLoad = store.fetchPostings();

assert.deepEqual(requestedPages, [1]);
assert.equal(store.loading.postings, true);
completeFirstPage(pageResponse(postings, 1));
const results = await Promise.all([preload, pageLoad]);

assert.deepEqual(requestedPages, [1, 2, 3, 4, 5]);
assert.deepEqual(copy(results[0]), postings);
assert.deepEqual(copy(results[1]), postings);
assert.deepEqual(copy(store.postings), postings);
assert.equal(store.loading.postings, false);
JS,
    'an older request completing after a forced refresh cannot overwrite refreshed data' => <<<'JS'
let completeOldRequest;
let calls = 0;
const refreshedPostings = postings.map(posting => ({ ...posting, title: `Refreshed ${posting.id}` }));
const refreshedCompanies = [{ id: 2, name: 'MSI' }];
const refreshedPipelines = [{ id: 2, name: 'Refreshed Pipeline' }];
axios.get = async (url, { params }) => {
  calls++;
  if (calls === 1) return new Promise(resolve => { completeOldRequest = resolve; });
  return pageResponse(refreshedPostings, params.page, {
    companies: refreshedCompanies,
    pipelines: refreshedPipelines,
  });
};

const oldRequest = store.fetchPostings();
await store.fetchPostings('', true);

assert.equal(calls, 6);
assert.deepEqual(copy(store.postings), refreshedPostings);
completeOldRequest(pageResponse([{ id: 9999, title: 'Stale posting' }], 1));
await oldRequest;

assert.deepEqual(copy(store.postings), refreshedPostings);
assert.deepEqual(copy(store.companies), refreshedCompanies);
assert.deepEqual(copy(store.pipelines), refreshedPipelines);
assert.equal(store.loading.postings, false);
await store.fetchPostings();
assert.equal(calls, 6);
JS,
    'successful creation survives a later refresh failure and can retry loading' => <<<'JS'
await assertSuccessfulMutationRefresh(
  'createJobPosting', 'post', [{ title: 'Sales' }], '/rekrutmen/api/job-postings',
);
JS,
    'successful updates survive a later refresh failure and can retry loading' => <<<'JS'
await assertSuccessfulMutationRefresh(
  'updateJobPosting', 'put', [1, { title: 'Updated Sales' }], '/rekrutmen/api/job-postings/1',
);
JS,
    'successful deletion survives a later refresh failure and can retry loading' => <<<'JS'
await assertSuccessfulMutationRefresh(
  'deleteJobPosting', 'delete', [1], '/rekrutmen/api/job-postings/1',
);
JS,
    'successful publishing survives a later refresh failure and can retry loading' => <<<'JS'
await assertSuccessfulMutationRefresh(
  'togglePublishPosting', 'patch', [1], '/rekrutmen/api/job-postings/1/publish',
);
JS,
    'an actual creation failure still rejects and never refreshes postings' => <<<'JS'
axios.get = async (url, { params }) => pageResponse(postings, params.page);
await store.fetchPostings();
axios.get = async () => { throw new Error('Refresh must not run after a failed creation'); };
let mutationCalls = 0;
axios.post = async (url, payload) => {
  assert.equal(url, '/rekrutmen/api/job-postings');
  assert.deepEqual(payload, { title: 'New posting' });
  mutationCalls++;
  throw new Error('The job posting was rejected');
};

await assert.rejects(store.createJobPosting({ title: 'New posting' }), /The job posting was rejected/);

assert.equal(mutationCalls, 1);
assert.deepEqual(copy(store.postings), postings);
assert.deepEqual(copy(await store.fetchPostings()), postings);
assert.equal(store.loading.postings, false);
JS,
]);
