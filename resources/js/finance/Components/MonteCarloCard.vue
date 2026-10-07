<script setup>
import { router, useForm, usePoll } from '@inertiajs/vue3';
import { IconArrowsShuffle, IconPlayerPlay } from '@tabler/icons-vue';
import { computed, watch } from 'vue';
import Card from './Card.vue';
import Field from './Field.vue';
import { asDate, moneyBrief, money, number } from '../lib/format';

/**
 * The Monte Carlo settings and results on the conversion tab.
 *
 * `monteCarlo` is the server's whole account of it (ConversionMonteCarlo::for):
 * the settings, whether the runs are worked out with the page or in the
 * background, where a background run has got to, and the results.
 *
 * While a background run is waiting or under way the card asks for fresh
 * news every few seconds, and stops once it is done.
 */
const props = defineProps({
    monteCarlo: { type: Object, required: true },
    strategies: { type: Array, required: true },
    colorOf: { type: Function, required: true },
});

const form = useForm({ ...props.monteCarlo.settings });

// The server's settings are the form's starting point, until they are edited.
watch(() => props.monteCarlo.settings, (settings) => {
    if (!form.isDirty) {
        form.defaults({ runs: settings.runs, return_volatility: settings.return_volatility, inflation_volatility: settings.inflation_volatility });
        form.reset();
    }
});

const save = () => form.put('/finance/retirement/monte-carlo', { preserveScroll: true });
const runNow = () => router.post('/finance/retirement/monte-carlo/run', {}, { preserveScroll: true });
const reshuffle = () => router.post('/finance/retirement/monte-carlo/reshuffle', {}, { preserveScroll: true });

const isWorking = computed(() => ['queued', 'running'].includes(props.monteCarlo.status));

const { start, stop } = usePoll(3000, { only: ['monte_carlo'] }, { autoStart: false, mode: 'rest' });

watch(isWorking, (working) => (working ? start() : stop()), { immediate: true });

const results = computed(() => props.monteCarlo.results);
const resultFor = (strategy) => results.value?.strategies?.[strategy.id] ?? null;

// The strategies the stored results cover: one added since has none yet.
const covered = computed(() => props.strategies.filter((strategy) => resultFor(strategy)));

const outcomeRows = [
    { key: 'p10', label: 'In a bad market', hint: '10th percentile' },
    { key: 'p50', label: 'In a typical market', hint: 'median', strong: true },
    { key: 'p90', label: 'In a good market', hint: '90th percentile' },
];

const successTone = (rate) => {
    if (rate >= 90) return 'text-fin-green-600';
    if (rate >= 75) return 'text-fin-gold-600';

    return 'font-bold text-fin-red-600';
};
</script>

<template>
    <Card title="Across many markets" :subtitle="strategies.length === 1 ? 'Monte Carlo: the strategy run through a set of random markets, with returns and inflation varying year to year around its own rates.' : 'Monte Carlo: every strategy run through the same set of random markets, with returns and inflation varying year to year around each strategy\'s own rates.'" :class="{ 'printing:hidden': !(results && covered.length) }" flush>
        <form class="flex flex-wrap items-end gap-3 border-t border-fin-grey-100 px-5 py-4 printing:hidden" @submit.prevent="save">
            <div class="w-32">
                <Field label="Markets" hint="0 turns it off." :error="form.errors.runs">
                    <input v-model.number="form.runs" type="number" min="0" max="10000" step="50" required>
                </Field>
            </div>
            <div class="w-40">
                <Field label="Return swings" suffix="pts" hint="Yearly, either way." :error="form.errors.return_volatility">
                    <input v-model.number="form.return_volatility" type="number" min="0" max="50" step="0.5" required>
                </Field>
            </div>
            <div class="w-40">
                <Field label="Inflation swings" suffix="pts" hint="Yearly, either way." :error="form.errors.inflation_volatility">
                    <input v-model.number="form.inflation_volatility" type="number" min="0" max="10" step="0.1" required>
                </Field>
            </div>
            <div class="flex flex-wrap gap-2 pb-5">
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing || !form.isDirty">Save</button>
                <button type="button" class="fin-btn fin-btn-quiet" :disabled="monteCarlo.status === 'off' || isWorking" title="Draw a different set of markets" @click="reshuffle">
                    <IconArrowsShuffle :size="16" /> New markets
                </button>
            </div>
        </form>

        <!-- Where things stand. -->
        <div class="border-t border-fin-grey-100 px-5 py-3 text-xs text-fin-grey-600 printing:hidden">
            <template v-if="monteCarlo.status === 'off'">Off. Set a number of markets above to run the strategies through them.</template>
            <template v-else-if="!monteCarlo.in_background">
                {{ number(monteCarlo.settings.runs) }} markets × {{ strategies.length }} {{ strategies.length === 1 ? 'strategy' : 'strategies' }} =
                {{ number(monteCarlo.simulations) }} simulations, worked out as the page loads. Up to {{ number(monteCarlo.page_limit) }} are; more run in the background.
            </template>
            <template v-else>
                {{ number(monteCarlo.simulations) }} simulations is more than the {{ number(monteCarlo.page_limit) }} worked out as the page loads, so they run in the background.
                <span v-if="isWorking" class="ml-1 inline-flex items-center gap-1.5 font-medium text-fin-charcoal">
                    <span class="h-2 w-2 animate-pulse rounded-full bg-fin-gold-400" aria-hidden="true" />
                    {{ monteCarlo.status === 'queued' ? 'Waiting for a worker…' : 'Running…' }}
                </span>
                <template v-else-if="monteCarlo.status === 'failed'">
                    <span class="font-medium text-fin-red-600">The last run failed: {{ monteCarlo.error }}</span>
                </template>
                <template v-else-if="monteCarlo.ran_at">Last finished {{ asDate(monteCarlo.ran_at.slice(0, 10)) }}.</template>
            </template>
        </div>

        <div
            v-if="monteCarlo.in_background && !isWorking && (!results || !monteCarlo.is_current)"
            class="flex flex-wrap items-center justify-between gap-3 border-t border-fin-gold-300 bg-fin-gold-100 px-5 py-3 text-sm text-fin-charcoal printing:hidden"
        >
            <span>{{ results ? 'These results are from before your last change to the strategies or settings.' : 'Nothing has been run with these settings yet.' }}</span>
            <button type="button" class="fin-btn fin-btn-quiet" @click="runNow"><IconPlayerPlay :size="16" /> Run in the background</button>
        </div>

        <div v-if="results && covered.length" class="overflow-x-auto" :class="{ 'opacity-60': !monteCarlo.is_current }">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                        <th class="sticky left-0 bg-fin-cream-50 px-5 py-2.5 font-medium">Across {{ number(results.runs) }} markets</th>
                        <th v-for="strategy in covered" :key="strategy.id" class="min-w-40 px-3 py-2.5 text-right font-semibold text-fin-black">
                            <span class="flex items-center justify-end gap-1.5">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colorOf(strategy) }" aria-hidden="true" />
                                {{ strategy.label }}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-fin-grey-100 bg-fin-cream-50/60">
                        <th scope="row" class="sticky left-0 bg-fin-white px-5 py-2.5 text-left font-semibold text-fin-black">Money lasts</th>
                        <td v-for="strategy in covered" :key="strategy.id" class="px-3 py-2.5 text-right">
                            <span class="font-semibold" :class="successTone(resultFor(strategy).success_rate)">{{ resultFor(strategy).success_rate }}%</span>
                            <span class="block text-[11px] text-fin-grey-500">
                                {{ resultFor(strategy).typical_short_age ? `of markets; else out around ${resultFor(strategy).typical_short_age}` : 'of markets' }}
                            </span>
                        </td>
                    </tr>
                    <tr class="border-b border-fin-grey-100">
                        <th scope="row" class="sticky left-0 bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">Beats not converting</th>
                        <td v-for="strategy in covered" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black">
                            <template v-if="resultFor(strategy).beats_baseline === null">—</template>
                            <template v-else>
                                {{ resultFor(strategy).beats_baseline }}%
                                <span class="block text-[11px] text-fin-grey-500">of markets, on what is left</span>
                            </template>
                        </td>
                    </tr>
                    <tr v-for="outcome in outcomeRows" :key="outcome.key" class="border-b border-fin-grey-100" :class="{ 'bg-fin-cream-50/60': outcome.strong }">
                        <th scope="row" class="sticky left-0 whitespace-nowrap bg-fin-white px-5 py-2.5 text-left" :class="outcome.strong ? 'font-semibold text-fin-black' : 'font-medium text-fin-charcoal'">
                            Left after heirs' tax <span class="font-normal text-fin-grey-500">· {{ outcome.label.toLowerCase() }}</span>
                        </th>
                        <td v-for="strategy in covered" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black" :class="{ 'font-semibold': outcome.strong }" :title="money(resultFor(strategy).ending_after_heir_tax[outcome.key])">
                            {{ moneyBrief(resultFor(strategy).ending_after_heir_tax[outcome.key]) }}
                        </td>
                    </tr>
                    <tr class="border-b border-fin-grey-100">
                        <th scope="row" class="sticky left-0 bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">Tax and IRMAA, yours and theirs</th>
                        <td v-for="strategy in covered" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black">
                            {{ moneyBrief(resultFor(strategy).tax_with_heirs.p50) }}
                            <span class="block text-[11px] text-fin-grey-500">{{ moneyBrief(resultFor(strategy).tax_with_heirs.p10) }} – {{ moneyBrief(resultFor(strategy).tax_with_heirs.p90) }}</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row" class="sticky left-0 bg-fin-white px-5 py-2.5 text-left font-medium text-fin-charcoal">IRMAA surcharges</th>
                        <td v-for="strategy in covered" :key="strategy.id" class="px-3 py-2.5 text-right text-fin-black">
                            {{ moneyBrief(resultFor(strategy).irmaa.p50) }}
                            <span class="block text-[11px] text-fin-grey-500">{{ moneyBrief(resultFor(strategy).irmaa.p10) }} – {{ moneyBrief(resultFor(strategy).irmaa.p90) }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p class="border-t border-fin-grey-100 px-5 py-3 text-xs text-fin-grey-500 printing:px-0">
                Ranges run from a bad market (10th percentile) to a good one (90th). Returns and inflation are drawn from a bell curve around each strategy's own rates, so they understate how lopsided real crashes are. These are always in today's dollars, whichever the rest of the report is in: each market has its own inflation, so only one year's prices let them be compared.
            </p>
        </div>
    </Card>
</template>
