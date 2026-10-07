<script setup>
import { router } from '@inertiajs/vue3';
import { IconChevronDown } from '@tabler/icons-vue';
import { computed, reactive, ref, watch } from 'vue';
import YearSliderChart from './YearSliderChart.vue';
import { money, moneyShort, moneySigned, percent, percentSigned, rateTone } from '../lib/format';
import { numberOrNull } from '../lib/input';

/**
 * One asset or liability inside a scenario: a row that opens onto its rate,
 * its monthly contribution or payment, and its value year by year.
 *
 * The point of the row is the split it shows. What a holding is worth at the
 * end of a year is where it started, plus what it *earned* (growth, or for a
 * debt the interest), plus what was *moved* — contributions, the income and
 * expenses that name it as their account, and transfers. The two are kept
 * apart all the way down.
 *
 * `draft` works as it does on ScenarioFlowRow: what this scenario says about
 * the holding, saved whole on every change. One difference in the chart: a
 * year-end set by hand moves the years after it too, so only the server's
 * answer shows where they land.
 */
const props = defineProps({
    holding: { type: Object, required: true },
    scenarioId: { type: Number, required: true },
    retirementYear: { type: Number, default: null },
    color: { type: String, default: 'var(--color-fin-chart-1)' },
});

const open = ref(false);
const error = ref('');
const inFlight = ref(0);

const isAsset = computed(() => props.holding.side === 'asset');

const saved = () => ({
    rate: props.holding.has_scenario_rate ? props.holding.rate : null,
    contribution: props.holding.has_scenario_contribution ? props.holding.contribution : null,
    pins: Object.fromEntries(props.holding.series.filter((point) => point.is_pinned).map((point) => [point.year, point.amount])),
});

const draft = reactive(saved());

watch(() => JSON.stringify(saved()), () => {
    if (inFlight.value === 0) Object.assign(draft, saved());
});

const save = () => {
    error.value = '';
    inFlight.value += 1;

    router.put(`/finance/scenarios/${props.scenarioId}/holdings/${props.holding.id}`, { annual_rate: draft.rate, monthly_contribution: draft.contribution, overrides: { ...draft.pins } }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => {
            error.value = Object.values(errors)[0] ?? 'That could not be saved.';
            Object.assign(draft, saved());
        },
        onFinish: () => {
            inFlight.value -= 1;
        },
    });
};

const setRate = (event) => { draft.rate = numberOrNull(event); save(); };
const setContribution = (event) => { draft.contribution = numberOrNull(event); save(); };
const reset = (field) => { draft[field] = null; save(); };

const pin = (year, amount) => { draft.pins = { ...draft.pins, [year]: amount }; save(); };
const unpin = (year) => { draft.pins = Object.fromEntries(Object.entries(draft.pins).filter(([pinned]) => Number(pinned) !== year)); save(); };
const clearPins = () => { draft.pins = {}; save(); };

// The server's series with the draft's pins laid over it, so a year just
// dragged shows where it was put before the server has answered.
const points = computed(() => props.holding.series.map((point) => {
    const isPinned = point.year in draft.pins;

    return { ...point, amount: isPinned ? draft.pins[point.year] : point.amount, is_pinned: isPinned };
}));

const pinnedCount = computed(() => points.value.filter((point) => point.is_pinned).length);
const rate = computed(() => draft.rate ?? props.holding.own_rate);
const contribution = computed(() => draft.contribution ?? props.holding.own_contribution);
</script>

<template>
    <div class="border-t border-fin-grey-100">
        <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-left hover:bg-fin-cream-50" :aria-expanded="open" @click="open = !open">
            <span class="min-w-[14rem] flex-1">
                <span class="font-medium text-fin-black">{{ holding.name }}</span>
                <span class="ml-2 text-xs text-fin-grey-500">{{ holding.type_label }}</span>
                <span v-if="holding.unfunded > 0" class="ml-2 whitespace-nowrap rounded-full bg-fin-red-100 px-2 py-0.5 text-[11px] font-medium text-fin-red-600">{{ moneyShort(holding.unfunded) }} it could not pay</span>
            </span>

            <span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium" :class="rateTone(holding.side, rate)">
                {{ isAsset ? percentSigned(rate) : percent(rate) }} {{ isAsset ? '/ yr' : 'APR' }}
            </span>
            <span v-if="pinnedCount" class="whitespace-nowrap rounded-full bg-fin-gold-100 px-2 py-0.5 text-[11px] font-medium text-fin-gold-600">
                {{ pinnedCount }} {{ pinnedCount === 1 ? 'year' : 'years' }} by hand
            </span>

            <span class="w-28 text-right text-sm tabular-nums text-fin-charcoal">{{ money(holding.start) }}<span class="block text-[11px] text-fin-grey-500">today</span></span>
            <span class="w-28 text-right text-sm tabular-nums" :class="isAsset ? 'text-fin-green-600' : 'text-fin-red-600'">{{ moneyShort(holding.growth) }}<span class="block text-[11px] text-fin-grey-500">{{ isAsset ? 'value gained' : 'interest' }}</span></span>
            <span class="w-28 text-right text-sm tabular-nums text-fin-charcoal">{{ isAsset ? moneySigned(holding.moved) : money(holding.moved) }}<span class="block text-[11px] text-fin-grey-500">{{ isAsset ? 'money moved in' : 'paid off' }}</span></span>
            <span class="w-24 text-right text-sm font-medium tabular-nums text-fin-black">{{ moneyShort(holding.end) }}<span class="block text-[11px] font-normal text-fin-grey-500">end of plan</span></span>

            <IconChevronDown :size="18" class="shrink-0 text-fin-grey-400 transition-transform" :class="{ 'rotate-180': open }" />
        </button>

        <div v-if="open" class="flex flex-col gap-4 border-t border-fin-grey-100 bg-fin-white px-5 pb-5 pt-4">
            <div class="flex flex-wrap items-end gap-x-4 gap-y-3">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-fin-grey-600">{{ isAsset ? 'Value grows' : 'Interest rate' }}</span>
                    <span class="relative block w-36">
                        <input :value="rate" type="number" min="-100" max="100" step="0.1" class="!pr-14" @change="setRate">
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-fin-grey-400">{{ isAsset ? '% / yr' : '% APR' }}</span>
                    </span>
                </label>
                <button v-if="draft.rate !== null" type="button" class="fin-btn fin-btn-quiet" @click="reset('rate')">Use its own ({{ percent(holding.own_rate) }})</button>

                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-fin-grey-600">{{ isAsset ? 'Contribution' : 'Payment' }}</span>
                    <span class="relative block w-40">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-fin-grey-400">$</span>
                        <input :value="contribution" type="number" min="0" step="10" class="!pl-6 !pr-12" @change="setContribution">
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-fin-grey-400">/ mo</span>
                    </span>
                </label>
                <button v-if="draft.contribution !== null" type="button" class="fin-btn fin-btn-quiet" @click="reset('contribution')">Use its own ({{ money(holding.own_contribution) }})</button>

                <button v-if="pinnedCount" type="button" class="fin-btn fin-btn-quiet ml-auto" @click="clearPins">Clear the years set by hand</button>
            </div>

            <p class="text-xs text-fin-grey-500">
                <template v-if="isAsset">Contributions stop when you retire. Drag a year to set what it is worth at that year's end — a sale, a revaluation — and the years after carry on from there.</template>
                <template v-else>The payment runs until nothing is owed. Drag a year to set what is owed at that year's end — a refinance, a lump sum — and the years after carry on from there.</template>
            </p>

            <p v-if="error" class="text-xs text-fin-red-600" role="alert">{{ error }}</p>

            <YearSliderChart :points="points" :color="color" :retirement-year="retirementYear" :label="holding.name" @pin="pin" @unpin="unpin" />

            <div class="max-h-72 overflow-auto rounded-xl border border-fin-grey-200">
                <table class="w-full text-sm">
                    <thead class="sticky top-0">
                        <tr class="bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                            <th class="px-4 py-2 font-medium">Year</th>
                            <th class="px-3 py-2 text-right font-medium">Started at</th>
                            <th class="px-3 py-2 text-right font-medium">{{ isAsset ? 'Value gained' : 'Interest' }}</th>
                            <th class="px-3 py-2 text-right font-medium" :title="isAsset ? 'Contributions, income paid in and transfers in, less expenses paid out and transfers out' : 'Payments and transfers in'">{{ isAsset ? 'Money moved in' : 'Paid off' }}</th>
                            <th class="px-4 py-2 text-right font-medium">Ended at</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="year in holding.series" :key="year.year" class="border-t border-fin-grey-100">
                            <td class="px-4 py-1.5 font-medium text-fin-black">{{ year.year }} <span class="font-normal text-fin-grey-500">· {{ year.age }}</span></td>
                            <td class="px-3 py-1.5 text-right text-fin-charcoal">{{ money(year.start) }}</td>
                            <td class="px-3 py-1.5 text-right" :class="isAsset ? 'text-fin-green-600' : 'text-fin-red-600'">{{ isAsset ? moneySigned(year.growth) : money(year.growth) }}</td>
                            <td class="px-3 py-1.5 text-right text-fin-charcoal">
                                {{ isAsset ? moneySigned(year.moved) : money(year.moved) }}
                                <span v-if="year.unfunded > 0" class="block text-[11px] text-fin-red-600">{{ money(year.unfunded) }} it could not pay</span>
                            </td>
                            <td class="px-4 py-1.5 text-right font-medium text-fin-black">{{ money(year.amount) }}<span v-if="year.is_pinned" class="ml-1 text-[11px] font-normal text-fin-gold-600">by hand</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
