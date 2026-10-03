<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import StatTile from '../Components/StatTile.vue';
import ToolNumber from '../Components/ToolNumber.vue';
import { useToolQuery } from '../composables/useToolQuery';
import { chartColors, money, moneyExact, moneyShort, percent } from '../lib/format';

const props = defineProps({
    options: Object,
    inflation_rate: Number,
    has_holdings: Boolean,
    series: Array,
    summary: Object,
    holdings: Array,
    milestones: Array,
});

const params = useToolQuery(props.options);

// The checkbox edits a 0 / 1 query parameter, which is what the server reads.
const real = computed({
    get: () => Number(params.real) === 1,
    set: (checked) => { params.real = checked ? 1 : 0; },
});

const line = (key) => props.series.map((row) => ({ x: row.year, y: row[key] }));

const lines = computed(() => [
    { label: 'Optimistic', color: chartColors[1], dashed: true, points: line('optimistic') },
    { label: 'Expected', color: chartColors[0], area: true, points: line('expected') },
    { label: 'Cautious', color: chartColors[2], dashed: true, points: line('cautious') },
]);

const lastYear = computed(() => props.series[props.series.length - 1]?.year);
</script>

<template>
    <FinShell title="Portfolio projector" subtitle="Your investable accounts, grown forward at their own expected returns — with a cautious and an optimistic line either side, because the expected one never happens exactly.">
        <EmptyState v-if="!has_holdings" title="Nothing to project yet" body="The projector reads savings, brokerage, retirement, HSA and crypto holdings from your fleet.">
            <Link href="/finance/fleet" class="fin-btn fin-btn-primary">Add an account</Link>
        </EmptyState>

        <div v-else class="flex flex-col gap-5">
            <Card title="Assumptions">
                <div class="grid items-start gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <ToolNumber v-model="params.years" label="Years to project" suffix="years" step="1" min="1" max="60" />
                    <ToolNumber v-model="params.extra_monthly" label="Extra saved each month" prefix="$" step="50" min="0" hint="On top of each account's own contribution." />
                    <ToolNumber v-model="params.spread" label="Scenario spread" suffix="± pts" step="0.5" min="0" hint="How far the outer lines sit from expected." />
                    <label class="flex items-center gap-2 text-sm text-fin-charcoal sm:pt-7">
                        <input v-model="real" type="checkbox">
                        In today's dollars ({{ percent(inflation_rate, 1) }} inflation)
                    </label>
                </div>
            </Card>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile label="Today" :value="money(summary.start)" :hint="`${moneyExact(summary.monthly_contribution)} going in each month`" />
                <StatTile feature :label="`Expected in ${lastYear}`" :value="money(summary.end)" :hint="`${moneyShort(summary.growth)} of it is growth`" />
                <StatTile :label="`Range in ${lastYear}`" :value="`${moneyShort(summary.cautious_end)} – ${moneyShort(summary.optimistic_end)}`" :hint="`Returns ${params.spread} points either way`" />
                <StatTile label="You put in" :value="money(summary.contributed)" hint="Contributions over the period" />
            </div>

            <Card title="Projected balance" :subtitle="real ? 'In today\'s dollars.' : 'In future dollars — tick the box above to take inflation out.'">
                <LineChart :series="lines" :height="320" :x-ticks="8" />
            </Card>

            <div class="grid items-start gap-5 xl:grid-cols-3">
                <Card class="xl:col-span-2" title="Account by account" subtitle="Each at its own rate — weighted across its positions, net of fees, where it has any." flush>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                    <th class="px-5 py-2.5 font-medium">Account</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Rate</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Monthly</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Today</th>
                                    <th class="px-5 py-2.5 text-right font-medium">In {{ lastYear }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="holding in holdings" :key="holding.id" class="border-b border-fin-grey-100 last:border-0">
                                    <td class="px-5 py-3">
                                        <Link :href="`/finance/fleet/${holding.id}`" class="font-medium text-fin-black hover:text-fin-green-600 hover:underline">{{ holding.name }}</Link>
                                        <span class="block text-xs text-fin-grey-500">{{ holding.type_label }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-right text-fin-grey-600">{{ percent(holding.rate) }}</td>
                                    <td class="px-3 py-3 text-right text-fin-grey-600">{{ holding.monthly_contribution ? moneyExact(holding.monthly_contribution) : '—' }}</td>
                                    <td class="px-3 py-3 text-right text-fin-charcoal">{{ money(holding.start) }}</td>
                                    <td class="px-5 py-3 text-right font-medium text-fin-black">{{ money(holding.end) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card title="Milestones" subtitle="When the expected line first crosses each.">
                    <p v-if="!milestones.length" class="text-sm text-fin-grey-500">None inside this window. Try more years.</p>
                    <ol v-else class="flex flex-col gap-3">
                        <li v-for="milestone in milestones" :key="milestone.amount" class="flex items-center justify-between gap-3 text-sm">
                            <span class="flex items-center gap-2.5">
                                <span class="h-2 w-2 rounded-full bg-fin-gold-400" />
                                <span class="font-medium text-fin-black">{{ moneyShort(milestone.amount) }}</span>
                            </span>
                            <span class="tabular-nums text-fin-grey-500">{{ milestone.year }} · {{ milestone.years_away }} yr</span>
                        </li>
                    </ol>
                </Card>
            </div>
        </div>
    </FinShell>
</template>
