export function unitForItem(units, selected, model) {
    const id = selected?.[model] ?? '';

    if (id === '' || id === null) {
        return '';
    }

    return units?.[id] ?? units?.[String(id)] ?? '';
}

export function commitSearchSelection(wire, model, optionId) {
    const value = optionId === null || optionId === undefined ? '' : String(optionId);

    wire.$set(model, value, false);

    return value;
}
