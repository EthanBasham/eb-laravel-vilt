<script setup>
import { router } from '@inertiajs/vue3';
import { IconChevronDown } from '@tabler/icons-vue';
import { computed, reactive, ref, watch } from 'vue';
import YearSliderChart from './YearSliderChart.vue';
import { money, moneyShort, percent } from '../lib/format';

/**
 * One income or expense inside a scenario: a row that opens onto its rate and
 * its year-by-year chart.
 *
 * `draft` is what this scenario says about the flow — the rate (null for the
 * flow's own) and the years pinned by hand — and it is what gets saved, whole,
 * on every change. It is kept here rather than read back off the props each
 * time because changes can outrun the server: drag two years in quick
 * succession and the second save has to carry the first, which the props do
 * not show yet.
 */
const props = defineProps({
    flow: { type: Object, required: true },
    scenarioId: { type: Number, required: true },
    retirementYear: { type: Number, default: null },
    color: { type: String, default: 'var(--color-fin-chart-1)' },
});

const open = ref(false);
const error = ref('');
const inFlight = ref(0);

const saved = () => ({
    rate: props.flow.has_scenario_rate ? props.flow.rate : null,
    pins: Object.fromEntries(props.flow.series.filter((point) => point.is_pinned).map((point) => [point.year, point.amount])),
});

const draft = reactive(saved());

// Something other than this row changed the flow's settings — the "apply to
// all" above the list — so take the server's word for it.
watch(() => JSON.stringify(saved()), () => {
    if (inFlight.value === 0) Object.assign(draft, saved());
});

const save = () => {
    error.value = '';
    inFlight.value += 1;

    router.put(`/finance/scenarios/${props.scenarioId}/flows/${props.flow.id}`, { annual_growth_rate: draft.rate, overrides: { ...draft.pins } }, {
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

const setRate = (event) => {
    const rate = Number(event.target.value);

    draft.rate = event.target.value === '' || Number.isNaN(rate) ? null : rate;
    save();
};

const useOwnRate = () => {
    draft.rate = null;
    save();
};

const pin = (year, amount) => {
    draft.pins = { ...draft.pins, [year]: amount };
    save();
};

const unpin = (year) => {
    draft.pins = Object.fromEntries(Object.entries(draft.pins).filter(([pinned]) => Number(pinned) !== year));
    save();
};

const clearPins = () => {
    draft.pins = {};
    save();
};

// The server's series with the draft's pins laid over it, so a year just
// dragged shows where it was put before the server has answered.
const points = computed(() => props.flow.series.map((point) => {
    const isPinned = point.year in draft.pins;

    return { ...point, amount: isPinned ? draft.pins[point.year] : point.base, is_pinned: isPinned };
}));

const pinnedCount = computed(() => points.value.filter((point) => point.is_pinned).length);
const rate = computed(() => draft.rate ?? props.flow.own_rate);
const signedRate = (value) => `${value > 0 ? '+' : ''}${percent(value)}`;
</script>

<template>
    <div class="border-t border-fin-grey-100">
        <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-left hover:bg-fin-cream-50" :aria-expanded="open" @click="open = !open">
            <span class="min-w-[14rem] flex-1">
                <span class="font-medium text-fin-black">{{ flow.name }}</span>
                <span class="ml-2 text-xs text-fin-grey-500">{{ flow.category_label }} · {{ flow.frequency_label }}<template v-if="flow.direction === 'income'"> · {{ flow.taxation_label ?? 'Not taxed' }}<template v-if="flow.taxation && flow.taxed_portion < 100"> ({{ flow.taxed_portion }}%)</template></template></span>
                <span v-if="flow.holding_name" class="ml-2 whitespace-nowrap rounded-full bg-fin-navy-100 px-2 py-0.5 text-[11px] text-fin-navy-700">{{ flow.holding_name }}</span>
            </span>

            <span v-if="!flow.is_one_time" class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium" :class="draft.rate === null ? 'bg-fin-grey-100 text-fin-grey-600' : 'bg-fin-green-100 text-fin-green-700'">
                {{ signedRate(rate) }} / yr
            </span>
            <span v-if="pinnedCount" class="whitespace-nowrap rounded-full bg-fin-gold-100 px-2 py-0.5 text-[11px] font-medium text-fin-gold-600">
                {{ pinnedCount }} {{ pinnedCount === 1 ? 'year' : 'years' }} by hand
            </span>

            <span class="w-28 text-right text-sm tabular-nums text-fin-charcoal">{{ money(points[0]?.amount) }}<span class="block text-[11px] text-fin-grey-500">this year</span></span>
            <span class="w-24 text-right text-sm font-medium tabular-nums text-fin-black">{{ moneyShort(flow.total) }}<span class="block text-[11px] font-normal text-fin-grey-500">whole plan</span></span>

            <IconChevronDown :size="18" class="shrink-0 text-fin-grey-400 transition-transform" :class="{ 'rotate-180': open }" />
        </button>

        <div v-if="open" class="flex flex-col gap-4 border-t border-fin-grey-100 bg-fin-white px-5 pb-5 pt-4">
            <div class="flex flex-wrap items-end gap-x-4 gap-y-3">
                <label v-if="!flow.is_one_time" class="block">
                    <span class="mb-1 block text-xs font-medium text-fin-grey-600">Change each year</span>
                    <span class="relative block w-36">
                        <input :value="rate" type="number" min="-50" max="50" step="0.1" class="!pr-14" @change="setRate">
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-fin-grey-400">% / yr</span>
                    </span>
                </label>
                <p v-else class="text-sm text-fin-grey-600">A one-time amount: it lands in its own year, and no rate applies. Move it by hand below.</p>

                <button v-if="draft.rate !== null" type="button" class="fin-btn fin-btn-quiet" @click="useOwnRate">
                    Use its own rate ({{ signedRate(flow.own_rate) }})
                </button>
                <p v-else-if="!flow.is_one_time" class="pb-2 text-xs text-fin-grey-500">
                    Its own rate, from Income &amp; expenses. A negative rate is a steady decrease.
                </p>

                <button v-if="pinnedCount" type="button" class="fin-btn fin-btn-quiet ml-auto" @click="clearPins">Clear the years set by hand</button>
            </div>

            <p v-if="error" class="text-xs text-fin-red-600" role="alert">{{ error }}</p>

            <YearSliderChart :points="points" :color="color" :retirement-year="retirementYear" :label="flow.name" @pin="pin" @unpin="unpin" />
        </div>
    </div>
</template>
