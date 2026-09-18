<?php

use Symfony\Component\Process\Process;

it('preserves pipeline state across recruitment store actions', function (string $scenario): void {
    $setup = <<<'JS'
import assert from 'node:assert/strict';
import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { useRekrutmenStore } from './plugins/cesa/rekrutmen/resources/js/stores/rekrutmen.js';

setActivePinia(createPinia());
const store = useRekrutmenStore();
const copy = value => JSON.parse(JSON.stringify(value));
const operationsStages = [
  { id: 11, rekrutmen_pipeline_id: 1, name: 'Screening CV', order_column: 1, color: '#2563eb' },
  { id: 12, rekrutmen_pipeline_id: 1, name: 'Interview HR', order_column: 2, color: '#d97706' },
];
const technologyStages = [
  { id: 21, rekrutmen_pipeline_id: 2, name: 'Technical Assessment', order_column: 1, color: '#4f46e5' },
  { id: 22, rekrutmen_pipeline_id: 2, name: 'Interview User', order_column: 3, color: '#0284c7' },
];
const allStages = [...operationsStages, ...technologyStages];
const configurations = {
  stages: operationsStages,
  pipelines: [
    { id: 1, name: 'Operations', description: 'Operations recruitment', stages: operationsStages, stages_count: 2 },
    { id: 2, name: 'Technology', description: 'Technology recruitment', stages: technologyStages, stages_count: 2 },
  ],
};
axios.get = async url => { throw new Error(`Unexpected GET ${url}`); };
axios.post = async url => { throw new Error(`Unexpected POST ${url}`); };
JS;

    $process = new Process(
        ['node', '--input-type=module', '-e', $setup."\n".$scenario."\nconsole.log('passed');"],
        dirname(__DIR__, 2),
    );
    $process->mustRun();

    expect(trim($process->getOutput()))->toBe('passed');
})->with([
    'configuration preload finishes after all-job applications' => <<<'JS'
let completeConfigurations;
axios.get = async url => {
  if (url === '/rekrutmen/api/configurations') {
    return new Promise(resolve => { completeConfigurations = resolve; });
  }
  assert.equal(url, '/rekrutmen/api/applications');
  return { data: { stages: copy(allStages), applications: [{ id: 101 }] } };
};

const preload = store.fetchConfigurations();
await store.fetchApplications();
completeConfigurations({ data: copy(configurations) });
await preload;

assert.deepEqual(copy(store.stages), allStages);
assert.deepEqual(copy(store.configurations.stages), operationsStages);
assert.deepEqual(copy(store.pipelines[1].stages), technologyStages);
JS,
    'posting summaries retain rich pipeline details' => <<<'JS'
axios.get = async url => {
  if (url === '/rekrutmen/api/configurations') {
    return { data: copy(configurations) };
  }
  assert.equal(url, '/rekrutmen/api/job-postings');
  return { data: {
    data: [{ id: 101 }],
    pipelines: [{ id: '1', name: 'Updated Operations' }, { id: 2, name: 'Technology' }],
  } };
};

await store.fetchConfigurations();
await store.fetchPostings();

assert.equal(store.pipelines[0].name, 'Updated Operations');
assert.equal(store.pipelines[0].description, 'Operations recruitment');
assert.equal(store.pipelines[0].stages_count, 2);
assert.deepEqual(copy(store.pipelines[0].stages), operationsStages);
assert.deepEqual(copy(store.pipelines[1].stages), technologyStages);
JS,
    'reordering refreshes configurations and preserves other pipelines' => <<<'JS'
store.stages = copy(allStages);
store.configurations = copy(configurations);
store.pipelines = copy(configurations.pipelines);
const reorderedStages = [
  { id: 12, rekrutmen_pipeline_id: 1, name: 'Interview HR', order_column: 1 },
  { id: 11, rekrutmen_pipeline_id: 1, name: 'Screening CV', order_column: 2 },
];
let configurationRequests = 0;
axios.post = async (url, payload) => {
  assert.equal(url, '/rekrutmen/api/stages/reorder');
  assert.deepEqual(payload.stage_ids, [12, 11]);
  assert.equal(payload.pipeline_id, 1);
  assert.deepEqual(copy(store.stages), allStages);
  return { data: { success: true, stages: copy(reorderedStages) } };
};
axios.get = async url => {
  assert.equal(url, '/rekrutmen/api/configurations');
  configurationRequests++;
  return { data: {
    ...copy(configurations),
    stages: copy(reorderedStages),
    pipelines: [
      { ...copy(configurations.pipelines[0]), stages: copy(reorderedStages) },
      copy(configurations.pipelines[1]),
    ],
  } };
};

await store.reorderStages([12, 11], 1);

assert.equal(configurationRequests, 1);
assert.deepEqual(copy(store.stages.filter(stage => stage.rekrutmen_pipeline_id === 2)), technologyStages);
assert.deepEqual(store.stages.filter(stage => stage.rekrutmen_pipeline_id === 1).map(stage => stage.id), [12, 11]);
assert.equal(store.stages.find(stage => stage.id === 12).color, '#d97706');
assert.equal(store.stages.find(stage => stage.id === 11).order_column, 2);
assert.deepEqual(copy(store.pipelines[0].stages), reorderedStages);
assert.deepEqual(copy(store.configurations.stages), reorderedStages);
JS,
    'failed reordering leaves all shared stages unchanged' => <<<'JS'
store.stages = copy(allStages);
store.configurations = copy(configurations);
axios.post = async () => { throw new Error('The stage order was rejected'); };

await assert.rejects(store.reorderStages([12, 11], 1), /stage order was rejected/);

assert.deepEqual(copy(store.stages), allStages);
assert.deepEqual(copy(store.configurations), configurations);
JS,
]);
