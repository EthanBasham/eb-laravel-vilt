<script setup>
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    stepId: { type: Number, required: true },
    field: { type: String, required: true },
    modelValue: { type: [Number, String], default: 0 },
    align: { type: String, default: 'text-right' },
});

const value = ref(props.modelValue ?? 0);
const saving = ref(false);

// Keep in step with server responses (a sibling edit can change totals), but
// never while the field is being edited.
const editing = ref(false);
watch(() => props.modelValue, (v) => {
    if (!editing.value) value.value = v ?? 0;
});

const commit = () => {
    editing.value = false;
    const next = value.value === '' ? 0 : Number(value.value);

    if (next === Number(props.modelValue ?? 0)) return;

    saving.value = true;
    router.patch(`/wot/grinding/steps/${props.stepId}`, { [props.field]: next }, {
        preserveScroll: true,
        // Only the board comes back; nothing else on the page moved.
        only: ['active', 'targets', 'totals'],
        onFinish: () => (saving.value = false),
    });
};
</script>

<template>
    <input
        v-model="value"
        type="number"
        min="0"
        inputmode="numeric"
        class="w-24 border border-transparent bg-transparent px-1 py-0.5 tabular-nums transition-colors hover:border-wot-border focus:border-wot-gold"
        :class="[align, saving ? 'opacity-50' : '']"
        :disabled="saving"
        @focus="editing = true"
        @blur="commit"
        @keyup.enter="$event.target.blur()"
    >
</template>
