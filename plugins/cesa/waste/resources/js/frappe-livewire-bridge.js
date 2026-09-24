export function normalizeWasteValue(component, value) {
    return component === 'Select' ? String(value ?? '') : (value ?? '');
}

export function normalizeWasteProps(component, props) {
    if (component !== 'Select') {
        return { ...props };
    }

    return {
        ...props,
        options: (props.options ?? []).map((option) => {
            if (typeof option !== 'object' || option === null) {
                return { label: String(option ?? ''), value: String(option ?? '') };
            }

            return { ...option, value: String(option.value ?? '') };
        }),
    };
}

export function createWasteModelBinding({ component, model, initialValue, wire, onChange, onInput }) {
    let currentValue = normalizeWasteValue(component, model ? wire.$get(model) : initialValue);
    let disposed = false;

    const update = (value) => {
        if (disposed) {
            return false;
        }

        const normalized = normalizeWasteValue(component, value);

        if (Object.is(currentValue, normalized)) {
            return false;
        }

        currentValue = normalized;
        onChange(normalized);

        return true;
    };

    const unwatch = model ? wire.$watch(model, update) : null;

    return {
        get value() {
            return currentValue;
        },
        set(value) {
            if (!update(value)) {
                return;
            }

            if (model) {
                wire.$set(model, currentValue, false);
            } else {
                onInput(currentValue);
            }
        },
        sync(value) {
            update(value);
        },
        destroy() {
            if (disposed) {
                return;
            }

            disposed = true;
            unwatch?.();
        },
    };
}
