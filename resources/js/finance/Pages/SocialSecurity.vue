<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { IconArrowsExchange, IconPencil, IconPlus, IconSparkles, IconTrash } from '@tabler/icons-vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import Field from '../Components/Field.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import RetirementTabs from '../Components/RetirementTabs.vue';
import SocialSecurityStrategyForm from '../Components/SocialSecurityStrategyForm.vue';
import StatTile from '../Components/StatTile.vue';
import StrategyPicker from '../Components/StrategyPicker.vue';
import { age, useStrategyBoard } from '../composables/useStrategyBoard';
import { asMonth, colorOf, money, moneyBrief, moneyExact } from '../lib/format';

/**
 * The Social Security tab: claiming strategies built by the user, run by the
 * server over the same lifetimes, and set side by side.
 *
 * Nothing here calculates. Each strategy arrives with its year-by-year rows
 * and its summary already worked out (SocialSecurityBoard).
 */
const props = defineProps({
    profile: Object,
    benefits: Object,
    people: Object,
    has_spouse: Boolean,
    has_benefit: Boolean,
    ages: Object,
    growth_rate: Number,
    strategies: Array,
});

// The household's benefits: facts about the people, saved on the profile.
const benefitsForm = useForm({ ...props.benefits });

benefitsForm.transform((data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])));

const saveBenefits = () => benefitsForm.put('/finance/retirement/social-security/benefits', { preserveScroll: true });

const { form, build, edit, selectedId, selectedIndex, selected, lines } = useStrategyBoard(() => props.strategies);

const addStarters = () => router.post('/finance/retirement/social-security/strategies/starters', {}, { preserveScroll: true });

const remove = (strategy) => {
    if (window.confirm(`Remove ${strategy.name}?`)) {
        router.delete(`/finance/retirement/social-security/strategies/${strategy.id}`, { preserveScroll: true });
    }
};

const apply = (strategy) => {
    if (window.confirm(`Use ${strategy.name} as your Social Security income? It replaces any Social Security income an earlier strategy wrote, and every projection and retirement tool will run on it.`)) {
        router.post(`/finance/retirement/social-security/strategies/${strategy.id}/apply`, {}, { preserveScroll: true });
    }
};


const claimAge = (claim) => `${claim.age}${claim.months ? ` and ${claim.months} mo` : ''}`;

const figures = [
    { key: 'monthly', label: 'A month, once everyone has claimed', format: moneyExact },
    { key: 'lifetime', label: 'Over the whole plan', best: true, strong: true },
    { key: 'present_value', label: 'Worth today', best: true, strong: true },
];

const bestOf = (figure) => (figure.best && props.strategies.length > 1 ? Math.max(...props.strategies.map((strategy) => strategy.summary[figure.key])) : null);

const fra = (person) => `${person.full_retirement_age.years}${person.full_retirement_age.months ? ` and ${person.full_retirement_age.months} months` : ''}`;
</script>

<template>
    <FinShell title="Retirement strategizer" subtitle="When to claim Social Security: a bigger cheque for waiting, against the years of cheques given up to get it.">
        <template #actions>
            <button type="button" class="fin-btn fin-btn-primary" :disabled="!has_benefit" @click="build"><IconPlus :size="16" /> Strategy</button>
        </template>

        <RetirementTabs current="social-security" />

        <div class="flex flex-col gap-5">
            <p v-if="!profile.has_birth_date" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                Your profile has no birth date, so every strategy assumes you are 40. Set it in Profile &amp; settings: full retirement age depends on the year you were born.
            </p>

            <Card title="Your benefits" subtitle="The monthly benefit at full retirement age, in today's dollars, from each person's Social Security statement (ssa.gov/myaccount). Everything on this page is worked from these.">
                <form class="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-5" @submit.prevent="saveBenefits">
                    <Field label="Your benefit" prefix="$" suffix="/ mo" :hint="`At ${fra(people.self)}, your full retirement age.`" :error="benefitsForm.errors.ss_monthly_benefit">
                        <input v-model.number="benefitsForm.ss_monthly_benefit" type="number" min="0" step="1">
                    </Field>
                    <Field label="Spouse's birth date" hint="Blank if there is no spouse to claim for." :error="benefitsForm.errors.spouse_birth_date">
                        <input v-model="benefitsForm.spouse_birth_date" type="date">
                    </Field>
                    <Field label="Spouse's benefit" prefix="$" suffix="/ mo" :hint="people.spouse ? `At ${fra(people.spouse)}. Zero if they have no record of their own.` : 'At their full retirement age.'" :error="benefitsForm.errors.spouse_ss_monthly_benefit">
                        <input v-model.number="benefitsForm.spouse_ss_monthly_benefit" type="number" min="0" step="1">
                    </Field>
                    <Field label="Spouse plans to age" :hint="`Blank is the same as you (${profile.life_expectancy}).`" :error="benefitsForm.errors.spouse_life_expectancy">
                        <input v-model.number="benefitsForm.spouse_life_expectancy" type="number" min="50" max="120" step="1" :placeholder="String(profile.life_expectancy)">
                    </Field>
                    <div class="sm:pt-6">
                        <button type="submit" class="fin-btn fin-btn-primary" :disabled="benefitsForm.processing || !benefitsForm.isDirty">Save benefits</button>
                    </div>
                </form>
            </Card>

            <EmptyState v-if="!has_benefit" title="Enter a benefit to begin" body="With a monthly benefit saved above, you can set claiming at 62 beside waiting to 70 and see what each is worth over your plan." />

            <EmptyState v-else-if="!strategies.length" title="No strategies yet" body="A strategy is an age to claim at for each person. Build a few and they are set side by side.">
                <button type="button" class="fin-btn fin-btn-primary" @click="addStarters"><IconSparkles :size="16" /> Start with 62, full retirement age and 70</button>
                <button type="button" class="fin-btn fin-btn-quiet" @click="build"><IconPlus :size="16" /> Build one</button>
            </EmptyState>

            <template v-else>
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile accent label="The plan" :value="`${profile.age} to ${profile.life_expectancy}`" :hint="`${profile.filing_status} · ${profile.inflation_rate}% inflation`" />
                    <StatTile v-for="(person, key) in people" :key="key" :label="`${person.label === 'You' ? 'Your' : 'Spouse\'s'} full benefit`" :value="`${money(person.benefit)} / mo`" :hint="`At ${fra(person)} · planned to ${person.life_expectancy}`" />
                </div>

                <Card title="Side by side" subtitle="In today's dollars. A green figure is the best in its row." flush>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left align-top text-xs text-fin-grey-500">
                                    <th class="sticky left-0 bg-fin-cream-50 px-5 py-2.5 font-medium">&nbsp;</th>
                                    <th v-for="(strategy, index) in strategies" :key="strategy.id" class="min-w-48 px-3 py-2.5 text-right font-normal">
                                        <span class="flex items-center justify-end gap-1.5 text-sm font-semibold text-fin-black">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colorOf(index) }" aria-hidden="true" />
                                            {{ strategy.name }}
                                        </span>
                                        <span class="mt-0.5 block">{{ strategy.assumptions.cola_rate }}% COLA · {{ strategy.assumptions.discount_rate }}% return</span>
                                        <span class="mt-1 flex justify-end">
                                            <button type="button" class="fin-icon-btn" :aria-label="`Use ${strategy.name} as your Social Security income`" title="Use as your Social Security income" @click="apply(strategy)"><IconArrowsExchange :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Edit ${strategy.name}`" @click="edit(strategy)"><IconPencil :size="15" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Remove ${strategy.name}`" @click="remove(strategy)"><IconTrash :size="15" /></button>
                                        </span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(person, key) in people" :key="key" class="border-b border-fin-grey-100">
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">{{ person.label === 'You' ? 'You claim' : 'Spouse claims' }}</th>
                                    <td v-for="strategy in strategies" :key="strategy.id" class="whitespace-nowrap px-3 py-2.5 text-right text-fin-black">
                                        at {{ claimAge(strategy.claims[key]) }} · {{ moneyExact(strategy.claims[key].monthly) }}
                                        <span class="block text-[11px] text-fin-grey-500">
                                            from {{ asMonth(strategy.claims[key].starts_on) }}<template v-if="strategy.claims[key].top_up > 0"> · {{ money(strategy.claims[key].top_up) }} of it on the other's record</template>
                                        </span>
                                    </td>
                                </tr>
                                <tr v-for="figure in figures" :key="figure.key" class="border-b border-fin-grey-100" :class="{ 'bg-fin-cream-50/60': figure.strong }">
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left" :class="figure.strong ? 'font-semibold text-fin-black' : 'font-medium text-fin-charcoal'">{{ figure.label }}</th>
                                    <td
                                        v-for="strategy in strategies" :key="strategy.id" class="whitespace-nowrap px-3 py-2.5 text-right"
                                        :class="[figure.strong ? 'font-semibold' : '', strategy.summary[figure.key] === bestOf(figure) ? 'text-fin-green-600' : 'text-fin-black']"
                                        :title="money(strategy.summary[figure.key])"
                                    >
                                        {{ (figure.format ?? moneyBrief)(strategy.summary[figure.key]) }}
                                    </td>
                                </tr>
                                <tr class="border-b border-fin-grey-100">
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">Pulls ahead of the earliest claim</th>
                                    <td v-for="strategy in strategies" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black">
                                        <template v-if="strategy.breakeven.against === null">—<span class="block text-[11px] text-fin-grey-500">pays soonest</span></template>
                                        <template v-else-if="strategy.breakeven.age === null">Never<span class="block text-[11px] text-fin-grey-500">inside the plan</span></template>
                                        <template v-else>At {{ strategy.breakeven.age }}<span class="block text-[11px] text-fin-grey-500">against {{ strategy.breakeven.against }}</span></template>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">Of it, taxable</th>
                                    <td v-for="strategy in strategies" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black">{{ strategy.summary.taxable_share }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <div class="grid gap-5 xl:grid-cols-2">
                    <Card title="Paid so far, by age" subtitle="The running total of each strategy. Where two lines cross is the age waiting pays for itself.">
                        <LineChart :series="lines('cumulative')" :height="260" :format-x="age" :x-ticks="6" />
                    </Card>
                    <Card title="Paid each year" subtitle="The household's benefit, both people together, in today's dollars.">
                        <LineChart :series="lines('total')" :height="260" :format-x="age" :x-ticks="6" />
                    </Card>
                </div>

                <Card v-if="selected" title="Year by year" flush>
                    <template v-if="strategies.length > 1" #actions>
                        <StrategyPicker v-model="selectedId" :strategies="strategies" :color-of="colorOf" />
                    </template>

                    <div class="max-h-[26rem] overflow-auto">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0">
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                    <th class="px-5 py-2.5 font-medium">Age</th>
                                    <th class="px-3 py-2.5 text-right font-medium">You</th>
                                    <th v-if="has_spouse" class="px-3 py-2.5 text-right font-medium">Spouse</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Together</th>
                                    <th class="px-3 py-2.5 text-right font-medium" title="By the provisional-income test, against the rest of your taxed income as projected">Taxable</th>
                                    <th class="px-5 py-2.5 text-right font-medium">Paid so far</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in selected.rows" :key="row.year" class="border-b border-fin-grey-100 last:border-0">
                                    <td class="px-5 py-2 font-medium text-fin-black">{{ row.age }} <span class="font-normal text-fin-grey-500">· {{ row.year }}</span></td>
                                    <td class="px-3 py-2 text-right" :class="row.self ? 'text-fin-charcoal' : 'text-fin-grey-400'">{{ row.self ? money(row.self) : '—' }}</td>
                                    <td v-if="has_spouse" class="px-3 py-2 text-right" :class="row.spouse ? 'text-fin-charcoal' : 'text-fin-grey-400'">{{ row.spouse ? money(row.spouse) : '—' }}</td>
                                    <td class="px-3 py-2 text-right font-medium text-fin-black">{{ row.total ? money(row.total) : '—' }}</td>
                                    <td class="px-3 py-2 text-right text-fin-charcoal">{{ row.total ? `${money(row.taxable)} · ${row.taxable_share}%` : '—' }}</td>
                                    <td class="px-5 py-2 text-right text-fin-charcoal">{{ money(row.cumulative) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <p class="text-xs text-fin-grey-500">
                    A planning model, not advice. A benefit claimed before full retirement age loses 5/9 of 1% for each of the first 36 months and 5/12 of 1% for each month beyond; one claimed after gains 2/3 of 1% a month up to 70.
                    A spouse is topped up to half the other's full benefit once both have claimed, and a survivor keeps the larger of the two. Someone already past a strategy's age claims now.
                    "Worth today" discounts each year by what the money could have earned over inflation. The taxable share is the provisional-income test, whose thresholds are not indexed, run against the rest of your taxed income as entered.
                    It leaves out the earnings test for someone claiming while still working, the restricted-application and widow's-limit rules, and benefits for children.
                    The <IconArrowsExchange :size="12" class="inline" /> button writes a strategy into Income &amp; expenses; the step up a survivor takes is not written there.
                </p>
            </template>
        </div>

        <SocialSecurityStrategyForm
            :open="form.open" :strategy="form.strategy" :people="people" :has-spouse="has_spouse" :ages="ages"
            :inflation-rate="profile.inflation_rate" :growth-rate="growth_rate" @close="form.open = false"
        />
    </FinShell>
</template>
