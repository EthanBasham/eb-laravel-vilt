<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';

/**
 * Add or edit an income stream or an expense.
 *
 * Used from the income & expenses page, where a flow may or may not belong to
 * a holding, and from a holding's own page, where `holdingId` pins it to that
 * holding and the choice is not offered.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    flow: { type: Object, default: null },
    direction: { type: String, default: 'expense' },
    holdingId: { type: Number, default: null },
    // Holdings to offer in "belongs to". Empty hides the field.
    holdings: { type: Array, default: () => [] },
    // The flow a new one is an item of, as { id, name } — set when itemising
    // a household expense on the budget. An item is always an expense, takes
    // any category that cannot itself hold items, and follows the flow it
    // sits in for its armada and the account it is paid from.
    parent: { type: Object, default: null },
    // The armada a new flow starts in — set when adding from an armada's page.
    armadaId: { type: Number, default: null },
    // The category a new flow starts on, when the page knows which it wants.
    category: { type: String, default: null },
});

const emit = defineEmits(['close']);

const lists = computed(() => usePage().props.lists);
const armadas = computed(() => usePage().props.armadas ?? []);
// Money lands in, and leaves, assets only.
const accounts = computed(() => (usePage().props.accounts ?? []).filter((account) => account.side === 'asset'));
const dialog = ref(null);

const form = useForm({
    direction: 'expense',
    category: '',
    name: '',
    amount: 0,
    frequency: 'monthly',
    hours_per_week: null,
    annual_growth_rate: 0,
    taxation: null,
    taxed_portion: 100,
    is_essential: false,
    starts_on: null,
    ends_on: null,
    holding_id: null,
    armada_id: null,
    account_id: null,
    parent_id: null,
});

const isItem = computed(() => form.parent_id !== null);

// An item takes any category but one that holds items itself.
const categories = computed(() => {
    const all = lists.value.flow_categories[form.direction] ?? {};

    if (!isItem.value) return all;

    return Object.fromEntries(Object.entries(all).filter(([, category]) => !category.itemized));
});

// What a new flow starts on: the first ordinary category, not one that holds
// items — that is chosen on purpose.
const firstCategory = () => Object.keys(categories.value).find((key) => !categories.value[key].itemized) ?? Object.keys(categories.value)[0];

const isItemized = computed(() => !isItem.value && Boolean(categories.value[form.category]?.itemized));

watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();

    if (props.flow) {
        Object.keys(form.data()).forEach((key) => {
            form[key] = props.flow[key];
        });

        return;
    }

    form.reset();
    form.direction = props.parent ? 'expense' : props.direction;
    form.holding_id = props.holdingId;
    form.armada_id = props.armadaId;
    form.parent_id = props.parent?.id ?? null;
    form.category = props.category ?? firstCategory();
    if (isItemized.value) form.name = categories.value[form.category].label;
    form.is_essential = categories.value[form.category]?.essential ?? false;
    form.taxation = categories.value[form.category]?.taxation ?? null;
}, { immediate: true });

// Changing direction empties the category list out from under the current
// choice, so move to the first of the new list.
const onDirectionChange = () => {
    form.category = firstCategory();
    // An expense has no tax treatment and an income needs one, whether or
    // not the flow is new.
    form.taxation = categories.value[form.category]?.taxation ?? null;
    onCategoryChange();
};

// A new flow takes its category's usual answers. One being edited keeps what
// was chosen for it: recategorising a salary should not quietly retax it.
const onCategoryChange = () => {
    if (!props.flow) {
        form.is_essential = categories.value[form.category]?.essential ?? false;
        form.taxation = categories.value[form.category]?.taxation ?? null;
    }
};

const isIncome = computed(() => form.direction === 'income');
const amountLabel = computed(() => ({ hourly: 'Hourly rate', once: 'Amount' }[form.frequency] ?? 'Amount each time'));

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (props.flow) {
        form.patch(`/finance/flows/${props.flow.id}`, options);
    } else {
        form.post('/finance/flows', options);
    }
};
</script>

<template>
    <FinDialog ref="dialog" :open="open" :title="flow ? `Edit ${flow.name}` : (parent ? `Add an item to ${parent.name}` : (isIncome ? 'Add income' : 'Add an expense'))" @close="emit('close')">
        <form class="flex flex-col gap-4" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field v-if="!isItem" label="Kind" :error="form.errors.direction">
                    <select v-model="form.direction" @change="onDirectionChange">
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                </Field>
                <Field label="Category" :error="form.errors.category || form.errors.parent_id">
                    <select v-model="form.category" @change="onCategoryChange">
                        <option v-for="(category, key) in categories" :key="key" :value="key">{{ category.label }}</option>
                    </select>
                </Field>
            </div>

            <Field label="Name" :error="form.errors.name">
                <input v-model="form.name" type="text" required maxlength="80" :placeholder="isIncome ? 'e.g. Salary' : 'e.g. Netflix'">
            </Field>

            <p v-if="isItemized && flow?.items_count" class="rounded-xl bg-fin-cream-100 px-4 py-3 text-xs text-fin-grey-600">
                This holds {{ flow.items_count }} {{ flow.items_count === 1 ? 'item' : 'items' }}, so it comes to their sum and the amount, schedule and dates below are ignored.
                Edit the items on the Monthly budget.
            </p>
            <p v-else-if="isItemized" class="rounded-xl bg-fin-cream-100 px-4 py-3 text-xs text-fin-grey-600">
                Enter one estimate for everything the household spends, or leave it rough and itemise it on the Monthly budget — once it has items, it comes to their sum.
            </p>

            <div class="grid gap-4 sm:grid-cols-3">
                <Field label="How often" :error="form.errors.frequency">
                    <select v-model="form.frequency">
                        <option v-for="(frequency, key) in lists.frequencies" :key="key" :value="key">{{ frequency.label }}</option>
                    </select>
                </Field>
                <Field :label="amountLabel" prefix="$" :error="form.errors.amount">
                    <input v-model.number="form.amount" type="number" min="0" step="0.01" required>
                </Field>
                <Field v-if="form.frequency === 'hourly'" label="Hours a week" :error="form.errors.hours_per_week">
                    <input v-model.number="form.hours_per_week" type="number" min="0" max="168" step="0.25">
                </Field>
                <Field v-else label="Yearly change" suffix="% / yr" hint="A raise, or inflation." :error="form.errors.annual_growth_rate">
                    <input v-model.number="form.annual_growth_rate" type="number" step="0.1">
                </Field>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <Field :label="form.frequency === 'once' ? 'On' : 'Starts'" hint="Blank means already running." :error="form.errors.starts_on">
                    <input v-model="form.starts_on" type="date">
                </Field>
                <Field v-if="form.frequency !== 'once'" label="Ends" hint="Blank means open-ended." :error="form.errors.ends_on">
                    <input v-model="form.ends_on" type="date">
                </Field>
            </div>

            <Field v-if="holdings.length && !holdingId && !isItem" label="Belongs to" hint="Hang it off an asset or a debt — rent on a property, its insurance." :error="form.errors.holding_id">
                <select v-model="form.holding_id">
                    <option :value="null">The household</option>
                    <option v-for="holding in holdings" :key="holding.id" :value="holding.id">{{ holding.name }}</option>
                </select>
            </Field>

            <div v-if="!isItem && ((armadas.length && !form.holding_id) || accounts.length)" class="grid gap-4 sm:grid-cols-2">
                <!-- A flow hung off a holding sails with the holding. -->
                <Field v-if="armadas.length && !form.holding_id" label="Armada" hint="The part of the fleet it belongs with." :error="form.errors.armada_id">
                    <select v-model="form.armada_id">
                        <option :value="null">Unassigned</option>
                        <option v-for="armada in armadas" :key="armada.id" :value="armada.id">{{ armada.name }}</option>
                    </select>
                </Field>
                <Field v-if="accounts.length" :label="isIncome ? 'Paid into' : 'Paid from'" :hint="isIncome ? 'The account it lands in, after tax, in the projections.' : 'The account it comes out of in the projections.'" :error="form.errors.account_id">
                    <select v-model="form.account_id">
                        <option :value="null">No account in particular</option>
                        <option v-for="account in accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                </Field>
            </div>

            <div v-if="isIncome" class="grid gap-4 sm:grid-cols-2">
                <Field label="Taxed as" :hint="lists.flow_taxations[form.taxation]?.hint ?? 'No tax is taken from this income.'" :error="form.errors.taxation">
                    <select v-model="form.taxation">
                        <option v-for="(taxation, key) in lists.flow_taxations" :key="key" :value="key">{{ taxation.label }}</option>
                        <option :value="null">Not taxed</option>
                    </select>
                </Field>
                <Field v-if="form.taxation" label="Taxed portion" suffix="%" hint="Usually all of it. Social Security is at most 85." :error="form.errors.taxed_portion">
                    <input v-model.number="form.taxed_portion" type="number" min="0" max="100" step="0.1" required>
                </Field>
            </div>
            <label v-else class="flex items-center gap-2 text-sm text-fin-charcoal">
                <input v-model="form.is_essential" type="checkbox">
                A need rather than a want
            </label>

            <div class="mt-2 flex justify-end gap-2">
                <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ flow ? 'Save changes' : 'Add' }}</button>
            </div>
        </form>
    </FinDialog>
</template>
