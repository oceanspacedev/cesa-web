import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createWasteModelBinding, normalizeWasteProps } from '../../resources/js/frappe-livewire-bridge.js';

test('select values and option IDs have the same type after restoring a report', () => {
    const binding = createWasteModelBinding({
        component: 'Select',
        model: 'events.0.lines.0.item_id',
        wire: { $get: () => 42, $watch: () => () => {} },
        onChange: () => {},
    });
    const props = normalizeWasteProps('Select', { options: [{ label: 'Rice', value: 42 }] });

    assert.equal(binding.value, props.options[0].value);
    assert.equal(binding.value, '42');
});

test('input and change events update Livewire once, deferring the request until the next action', () => {
    const calls = [];
    const binding = createWasteModelBinding({
        component: 'TextInput',
        model: 'events.0.lines.0.quantity',
        wire: {
            $get: () => '',
            $watch: () => () => {},
            $set: (...args) => calls.push(args),
        },
        onChange: () => {},
    });

    binding.set('1.25');
    binding.set('1.25');

    assert.deepEqual(calls, [['events.0.lines.0.quantity', '1.25', false]]);
});

test('server changes synchronize the field without writing back and removed fields release their watcher', () => {
    let watcher;
    let unsubscribeCount = 0;
    const changes = [];
    const binding = createWasteModelBinding({
        component: 'Select',
        model: 'events.1.item_id',
        wire: {
            $get: () => 42,
            $watch: (_path, callback) => {
                watcher = callback;

                return () => { unsubscribeCount++; };
            },
            $set: () => assert.fail('Server updates must not write back to Livewire.'),
        },
        onChange: (value) => changes.push(value),
    });

    watcher(56);
    assert.equal(binding.value, '56');
    assert.deepEqual(changes, ['56']);

    binding.destroy();
    binding.destroy();
    watcher(80);
    binding.set(90);
    assert.equal(unsubscribeCount, 1);
    assert.equal(binding.value, '56');
});

test('public search fields emit changes without needing a Livewire component', () => {
    const inputs = [];
    const binding = createWasteModelBinding({
        component: 'TextInput',
        initialValue: '',
        onChange: () => {},
        onInput: (value) => inputs.push(value),
    });

    binding.set('Jchicken');
    binding.set('Jchicken');

    assert.deepEqual(inputs, ['Jchicken']);
});
