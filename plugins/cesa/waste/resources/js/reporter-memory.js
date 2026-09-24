export const WASTE_REPORTER_STORAGE_KEY = 'cesa.waste.reporter';

const fields = ['name', 'phone', 'email'];

function reporterFrom(value) {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
        return null;
    }

    return Object.fromEntries(fields.map((field) => [
        field,
        typeof value[field] === 'string' ? value[field].trim() : '',
    ]));
}

function isBlank(value) {
    return typeof value !== 'string' || value.trim() === '';
}

export function readWasteReporter(storage) {
    if (!storage || typeof storage.getItem !== 'function') {
        return null;
    }

    try {
        const raw = storage.getItem(WASTE_REPORTER_STORAGE_KEY);

        if (typeof raw !== 'string' || raw.trim() === '') {
            return null;
        }

        return reporterFrom(JSON.parse(raw));
    } catch {
        return null;
    }
}

export function writeWasteReporter(storage, reporter) {
    const normalized = reporterFrom(reporter) ?? { name: '', phone: '', email: '' };

    try {
        storage.setItem(WASTE_REPORTER_STORAGE_KEY, JSON.stringify(normalized));
    } catch {
        // Browser storage can reject writes in private mode or when it is full.
    }
}

export function reporterFieldsToRestore(saved, current, isRevision) {
    if (isRevision || !saved) {
        return null;
    }

    const restored = {};

    fields.forEach((field) => {
        const savedValue = typeof saved[field] === 'string' ? saved[field].trim() : '';
        const currentValue = current && typeof current[field] === 'string' ? current[field] : '';

        if (savedValue !== '' && isBlank(currentValue)) {
            restored[field] = savedValue;
        }
    });

    return Object.keys(restored).length === 0 ? null : restored;
}
