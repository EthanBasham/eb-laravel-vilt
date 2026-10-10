<script setup>
import { Deferred, router, usePage } from '@inertiajs/vue3';
import { IconCopy, IconFileTypePdf, IconPencil, IconPlus, IconSparkles, IconTableMinus, IconX } from '@tabler/icons-vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import Card from '../Components/Card.vue';
import ConversionStrategyForm from '../Components/ConversionStrategyForm.vue';
import EmptyState from '../Components/EmptyState.vue';
import FanChart from '../Components/FanChart.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import MonteCarloCard from '../Components/MonteCarloCard.vue';
import RetirementTabs from '../Components/RetirementTabs.vue';
import StatTile from '../Components/StatTile.vue';
import StrategyLibrary from '../Components/StrategyLibrary.vue';
import StrategyPicker from '../Components/StrategyPicker.vue';
import ThresholdChart from '../Components/ThresholdChart.vue';
import { age, bucketLines, useStrategyBoard } from '../composables/useStrategyBoard';
import { colorOf, money, moneyBrief } from '../lib/format';

/**
 * The Roth conversion tab: strategies built by the user, and a report whose
 * columns are each one of them run on one projection. One column alone is a
 * report on it; several are set side by side — the same strategy across
 * projections, several strategies on the same one, or both.
 *
 * Nothing here calculates. Each column arrives with its year-by-year rows
 * and its summary already worked out (ConversionBoard); the page chooses what
 * to show and which column to look at closely.
 */
const props = defineProps({
    profile: Object,
    balances: Object,
    growth_rate: Number,
    tax_year: Number,
    kinds: Object,
    fill_rates: Array,
    tax_payments: Object,
    default_heir_income: Number,
    default_conversion_amount: Number,
    defaults: Object,
    scenarios: Array,
    brackets: Array,
    irmaa_tiers: Array,
    // The report's columns, worked out. Each has its strategy's settings, but
    // its `id` and `label` are the column's own; the strategy is `strategy_id`.
    report: Array,
    // Every strategy, in the report or not, as settings alone.
    strategies: Array,
    // { count, default, max }: how many columns the report has, and can have.
    comparison: Object,
    // Deferred: undefined until the follow-up request brings it.
    monte_carlo: Object,
});


// One of each kind, in the report on every saved projection — or, with none
// saved, on the income and expenses as entered.
const addStarters = () => router.post('/finance/retirement/strategies/starters', { every_projection: true }, { preserveScroll: true });

// One of each kind, in the report on the projection chosen; `null` is the
// income and expenses as entered.
const setFor = ref(null);
const addSet = () => router.post('/finance/retirement/strategies/starters', { scenario_id: setFor.value }, { preserveScroll: true });

// The strategy a column of the report is a run of.
const strategyOf = (column) => props.strategies.find((strategy) => strategy.id === column.strategy_id);

// Takes one column out of the report. Its strategy stays.
const takeOut = (column) => router.delete(`/finance/retirement/report/${column.id}`, { preserveScroll: true });

// Empties the report. No strategy is removed.
const clearReport = () => {
    if (window.confirm(`Clear the report? The ${props.report.length} in it leave it; no strategy is removed.`)) {
        router.delete('/finance/retirement/report', { preserveScroll: true });
    }
};

const duplicate = (column) => router.post(`/finance/retirement/strategies/${column.strategy_id}/duplicate`, {}, { preserveScroll: true });

// A copy is made to be changed, so it opens for editing as soon as it lands.
// The server flashes its id.
const page = usePage();

watch(() => page.props.flash?.copied, (id) => {
    const copy = props.strategies.find((strategy) => strategy.id === id);

    if (copy) edit(copy);
});

const colorOfColumn = (column) => colorOf(props.report.findIndex((candidate) => candidate.id === column.id));

// The selected column's spread across the Monte Carlo markets, beside its
// steady-market line, when there are results for it.
const fan = computed(() => {
    const result = props.monte_carlo?.results?.strategies?.[selectedId.value];

    if (!result) return null;

    return {
        band: result.balances.map((point) => ({ x: point.age, p10: point.p10, p50: point.p50, p90: point.p90 })),
        // The runs are in today's dollars, so the line over them is too.
        line: selected.value.rows.map((row) => ({ x: row.age, y: row.total_balance_today })),
        runs: props.monte_carlo.results.runs,
    };
});

// One column in the report is reported on alone, with nothing to compare.
const isSingle = computed(() => props.report.length === 1);

/*
 * The report as a PDF, by way of the browser's own print dialog. The page
 * takes the paper's layout first (FinShell's `fin-printing` styles say why),
 * waits a frame for the charts to redraw at that width, and then prints. The
 * dialog names the file after the document's title, so that is lent to the
 * report for as long as the dialog is open.
 */
const printedOn = new Date().toLocaleDateString(undefined, { dateStyle: 'long' });

const printReport = async () => {
    const root = document.documentElement;
    const title = document.title;

    const restore = () => {
        root.classList.remove('fin-printing');
        document.title = title;
        window.removeEventListener('afterprint', restore);
    };

    root.classList.add('fin-printing');
    document.title = `Roth conversion report · ${isSingle.value ? selected.value.label : `${props.report.length} compared`}`;
    window.addEventListener('afterprint', restore);

    await new Promise((resolve) => { requestAnimationFrame(() => requestAnimationFrame(resolve)); });
    await nextTick();

    window.print();
};


/*
 * Which dollars the report is in: each year's own, or all of them brought
 * back to today's prices. Every column arrives worked out both ways, so
 * this only picks; the choice is remembered in this browser.
 */
const DOLLARS_KEY = 'finance.roth.dollars';
const inTodaysDollars = ref(true);

onMounted(() => {
    try {
        inTodaysDollars.value = window.localStorage.getItem(DOLLARS_KEY) !== 'each_year';
    } catch {
        // No storage: the report opens in today's dollars.
    }
});

const showDollars = (today) => {
    inTodaysDollars.value = today;

    try {
        window.localStorage.setItem(DOLLARS_KEY, today ? 'today' : 'each_year');
    } catch {
        // The switch still works for this visit.
    }
};

const dollars = computed(() => (inTodaysDollars.value ? 'in today\'s dollars' : 'in the dollars of each year, not today\'s'));

// The columns with the figures of whichever dollars are being shown.
const reported = computed(() => props.report.map((strategy) => (inTodaysDollars.value ? { ...strategy, ...strategy.today } : strategy)));

const { form, build, edit, selectedId, selectedIndex, selected, lines } = useStrategyBoard(() => reported.value, { label: (strategy) => strategy.label });

const tierName = (tier) => (tier === 0 ? 'no surcharge' : `tier ${tier}`);

/*
 * Each line on the two charts is named for what begins above it — "35%
 * starts", "Tier 4 starts" — not for what it is the top of. A year sitting
 * just over a line named "32%" reads as being in the 32% bracket, when that
 * line is where 32% ends.
 */
const startsAt = (lines, field) => lines.map((line, position) => ({
    label: `${line.above} starts`,
    // Where the line stands in each year of the strategy looked at: it rises
    // with that strategy's inflation rate.
    values: selected.value.rows.map((row) => row[field][position]),
}));

const bracketPoints = computed(() => selected.value.rows.map((row) => ({
    x: row.age,
    y: row.taxable_income,
    before: row.taxable_income_before,
    note: `In the ${row.marginal_rate}% bracket.${row.conversion ? ` ${money(row.conversion)} converted.` : ''}`,
})));

const irmaaPoints = computed(() => selected.value.rows.map((row) => ({
    x: row.age,
    y: row.magi,
    before: row.magi_before,
    note: `Sets the premium at ${row.age + 2}: ${tierName(row.magi_tier)}.`,
})));

/*
 * The report's figures: a row each when strategies are compared, a tile each
 * for one alone. `best` names which end of the row is the one to want, and
 * marks whichever strategies reach it — only where the figure is one a
 * person would actually choose a strategy by. `alone` is the label for a
 * figure whose own only reads beneath the row above it.
 */
const figures = [
    { key: 'converted', label: 'Converted to Roth' },
    { key: 'total_rmd', label: 'RMDs taken' },
    { key: 'lifetime_tax', label: 'Tax you pay', best: 'low', note: (summary) => (summary.penalties ? `${moneyBrief(summary.penalties)} early-withdrawal penalty` : '') },
    { key: 'conversion_tax', label: 'of it, on the conversions', alone: 'Tax on the conversions', note: (summary) => (summary.conversion_tax_withheld ? `${moneyBrief(summary.conversion_tax_withheld)} from the converted money` : '') },
    { key: 'irmaa', label: 'IRMAA surcharges', best: 'low', note: (summary) => (summary.irmaa_years ? `${summary.irmaa_years} ${summary.irmaa_years === 1 ? 'year' : 'years'}` : '') },
    { key: 'ending_traditional', label: 'Traditional at the end' },
    { key: 'ending_roth', label: 'Roth at the end' },
    { key: 'ending_taxable', label: 'Taxable savings at the end' },
    { key: 'ending_balance', label: 'All three together' },
    {
        key: 'leftover_tax', label: 'Est. Leftover Taxes', best: 'low',
        note: (summary, strategy) => `${strategy.heir_is_charity ? 'charity' : moneyBrief(summary.heir_tax)} on traditional · ${moneyBrief(summary.gains_tax)} on savings' gains`,
    },
    { key: 'inheritable', label: 'Est. Inheritable Amount', best: 'high', strong: true },
];

const bestOf = (figure) => {
    if (!figure.best || props.report.length < 2) return null;

    const values = reported.value.map((strategy) => strategy.summary[figure.key]);

    return figure.best === 'low' ? Math.min(...values) : Math.max(...values);
};

const assumptions = (strategy) => [
    strategy.assumptions.scenario_name ?? 'As entered',
    `${strategy.assumptions.inflation_rate}% inflation`,
    `${strategy.assumptions.growth_rate}% growth`,
].join(' · ');

// When a strategy converts: "at 68", "68–72", or nothing for one that does not.
const convertsWhen = (strategy) => {
    const { convert_from_age: from, convert_until_age: until } = strategy.assumptions;

    if (from === null) return '';

    return from === until ? `at ${from}` : `${from}–${until}`;
};
</script>

<template>
    <FinShell title="Retirement strategizer" subtitle="Build strategies for moving traditional money to Roth, then report on one, or compare several, by what each does to your tax, your Medicare premiums and what is left.">
        <template #actions>
            <div v-if="report.length" class="flex gap-1.5" role="group" aria-label="Dollars the report is in">
                <button
                    v-for="choice in [{ today: true, label: 'Today\'s dollars' }, { today: false, label: 'Each year\'s dollars' }]" :key="choice.label" type="button"
                    class="fin-pill" :aria-pressed="choice.today === inTodaysDollars" @click="showDollars(choice.today)"
                >
                    {{ choice.label }}
                </button>
            </div>
            <span v-if="report.length" class="h-6 w-px bg-fin-grey-300" aria-hidden="true" />
            <button type="button" class="fin-btn fin-btn-primary" @click="build"><IconPlus :size="16" /> Strategy</button>
            <span class="h-6 w-px bg-fin-grey-300" aria-hidden="true" />
            <form class="flex flex-wrap items-center gap-2" @submit.prevent="addSet">
                <span class="text-xs font-medium text-fin-grey-600">One of each type for</span>
                <select v-model="setFor" class="!w-52" aria-label="Projection to make one of each strategy type for">
                    <option :value="null">No projection</option>
                    <option v-for="scenario in scenarios" :key="scenario.id" :value="scenario.id">{{ scenario.name }}</option>
                </select>
                <button type="submit" class="fin-btn fin-btn-quiet"><IconSparkles :size="16" /> Add</button>
            </form>
        </template>

        <RetirementTabs class="printing:hidden" current="conversions" />

        <!-- With `printing:`, the width of the paper: letter on its side, less the margins. -->
        <div class="flex flex-col gap-5 printing:block printing:w-[976px] printing:space-y-5">
            <p class="hidden text-sm text-fin-grey-600 printing:block">Roth conversion report · {{ printedOn }}</p>

            <div class="fin-keep grid gap-4 sm:grid-cols-2 xl:grid-cols-4 printing:grid-cols-4">
                <StatTile accent label="The plan" :value="`${profile.age} to ${profile.life_expectancy}`" :hint="`${profile.filing_status} · retiring at ${profile.retirement_age}`" />
                <StatTile label="Traditional" :value="money(balances.deferred)" :hint="`RMDs begin at ${profile.rmd_start_age}`" />
                <StatTile label="Roth & HSA" :value="money(balances.free)" hint="Never taxed again" />
                <StatTile label="Taxable savings" :value="money(balances.taxable)" hint="Spent first once retired" />
            </div>

            <p v-if="!profile.has_birth_date" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                Your profile has no birth date, so every strategy assumes you are 40. Set it in Profile &amp; settings for ages that mean something.
            </p>
            <p v-if="balances.deferred <= 0" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                There is no traditional balance in your fleet, so there is nothing to convert and every strategy will come out the same.
            </p>

            <EmptyState v-if="!strategies.length" title="No strategies yet" :body="`A strategy is a way of converting, an inflation rate, and who inherits. Put it in the report on a projection to look at it alone, on several to see how it holds up across them, or beside other strategies to compare them.${scenarios.length > 1 ? ` Starting with one of each kind puts them in the report on your first projection; add them on the others from there.` : ''}`">
                <button type="button" class="fin-btn fin-btn-primary" @click="addStarters"><IconSparkles :size="16" /> Start with one of each kind</button>
                <button type="button" class="fin-btn fin-btn-quiet" @click="build"><IconPlus :size="16" /> Build one</button>
            </EmptyState>

            <template v-else>
                <StrategyLibrary class="printing:hidden" :strategies="strategies" :kinds="kinds" :scenarios="scenarios" :comparison="comparison" @edit="edit" />

                <p v-if="!report.length" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                    Nothing is in the report. Add a strategy above on one projection for a report on it alone, or on several — or several strategies — to compare them.
                </p>
            </template>

            <template v-if="report.length">
                <!-- One column: its figures as tiles, with nothing to be best of. -->
                <Card v-if="isSingle" :title="selected.label" :subtitle="`${[selected.name ? selected.kind_label : '', convertsWhen(selected)].filter(Boolean).join(' ')} · ${assumptions(selected)}. Over the whole plan, ${dollars}.`">
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="printReport"><IconFileTypePdf :size="16" /> Export PDF</button>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="edit(strategyOf(selected))"><IconPencil :size="16" /> Edit</button>
                        <button type="button" class="fin-icon-btn" :aria-label="`Copy ${selected.label}`" title="Copy" @click="duplicate(selected)"><IconCopy :size="15" /></button>
                        <button type="button" class="fin-icon-btn" :aria-label="`Take ${selected.label} out of the report`" title="Take out of the report" @click="takeOut(selected)"><IconX :size="15" /></button>
                    </template>

                    <dl class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-4 printing:grid-cols-4">
                        <div v-for="figure in figures" :key="figure.key">
                            <dt class="text-xs text-fin-grey-500">{{ figure.alone ?? figure.label }}</dt>
                            <dd class="mt-0.5 text-fin-black" :class="figure.strong ? 'text-lg font-bold' : 'text-base font-semibold'" :title="money(selected.summary[figure.key])">
                                {{ moneyBrief(selected.summary[figure.key]) }}
                                <span v-if="figure.note?.(selected.summary, selected)" class="block text-[11px] font-normal text-fin-grey-500">{{ figure.note(selected.summary, selected) }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fin-grey-500">Highest bracket reached</dt>
                            <dd class="mt-0.5 text-base font-semibold text-fin-black">
                                {{ selected.summary.peak_marginal_rate }}%
                                <span v-if="selected.summary.short_at_age" class="block text-[11px] font-semibold text-fin-red-600">Money runs out at {{ selected.summary.short_at_age }}</span>
                            </dd>
                        </div>
                    </dl>
                </Card>

                <Card v-else title="Side by side" flush>
                    <template #subtitle>
                        Over the whole plan, in <strong class="font-semibold text-fin-charcoal">{{ inTodaysDollars ? 'today\'s dollars' : 'the dollars of each year' }}</strong>{{ inTodaysDollars ? '' : ', not today\'s' }}. A green figure is the best in its row. {{ comparison.count }} of at most {{ comparison.max }} in the report.
                    </template>
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="printReport"><IconFileTypePdf :size="16" /> Export PDF</button>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="clearReport"><IconTableMinus :size="16" /> Clear Report</button>
                    </template>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left align-top text-xs text-fin-grey-500">
                                    <th class="sticky left-0 bg-fin-cream-50 px-5 py-2.5 font-medium">&nbsp;</th>
                                    <th v-for="(strategy, index) in report" :key="strategy.id" class="min-w-44 px-3 py-2.5 text-right font-normal">
                                        <span class="flex items-center justify-end gap-1.5 text-sm font-semibold text-fin-black">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colorOf(index) }" aria-hidden="true" />
                                            {{ strategy.strategy_label }}
                                        </span>
                                        <span class="mt-0.5 block">{{ strategy.name ? strategy.kind_label : '' }} {{ convertsWhen(strategy) }}</span>
                                        <span v-if="kinds[strategy.kind]?.amount" class="block">{{ moneyBrief(strategy.conversion_amount) }} a year</span>
                                        <span class="block">{{ assumptions(strategy) }}</span>
                                        <span class="mt-1 flex justify-end">
                                            <button type="button" class="fin-icon-btn" :aria-label="`Edit ${strategy.strategy_label}`" :title="`Edit, in every column of ${strategy.strategy_label}`" @click="edit(strategyOf(strategy))"><IconPencil :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Copy ${strategy.strategy_label}`" title="Copy the strategy" @click="duplicate(strategy)"><IconCopy :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Take ${strategy.label} out of the report`" title="Take out of the report" @click="takeOut(strategy)"><IconX :size="15" /></button>
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="figure in figures" :key="figure.key" class="border-b border-fin-grey-100 last:border-0" :class="{ 'bg-fin-cream-50/60': figure.strong }">
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left" :class="figure.strong ? 'font-semibold text-fin-black' : 'font-medium text-fin-charcoal'">
                                        {{ figure.label }}
                                    </th>
                                    <td
                                        v-for="strategy in reported" :key="strategy.id" class="whitespace-nowrap px-3 py-2.5 text-right"
                                        :class="[figure.strong ? 'font-semibold' : '', strategy.summary[figure.key] === bestOf(figure) ? 'text-fin-green-600' : 'text-fin-black']"
                                        :title="money(strategy.summary[figure.key])"
                                    >
                                        {{ moneyBrief(strategy.summary[figure.key]) }}
                                        <span v-if="figure.note?.(strategy.summary, strategy)" class="block text-[11px] font-normal text-fin-grey-500">{{ figure.note(strategy.summary, strategy) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">Highest bracket reached</th>
                                    <td v-for="strategy in reported" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black">
                                        {{ strategy.summary.peak_marginal_rate }}%
                                        <span v-if="strategy.summary.short_at_age" class="block text-[11px] font-semibold text-fin-red-600">Money runs out at {{ strategy.summary.short_at_age }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Deferred data="monte_carlo">
                    <template #fallback>
                        <div class="rounded-2xl border border-fin-grey-200 bg-fin-white p-5 printing:hidden">
                            <p class="text-sm font-semibold text-fin-black">Across many markets</p>
                            <p class="mt-0.5 text-xs text-fin-grey-500">Running the strategies through random markets…</p>
                            <div class="mt-4 flex flex-col gap-2">
                                <div v-for="line in 4" :key="line" class="h-5 animate-pulse rounded bg-fin-cream-200" :style="{ width: `${100 - line * 12}%` }" />
                            </div>
                        </div>
                    </template>

                    <MonteCarloCard :monte-carlo="monte_carlo" :strategies="report" :color-of="colorOfColumn" />
                </Deferred>

                <div v-if="report.length > 1" class="grid gap-5 xl:grid-cols-2 printing:grid-cols-2">
                    <Card title="Tax and IRMAA each year" subtitle="What each strategy costs, year by year.">
                        <LineChart :series="lines('tax_and_irmaa')" :height="240" :format-x="age" :x-ticks="6" />
                    </Card>
                    <Card title="Traditional and Roth together" subtitle="The retirement accounts, whichever side the money is on.">
                        <LineChart :series="lines('retirement_balance')" :height="240" :format-x="age" :x-ticks="6" />
                    </Card>
                </div>

                <Card v-if="selected" class="fin-card-splits" :title="isSingle ? 'Year by year' : 'A closer look'" :subtitle="isSingle ? 'Against the lines that matter: the tops of the tax brackets, and the IRMAA tiers.' : 'One strategy against the lines that matter: the tops of the tax brackets, and the IRMAA tiers.'">
                    <template v-if="report.length > 1" #actions>
                        <StrategyPicker v-model="selectedId" :strategies="report" :color-of="colorOf" :label="(strategy) => strategy.label" />
                    </template>

                    <div class="flex flex-col gap-7 printing:block printing:space-y-7">
                        <!-- The picker is not printed, so the paper names the strategy looked at. -->
                        <p v-if="!isSingle" class="hidden text-sm font-semibold text-fin-black printing:block">{{ selected.label }}</p>

                        <div class="fin-keep grid gap-7 xl:grid-cols-2 printing:grid-cols-2">
                            <section>
                                <h3 class="text-sm font-semibold text-fin-black">Income against the tax brackets</h3>
                                <p class="mb-3 mt-0.5 text-xs text-fin-grey-500">
                                    Taxable ordinary income — the projection's, plus RMDs, conversions and withdrawals, less the standard deduction — against the federal brackets; each line is where the bracket named on it begins{{ inTodaysDollars ? '' : ', rising each year with the strategy\'s inflation rate' }}. The dashed line is the year had it not converted; the gold between is what converting added.
                                </p>
                                <ThresholdChart :points="bracketPoints" :thresholds="startsAt(brackets, 'bracket_lines')" :color="colorOf(selectedIndex)" :format-x="age" label="Taxable income" />
                            </section>
                            <section>
                                <h3 class="text-sm font-semibold text-fin-black">Income against the IRMAA tiers</h3>
                                <p class="mb-3 mt-0.5 text-xs text-fin-grey-500">
                                    Each line is where the tier named on it begins. Crossing one raises Medicare premiums two years later, and by the whole step, not a slice. Only years from 63 on can cost anything.
                                    <template v-if="inTodaysDollars">Income is shown in the prices of the year it sets the premium for, so it reads against today's tiers.</template>
                                    <template v-else>The lines are the tiers of the year the income sets the premium for, two years on, raised by the strategy's inflation rate.</template>
                                </p>
                                <ThresholdChart :points="irmaaPoints" :thresholds="startsAt(irmaa_tiers, 'irmaa_lines')" :color="colorOf(selectedIndex)" :format-x="age" label="Income for IRMAA" />
                            </section>
                        </div>

                        <section v-if="fan" class="fin-keep">
                            <h3 class="text-sm font-semibold text-fin-black">Everything left, across {{ fan.runs }} markets</h3>
                            <p class="mb-3 mt-0.5 text-xs text-fin-grey-500">Traditional, Roth and taxable together. Always in today's dollars, whichever the rest of the report is in: each market has its own inflation, so only one year's prices let them be set side by side. The band is where the middle 80% of markets land.</p>
                            <FanChart :band="fan.band" :line="fan.line" :color="colorOf(selectedIndex)" :format-x="age" />
                        </section>

                        <section class="fin-keep">
                            <h3 class="mb-3 text-sm font-semibold text-fin-black">Balances</h3>
                            <LineChart :series="bucketLines(selected)" :height="230" :format-x="age" :x-ticks="8" />
                        </section>

                        <section>
                            <h3 class="mb-3 text-sm font-semibold text-fin-black">{{ isSingle ? 'Every year' : 'Year by year' }}</h3>
                            <div class="max-h-[26rem] overflow-auto rounded-xl border border-fin-grey-200 printing:rounded-none printing:border-0">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0">
                                        <tr class="bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                            <th class="px-4 py-2.5 font-medium">Age</th>
                                            <th class="px-3 py-2.5 text-right font-medium" title="What your projection brings in, before tax">Income</th>
                                            <th class="px-3 py-2.5 text-right font-medium">RMD</th>
                                            <th class="px-3 py-2.5 text-right font-medium" title="The most a qualified charitable distribution could come to that year, from 70½. Set in Profile &amp; settings; the plan makes none.">QCD limit</th>
                                            <th class="px-3 py-2.5 text-right font-medium">Converted</th>
                                            <th class="px-3 py-2.5 text-right font-medium" title="Taken from traditional to pay for spending or tax">Withdrawn</th>
                                            <th class="px-3 py-2.5 text-right font-medium" title="Ordinary income less the standard deduction: what the tax brackets are read against">Taxable income</th>
                                            <th class="px-3 py-2.5 text-right font-medium">Tax <span class="font-normal">(on the conversion)</span></th>
                                            <th class="px-3 py-2.5 text-right font-medium">Bracket</th>
                                            <th class="px-3 py-2.5 text-right font-medium">IRMAA</th>
                                            <th class="px-3 py-2.5 text-right font-medium">Traditional</th>
                                            <th class="px-4 py-2.5 text-right font-medium">Roth</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in selected.rows" :key="row.age" class="border-t border-fin-grey-100">
                                            <td class="px-4 py-2 font-medium text-fin-black">{{ row.age }} <span class="font-normal text-fin-grey-500">· {{ row.year }}</span></td>
                                            <td class="px-3 py-2 text-right text-fin-charcoal">{{ money(row.income) }}</td>
                                            <td class="px-3 py-2 text-right text-fin-charcoal">{{ row.rmd ? money(row.rmd) : '—' }}</td>
                                            <td class="px-3 py-2 text-right" :class="row.qcd_limit === null ? 'text-fin-grey-400' : 'text-fin-charcoal'">{{ row.qcd_limit === null ? '—' : money(row.qcd_limit) }}</td>
                                            <td class="px-3 py-2 text-right font-medium" :class="row.conversion ? 'text-fin-gold-600' : 'text-fin-grey-400'">{{ row.conversion ? money(row.conversion) : '—' }}</td>
                                            <td class="px-3 py-2 text-right" :class="row.withdrawal ? 'text-fin-charcoal' : 'text-fin-grey-400'">{{ row.withdrawal ? money(row.withdrawal) : '—' }}</td>
                                            <td class="px-3 py-2 text-right font-medium text-fin-black" :title="`${money(row.bracket_income)} before the deduction`">{{ money(row.taxable_income) }}</td>
                                            <td class="whitespace-nowrap px-3 py-2 text-right text-fin-charcoal">
                                                {{ money(row.tax) }}
                                                <span v-if="row.conversion" class="text-fin-gold-600" :title="row.conversion_tax_withheld ? `${money(row.conversion_tax_withheld)} of it taken from the converted money` : 'Paid from outside the conversion'">({{ money(row.conversion_tax) }})</span>
                                                <span v-if="row.penalty" class="block text-[11px] text-fin-red-600">incl. {{ money(row.penalty) }} penalty</span>
                                            </td>
                                            <td class="px-3 py-2 text-right text-fin-charcoal">{{ row.marginal_rate }}%</td>
                                            <td class="px-3 py-2 text-right" :class="row.irmaa ? 'font-medium text-fin-red-600' : 'text-fin-grey-400'">{{ row.irmaa ? money(row.irmaa) : '—' }}</td>
                                            <td class="px-3 py-2 text-right text-fin-charcoal">{{ money(row.traditional) }}</td>
                                            <td class="px-4 py-2 text-right text-fin-charcoal">{{ money(row.roth) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </Card>

                <p class="fin-keep text-xs text-fin-grey-500">
                    A planning model, not tax advice. It uses the {{ tax_year }} federal brackets, your standard deduction (plus the additional deduction from 65), your state and local brackets and the IRMAA tiers, all raised each year by the strategy's inflation rate, and the IRS Uniform Lifetime Table for RMDs.
                    IRMAA is charged from 65 for {{ profile.persons === 1 ? 'one person' : 'two people' }}. Money taken from traditional before 59½ pays the 10% penalty. Est. Leftover Taxes is what is still owed on what is left, in two parts. One is the income tax an heir pays drawing the traditional balance in ten equal parts as a single filer. The other is long-term capital gains tax on the growth in taxable savings, worked on your own filing status and brackets, realised in ten equal parts on top of the income you have in the plan's last year. That second part is yours rather than an heir's on purpose, and ignores the stepped-up basis an heir would get: it stands in for the tax on dividends and sales the plan never charges along the way, and for what you would pay if you had to draw on that money in an emergency. Neither part includes state tax.
                    It leaves out tax on growth and sales in the taxable account along the way, the net investment income tax, the temporary senior deduction, a survivor moving to single brackets, the Roth five-year rules and Social Security's real taxation formula. Most of those bear on every strategy alike, so the comparison holds up better than any single figure does.
                </p>
            </template>
        </div>

        <ConversionStrategyForm
            :open="form.open" :strategy="form.strategy" :kinds="kinds" :fill-rates="fill_rates" :tax-payments="tax_payments" :defaults="defaults"
            :scenarios="scenarios" :profile="profile" :growth-rate="growth_rate" :heir-income="default_heir_income" :conversion-amount="default_conversion_amount" @close="form.open = false"
        />
    </FinShell>
</template>
