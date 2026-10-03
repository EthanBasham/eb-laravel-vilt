<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '../Components/Card.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import StatTile from '../Components/StatTile.vue';
import ToolNumber from '../Components/ToolNumber.vue';
import { useToolQuery } from '../composables/useToolQuery';
import { chartColors, duration, money, moneyExact } from '../lib/format';

/**
 * The simple tools, one per tab. Each tab is its own URL
 * (/finance/calculators/mortgage, …) and a plain Inertia visit, so switching
 * tabs builds this component afresh and the form below starts from that
 * tool's own inputs.
 */
const props = defineProps({
    tool: String,
    tools: Object,
    result: Object,
});

const params = useToolQuery(props.result.inputs);

const yearLabel = (year) => `Yr ${year}`;

// One chart per tool, each drawn from the year rows the server sent.
const lines = computed(() => {
    const years = props.result.years ?? [];

    if (props.tool === 'mortgage') {
        return [{ label: 'Balance', color: chartColors[1], area: true, points: years.map((row) => ({ x: row.year, y: row.balance })) }];
    }

    if (props.tool === 'payoff') {
        return [
            { label: 'Current payment', color: chartColors[4], dashed: true, points: props.result.standard.years.map((row) => ({ x: row.year, y: row.balance })) },
            { label: 'With the extra', color: chartColors[0], points: props.result.accelerated.years.map((row) => ({ x: row.year, y: row.balance })) },
        ];
    }

    return [
        { label: 'Balance', color: chartColors[0], area: true, points: years.map((row) => ({ x: row.year, y: row.balance })) },
        { label: 'Paid in', color: chartColors[1], dashed: true, points: years.map((row) => ({ x: row.year, y: row.contributed })) },
    ];
});
</script>

<template>
    <FinShell title="Calculators" subtitle="The everyday ones, so there is no reason to go anywhere else. They read nothing from your fleet — just the form.">
        <nav class="mb-5 flex flex-wrap gap-1 rounded-xl bg-fin-cream-200/70 p-1 sm:w-fit" aria-label="Calculators">
            <Link
                v-for="(title, slug) in tools" :key="slug" :href="`/finance/calculators/${slug}`"
                class="rounded-lg px-3.5 py-1.5 text-sm font-medium transition-colors"
                :class="slug === tool ? 'bg-fin-white text-fin-black shadow-sm' : 'text-fin-grey-600 hover:text-fin-black'"
                :aria-current="slug === tool ? 'page' : undefined"
            >
                {{ title }}
            </Link>
        </nav>

        <div class="grid items-start gap-5 xl:grid-cols-3">
            <Card :title="tools[tool]">
                <div v-if="tool === 'mortgage'" class="grid grid-cols-2 gap-4">
                    <ToolNumber v-model="params.price" label="Home price" prefix="$" step="1000" />
                    <ToolNumber v-model="params.down_pct" label="Down payment" suffix="%" step="1" />
                    <ToolNumber v-model="params.rate" label="Rate" suffix="% APR" step="0.125" />
                    <ToolNumber v-model="params.term_years" label="Term" suffix="years" step="1" />
                    <ToolNumber v-model="params.tax_annual" label="Property tax" prefix="$" suffix="/ yr" step="100" />
                    <ToolNumber v-model="params.insurance_annual" label="Insurance" prefix="$" suffix="/ yr" step="100" />
                    <ToolNumber v-model="params.hoa_monthly" label="HOA" prefix="$" suffix="/ mo" step="10" />
                    <ToolNumber v-model="params.extra_monthly" label="Extra payment" prefix="$" suffix="/ mo" step="50" />
                </div>

                <div v-else-if="tool === 'compound'" class="grid grid-cols-2 gap-4">
                    <ToolNumber v-model="params.principal" label="Starting amount" prefix="$" step="500" />
                    <ToolNumber v-model="params.monthly" label="Added each month" prefix="$" step="50" />
                    <ToolNumber v-model="params.rate" label="Annual return" suffix="% / yr" step="0.25" />
                    <ToolNumber v-model="params.years" label="For" suffix="years" step="1" />
                    <ToolNumber v-model="params.inflation" label="Inflation" suffix="% / yr" step="0.25" hint="Above zero shows today's dollars." />
                </div>

                <div v-else-if="tool === 'payoff'" class="grid grid-cols-2 gap-4">
                    <ToolNumber v-model="params.balance" label="Balance" prefix="$" step="100" />
                    <ToolNumber v-model="params.rate" label="Rate" suffix="% APR" step="0.25" />
                    <ToolNumber v-model="params.payment" label="Monthly payment" prefix="$" step="25" />
                    <ToolNumber v-model="params.extra_monthly" label="Extra each month" prefix="$" step="25" />
                </div>

                <div v-else class="grid grid-cols-2 gap-4">
                    <ToolNumber v-model="params.target" label="Target" prefix="$" step="500" />
                    <ToolNumber v-model="params.present" label="Already saved" prefix="$" step="500" />
                    <ToolNumber v-model="params.rate" label="Annual return" suffix="% / yr" step="0.25" />
                    <ToolNumber v-model="params.years" label="In" suffix="years" step="1" />
                </div>
            </Card>

            <div class="flex flex-col gap-5 xl:col-span-2">
                <div v-if="tool === 'mortgage'" class="grid gap-4 sm:grid-cols-3">
                    <StatTile feature label="Monthly payment" :value="moneyExact(result.monthly_total)" :hint="`${moneyExact(result.payment)} principal & interest`" />
                    <StatTile label="Loan amount" :value="money(result.loan)" :hint="result.pmi ? `Plus about ${moneyExact(result.pmi)}/mo PMI (estimate)` : 'No PMI at 20% down'" />
                    <StatTile label="Total interest" :value="money(result.total_interest)" :hint="`Over ${duration(result.months)}`" />
                    <StatTile
                        v-if="result.accelerated.months_saved > 0" class="sm:col-span-3" tone="good"
                        label="With the extra payment" :value="`Paid off ${duration(result.accelerated.months_saved)} sooner`"
                        :hint="`Saving ${money(result.accelerated.interest_saved)} in interest`"
                    />
                </div>

                <div v-else-if="tool === 'compound'" class="grid gap-4 sm:grid-cols-3">
                    <StatTile feature label="Ends at" :value="money(result.balance)" :hint="result.inputs.inflation > 0 ? 'In today\'s dollars' : ''" />
                    <StatTile label="You put in" :value="money(result.contributed)" />
                    <StatTile label="Growth" :value="money(result.growth)" tone="good" hint="What compounding added" />
                </div>

                <div v-else-if="tool === 'payoff'" class="grid gap-4 sm:grid-cols-3">
                    <StatTile
                        feature label="Paid off in"
                        :value="result.accelerated.paid_off ? duration(result.accelerated.months) : 'Never'"
                        :hint="result.accelerated.paid_off ? `${money(result.accelerated.total_interest)} in interest` : `The payment does not cover ${moneyExact(result.monthly_interest)} of monthly interest`"
                    />
                    <StatTile
                        label="At the current payment"
                        :value="result.standard.paid_off ? duration(result.standard.months) : 'Never'"
                        :hint="result.standard.paid_off ? `${money(result.standard.total_interest)} in interest` : `Interest alone is ${moneyExact(result.monthly_interest)} a month`"
                    />
                    <StatTile
                        label="The extra saves" tone="good"
                        :value="result.interest_saved === null ? '—' : money(result.interest_saved)"
                        :hint="result.months_saved === null ? '' : `and ${duration(result.months_saved)}`"
                    />
                </div>

                <div v-else class="grid gap-4 sm:grid-cols-3">
                    <StatTile feature label="Save each month" :value="moneyExact(result.monthly)" />
                    <StatTile label="You put in" :value="money(result.contributed)" />
                    <StatTile label="Growth does the rest" :value="money(result.growth)" tone="good" />
                </div>

                <Card title="Year by year">
                    <LineChart :series="lines" :format-x="yearLabel" :x-ticks="10" />
                </Card>
            </div>
        </div>
    </FinShell>
</template>
