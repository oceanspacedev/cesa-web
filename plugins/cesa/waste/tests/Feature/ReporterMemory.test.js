import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readWasteReporter, reporterFieldsToRestore, writeWasteReporter } from '../../resources/js/reporter-memory.js';

function memoryStorage(initial = {}) {
    const values = { ...initial };

    return {
        getItem: (key) => (Object.hasOwn(values, key) ? values[key] : null),
        setItem: (key, value) => {
            values[key] = String(value);
        },
    };
}

test('stores reporter identity and restores it on the next empty form', () => {
    const storage = memoryStorage();

    writeWasteReporter(storage, {
        name: 'Sari',
        phone: '081234567890',
        email: 'sari@example.test',
    });

    const restored = reporterFieldsToRestore(readWasteReporter(storage), {
        name: '',
        phone: '',
        email: '',
    }, false);

    assert.deepEqual(restored, {
        name: 'Sari',
        phone: '081234567890',
        email: 'sari@example.test',
    });
});

test('keeps a revision and any field the reporter already filled', () => {
    const storage = memoryStorage();
    writeWasteReporter(storage, {
        name: 'Sari',
        phone: '081234567890',
        email: 'sari@example.test',
    });
    const saved = readWasteReporter(storage);

    assert.equal(reporterFieldsToRestore(saved, {
        name: 'Field Reporter',
        phone: '089999887766',
        email: 'reporter@example.test',
    }, true), null);

    assert.deepEqual(reporterFieldsToRestore(saved, {
        name: 'Baru',
        phone: '',
        email: 'baru@example.test',
    }, false), {
        phone: '081234567890',
    });
});

test('ignores missing or unreadable browser storage', () => {
    assert.equal(readWasteReporter(memoryStorage()), null);
    assert.equal(readWasteReporter(memoryStorage({ 'cesa.waste.reporter': '{not-json' })), null);
    assert.equal(reporterFieldsToRestore(null, { name: '', phone: '', email: '' }, false), null);
});
