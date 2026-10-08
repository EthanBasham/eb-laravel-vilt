<script setup>
import { useForm, useHttp } from '@inertiajs/vue3';
import { IconX } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';
import { money } from '../lib/format';
import { numberOrNull } from '../lib/input';

/**
 * Build or edit one conversion strategy.
 *
 * Most fields may be left blank, and a blank is a real answer — "follow the
 * projection", "use the usual window" — so each shows what it falls back to
 * as its placeholder rather than making the fallback up as a value.
 *
 * A strategy being edited can also be customised: the settings give way to
 * a table of every year of the plan, where a year's conversion, and how much
 * of its tax comes out of the converted money, can be set by hand. The
 * settings still decide every year left alone. The table is filled by asking
 * the server what the settings as they stand would do, each time a year is
 * changed, so the years after it follow.
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
    // The years set by hand: { 2031: { conversion: 50000, withheld: null } }.
    overrides: {},
    inflation_rate: null,
    growth_rate: null,
    heir_is_charity: false,
    heir_income: props.heirIncome,
};

const form = useForm({ ...blank });

// An emptied number field is '', which is this form's way of saying "blank".
const sent = (data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value]));

form.transform(sent);

// Whether the table of years has taken the settings' place, and its rows:
// what the settings as they stand convert each year, in today's dollars.
const customizing = ref(false);
const years = ref([]);
const preview = useHttp({});

preview.transform(() => sent(form.data()));

const customized = computed(() => Object.keys(form.overrides).length);

// A request overtaken by a newer one is cancelled, and a refusal is shown
// from `preview.errors`; neither has rows to take.
const refresh = () => {
    preview.cancel();
    preview.post('/finance/retirement/strategies/preview').then((response) => { years.value = response.rows; }).catch(() => {});
};

const customize = () => {
    customizing.value = true;
    refresh();
};

// Sets one figure of one year, or hands it back to the strategy when the
// field was emptied. A year with neither figure set is no longer set by hand.
const pin = (year, field, input) => {
    const row = { conversion: null, withheld: null, ...form.overrides[year], [field]: numberOrNull(input) };

    if (row.conversion === null && row.withheld === null) {
        delete form.overrides[year];
    } else {
        form.overrides[year] = row;
    }

    refresh();
};

const unpin = (year) => {
    delete form.overrides[year];
    refresh();
};

// What the server refused about the years, whichever year it was.
const overridesError = computed(() => Object.entries(form.errors).find(([key]) => key.startsWith('overrides'))?.[1]);

const unpinAll = () => {
    form.overrides = {};
    refresh();
};

watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();
    preview.clearErrors();
    customizing.value = false;
    years.value = [];

    Object.keys(blank).forEach((key) => {
        form[key] = props.strategy ? props.strategy[key] : blank[key];
    });

    // A copy, so that a year set here and then cancelled is not left on the
    // page's own strategy.
    form.overrides = Object.fromEntries(Object.entries(props.strategy?.overrides ?? {}).map(([year, row]) => [year, { ...row }]));

}, { immediate: true });

const kind = computed(() => props.kinds[form.kind]);
const payment = computed(() => props.taxPayments[form.tax_payment]);
const ages = computed(() => props.defaults[form.kind] ?? []);

const onKindChange = () => {
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

// What an unnamed strategy is shown as: its projection and its kind.
const unnamed = computed(() => `${props.scenarios.find((scenario) => scenario.id === form.scenario_id)?.name ?? 'As entered'} · ${kind.value.label}${customized.value ? ' [C]' : ''}`);

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
    <FinDialog ref="dialog" :open="open" :title="strategy ? `Edit ${strategy.label}` : 'Build a strategy'" wide @close="emit('close')">
        <form class="flex flex-col gap-5" @submit.prevent="save" @invalid.capture="customizing = false">
            <!-- Only a saved strategy: there is nothing to customise until it has been built. -->
            <div v-if="strategy" class="flex items-center justify-between gap-3 rounded-xl bg-fin-cream-100 px-4 py-2.5">
                <p class="text-xs text-fin-grey-600">
                    <template v-if="customizing">The settings still decide every year you leave blank.</template>
                    <template v-else-if="customized">{{ customized }} {{ customized === 1 ? 'year is' : 'years are' }} set by hand.</template>
                    <template v-else>Set a year's conversion, or how its tax is paid, by hand.</template>
                </p>
                <button type="button" class="fin-btn fin-btn-quiet shrink-0" @click="customizing ? customizing = false : customize()">{{ customizing ? 'Back to settings' : 'Customize' }}</button>
            </div>

            <div v-if="customizing" class="flex flex-col gap-3">
                <p v-if="preview.hasErrors || overridesError" class="text-xs text-fin-red-600">
                    {{ overridesError || 'These settings cannot be run as they stand. Go back to the settings and correct them.' }}
                </p>

                <div class="max-h-[26rem] overflow-auto rounded-xl border border-fin-grey-200" :class="{ 'opacity-60': preview.processing }" :aria-busy="preview.processing">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 z-10">
                            <tr class="bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-3 py-2.5 font-medium">Year</th>
                                <th class="px-2 py-2.5 font-medium">Age</th>
                                <th class="px-2 py-2.5 font-medium">Convert</th>
                                <th class="px-2 py-2.5 text-right font-medium" title="What the conversion adds to the year's tax">Tax on it</th>
                                <th class="px-2 py-2.5 font-medium" title="Taken out of the converted money before it reaches the Roth">Paid from the conversion</th>
                                <th class="px-2 py-2.5 text-right font-medium" title="The rest of the tax: found from the year's spare income first, then from taxable savings">Paid from taxable savings</th>
                                <th class="w-8 px-1 py-2.5"><span class="sr-only">Hand back to the strategy</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in years" :key="row.year" class="border-t border-fin-grey-100" :class="{ 'bg-fin-cream-50': form.overrides[row.year] }">
                                <td class="px-3 py-1.5 tabular-nums text-fin-charcoal">{{ row.year }}</td>
                                <td class="px-2 py-1.5 tabular-nums text-fin-grey-500">{{ row.age }}</td>
                                <td class="px-2 py-1.5">
                                    <input
                                        type="number" min="0" step="1" class="!w-28 !py-1 text-right tabular-nums" :aria-label="`Convert in ${row.year}`"
                                        :value="form.overrides[row.year]?.conversion ?? ''" :placeholder="String(row.conversion)" @change="pin(row.year, 'conversion', $event)"
                                    >
                                </td>
                                <td class="px-2 py-1.5 text-right tabular-nums text-fin-grey-600">{{ row.conversion_tax ? money(row.conversion_tax) : '—' }}</td>
                                <td class="px-2 py-1.5">
                                    <input
                                        type="number" min="0" step="1" class="!w-28 !py-1 text-right tabular-nums" :aria-label="`Tax paid from the conversion in ${row.year}`"
                                        :value="form.overrides[row.year]?.withheld ?? ''" :placeholder="String(row.conversion_tax_withheld)" @change="pin(row.year, 'withheld', $event)"
                                    >
                                </td>
                                <td class="px-2 py-1.5 text-right tabular-nums text-fin-grey-600">{{ row.conversion_tax ? money(row.conversion_tax - row.conversion_tax_withheld) : '—' }}</td>
                                <td class="px-1 py-1.5">
                                    <button v-if="form.overrides[row.year]" type="button" class="fin-icon-btn" :aria-label="`Hand ${row.year} back to the strategy`" title="Hand back to the strategy" @click="unpin(row.year)"><IconX :size="14" /></button>
                                </td>
                            </tr>
                            <tr v-if="!years.length">
                                <td colspan="7" class="px-3 py-6 text-center text-xs text-fin-grey-500">{{ preview.processing ? 'Working the years out…' : 'No years to show.' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-start justify-between gap-3">
                    <p class="text-xs text-fin-grey-500">In today's dollars. A blank field follows the strategy and shows what it comes to; an amount typed in sets that year by hand, whatever its age range. The years after it are worked out again each time.</p>
                    <button v-if="customized" type="button" class="fin-btn fin-btn-quiet shrink-0" @click="unpinAll">Clear all</button>
                </div>
            </div>

            <!--
                Hidden rather than removed while the table is up, so the browser still checks the fields on save. It
                cannot point at a field it refuses while that field is hidden, so `invalid` on the form brings these back.
            -->
            <div v-show="!customizing" class="flex flex-col gap-5">
                <div class="grid items-start gap-4 sm:grid-cols-[3fr_2fr]">
                    <Field label="Strategy" :hint="kind.description" :error="form.errors.kind">
                        <select v-model="form.kind" @change="onKindChange">
                            <option v-for="(option, key) in kinds" :key="key" :value="key">{{ option.label }}</option>
                        </select>
                    </Field>
                    <!-- What only this kind of strategy asks for. -->
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

                <!-- The ages take 70% of the strategy's column above; the name has the rest. -->
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <Field v-if="kind.ages === 'at'" class="sm:w-[42%] sm:shrink-0" label="Convert at age" :hint="`Blank is ${ages[0]}.`" :error="form.errors.convert_from_age">
                        <input v-model.number="form.convert_from_age" type="number" min="18" max="110" step="1" :placeholder="String(ages[0])">
                    </Field>
                    <!-- Two inputs under one label, so a group rather than a Field. -->
                    <div v-if="kind.ages === 'window'" class="sm:w-[42%] sm:shrink-0" role="group" aria-label="Age range">
                        <span class="mb-1 block text-xs font-medium text-fin-grey-600">Age range</span>
                        <div class="flex items-center gap-2">
                            <input v-model.number="form.convert_from_age" type="number" min="18" max="110" step="1" class="min-w-0 flex-1" aria-label="From age" :placeholder="String(ages[0])">
                            <span class="text-sm text-fin-grey-500">to</span>
                            <input v-model.number="form.convert_until_age" type="number" min="18" max="110" step="1" class="min-w-0 flex-1" aria-label="Through age" :placeholder="String(ages[1])">
                        </div>
                        <span v-if="form.errors.convert_from_age || form.errors.convert_until_age" class="mt-1 block text-xs text-fin-red-600">{{ form.errors.convert_from_age || form.errors.convert_until_age }}</span>
                        <span v-else class="mt-1 block text-xs text-fin-grey-500">Blank is {{ ages[0] }} to {{ ages[1] }}. RMDs begin at {{ profile.rmd_start_age }}.</span>
                    </div>
                    <Field class="min-w-0 sm:flex-1" label="Name" :error="form.errors.name">
                        <input v-model="form.name" type="text" maxlength="80" :placeholder="unnamed">
                    </Field>
                </div>

                <div v-if="kind.ages" class="grid items-start gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <Field label="Pay the conversion's tax" hint="Spare income caps each conversion at what it can pay the tax on. Converted money has no cap, but less reaches the Roth." :error="form.errors.tax_payment">
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
                        <Field label="Projection" :error="form.errors.scenario_id">
                            <select v-model="form.scenario_id">
                                <option :value="null">No projection</option>
                                <option v-for="scenario in scenarios" :key="scenario.id" :value="scenario.id">{{ scenario.name }}</option>
                            </select>
                        </Field>
                        <Field label="Inflation" suffix="% / yr" hint="Adjusts brackets and IRMAA tiers" :error="form.errors.inflation_rate">
                            <input v-model.number="form.inflation_rate" type="number" min="-5" max="15" step="0.1" :placeholder="String(projectionRate)">
                        </Field>
                        <Field label="Growth" suffix="% / yr" hint="Override projected rates" :error="form.errors.growth_rate">
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
            </div>

            <div class="mt-1 flex justify-end gap-2">
                <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ strategy ? 'Save changes' : 'Add strategy' }}</button>
            </div>
        </form>
    </FinDialog>
</template>
