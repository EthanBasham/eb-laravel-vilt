<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';
import { money } from '../lib/format';

/**
 * Build or edit one conversion strategy.
 *
 * Most fields may be left blank, and a blank is a real answer — "follow the
 * projection", "use the usual window" — so each shows what it falls back to
 * as its placeholder rather than making the fallback up as a value.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    strategy: { type: Object, default: null },
    // Config's kinds of strategy, and the brackets one can fill to.
    kinds: { type: Object, required: true },
    fillRates: { type: Array, required: true },
    // Config's ways of paying a conversion's tax.
    taxPayments: { type: Object, required: true },
    // The ages each kind runs at when its own are blank: { even: [68, 72] }.
    defaults: { type: Object, required: true },
    scenarios: { type: Array, required: true },
    profile: { type: Object, required: true },
    growthRate: { type: Number, required: true },
    // What a new strategy's heir is taken to earn.
    heirIncome: { type: Number, required: true },
    // What a fixed-amount strategy converts a year until given its own.
    conversionAmount: { type: Number, required: true },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

const blank = {
    name: '',
    kind: 'fill_bracket',
    scenario_id: null,
    convert_from_age: null,
    convert_until_age: null,
    fill_rate: 22,
    conversion_amount: null,
    tax_payment: 'outside',
    tax_outside_amount: null,
    inflation_rate: null,
    growth_rate: null,
    heir_is_charity: false,
    heir_income: props.heirIncome,
};

const form = useForm({ ...blank });

// An emptied number field is '', which is this form's way of saying "blank".
form.transform((data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])));

watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();

    Object.keys(blank).forEach((key) => {
        form[key] = props.strategy ? props.strategy[key] : blank[key];
    });

    if (!props.strategy) {
        form.name = props.kinds[form.kind].label;
    }
}, { immediate: true });

const kind = computed(() => props.kinds[form.kind]);
const payment = computed(() => props.taxPayments[form.tax_payment]);
const ages = computed(() => props.defaults[form.kind] ?? []);

// A new strategy is named after its kind until it is given a name of its own.
const onKindChange = () => {
    if (!props.strategy && Object.values(props.kinds).some((candidate) => candidate.label === form.name)) {
        form.name = kind.value.label;
    }

    // Staying inside the tier you are in pairs with staying inside the
    // bracket you are in, so that is where the IRMAA-aware kind starts.
    if (!props.strategy && form.kind === 'fill_bracket_irmaa') {
        form.fill_rate = null;
    }

    if (kind.value.amount && !form.conversion_amount) {
        form.conversion_amount = props.conversionAmount;
    }
};

const projectionRate = computed(() => props.scenarios.find((scenario) => scenario.id === form.scenario_id)?.bracket_inflation_rate ?? props.profile.inflation_rate);

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (props.strategy) {
        form.patch(`/finance/retirement/strategies/${props.strategy.id}`, options);
    } else {
        form.post('/finance/retirement/strategies', options);
    }
};
</script>

<template>
    <FinDialog ref="dialog" :open="open" :title="strategy ? `Edit ${strategy.name}` : 'Build a strategy'" wide @close="emit('close')">
        <form class="flex flex-col gap-5" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="Strategy" :hint="kind.description" :error="form.errors.kind">
                    <select v-model="form.kind" @change="onKindChange">
                        <option v-for="(option, key) in kinds" :key="key" :value="key">{{ option.label }}</option>
                    </select>
                </Field>
                <Field label="Name" :error="form.errors.name">
                    <input v-model="form.name" type="text" required maxlength="80">
                </Field>
            </div>

            <div v-if="kind.ages" class="grid gap-4 sm:grid-cols-3">
                <Field :label="kind.ages === 'at' ? 'Convert at age' : 'From age'" :hint="`Blank is ${ages[0]}.`" :error="form.errors.convert_from_age">
                    <input v-model.number="form.convert_from_age" type="number" min="18" max="110" step="1" :placeholder="String(ages[0])">
                </Field>
                <Field v-if="kind.ages === 'window'" label="Through age" :hint="`Blank is ${ages[1]}. RMDs begin at ${profile.rmd_start_age}.`" :error="form.errors.convert_until_age">
                    <input v-model.number="form.convert_until_age" type="number" min="18" max="110" step="1" :placeholder="String(ages[1])">
                </Field>
                <Field v-if="kind.amount" label="Convert each year" prefix="$" hint="In today's dollars. Once less than this is left, the rest is converted." :error="form.errors.conversion_amount">
                    <input v-model.number="form.conversion_amount" type="number" min="1" step="1" required>
                </Field>
                <Field v-if="kind.fills" label="Fill to the top of" :error="form.errors.fill_rate">
                    <select v-model="form.fill_rate">
                        <option :value="null">Whichever bracket I am in</option>
                        <option v-for="rate in fillRates" :key="rate" :value="rate">The {{ rate }}% bracket</option>
                    </select>
                </Field>
            </div>

            <div v-if="kind.ages" class="grid items-start gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <Field label="Pay the conversion's tax" hint="From spare income, a year converts only what its income left after expenses and tax can pay the tax on, and savings are never touched. Taken from the converted money, there is no cap but less reaches the Roth." :error="form.errors.tax_payment">
                        <select v-model="form.tax_payment">
                            <option v-for="(payment, key) in taxPayments" :key="key" :value="key">{{ payment.label }}</option>
                        </select>
                    </Field>
                </div>
                <Field
                    v-if="payment.amount" label="From outside"
                    :prefix="payment.amount === 'dollars' ? '$' : ''" :suffix="payment.amount === 'dollars' ? '/ yr' : '%'"
                    :hint="payment.amount === 'dollars' ? 'Of each year\'s conversion tax, in today\'s dollars.' : 'Of each year\'s conversion tax.'"
                    :error="form.errors.tax_outside_amount"
                >
                    <input v-model.number="form.tax_outside_amount" type="number" min="0" :max="payment.amount === 'percent' ? 100 : null" :step="payment.amount === 'dollars' ? 100 : 1" required>
                </Field>
            </div>

            <fieldset>
                <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Run it on</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <Field label="Projection" hint="From Projections & scenarios." :error="form.errors.scenario_id">
                        <select v-model="form.scenario_id">
                            <option :value="null">Income &amp; expenses as entered</option>
                            <option v-for="scenario in scenarios" :key="scenario.id" :value="scenario.id">{{ scenario.name }}</option>
                        </select>
                    </Field>
                    <Field label="Inflation" suffix="% / yr" :hint="`Prices, tax brackets and IRMAA tiers. Blank follows the projection (${projectionRate}%).`" :error="form.errors.inflation_rate">
                        <input v-model.number="form.inflation_rate" type="number" min="-5" max="15" step="0.1" :placeholder="String(projectionRate)">
                    </Field>
                    <Field label="Growth" suffix="% / yr" :hint="`Every account, before inflation. Blank lets each grow at its own holdings' rate (${growthRate}% overall).`" :error="form.errors.growth_rate">
                        <input v-model.number="form.growth_rate" type="number" min="-10" max="20" step="0.1" :placeholder="String(growthRate)">
                    </Field>
                </div>
            </fieldset>

            <fieldset>
                <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Who inherits what is left</legend>
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <label class="flex items-start gap-2 text-sm text-fin-charcoal sm:pt-6">
                        <input v-model="form.heir_is_charity" type="checkbox" class="mt-0.5">
                        <span>A charity<span class="block text-xs text-fin-grey-500">It pays no tax on traditional money, so converting for its sake is rarely worth it.</span></span>
                    </label>
                    <Field v-if="!form.heir_is_charity" label="Heir's own income" prefix="$" :hint="`A year, before the inheritance. They draw it over ten years on top of this${form.heir_income ? ` — ${money(form.heir_income)}` : ''}, as a single filer.`" :error="form.errors.heir_income">
                        <input v-model.number="form.heir_income" type="number" min="0" step="1000">
                    </Field>
                </div>
            </fieldset>

            <div class="mt-1 flex justify-end gap-2">
                <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ strategy ? 'Save changes' : 'Add strategy' }}</button>
            </div>
        </form>
    </FinDialog>
</template>
