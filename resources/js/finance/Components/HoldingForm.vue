<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';

/**
 * Add or edit one row of the fleet.
 *
 * One form for both sides of the balance sheet. The fields are the same; what
 * changes is what they are called — a "balance" is a value for an asset and
 * a debt for a liability, the rate is growth or APR, the monthly figure a
 * contribution or a payment.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // The holding being edited, or null to add one.
    holding: { type: Object, default: null },
    // Which side a new holding starts on.
    side: { type: String, default: 'asset' },
    // The user's assets, for "secured against".
    assets: { type: Array, default: () => [] },
    // The compound account this one sits inside, as { id, name, type }. Set
    // when adding an account from a retirement account's page, or editing one
    // that is already inside it. It narrows the type list to what the parent
    // can hold, and is sent back as `parent_id`.
    parent: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const lists = computed(() => usePage().props.lists);
const types = computed(() => lists.value.holding_types);
const dialog = ref(null);

const form = useForm({
    type: '',
    plan_type: null,
    tax_type: null,
    parent_id: null,
    name: '',
    institution: '',
    balance: 0,
    annual_rate: 0,
    monthly_contribution: 0,
    secured_by_id: null,
    notes: '',
});

// What the type select offers. Inside a compound account, only the types it
// can hold; otherwise everything, grouped by side of the balance sheet.
const grouped = computed(() => {
    if (props.parent) {
        const holds = types.value[props.parent.type]?.holds ?? [];

        return { [`Inside ${props.parent.name}`]: holds.map((key) => [key, types.value[key]]) };
    }

    return {
        Assets: Object.entries(types.value).filter(([, type]) => type.side === 'asset'),
        Liabilities: Object.entries(types.value).filter(([, type]) => type.side === 'liability'),
    };
});

const firstTypeOn = (side) => Object.values(grouped.value).flat().find(([, type]) => type.side === side)?.[0];

// A retirement account needs its two facts; nothing else has them. Offer the
// commonest pair when the type becomes retirement and the fields are empty.
const fillRetirementFacts = () => {
    if (form.type === 'retirement') {
        form.plan_type ??= '401k';
        form.tax_type ??= 'traditional';
    }
};

// Refill the form each time the dialog opens: a working copy, so cancelling
// leaves the page as it was.
watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();

    if (props.holding) {
        form.type = props.holding.type;
        form.plan_type = props.holding.plan_type;
        form.tax_type = props.holding.tax_type;
        form.parent_id = props.holding.parent_id;
        form.name = props.holding.name;
        form.institution = props.holding.institution ?? '';
        form.balance = props.holding.balance;
        form.annual_rate = props.holding.annual_rate;
        form.monthly_contribution = props.holding.monthly_contribution;
        form.secured_by_id = props.holding.secured_by_id;
        form.notes = props.holding.notes ?? '';

        return;
    }

    form.reset();
    form.type = firstTypeOn(props.side);
    form.parent_id = props.parent?.id ?? null;
    form.annual_rate = types.value[form.type].rate;
    fillRetirementFacts();
}, { immediate: true });

const isLiability = computed(() => types.value[form.type]?.side === 'liability');

// Picking a type on a new holding offers that type's usual rate. Left alone
// when editing, where the rate on screen is one somebody chose.
const onTypeChange = () => {
    if (!props.holding) {
        form.annual_rate = types.value[form.type].rate;
    }

    fillRetirementFacts();
};

// While an account holds others, its own balance, rate and contribution are
// not what it is worth — theirs are — so the form says so rather than taking
// figures that will be ignored.
const isCompound = computed(() => (props.holding?.children_count ?? 0) > 0);

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (props.holding) {
        form.patch(`/finance/fleet/${props.holding.id}`, options);
    } else {
        form.post('/finance/fleet', options);
    }
};
</script>

<template>
    <FinDialog ref="dialog" :open="open" :title="holding ? `Edit ${holding.name}` : (parent ? `Add an account inside ${parent.name}` : 'Add to your fleet')" @close="emit('close')">
        <form class="flex flex-col gap-4" @submit.prevent="save">
            <Field label="Type" :error="form.errors.type || form.errors.parent_id">
                <select v-model="form.type" @change="onTypeChange">
                    <optgroup v-for="(options, label) in grouped" :key="label" :label="label">
                        <option v-for="[key, type] in options" :key="key" :value="key">{{ type.label }}</option>
                    </optgroup>
                </select>
            </Field>

            <div v-if="form.type === 'retirement'" class="grid gap-4 sm:grid-cols-2">
                <Field label="Plan" :error="form.errors.plan_type">
                    <select v-model="form.plan_type">
                        <option v-for="(plan, key) in lists.retirement_plans" :key="key" :value="key">{{ plan.label }}</option>
                    </select>
                </Field>
                <!-- A fieldset rather than a Field: Field is a <label> around one
                     control, and a radio group is several controls, each with
                     a label of its own, under one legend. -->
                <fieldset>
                    <legend class="mb-1 block text-xs font-medium text-fin-grey-600">Tax treatment</legend>
                    <div class="flex h-[2.375rem] items-center gap-5">
                        <label v-for="(taxType, key) in lists.retirement_tax_types" :key="key" class="flex items-center gap-2 text-sm text-fin-charcoal">
                            <input v-model="form.tax_type" type="radio" name="tax_type" :value="key">
                            {{ taxType.label }}
                        </label>
                    </div>
                    <span v-if="form.errors.tax_type" class="mt-1 block text-xs text-fin-red-600">{{ form.errors.tax_type }}</span>
                </fieldset>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="Name" :error="form.errors.name">
                    <input v-model="form.name" type="text" required maxlength="80" placeholder="e.g. Joint brokerage">
                </Field>
                <Field label="Institution" hint="Optional" :error="form.errors.institution">
                    <input v-model="form.institution" type="text" maxlength="80">
                </Field>
            </div>

            <p v-if="isCompound" class="rounded-xl bg-fin-cream-100 px-4 py-3 text-xs text-fin-grey-600">
                This account holds {{ holding.children_count }} {{ holding.children_count === 1 ? 'account' : 'accounts' }}, so its value, growth and
                contributions are theirs added up. Edit those on each account inside it.
            </p>

            <div v-else class="grid gap-4 sm:grid-cols-3">
                <Field
                    :label="isLiability ? 'Balance owed' : 'Current value'" prefix="$" :error="form.errors.balance"
                    :hint="!isLiability && holding?.positions_count ? 'Ignored while the account has positions.' : ''"
                >
                    <input v-model.number="form.balance" type="number" min="0" step="0.01" required>
                </Field>
                <Field :label="isLiability ? 'Interest rate (APR)' : 'Expected growth'" suffix="% / yr" :error="form.errors.annual_rate">
                    <input v-model.number="form.annual_rate" type="number" step="0.001" required>
                </Field>
                <Field :label="isLiability ? 'Monthly payment' : 'Monthly contribution'" prefix="$" :error="form.errors.monthly_contribution">
                    <input v-model.number="form.monthly_contribution" type="number" min="0" step="0.01" required>
                </Field>
            </div>

            <Field v-if="isLiability && assets.length" label="Secured against" hint="The asset this debt is borrowed against, if any." :error="form.errors.secured_by_id">
                <select v-model="form.secured_by_id">
                    <option :value="null">Nothing</option>
                    <option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.name }}</option>
                </select>
            </Field>

            <Field label="Notes" :error="form.errors.notes">
                <textarea v-model="form.notes" rows="2" maxlength="2000" />
            </Field>

            <div class="mt-2 flex justify-end gap-2">
                <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ holding ? 'Save changes' : 'Add to fleet' }}</button>
            </div>
        </form>
    </FinDialog>
</template>
