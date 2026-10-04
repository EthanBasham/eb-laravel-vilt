<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';

/**
 * Add or edit an automated transfer: a standing instruction to move money
 * from one holding to another each month.
 *
 * What the two number fields mean depends on the kind, so their labels and
 * hints are read off it rather than fixed.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    transfer: { type: Object, default: null },
    // Where a new transfer is ordered: after the ones there already are.
    nextOrder: { type: Number, default: 1 },
});

const emit = defineEmits(['close']);

const kinds = computed(() => usePage().props.lists.transfer_kinds);
const accounts = computed(() => usePage().props.accounts ?? []);
// Money only ever leaves an asset; it may arrive at a debt.
const sources = computed(() => accounts.value.filter((account) => account.side === 'asset'));
const dialog = ref(null);

const blank = {
    name: '',
    kind: 'sweep',
    from_holding_id: null,
    to_holding_id: null,
    amount: null,
    keep_balance: 0,
    sort_order: 1,
    is_active: true,
};

const form = useForm({ ...blank });

// An emptied number field is '', which is this form's way of saying "blank".
form.transform((data) => ({ ...data, amount: data.amount === '' ? null : data.amount }));

watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();

    Object.keys(blank).forEach((key) => {
        form[key] = props.transfer ? props.transfer[key] : blank[key];
    });

    if (!props.transfer) {
        form.sort_order = props.nextOrder;
        form.from_holding_id = sources.value[0]?.id ?? null;
    }
}, { immediate: true });

const kind = computed(() => kinds.value[form.kind]);
const destinations = computed(() => accounts.value.filter((account) => account.id !== form.from_holding_id && (form.kind !== 'top_up' || account.side === 'asset')));

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (props.transfer) {
        form.patch(`/finance/transfers/${props.transfer.id}`, options);
    } else {
        form.post('/finance/transfers', options);
    }
};
</script>

<template>
    <FinDialog ref="dialog" :open="open" :title="transfer ? `Edit ${transfer.name}` : 'Add an automated transfer'" @close="emit('close')">
        <form class="flex flex-col gap-4" @submit.prevent="save">
            <Field label="What it does" :hint="kind.description" :error="form.errors.kind">
                <select v-model="form.kind">
                    <option v-for="(option, key) in kinds" :key="key" :value="key">{{ option.label }}</option>
                </select>
            </Field>

            <Field label="Name" :error="form.errors.name">
                <input v-model="form.name" type="text" required maxlength="80" placeholder="e.g. Sweep checking into savings">
            </Field>

            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="From" :error="form.errors.from_holding_id">
                    <select v-model="form.from_holding_id" required>
                        <option v-for="account in sources" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                </Field>
                <Field label="To" :hint="form.kind === 'top_up' ? '' : 'An account, or a debt to pay down.'" :error="form.errors.to_holding_id">
                    <select v-model="form.to_holding_id" required>
                        <option :value="null" disabled>Choose…</option>
                        <option v-for="account in destinations" :key="account.id" :value="account.id">{{ account.name }}{{ account.side === 'liability' ? ' (debt)' : '' }}</option>
                    </select>
                </Field>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <Field
                    v-if="kind.keeps" :label="kind.keeps === 'from' ? 'Keep in the source' : 'Keep in the destination'" prefix="$"
                    :hint="kind.keeps === 'from' ? 'Everything above this moves.' : 'Refilled whenever it falls below this.'" :error="form.errors.keep_balance"
                >
                    <input v-model.number="form.keep_balance" type="number" min="0" step="100" required>
                </Field>
                <Field
                    :label="kind.amount === 'required' ? 'Each month' : 'At most, each month'" prefix="$"
                    :hint="kind.amount === 'required' ? 'As far as the source has it.' : 'Blank is no limit.'" :error="form.errors.amount"
                >
                    <input v-model.number="form.amount" type="number" min="0" step="10" :required="kind.amount === 'required'">
                </Field>
            </div>

            <div class="grid items-start gap-4 sm:grid-cols-2">
                <Field label="Order" hint="Transfers run lowest first each month, so an earlier one gets first call on the money." :error="form.errors.sort_order">
                    <input v-model.number="form.sort_order" type="number" min="0" max="999" step="1" required>
                </Field>
                <label class="flex items-center gap-2 text-sm text-fin-charcoal sm:pt-7">
                    <input v-model="form.is_active" type="checkbox">
                    Running
                </label>
            </div>

            <div class="mt-2 flex justify-end gap-2">
                <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ transfer ? 'Save changes' : 'Add transfer' }}</button>
            </div>
        </form>
    </FinDialog>
</template>
