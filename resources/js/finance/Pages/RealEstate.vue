<script setup>
import { IconPlus, IconX } from '@tabler/icons-vue';
import { computed } from 'vue';
import Card from '../Components/Card.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import ToolNumber from '../Components/ToolNumber.vue';
import { useToolQuery } from '../composables/useToolQuery';
import { chartColors, money, moneyExact, moneySigned, percent } from '../lib/format';

const props = defineProps({
    options: Object,
    properties: Array,
    best: Number,
    max_properties: Number,
});

// The form is the inputs the server echoed back, so a property opened from
// the fleet arrives filled in with what the fleet knows about it.
const params = useToolQuery({
    ...props.options,
    properties: props.properties.map((property) => ({ name: property.name, ...property.inputs })),
});

const add = () => params.properties.push({ name: '', price: 300000, rent: 2200 });
const remove = (index) => params.properties.splice(index, 1);

/*
 * The inputs, grouped as the form prints them. `key` is the query parameter;
 * the server owns the defaults and the limits (RealEstateAnalyzer::FIELDS).
 */
const fields = [
    { title: 'Purchase', items: [
        { key: 'price', label: 'Price', prefix: '$', step: 1000 },
        { key: 'down_pct', label: 'Down payment', suffix: '%', step: 1 },
        { key: 'closing_pct', label: 'Closing costs', suffix: '%', step: 0.5 },
    ] },
    { title: 'Loan', items: [
        { key: 'rate', label: 'Rate', suffix: '% APR', step: 0.125 },
        { key: 'term_years', label: 'Term', suffix: 'years', step: 1 },
    ] },
    { title: 'Income', items: [
        { key: 'rent', label: 'Monthly rent', prefix: '$', step: 50 },
        { key: 'vacancy_pct', label: 'Vacancy', suffix: '%', step: 1 },
        { key: 'rent_growth_pct', label: 'Rent growth', suffix: '% / yr', step: 0.5 },
    ] },
    { title: 'Costs', items: [
        { key: 'tax_annual', label: 'Property tax', prefix: '$', suffix: '/ yr', step: 100 },
        { key: 'insurance_annual', label: 'Insurance', prefix: '$', suffix: '/ yr', step: 100 },
        { key: 'maintenance_pct', label: 'Maintenance', suffix: '% value', step: 0.25 },
        { key: 'management_pct', label: 'Management', suffix: '% rent', step: 1 },
        { key: 'hoa_monthly', label: 'HOA', prefix: '$', suffix: '/ mo', step: 10 },
    ] },
    { title: 'Growth', items: [
        { key: 'appreciation_pct', label: 'Appreciation', suffix: '% / yr', step: 0.5 },
    ] },
];

// The comparison, one row per measure. `higher` says which way is better, so
// the table can mark the winner on each line.
const measures = [
    { key: 'cash_in', label: 'Cash needed to buy', format: money, hint: 'Down payment plus closing costs' },
    { key: 'payment', label: 'Loan payment', format: moneyExact, hint: 'Principal and interest, monthly' },
    { key: 'monthly_cashflow', label: 'Monthly cash flow', format: moneySigned, higher: true, hint: 'Year one, after every cost and the loan' },
    { key: 'cap_rate', label: 'Cap rate', format: percent, higher: true, hint: 'Operating income ÷ price. Ignores the loan.' },
    { key: 'cash_on_cash', label: 'Cash-on-cash return', format: percent, higher: true, hint: 'Year-one cash flow ÷ cash put in' },
    { key: 'dscr', label: 'Debt coverage (DSCR)', format: (value) => (value === null ? '—' : `${value}×`), higher: true, hint: 'Operating income ÷ loan payments. Lenders like 1.25× or more.' },
    { key: 'equity_at_exit', label: 'Equity at exit', format: money, higher: true },
    { key: 'profit', label: 'Total profit', format: moneySigned, higher: true, hint: 'Cash flow plus sale proceeds, less cash put in' },
    { key: 'equity_multiple', label: 'Equity multiple', format: (value) => (value === null ? '—' : `${value}×`), higher: true },
    { key: 'irr', label: 'IRR', format: percent, higher: true, hint: 'The annual return that accounts for when each dollar arrives' },
];

const winner = (measure) => {
    if (!measure.higher || props.properties.length < 2) return null;

    const values = props.properties.map((property) => property[measure.key]);

    return values.every((value) => value === null) ? null : values.indexOf(Math.max(...values.filter((value) => value !== null)));
};

const lines = computed(() => props.properties.map((property, index) => ({
    label: property.name,
    color: chartColors[index],
    points: property.years.map((year) => ({ x: year.year, y: year.equity })),
})));
</script>

<template>
    <FinShell title="Real estate comparator" subtitle="Up to three properties side by side, on the numbers an investor screens with and on what each returns over the years you hold it.">
        <template #actions>
            <button v-if="params.properties.length < max_properties" type="button" class="fin-btn fin-btn-primary" @click="add"><IconPlus :size="16" /> Property</button>
        </template>

        <div class="flex flex-col gap-5">
            <Card title="Holding period">
                <div class="grid max-w-md gap-4 sm:grid-cols-2">
                    <ToolNumber v-model="params.hold_years" label="Years held" suffix="years" step="1" min="1" max="40" />
                    <ToolNumber v-model="params.selling_pct" label="Selling costs" suffix="%" step="0.5" />
                </div>
            </Card>

            <div class="grid items-start gap-5" :class="params.properties.length > 2 ? 'xl:grid-cols-3' : 'lg:grid-cols-2'">
                <Card v-for="(property, index) in params.properties" :key="index">
                    <div class="mb-4 flex items-center gap-2">
                        <span class="h-3 w-3 shrink-0 rounded-sm" :style="{ backgroundColor: chartColors[index] }" />
                        <input v-model="property.name" type="text" maxlength="60" class="font-semibold" :aria-label="`Name of property ${index + 1}`" :placeholder="`Property ${index + 1}`">
                        <button v-if="params.properties.length > 1" type="button" class="fin-icon-btn shrink-0" :aria-label="`Remove property ${index + 1}`" @click="remove(index)"><IconX :size="16" /></button>
                    </div>

                    <div class="flex flex-col gap-4">
                        <fieldset v-for="group in fields" :key="group.title">
                            <legend class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">{{ group.title }}</legend>
                            <div class="grid grid-cols-2 gap-3">
                                <ToolNumber
                                    v-for="field in group.items" :key="field.key"
                                    v-model="property[field.key]" :label="field.label" :prefix="field.prefix" :suffix="field.suffix" :step="field.step"
                                />
                            </div>
                        </fieldset>
                    </div>
                </Card>
            </div>

            <Card title="Side by side" :subtitle="`Over ${options.hold_years} years. The best figure on each line is marked.`" flush>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-xs text-fin-grey-500">
                                <th class="px-5 py-2.5 text-left font-medium">Measure</th>
                                <th v-for="(property, index) in properties" :key="index" class="px-5 py-2.5 text-right font-medium">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: chartColors[index] }" />
                                        {{ property.name }}
                                        <span v-if="index === best && properties.length > 1" class="rounded-full bg-fin-gold-100 px-2 py-0.5 text-[11px] font-medium text-fin-gold-600">Best IRR</span>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="measure in measures" :key="measure.key" class="border-b border-fin-grey-100 last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-fin-black">{{ measure.label }}</span>
                                    <span v-if="measure.hint" class="block text-xs text-fin-grey-500">{{ measure.hint }}</span>
                                </td>
                                <td
                                    v-for="(property, index) in properties" :key="index"
                                    class="px-5 py-3 text-right"
                                    :class="winner(measure) === index ? 'bg-fin-green-50 font-semibold text-fin-green-700' : 'text-fin-charcoal'"
                                >
                                    {{ measure.format(property[measure.key]) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card title="Equity over time" subtitle="Value less what is still owed, at each year-end.">
                <LineChart :series="lines" :format-x="(year) => `Yr ${year}`" :x-ticks="10" />
            </Card>
        </div>
    </FinShell>
</template>
