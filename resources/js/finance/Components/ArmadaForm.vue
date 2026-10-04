<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';

/**
 * Launch or rename an armada. A name and a line about it; what is in it is
 * chosen on its own page.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    armada: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const dialog = ref(null);
const form = useForm({ name: '', description: '' });

watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();
    form.name = props.armada?.name ?? '';
    form.description = props.armada?.description ?? '';
}, { immediate: true });

// Names to start from, for someone looking at an empty field.
const suggestions = ['Foundational', 'Real estate', 'Retirement', 'Business'];

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (props.armada) {
        form.patch(`/finance/armadas/${props.armada.id}`, options);
    } else {
        form.post('/finance/armadas', options);
    }
};
</script>

<template>
    <FinDialog ref="dialog" :open="open" :title="armada ? `Rename ${armada.name}` : 'Launch an armada'" @close="emit('close')">
        <form class="flex flex-col gap-4" @submit.prevent="save">
            <Field label="Name" :error="form.errors.name">
                <input v-model="form.name" type="text" required maxlength="80" placeholder="e.g. Real estate">
            </Field>

            <div v-if="!armada" class="-mt-2 flex flex-wrap gap-1.5">
                <button
                    v-for="suggestion in suggestions" :key="suggestion" type="button"
                    class="rounded-full border border-fin-grey-300 bg-fin-white px-3 py-1 text-xs text-fin-charcoal hover:bg-fin-cream-100"
                    @click="form.name = suggestion"
                >
                    {{ suggestion }}
                </button>
            </div>

            <Field label="What it is for" hint="Optional." :error="form.errors.description">
                <input v-model="form.description" type="text" maxlength="255" placeholder="e.g. The rentals, and everything they cost.">
            </Field>

            <div class="mt-2 flex justify-end gap-2">
                <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ armada ? 'Save changes' : 'Launch' }}</button>
            </div>
        </form>
    </FinDialog>
</template>
