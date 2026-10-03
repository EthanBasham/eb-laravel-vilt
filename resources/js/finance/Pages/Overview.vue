<script setup>
import { Link, router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCircleCheck, IconInfoCircle, IconPlus, IconSparkles } from '@tabler/icons-vue';
import { computed } from 'vue';
import BarList from '../Components/BarList.vue';
import Card from '../Components/Card.vue';
import DonutChart from '../Components/DonutChart.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import StatTile from '../Components/StatTile.vue';
import { asDate, chartColors, money, moneyShort, moneySigned, percent } from '../lib/format';

const props = defineProps({
    is_empty: Boolean,
    totals: Object,
    cashflow: Object,
    allocation: Array,
    debts: Array,
    projection: Array,
    top_holdings: Array,
    goals: Array,
    last_snapshot: Object,
    insights: Array,
});

const loadSample = () => router.post('/finance/sample');

// Three lines on one dollar axis: what is owned, what is owed, and the gap.
const lines = computed(() => [
    { label: 'Net worth', color: chartColors[0], area: true, points: props.projection.map((row) => ({ x: row.year, y: row.net_worth })) },
    { label: 'Assets', color: chartColors[1], dashed: true, points: props.projection.map((row) => ({ x: row.year, y: row.assets })) },
    { label: 'Liabilities', color: chartColors[4], dashed: true, points: props.projection.map((row) => ({ x: row.year, y: row.liabilities })) },
]);

const horizon = computed(() => props.projection[props.projection.length - 1]);

const tones = {
    good: { icon: IconCircleCheck, class: 'bg-fin-green-100 text-fin-green-700' },
    warn: { icon: IconAlertTriangle, class: 'bg-fin-gold-100 text-fin-gold-600' },
    info: { icon: IconInfoCircle, class: 'bg-fin-navy-100 text-fin-navy-700' },
};
</script>

<template>
    <FinShell title="Overview" subtitle="Your whole financial fleet on one screen: what you own, what you owe, and where it is heading.">
        <template #actions>
            <Link href="/finance/fleet" class="fin-btn fin-btn-primary"><IconPlus :size="16" /> Add to fleet</Link>
        </template>

        <EmptyState
            v-if="is_empty"
            title="Your fleet is empty"
            body="Add your accounts, property and debts to build a picture — or load a sample household first to see what every tool does with one."
        >
            <button type="button" class="fin-btn fin-btn-primary" @click="loadSample"><IconSparkles :size="16" /> Load the sample fleet</button>
            <Link href="/finance/fleet" class="fin-btn fin-btn-quiet">Start with my own</Link>
        </EmptyState>

        <div v-else class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    feature label="Net worth" :value="money(totals.net_worth)"
                    :hint="last_snapshot ? `${moneySigned(last_snapshot.change)} since ${asDate(last_snapshot.taken_on)}` : 'Take a snapshot to track it over time'"
                />
                <StatTile label="Assets" :value="money(totals.assets)" />
                <StatTile label="Liabilities" :value="money(totals.liabilities)" />
                <StatTile
                    label="Monthly cash flow" :value="moneySigned(cashflow.net)"
                    :hint="`${percent(cashflow.savings_rate, 1)} of ${money(cashflow.income)} income kept`"
                    :tone="cashflow.net >= 0 ? 'good' : 'bad'"
                />
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <Card class="xl:col-span-2" title="Where it is heading" :subtitle="`If every balance keeps its rate and its monthly amount, ${moneyShort(horizon.net_worth)} by ${horizon.year}.`">
                    <LineChart :series="lines" />
                </Card>

                <Card title="What you own" subtitle="Assets by kind.">
                    <DonutChart :items="allocation" center-label="Assets" />
                </Card>
            </div>

            <div class="grid gap-5 xl:grid-cols-3">
                <Card title="Worth knowing" subtitle="Read off your own numbers. Facts, not advice.">
                    <ul class="flex flex-col gap-3">
                        <li v-for="insight in insights" :key="insight.title" class="flex gap-3">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg" :class="tones[insight.tone].class">
                                <component :is="tones[insight.tone].icon" :size="17" />
                            </span>
                            <div class="min-w-0">
                                <component :is="insight.href ? Link : 'p'" :href="insight.href" class="text-sm font-medium text-fin-black" :class="insight.href ? 'hover:text-fin-green-600 hover:underline' : ''">
                                    {{ insight.title }}
                                </component>
                                <p class="text-xs text-fin-grey-500">{{ insight.body }}</p>
                            </div>
                        </li>
                    </ul>
                </Card>

                <Card title="Largest holdings" flush>
                    <template #actions><Link href="/finance/fleet" class="text-xs font-medium text-fin-green-600 hover:underline">All of them</Link></template>

                    <ul>
                        <li v-for="holding in top_holdings" :key="holding.id" class="border-t border-fin-grey-100">
                            <Link :href="`/finance/fleet/${holding.id}`" class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm hover:bg-fin-cream-50">
                                <span class="min-w-0">
                                    <span class="block truncate font-medium text-fin-black">{{ holding.name }}</span>
                                    <span class="text-xs text-fin-grey-500">{{ holding.type_label }}</span>
                                </span>
                                <span class="tabular-nums" :class="holding.side === 'liability' ? 'text-fin-red-600' : 'text-fin-black'">
                                    {{ holding.side === 'liability' ? '−' : '' }}{{ money(holding.value) }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                </Card>

                <div class="flex flex-col gap-5">
                    <Card v-if="debts.length" title="What you owe">
                        <BarList :items="debts" color="var(--color-fin-chart-5)" />
                    </Card>

                    <Card title="Savings goals">
                        <template #actions><Link href="/finance/goals" class="text-xs font-medium text-fin-green-600 hover:underline">Plans</Link></template>

                        <p v-if="!goals.length" class="text-sm text-fin-grey-500">No goals yet.</p>
                        <ul v-else class="flex flex-col gap-3.5">
                            <li v-for="goal in goals" :key="goal.id">
                                <div class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="truncate font-medium text-fin-black">{{ goal.name }}</span>
                                    <span class="whitespace-nowrap text-xs tabular-nums text-fin-grey-500">{{ money(goal.saved_amount) }} of {{ money(goal.target_amount) }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 rounded-full bg-fin-gold-100">
                                    <div class="h-full rounded-full bg-fin-gold-400" :style="{ width: `${goal.progress}%` }" />
                                </div>
                            </li>
                        </ul>
                    </Card>
                </div>
            </div>
        </div>
    </FinShell>
</template>
