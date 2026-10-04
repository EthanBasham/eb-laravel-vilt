<script setup>
import { Deferred, router } from '@inertiajs/vue3';
import { IconArchive, IconCopy, IconPencil, IconPlus, IconSparkles } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import Card from '../Components/Card.vue';
import ConversionStrategyForm from '../Components/ConversionStrategyForm.vue';
import EmptyState from '../Components/EmptyState.vue';
import FanChart from '../Components/FanChart.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import MonteCarloCard from '../Components/MonteCarloCard.vue';
import RetirementTabs from '../Components/RetirementTabs.vue';
import StatTile from '../Components/StatTile.vue';
import StrategyHoldingArea from '../Components/StrategyHoldingArea.vue';
import ThresholdChart from '../Components/ThresholdChart.vue';
import { chartColors, money, moneyBrief } from '../lib/format';

/**
 * The Roth conversion tab: strategies built by the user, run by the server
 * over the same lifetime, and set side by side.
 *
 * Nothing here calculates. Each strategy arrives with its year-by-year rows
 * and its summary already worked out (ConversionBoard); the page chooses what
 * to show and which strategy to look at closely.
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
    strategies: Array,
    // The holding area: strategies not being compared, as settings alone.
    held: Array,
    // { count, default, max }: how many are compared, and how many can be.
    comparison: Object,
    // Deferred: undefined until the follow-up request brings it.
    monte_carlo: Object,
});

const form = ref({ open: false, strategy: null });
const build = () => { form.value = { open: true, strategy: null }; };
const edit = (strategy) => { form.value = { open: true, strategy }; };

// One of each kind for every saved projection — or, with none saved, one set
// on the income and expenses as entered.
const addStarters = () => router.post('/finance/retirement/strategies/starters', { every_projection: true }, { preserveScroll: true });
const hold = (strategy) => router.delete(`/finance/retirement/strategies/${strategy.id}/compare`, { preserveScroll: true });
// Sends everything being compared to the holding area. Nothing is removed.
const clearComparison = () => {
    if (window.confirm(`Clear the comparison? The ${props.strategies.length} being compared move to the holding area; none is removed.`)) {
        router.delete('/finance/retirement/strategies/comparison', { preserveScroll: true });
    }
};

const duplicate = (strategy) => router.post(`/finance/retirement/strategies/${strategy.id}/duplicate`, {}, { preserveScroll: true });

const colorOf = (index) => chartColors[index % chartColors.length];
const colorOfStrategy = (strategy) => colorOf(props.strategies.findIndex((candidate) => candidate.id === strategy.id));

// The selected strategy's spread across the Monte Carlo markets, beside its
// steady-market line, when there are results for it.
const fan = computed(() => {
    const result = props.monte_carlo?.results?.strategies?.[selectedId.value];

    if (!result) return null;

    return {
        band: result.balances.map((point) => ({ x: point.age, p10: point.p10, p50: point.p50, p90: point.p90 })),
        line: selected.value.rows.map((row) => ({ x: row.age, y: row.total_balance })),
        runs: props.monte_carlo.results.runs,
    };
});

// The strategy under the closer look. It follows the list: the first one to
// begin with, and the first again if the one being looked at is removed.
const selectedId = ref(props.strategies[0]?.id ?? null);

watch(() => props.strategies.map((strategy) => strategy.id), (ids) => {
    if (!ids.includes(selectedId.value)) selectedId.value = ids[0] ?? null;
});

const selectedIndex = computed(() => props.strategies.findIndex((strategy) => strategy.id === selectedId.value));
const selected = computed(() => props.strategies[selectedIndex.value] ?? null);

const age = (value) => `Age ${value}`;

// One line per strategy, of whichever figure in its rows.
const lines = (field) => props.strategies.map((strategy, index) => ({
    label: strategy.name,
    color: colorOf(index),
    points: strategy.rows.map((row) => ({ x: row.age, y: row[field] })),
}));

const tierName = (tier) => (tier === 0 ? 'no surcharge' : `tier ${tier}`);

/*
 * Each line on the two charts is named for what begins above it — "35%
 * starts", "Tier 4 starts" — not for what it is the top of. A year sitting
 * just over a line named "32%" reads as being in the 32% bracket, when that
 * line is where 32% ends.
 */
const startsAt = (lines) => lines.map((line) => ({ ...line, label: `${line.above} starts` }));

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

const balanceLines = computed(() => [
    { label: 'Traditional', color: chartColors[1], points: selected.value.rows.map((row) => ({ x: row.age, y: row.traditional })) },
    { label: 'Roth', color: chartColors[0], points: selected.value.rows.map((row) => ({ x: row.age, y: row.roth })) },
    { label: 'Taxable savings', color: chartColors[2], dashed: true, points: selected.value.rows.map((row) => ({ x: row.age, y: row.taxable })) },
]);

/*
 * The comparison, a row to a figure. `best` names which end of the row is
 * the one to want, and marks whichever strategies reach it — only where the
 * figure is one a person would actually choose a strategy by.
 */
const figures = [
    { key: 'converted', label: 'Converted to Roth' },
    { key: 'total_rmd', label: 'RMDs taken' },
    { key: 'lifetime_tax', label: 'Tax you pay', best: 'low', note: (summary) => (summary.penalties ? `${moneyBrief(summary.penalties)} early-withdrawal penalty` : '') },
    { key: 'conversion_tax', label: 'of it, on the conversions', note: (summary) => (summary.conversion_tax_withheld ? `${moneyBrief(summary.conversion_tax_withheld)} from the converted money` : '') },
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
    if (!figure.best || props.strategies.length < 2) return null;

    const values = props.strategies.map((strategy) => strategy.summary[figure.key]);

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
    <FinShell title="Retirement strategizer" subtitle="Build strategies for moving traditional money to Roth, then compare what each does to your tax, your Medicare premiums and what is left.">
        <template #actions>
            <button type="button" class="fin-btn fin-btn-primary" @click="build"><IconPlus :size="16" /> Strategy</button>
        </template>

        <RetirementTabs current="conversions" />

        <div class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
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

            <EmptyState v-if="!strategies.length && !held.length" title="No strategies yet" :body="`A strategy is a way of converting, the projection it runs on, an inflation rate, and who inherits. Build a few and they are set side by side.${scenarios.length > 1 ? ' Starting with a set for each projection compares the first six and puts the rest in the holding area.' : ''}`">
                <button type="button" class="fin-btn fin-btn-primary" @click="addStarters"><IconSparkles :size="16" /> {{ scenarios.length ? `Start with one of each kind for each projection (${scenarios.length})` : 'Start with one of each kind' }}</button>
                <button type="button" class="fin-btn fin-btn-quiet" @click="build"><IconPlus :size="16" /> Build one</button>
            </EmptyState>

            <template v-else>
                <StrategyHoldingArea :held="held" :kinds="kinds" :scenarios="scenarios" :comparison="comparison" @build="build" @edit="edit" />

                <p v-if="!strategies.length" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                    Nothing is being compared. Add a strategy to the comparison from the holding area above.
                </p>
            </template>

            <template v-if="strategies.length">
                <Card title="Side by side" :subtitle="`Over the whole plan, in today's dollars. A green figure is the best in its row. ${comparison.count} of at most ${comparison.max} being compared.`" flush>
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="clearComparison"><IconArchive :size="16" /> Clear Comparison</button>
                    </template>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left align-top text-xs text-fin-grey-500">
                                    <th class="sticky left-0 bg-fin-cream-50 px-5 py-2.5 font-medium">&nbsp;</th>
                                    <th v-for="(strategy, index) in strategies" :key="strategy.id" class="min-w-44 px-3 py-2.5 text-right font-normal">
                                        <span class="flex items-center justify-end gap-1.5 text-sm font-semibold text-fin-black">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colorOf(index) }" aria-hidden="true" />
                                            {{ strategy.name }}
                                        </span>
                                        <span class="mt-0.5 block">{{ strategy.kind_label }} {{ convertsWhen(strategy) }}</span>
                                        <span v-if="kinds[strategy.kind]?.amount" class="block">{{ moneyBrief(strategy.conversion_amount) }} a year</span>
                                        <span class="block">{{ assumptions(strategy) }}</span>
                                        <span class="mt-1 flex justify-end">
                                            <button type="button" class="fin-icon-btn" :aria-label="`Edit ${strategy.name}`" @click="edit(strategy)"><IconPencil :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Copy ${strategy.name}`" @click="duplicate(strategy)"><IconCopy :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Move ${strategy.name} to the holding area`" title="Move to the holding area" @click="hold(strategy)"><IconArchive :size="15" /></button>
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
                                        v-for="strategy in strategies" :key="strategy.id" class="whitespace-nowrap px-3 py-2.5 text-right"
                                        :class="[figure.strong ? 'font-semibold' : '', strategy.summary[figure.key] === bestOf(figure) ? 'text-fin-green-600' : 'text-fin-black']"
                                        :title="money(strategy.summary[figure.key])"
                                    >
                                        {{ moneyBrief(strategy.summary[figure.key]) }}
                                        <span v-if="figure.note?.(strategy.summary, strategy)" class="block text-[11px] font-normal text-fin-grey-500">{{ figure.note(strategy.summary, strategy) }}</span>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">Highest bracket reached</th>
                                    <td v-for="strategy in strategies" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black">
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
                        <div class="rounded-2xl border border-fin-grey-200 bg-fin-white p-5">
                            <p class="text-sm font-semibold text-fin-black">Across many markets</p>
                            <p class="mt-0.5 text-xs text-fin-grey-500">Running the strategies through random markets…</p>
                            <div class="mt-4 flex flex-col gap-2">
                                <div v-for="line in 4" :key="line" class="h-5 animate-pulse rounded bg-fin-cream-200" :style="{ width: `${100 - line * 12}%` }" />
                            </div>
                        </div>
                    </template>

                    <MonteCarloCard :monte-carlo="monte_carlo" :strategies="strategies" :color-of="colorOfStrategy" />
                </Deferred>

                <div v-if="strategies.length > 1" class="grid gap-5 xl:grid-cols-2">
                    <Card title="Tax and IRMAA each year" subtitle="What each strategy costs, year by year.">
                        <LineChart :series="lines('tax_and_irmaa')" :height="240" :format-x="age" :x-ticks="6" />
                    </Card>
                    <Card title="Traditional and Roth together" subtitle="The retirement accounts, whichever side the money is on.">
                        <LineChart :series="lines('retirement_balance')" :height="240" :format-x="age" :x-ticks="6" />
                    </Card>
                </div>

                <Card v-if="selected" title="A closer look" subtitle="One strategy against the lines that matter: the tops of the tax brackets, and the IRMAA tiers.">
                    <template v-if="strategies.length > 1" #actions>
                        <div class="flex flex-wrap gap-1.5" role="group" aria-label="Strategy to look at">
                            <button
                                v-for="(strategy, index) in strategies" :key="strategy.id" type="button"
                                class="flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium"
                                :class="strategy.id === selectedId ? 'border-fin-charcoal bg-fin-charcoal text-fin-white' : 'border-fin-grey-300 bg-fin-white text-fin-charcoal hover:bg-fin-cream-100'"
                                :aria-pressed="strategy.id === selectedId" @click="selectedId = strategy.id"
                            >
                                <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: colorOf(index) }" aria-hidden="true" />
                                {{ strategy.name }}
                            </button>
                        </div>
                    </template>

                    <div class="flex flex-col gap-7">
                        <div class="grid gap-7 xl:grid-cols-2">
                            <section>
                                <h3 class="text-sm font-semibold text-fin-black">Income against the tax brackets</h3>
                                <p class="mb-3 mt-0.5 text-xs text-fin-grey-500">
                                    Taxable ordinary income — the projection's, plus RMDs, conversions and withdrawals, less the standard deduction — against the federal brackets; each line is where the bracket named on it begins. The dashed line is the year had it not converted; the gold between is what converting added.
                                </p>
                                <ThresholdChart :points="bracketPoints" :thresholds="startsAt(brackets)" :color="colorOf(selectedIndex)" :format-x="age" label="Taxable income" />
                            </section>
                            <section>
                                <h3 class="text-sm font-semibold text-fin-black">Income against the IRMAA tiers</h3>
                                <p class="mb-3 mt-0.5 text-xs text-fin-grey-500">
                                    Each line is where the tier named on it begins. Crossing one raises Medicare premiums two years later, and by the whole step, not a slice. Only years from 63 on can cost anything. Income is shown in the prices of the year it sets the premium for, so it reads against today's tiers.
                                </p>
                                <ThresholdChart :points="irmaaPoints" :thresholds="startsAt(irmaa_tiers)" :color="colorOf(selectedIndex)" :format-x="age" label="Income for IRMAA" />
                            </section>
                        </div>

                        <section v-if="fan">
                            <h3 class="text-sm font-semibold text-fin-black">Everything left, across {{ fan.runs }} markets</h3>
                            <p class="mb-3 mt-0.5 text-xs text-fin-grey-500">Traditional, Roth and taxable together, in today's dollars. The band is where the middle 80% of markets land.</p>
                            <FanChart :band="fan.band" :line="fan.line" :color="colorOf(selectedIndex)" :format-x="age" />
                        </section>

                        <section>
                            <h3 class="mb-3 text-sm font-semibold text-fin-black">Balances</h3>
                            <LineChart :series="balanceLines" :height="230" :format-x="age" :x-ticks="8" />
                        </section>

                        <section>
                            <h3 class="mb-3 text-sm font-semibold text-fin-black">Year by year</h3>
                            <div class="max-h-[26rem] overflow-auto rounded-xl border border-fin-grey-200">
                                <table class="w-full text-sm">
                                    <thead class="sticky top-0">
                                        <tr class="bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                            <th class="px-4 py-2.5 font-medium">Age</th>
                                            <th class="px-3 py-2.5 text-right font-medium" title="What your projection brings in, before tax">Income</th>
                                            <th class="px-3 py-2.5 text-right font-medium">RMD</th>
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

                <p class="text-xs text-fin-grey-500">
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
