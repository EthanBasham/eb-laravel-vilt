<script setup>
import { Link, router } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';
import { computed, reactive, ref, watch } from 'vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import ScenarioFlowGroup from '../Components/ScenarioFlowGroup.vue';
import ScenarioFlowRow from '../Components/ScenarioFlowRow.vue';
import ScenarioHoldingRow from '../Components/ScenarioHoldingRow.vue';
import StatTile from '../Components/StatTile.vue';
import { chartColors, money, moneyBrief, moneyBriefSigned, moneySigned, signTone } from '../lib/format';

const props = defineProps({
    scenario: Object,
    horizon: Object,
    income: Array,
    expenses: Array,
    totals: Array,
    summary: Object,
    net_vs_baseline: Number,
    bracket_inflation: Object,
    assets: Array,
    liabilities: Array,
    net_worth: Array,
    unfunded: Number,
    armadas: Array,
});

const lines = computed(() => [
    { label: 'Income', color: chartColors[0], points: props.totals.map((year) => ({ x: year.year, y: year.income })) },
    { label: 'Tax', color: chartColors[4], points: props.totals.map((year) => ({ x: year.year, y: year.taxes })) },
    { label: 'Expenses', color: chartColors[1], points: props.totals.map((year) => ({ x: year.year, y: year.expenses })) },
    { label: 'Left over', color: chartColors[2], dashed: true, points: props.totals.map((year) => ({ x: year.year, y: year.net })) },
]);

// A year on a chart axis, with the age it is reached at: "2038 (62)".
const ages = computed(() => Object.fromEntries(props.totals.map((year) => [year.year, year.age])));
const yearAndAge = (year) => (year in ages.value ? `${year} (${ages.value[year]})` : String(year));

/*
 * Which armada's rows the lists below show: `undefined` is all of them, and
 * `null` is whatever belongs to none. It narrows the lists only — the charts
 * and the totals above stay the whole plan's, since tax is worked out on
 * every income together.
 */
const armadaFilter = ref(undefined);
const inArmada = (row) => armadaFilter.value === undefined || row.armada_id === armadaFilter.value;

const groups = computed(() => [
    { direction: 'income', title: 'Income', noun: 'income', flows: props.income.filter(inArmada), color: chartColors[0] },
    { direction: 'expense', title: 'Expenses', noun: 'expense', flows: props.expenses.filter(inArmada), color: chartColors[1] },
]);

const sides = computed(() => [
    { key: 'asset', title: 'Assets', subtitle: 'What each is worth, year by year — what it gains in value kept apart from the money moved into or out of it.', holdings: props.assets.filter(inArmada), color: chartColors[0], empty: 'No assets.' },
    { key: 'liability', title: 'Liabilities', subtitle: 'What is owed on each, year by year — the interest it charges kept apart from what is paid off.', holdings: props.liabilities.filter(inArmada), color: chartColors[1], empty: 'No debts.' },
]);

const worthLines = computed(() => [
    { label: 'Assets', color: chartColors[0], points: props.net_worth.map((year) => ({ x: year.year, y: year.assets })) },
    { label: 'Debts', color: chartColors[1], points: props.net_worth.map((year) => ({ x: year.year, y: year.liabilities })) },
    { label: 'Net worth', color: chartColors[2], dashed: true, points: props.net_worth.map((year) => ({ x: year.year, y: year.net_worth })) },
]);

const hasHoldings = computed(() => props.assets.length + props.liabilities.length > 0);

/*
 * How fast this scenario's tax tables rise: every bracket threshold and the
 * standard deduction, by one flat rate a year. Saved with the scenario's name
 * and description, which the same request carries.
 */
const bracketRate = ref(props.bracket_inflation.rate);

watch(() => props.bracket_inflation.rate, (rate) => {
    bracketRate.value = rate;
});

const saveBracketRate = (rate) => {
    router.patch(`/finance/scenarios/${props.scenario.id}`, { name: props.scenario.name, description: props.scenario.description, bracket_inflation_rate: rate }, { preserveScroll: true, preserveState: true });
};

const applyBracketRate = () => {
    if (bracketRate.value === null || bracketRate.value === '') return;

    saveBracketRate(bracketRate.value);
};

// One rate for every flow on a side. Years set by hand stay as they are.
const bulk = reactive({ income: null, expense: null });

const applyRate = (direction) => {
    if (bulk[direction] === null || bulk[direction] === '') return;

    router.put(`/finance/scenarios/${props.scenario.id}/rates`, { direction, annual_growth_rate: bulk[direction] }, { preserveScroll: true, preserveState: true });
};
</script>

<template>
    <FinShell :title="scenario.name" :subtitle="scenario.description || `Every income, expense, asset and debt from ${horizon.from} to ${horizon.to}, the year you plan to. Open one to set how it moves.`">
        <template #actions>
            <Link href="/finance/scenarios" class="fin-btn fin-btn-quiet"><IconArrowLeft :size="16" /> All scenarios</Link>
        </template>

        <EmptyState v-if="!income.length && !expenses.length && !hasHoldings" title="Nothing to project yet" body="A scenario adjusts the incomes, expenses, assets and debts you have entered. Add a few and they will appear here.">
            <Link href="/finance/cashflow" class="fin-btn fin-btn-primary">Go to Income &amp; expenses</Link>
            <Link href="/finance/fleet" class="fin-btn fin-btn-quiet">Go to the fleet</Link>
        </EmptyState>

        <div v-else class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile label="Income over the plan" :value="money(summary.income)" :hint="`${horizon.from}–${horizon.to}, before tax`" />
                <StatTile label="Tax over the plan" :value="money(summary.taxes)" hint="Estimated, by how each income is taxed" />
                <StatTile label="Expenses over the plan" :value="money(summary.expenses)" :hint="`${horizon.from}–${horizon.to}`" />
                <StatTile
                    feature label="Left over" :value="moneySigned(summary.net)"
                    :hint="net_vs_baseline === 0 ? 'The same as with nothing adjusted' : `${moneySigned(net_vs_baseline)} against nothing adjusted`"
                />
            </div>

            <Card title="Year by year" :subtitle="`In the dollars of each year, not today's. Left over is after tax and expenses.${horizon.has_birth_date ? '' : ' Your profile has no birth date, so the plan assumes you are 40.'}`">
                <template #actions>
                    <form class="flex flex-wrap items-center gap-2" @submit.prevent="applyBracketRate">
                        <label class="flex items-center gap-2 text-xs text-fin-grey-600">
                            Tax brackets rise
                            <span class="relative block w-28">
                                <input v-model.number="bracketRate" type="number" min="-5" max="15" step="0.1" required class="!pr-12" aria-label="Yearly rise in the tax brackets">
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-fin-grey-400">% / yr</span>
                            </span>
                        </label>
                        <button type="submit" class="fin-btn fin-btn-quiet">Apply</button>
                        <button v-if="bracket_inflation.is_own" type="button" class="fin-btn fin-btn-quiet" @click="saveBracketRate(null)">
                            Use my inflation rate ({{ bracket_inflation.profile_rate }}%)
                        </button>
                    </form>
                </template>

                <LineChart :series="lines" :height="260" :x-ticks="6" :format-x="yearAndAge" />

                <p class="mt-3 text-xs text-fin-grey-500">
                    Tax is worked out each year on brackets and a standard deduction raised {{ bracket_inflation.rate }}% a year from today's{{ bracket_inflation.is_own ? '' : ', your profile\'s inflation rate' }}.
                    A lower rate lets growing income climb into higher brackets; a higher one keeps it in lower ones.
                </p>
            </Card>

            <Card v-if="hasHoldings" title="Net worth, year by year" :subtitle="`Every asset and debt walked forward a month at a time: growth, contributions and payments, the income and expenses that name an account, then your automated transfers.`">
                <LineChart :series="worthLines" :height="240" :x-ticks="6" :format-x="yearAndAge" />

                <p v-if="unfunded > 0" class="mt-3 rounded-xl border border-fin-red-600/30 bg-fin-red-100 px-4 py-3 text-sm text-fin-red-600">
                    {{ money(unfunded) }} of expenses over the plan could not be paid from the account they name, because it had run dry. Open the assets below to see which, and when.
                </p>
            </Card>

            <Card v-if="armadas.length" title="By armada" subtitle="The plan added up by the part of the fleet it belongs to. Pick one to narrow the lists below to it." flush>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-5 py-2.5 font-medium">Armada</th>
                                <th class="px-3 py-2.5 text-right font-medium">Income</th>
                                <th class="px-3 py-2.5 text-right font-medium">Expenses</th>
                                <th class="px-3 py-2.5 text-right font-medium" title="Income less expenses over the plan, before tax">Cash it throws off</th>
                                <th class="px-3 py-2.5 text-right font-medium" title="What its assets gain in value, less the interest its debts charge">Value it gains</th>
                                <th class="px-3 py-2.5 text-right font-medium">Net worth today</th>
                                <th class="px-5 py-2.5 text-right font-medium">Net worth in {{ horizon.to }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-fin-grey-100">
                                <td class="px-5 py-2" colspan="7">
                                    <button type="button" class="fin-pill" :aria-pressed="armadaFilter === undefined" @click="armadaFilter = undefined">
                                        Show every armada
                                    </button>
                                </td>
                            </tr>
                            <tr v-for="armada in armadas" :key="armada.id ?? 'none'" class="border-b border-fin-grey-100 last:border-0" :class="{ 'bg-fin-cream-50': armadaFilter === armada.id }">
                                <td class="px-5 py-2.5">
                                    <button type="button" class="font-medium hover:underline" :class="armadaFilter === armada.id ? 'text-fin-green-600' : 'text-fin-black'" :aria-pressed="armadaFilter === armada.id" @click="armadaFilter = armada.id">{{ armada.name }}</button>
                                    <span class="block text-xs text-fin-grey-500">{{ armada.holdings_count }} holdings · {{ armada.flows_count }} lines</span>
                                </td>
                                <td class="px-3 py-2.5 text-right text-fin-charcoal" :title="money(armada.income)">{{ moneyBrief(armada.income) }}</td>
                                <td class="px-3 py-2.5 text-right text-fin-charcoal" :title="money(armada.expenses)">{{ moneyBrief(armada.expenses) }}</td>
                                <td class="px-3 py-2.5 text-right font-medium" :class="signTone(armada.cashflow)" :title="money(armada.cashflow)">{{ moneyBriefSigned(armada.cashflow) }}</td>
                                <td class="px-3 py-2.5 text-right font-medium" :class="signTone(armada.growth)" :title="money(armada.growth)">{{ moneyBriefSigned(armada.growth) }}</td>
                                <td class="px-3 py-2.5 text-right text-fin-charcoal" :title="money(armada.net_worth_start)">{{ moneyBrief(armada.net_worth_start) }}</td>
                                <td class="px-5 py-2.5 text-right font-medium text-fin-black" :title="money(armada.net_worth_end)">{{ moneyBrief(armada.net_worth_end) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card v-for="group in groups" :key="group.direction" :title="group.title" :subtitle="`Open one to set its rate and move individual years.`" flush>
                <template v-if="group.flows.length" #actions>
                    <form class="flex items-center gap-2" @submit.prevent="applyRate(group.direction)">
                        <label class="flex items-center gap-2 text-xs text-fin-grey-600">
                            Set every {{ group.noun }} to
                            <span class="relative block w-28">
                                <input v-model.number="bulk[group.direction]" type="number" min="-50" max="50" step="0.1" required class="!pr-12" :aria-label="`Rate for every ${group.noun}`">
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-xs text-fin-grey-400">% / yr</span>
                            </span>
                        </label>
                        <button type="submit" class="fin-btn fin-btn-quiet">Apply</button>
                    </form>
                </template>

                <p v-if="!group.flows.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">No {{ group.noun }} {{ armadaFilter === undefined ? 'yet' : 'in this armada' }}.</p>

                <template v-for="flow in group.flows" :key="flow.id">
                    <ScenarioFlowGroup v-if="flow.is_group" :flow="flow" :scenario-id="scenario.id" :retirement-year="horizon.retirement_year" :color="group.color" />
                    <ScenarioFlowRow v-else :flow="flow" :scenario-id="scenario.id" :retirement-year="horizon.retirement_year" :color="group.color" />
                </template>
            </Card>

            <template v-if="hasHoldings">
                <Card v-for="side in sides" :key="side.key" :title="side.title" :subtitle="side.subtitle" flush>
                    <p v-if="!side.holdings.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">{{ side.empty }}</p>

                    <ScenarioHoldingRow
                        v-for="holding in side.holdings" :key="holding.id"
                        :holding="holding" :scenario-id="scenario.id" :retirement-year="horizon.retirement_year" :color="side.color"
                    />
                </Card>
            </template>
        </div>
    </FinShell>
</template>
