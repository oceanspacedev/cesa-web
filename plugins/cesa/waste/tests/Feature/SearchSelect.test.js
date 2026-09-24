import assert from 'node:assert/strict';
import { test } from 'node:test';
import { commitSearchSelection, unitForItem } from '../../resources/js/search-select.js';

test('returns the unit of the item chosen for a quantity field', () => {
    const units = { 15: 'PCS', 8: 'GR' };
    const selected = { 'data.events.0.lines.0.item_id': '15' };

    assert.equal(unitForItem(units, selected, 'data.events.0.lines.0.item_id'), 'PCS');
    assert.equal(unitForItem(units, selected, 'data.events.0.lines.1.item_id'), '');
});

test('stores the chosen option on the Livewire model without a request', () => {
    const calls = [];
    const wire = {
        $set(model, value, live) {
            calls.push({ model, value, live });
        },
    };

    const value = commitSearchSelection(wire, 'data.events.0.lines.0.item_id', 15);

    assert.equal(value, '15');
    assert.deepEqual(calls, [{
        model: 'data.events.0.lines.0.item_id',
        value: '15',
        live: false,
    }]);
});
