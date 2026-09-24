import { createApp, h, shallowReactive } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { Livewire, Alpine } from '../../../../../vendor/livewire/livewire/dist/livewire.esm';
import TextInput from 'frappe-ui/src/components/TextInput/TextInput.vue';
import Textarea from 'frappe-ui/src/components/Textarea/Textarea.vue';
import Select from 'frappe-ui/src/components/Select/Select.vue';
import Button from 'frappe-ui/src/components/Button/Button.vue';
import Badge from 'frappe-ui/src/components/Badge/Badge.vue';
import { createWasteModelBinding, normalizeWasteProps } from './frappe-livewire-bridge';
import { readWasteReporter, reporterFieldsToRestore, writeWasteReporter } from './reporter-memory';
import { commitSearchSelection, unitForItem } from './search-select';

const components = { TextInput, Textarea, Select, Button, Badge };
const inputComponents = new Set(['TextInput', 'Textarea', 'Select']);
const buttonRouter = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: { render: () => null } }],
});

Alpine.data('wasteFrappe', () => {
    let app;
    let observer;
    let binding;
    let state;
    let modelPath;
    let componentName;

    return {
        init() {
            const root = this.$el;
            const host = root.querySelector('[data-waste-mount]');
            const fallbackNodes = host ? Array.from(host.childNodes) : [];

            componentName = root.dataset.wasteComponent;

            if (!host || !Object.hasOwn(components, componentName)) {
                return;
            }

            const refresh = () => {
                let props;

                try {
                    props = JSON.parse(root.dataset.wasteProps || '{}');

                    if (!props || typeof props !== 'object' || Array.isArray(props)) {
                        return false;
                    }

                    props = normalizeWasteProps(componentName, props);
                } catch {
                    return false;
                }

                const loading = root.hasAttribute('data-disabled')
                    && root.getAttribute('data-disabled') !== 'false';
                props.disabled = Boolean(props.disabled || loading);

                if (componentName === 'Button') {
                    props.loading = Boolean(props.loading || loading);
                }

                if (!state) {
                    state = shallowReactive({ props, modelValue: '' });
                } else {
                    state.props = props;
                }

                const nextModelPath = root.dataset.wasteModel || null;

                if (inputComponents.has(componentName) && (!binding || modelPath !== nextModelPath)) {
                    binding?.destroy();
                    modelPath = nextModelPath;
                    binding = createWasteModelBinding({
                        component: componentName,
                        model: modelPath,
                        initialValue: props.modelValue,
                        wire: modelPath ? this.$wire : null,
                        onChange: (value) => { state.modelValue = value; },
                        onInput: (value) => root.dispatchEvent(new CustomEvent('waste-ui-input', {
                            detail: value,
                            bubbles: true,
                        })),
                    });
                    state.modelValue = binding.value;
                } else if (binding && !modelPath && Object.hasOwn(props, 'modelValue')) {
                    binding.sync(props.modelValue);
                }

                return true;
            };

            if (!refresh()) {
                return;
            }

            app = createApp({
                render() {
                    const props = { ...state.props };

                    if (binding) {
                        props.modelValue = state.modelValue;
                        props['onUpdate:modelValue'] = (value) => binding.set(value);
                    }

                    return h(components[componentName], props);
                },
            });
            app.use(buttonRouter);

            try {
                app.mount(host);
            } catch (error) {
                app.unmount();
                binding?.destroy();
                host.replaceChildren(...fallbackNodes);
                console.error('Waste UI could not initialize.', error);

                return;
            }

            observer = new MutationObserver(refresh);
            observer.observe(root, {
                attributes: true,
                attributeFilter: ['data-waste-props', 'data-waste-model', 'data-disabled'],
            });
        },
        destroy() {
            observer?.disconnect();
            binding?.destroy();
            app?.unmount();
        },
    };
});

const reporterPaths = {
    name: 'data.reporter_name',
    phone: 'data.reporter_phone',
    email: 'data.reporter_email',
};

Alpine.data('wasteSearchSelect', (options, initial, placeholder, model) => ({
    open: false,
    query: '',
    value: initial === null || initial === undefined ? '' : String(initial),
    placeholder,
    options,
    init() {
        this.$watch('open', (isOpen) => {
            if (! isOpen) {
                return;
            }

            this.query = '';
            this.$nextTick(() => {
                this.$refs.search?.focus();
                this.$refs.panel?.scrollIntoView({ block: 'nearest' });
            });
        });
    },
    get selectedLabel() {
        return this.options.find((option) => String(option.id) === this.value)?.label ?? '';
    },
    get matches() {
        const query = this.query.trim().toLowerCase();

        if (query === '') {
            return this.options;
        }

        return this.options.filter((option) => option.label.toLowerCase().includes(query));
    },
    get filtered() {
        return this.matches.slice(0, 30);
    },
    toggle() {
        this.open = ! this.open;
    },
    choose(option) {
        this.value = commitSearchSelection(this.$wire, model, option.id);
        this.open = false;
        this.query = '';
        this.$dispatch('waste-item-selected', { model, id: String(option.id) });
    },
}));

Alpine.data('wasteItemUnits', (units, selected) => ({
    units,
    selected,
    remember(model, id) {
        this.selected[model] = id === null || id === undefined ? '' : String(id);
    },
    unitFor(model) {
        return unitForItem(this.units, this.selected, model);
    },
}));

Alpine.data('wasteLineUnits', (units, initialItem, initialUnit, unitModel) => ({
    units,
    itemId: initialItem === null || initialItem === undefined ? '' : String(initialItem),
    selectedUnit: String(initialUnit || units?.[initialItem]?.[0] || ''),
    get availableUnits() {
        return this.units?.[this.itemId] ?? [];
    },
    selectItem(id) {
        const nextItemId = id === null || id === undefined ? '' : String(id);

        if (this.itemId === nextItemId) {
            return;
        }

        this.itemId = nextItemId;
        this.selectedUnit = this.availableUnits[0] ?? '';
        this.$wire.$set(unitModel, this.selectedUnit, false);
    },
    selectUnit(unit) {
        if (! this.availableUnits.includes(unit)) {
            return;
        }

        this.selectedUnit = unit;
        this.$wire.$set(unitModel, unit, false);
    },
}));

Alpine.data('wasteReporter', () => ({
    restore() {
        this.$nextTick(() => {
            const current = Object.fromEntries(Object.entries(reporterPaths).map(([field, path]) => [
                field,
                this.$wire.get(path) ?? '',
            ]));
            const restored = reporterFieldsToRestore(
                readWasteReporter(window.localStorage),
                current,
                Boolean(this.$wire.get('isRevision')),
            );

            if (!restored) {
                return;
            }

            Object.entries(restored).forEach(([field, value]) => {
                this.$wire.$set(reporterPaths[field], value, false);
            });
        });
    },
    remember() {
        writeWasteReporter(window.localStorage, Object.fromEntries(Object.keys(reporterPaths).map((field) => [
            field,
            this.$root.querySelector(`[data-waste-reporter="${field}"]`)?.value ?? '',
        ])));
    },
}));

Livewire.start();
