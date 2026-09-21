<?php

use Symfony\Component\Process\Process;

it('filters recruitment job postings safely', function (string $scenario): void {
    $setup = <<<'JS'
import assert from 'node:assert/strict';
import { filterJobPostings } from './plugins/cesa/rekrutmen/resources/js/lib/jobPostings.js';

const ids = postings => postings.map(posting => posting.id);
const postings = [
  { id: 1, title: 'Sales Consultant', company_name: 'SMI', company_id: 10, location: 'Bandung', description: 'Retail sales', requirements: 'Customer service', is_published: true },
  { id: 2, title: 'Sales Supervisor', company_name: 'MSI', company_id: '20', location: 'Jakarta', description: 'Team leadership', requirements: 'Coaching experience', is_published: false },
  { id: 3, title: 'Sales Manager', company_name: 'SMI', company_id: '10', location: 'Surabaya', description: 'Area operations', requirements: 'Management experience', is_published: false },
  { id: 4, title: 'Courier', company_name: 'SMI', company_id: 10, location: 'Bandung', description: 'Delivery', requirements: 'Driving license', is_published: true },
];
JS;

    $process = new Process(
        ['node', '--input-type=module', '-e', $setup."\n".$scenario."\nconsole.log('passed');"],
        dirname(__DIR__, 2),
    );
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('passed');
})->with([
    'search includes every supported text field' => <<<'JS'
for (const [search, expected] of [
  ['Consultant', [1]],
  ['MSI', [2]],
  ['Surabaya', [3]],
  ['Delivery', [4]],
  ['Coaching experience', [2]],
]) {
  assert.deepEqual(ids(filterJobPostings(postings, { search })), expected, search);
}
JS,
    'padded mixed case terms match' => <<<'JS'
assert.deepEqual(ids(filterJobPostings(postings, { search: '  sAlEs cOnSuLtAnT  ' })), [1]);
assert.deepEqual(ids(filterJobPostings(postings, { search: '\tJAKARTA\n' })), [2]);
JS,
    'empty and nullish queries preserve all available postings' => <<<'JS'
assert.deepEqual(filterJobPostings(postings), postings);
for (const search of [undefined, null, '', '   ', '\t\n']) {
  assert.deepEqual(filterJobPostings(postings, { search }), postings);
}
JS,
    'missing collections and null rows are safe' => <<<'JS'
assert.deepEqual(filterJobPostings(undefined, { search: 'sales' }), []);
assert.deepEqual(filterJobPostings(null), []);
assert.deepEqual(filterJobPostings([]), []);
assert.deepEqual(filterJobPostings([null, undefined, postings[0]]), [postings[0]]);
assert.deepEqual(filterJobPostings([null, undefined, postings[0]], { search: 'consultant' }), [postings[0]]);
JS,
    'optional and numeric fields never crash or become nullish search text' => <<<'JS'
const sparsePostings = [
  { id: 1, title: null, company_name: undefined, location: null, description: undefined, requirements: null },
  { id: 2, title: 12345, company_name: 24680, location: 35791, description: 46802, requirements: 57913 },
  { id: 3 },
];

for (const search of ['12345', '24680', '35791', '46802', '57913', 12345]) {
  assert.deepEqual(ids(filterJobPostings(sparsePostings, { search })), [2]);
}
for (const search of ['null', 'undefined']) {
  assert.deepEqual(filterJobPostings(sparsePostings, { search }), []);
}
assert.deepEqual(filterJobPostings(sparsePostings, { search: '   ' }), sparsePostings);
JS,
    'publication status and company filters combine with search' => <<<'JS'
assert.deepEqual(ids(filterJobPostings(postings, { status: 'published' })), [1, 4]);
assert.deepEqual(ids(filterJobPostings(postings, { status: 'draft' })), [2, 3]);
assert.deepEqual(ids(filterJobPostings(postings, { company: '10' })), [1, 3, 4]);
assert.deepEqual(ids(filterJobPostings(postings, { search: ' sales ', status: 'published', company: '10' })), [1]);
assert.deepEqual(ids(filterJobPostings(postings, { search: 'SALES', status: 'draft', company: 10 })), [3]);
assert.deepEqual(ids(filterJobPostings(postings, { search: 'sales', status: 'draft', company: 20 })), [2]);
assert.deepEqual(filterJobPostings(postings, { search: 'courier', status: 'draft', company: 10 }), []);
assert.deepEqual(filterJobPostings(postings, { search: 'sales', status: 'published', company: 20 }), []);
JS,
    'search reaches the final record in a large collection' => <<<'JS'
const largeCollection = Array.from({ length: 451 }, (_, index) => ({
  id: index + 1,
  title: index === 450 ? 'Unique Logistics Coordinator' : `Sales Posting ${index + 1}`,
}));

assert.deepEqual(ids(filterJobPostings(largeCollection, { search: 'unique logistics' })), [451]);
assert.deepEqual(filterJobPostings(largeCollection, { search: 'an absent search phrase' }), []);
assert.equal(largeCollection.length, 451);
JS,
]);
