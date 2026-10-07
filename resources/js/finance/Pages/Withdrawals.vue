<script setup>
import { router } from '@inertiajs/vue3';
import { IconCopy, IconPencil, IconPlus, IconSparkles, IconTrash } from '@tabler/icons-vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import RetirementTabs from '../Components/RetirementTabs.vue';
import StatTile from '../Components/StatTile.vue';
import StrategyPicker from '../Components/StrategyPicker.vue';
import WithdrawalStrategyForm from '../Components/WithdrawalStrategyForm.vue';
import { age, bucketLines, useStrategyBoard } from '../composables/useStrategyBoard';
import { colorOf, money, moneyBrief } from '../lib/format';

/**
 * The withdrawals tab: ways of drawing the retirement accounts down, built by
 * the user, run by the server over the same lifetime, and set side by side.
 *
 * Nothing here calculates. Each strategy arrives with its year-by-year rows
 * and its summary already worked out (WithdrawalBoard).
 */
const props = defineProps({
    profile: Object,
    balances: Object,
    growth_rate: Number,
    tax_year: Number,
    kinds: Object,
    spending_rules: Object,
    fill_rates: Array,
    default_percent: Number,
    default_spending_amount: Number,
    scenarios: Array,
    strategies: Array,
});

const { form, build, edit, selectedId, selectedIndex, selected, lines } = useStrategyBoard(() => props.strategies);

const addStarters = () => router.post('/finance/retirement/withdrawals/strategies/starters', {}, { preserveScroll: true });
const duplicate = (strategy) => router.post(`/finance/retirement/withdrawals/strategies/${strategy.id}/duplicate`, {}, { preserveScroll: true });

const remove = (strategy) => {
    if (window.confirm(`Remove ${strategy.name}?`)) {
        router.delete(`/finance/retirement/withdrawals/strategies/${strategy.id}`, { preserveScroll: true });
    }
};




/*
 * The comparison, a row to a figure. `best` names which end of the row is the
 * one to want, and marks whichever strategies reach it.
 */
const figures = [
    { key: 'withdrawn', label: 'Taken from the accounts' },
    { key: 'from_taxable', label: 'of it, from savings' },
    { key: 'from_traditional', label: 'of it, from traditional', note: (summary) => (summary.total_rmd ? `${moneyBrief(summary.total_rmd)} as RMDs` : '') },
    { key: 'from_roth', label: 'of it, from Roth' },
    { key: 'spendable', label: 'To spend, once retired', best: 'high', note: (summary) => (summary.least_spendable === null ? '' : `${moneyBrief(summary.least_spendable)} in the leanest year`) },
    { key: 'lifetime_tax', label: 'Tax you pay', best: 'low' },
    { key: 'irmaa', label: 'IRMAA surcharges', best: 'low' },
    { key: 'tax_and_irmaa', label: 'Tax and IRMAA together', best: 'low', strong: true },
    { key: 'ending_traditional', label: 'Traditional at the end' },
    { key: 'ending_roth', label: 'Roth at the end' },
    { key: 'ending_taxable', label: 'Taxable savings at the end' },
    { key: 'ending_balance', label: 'Left at the end', best: 'high', strong: true },
];

const bestOf = (figure) => {
    if (!figure.best || props.strategies.length < 2) return null;

    const values = props.strategies.map((strategy) => strategy.summary[figure.key]);

    return figure.best === 'low' ? Math.min(...values) : Math.max(...values);
};

const takes = (strategy) => {
    if (strategy.spending_rule === 'fixed') return `${moneyBrief(strategy.spending_amount)} a year`;
    if (strategy.spending_rule === 'percent') return `${strategy.spending_percent}% of the balance`;

    return 'What the projection needs';
};

const assumptions = (strategy) => [
    strategy.assumptions.scenario_name ?? 'As entered',
    `${strategy.assumptions.inflation_rate}% inflation`,
    `${strategy.assumptions.growth_rate}% growth`,
].join(' · ');
</script>

<template>
    <FinShell title="Retirement strategizer" subtitle="Which account to spend from first, and how much to take: the order changes the tax, and the tax changes how long the money lasts.">
        <template #actions>
            <button type="button" class="fin-btn fin-btn-primary" @click="build"><IconPlus :size="16" /> Strategy</button>
        </template>

        <RetirementTabs current="withdrawals" />

        <div class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile accent label="The plan" :value="`${profile.age} to ${profile.life_expectancy}`" :hint="`${profile.filing_status} · retiring at ${profile.retirement_age}`" />
                <StatTile label="Traditional" :value="money(balances.deferred)" :hint="`Taxed on the way out · RMDs from ${profile.rmd_start_age}`" />
                <StatTile label="Roth & HSA" :value="money(balances.free)" hint="Never taxed again" />
                <StatTile label="Taxable savings" :value="money(balances.taxable)" hint="Already taxed" />
            </div>

            <p v-if="!profile.has_birth_date" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                Your profile has no birth date, so every strategy assumes you are 40. Set it in Profile &amp; settings for ages that mean something.
            </p>
            <p v-if="balances.total <= 0" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                There are no investable accounts in your fleet, so there is nothing to draw down and every strategy will come out the same.
            </p>

            <EmptyState v-if="!strategies.length" title="No strategies yet" body="A strategy is an order to draw the accounts in, how much a retired year takes, and the projection it runs on. Build a few and they are set side by side.">
                <button type="button" class="fin-btn fin-btn-primary" @click="addStarters"><IconSparkles :size="16" /> Start with one for each order</button>
                <button type="button" class="fin-btn fin-btn-quiet" @click="build"><IconPlus :size="16" /> Build one</button>
            </EmptyState>

            <template v-else>
                <Card title="Side by side" subtitle="Over the whole plan, in today's dollars. A green figure is the best in its row." flush>
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
                                        <span class="mt-0.5 block">{{ strategy.kind_label }}<template v-if="kinds[strategy.kind]?.fills"> · {{ strategy.fill_rate === null ? 'the bracket it is in' : `${strategy.fill_rate}%` }}</template></span>
                                        <span class="block">{{ takes(strategy) }}</span>
                                        <span class="block">{{ assumptions(strategy) }}</span>
                                        <span class="mt-1 flex justify-end">
                                            <button type="button" class="fin-icon-btn" :aria-label="`Edit ${strategy.name}`" @click="edit(strategy)"><IconPencil :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Copy ${strategy.name}`" @click="duplicate(strategy)"><IconCopy :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Remove ${strategy.name}`" @click="remove(strategy)"><IconTrash :size="15" /></button>
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="figure in figures" :key="figure.key" class="border-b border-fin-grey-100" :class="{ 'bg-fin-cream-50/60': figure.strong }">
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left" :class="figure.strong ? 'font-semibold text-fin-black' : 'font-medium text-fin-charcoal'">{{ figure.label }}</th>
                                    <td
                                        v-for="strategy in strategies" :key="strategy.id" class="whitespace-nowrap px-3 py-2.5 text-right"
                                        :class="[figure.strong ? 'font-semibold' : '', strategy.summary[figure.key] === bestOf(figure) ? 'text-fin-green-600' : 'text-fin-black']"
                                        :title="money(strategy.summary[figure.key])"
                                    >
                                        {{ moneyBrief(strategy.summary[figure.key]) }}
                                        <span v-if="figure.note?.(strategy.summary)" class="block text-[11px] font-normal text-fin-grey-500">{{ figure.note(strategy.summary) }}</span>
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

                <div class="grid gap-5 xl:grid-cols-2">
                    <Card title="Everything left" subtitle="Traditional, Roth and taxable savings together, year by year.">
                        <LineChart :series="lines('total_balance')" :height="240" :format-x="age" :x-ticks="6" />
                    </Card>
                    <Card title="Tax and IRMAA each year" subtitle="What each order costs, year by year.">
                        <LineChart :series="lines('tax_and_irmaa')" :height="240" :format-x="age" :x-ticks="6" />
                    </Card>
                </div>

                <Card v-if="selected" title="A closer look" subtitle="One strategy: where each year's money comes from, and what that leaves in each account.">
                    <template v-if="strategies.length > 1" #actions>
                        <StrategyPicker v-model="selectedId" :strategies="strategies" :color-of="colorOf" />
                    </template>

                    <div class="flex flex-col gap-7">
                        <section>
                            <h3 class="mb-3 text-sm font-semibold text-fin-black">Balances</h3>
                            <LineChart :series="bucketLines(selected)" :height="230" :format-x="age" :x-ticks="8" />
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
                                            <th class="px-3 py-2.5 text-right font-medium">From savings</th>
                                            <th class="px-3 py-2.5 text-right font-medium" title="Beyond the RMD">From traditional</th>
                                            <th class="px-3 py-2.5 text-right font-medium">From Roth</th>
                                            <th class="px-3 py-2.5 text-right font-medium">Tax</th>
                                            <th class="px-3 py-2.5 text-right font-medium">Bracket</th>
                                            <th class="px-3 py-2.5 text-right font-medium">IRMAA</th>
                                            <th class="px-3 py-2.5 text-right font-medium" title="After tax and IRMAA">To spend</th>
                                            <th class="px-4 py-2.5 text-right font-medium">Left</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="row in selected.rows" :key="row.age" class="border-t border-fin-grey-100" :class="{ 'bg-fin-cream-50/40': !row.is_retired }">
                                            <td class="whitespace-nowrap px-4 py-2 font-medium text-fin-black">{{ row.age }} <span class="font-normal text-fin-grey-500">· {{ row.year }}<template v-if="!row.is_retired"> · working</template></span></td>
                                            <td class="px-3 py-2 text-right text-fin-charcoal">{{ money(row.income) }}</td>
                                            <td class="px-3 py-2 text-right" :class="row.rmd ? 'text-fin-charcoal' : 'text-fin-grey-400'">{{ row.rmd ? money(row.rmd) : '—' }}</td>
                                            <td class="px-3 py-2 text-right" :class="row.from_taxable ? 'text-fin-charcoal' : 'text-fin-grey-400'">{{ row.from_taxable ? money(row.from_taxable) : '—' }}</td>
                                            <td class="px-3 py-2 text-right" :class="row.from_traditional ? 'font-medium text-fin-gold-600' : 'text-fin-grey-400'">{{ row.from_traditional ? money(row.from_traditional) : '—' }}</td>
                                            <td class="px-3 py-2 text-right" :class="row.from_roth ? 'text-fin-charcoal' : 'text-fin-grey-400'">{{ row.from_roth ? money(row.from_roth) : '—' }}</td>
                                            <td class="px-3 py-2 text-right text-fin-charcoal">{{ money(row.tax) }}</td>
                                            <td class="px-3 py-2 text-right text-fin-charcoal">{{ row.marginal_rate }}%</td>
                                            <td class="px-3 py-2 text-right" :class="row.irmaa ? 'font-medium text-fin-red-600' : 'text-fin-grey-400'">{{ row.irmaa ? money(row.irmaa) : '—' }}</td>
                                            <td class="px-3 py-2 text-right font-medium text-fin-black">
                                                {{ money(row.spendable) }}
                                                <span v-if="row.unmet" class="block text-[11px] text-fin-red-600">{{ money(row.unmet) }} short</span>
                                            </td>
                                            <td class="px-4 py-2 text-right text-fin-charcoal">{{ money(row.total_balance) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    </div>
                </Card>

                <p class="text-xs text-fin-grey-500">
                    A planning model, not tax advice, and the same one the Roth conversions tab runs with the conversions taken out: the {{ tax_year }} federal brackets, your standard deduction (plus the additional deduction from 65), your state and local brackets and the IRMAA tiers, all raised each year by the strategy's inflation rate, and the IRS Uniform Lifetime Table for RMDs.
                    While you are working a year covers its expenses, tax and contributions from income, saves what is spare and draws any shortfall in the strategy's order. Money taken from traditional before 59½ pays the 10% penalty.
                    It leaves out tax on growth and sales in the taxable account, the net investment income tax, a survivor moving to single brackets, the Roth five-year rules and Social Security's real taxation formula. Those bear on every order alike, so the comparison holds up better than any single figure does.
                </p>
            </template>
        </div>

        <WithdrawalStrategyForm
            :open="form.open" :strategy="form.strategy" :kinds="kinds" :spending-rules="spending_rules" :fill-rates="fill_rates" :scenarios="scenarios"
            :profile="profile" :growth-rate="growth_rate" :balance="balances.total" :default-amount="default_spending_amount" :default-percent="default_percent" @close="form.open = false"
        />
    </FinShell>
</template>
