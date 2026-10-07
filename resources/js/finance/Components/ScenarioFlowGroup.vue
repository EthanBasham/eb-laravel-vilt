<script setup>
import { router } from '@inertiajs/vue3';
import { IconChevronDown } from '@tabler/icons-vue';
import { ref } from 'vue';
import ScenarioFlowRow from './ScenarioFlowRow.vue';
import { money, moneyShort, percentSigned, rateTone } from '../lib/format';
import { numberOrNull } from '../lib/input';

/**
 * A compound flow inside a scenario — "Household expenses" and the items in
 * it — as one row that opens onto the items, each projected on its own.
 *
 * Grouped by default: shut, it is one line with one total. Its rate, when
 * the scenario gives it one, reaches every item that has none of its own, so
 * the whole household can be moved with one number and a single item still
 * singled out.
 */
const props = defineProps({
    flow: { type: Object, required: true },
    scenarioId: { type: Number, required: true },
    retirementYear: { type: Number, default: null },
    color: { type: String, default: 'var(--color-fin-chart-1)' },
});

const open = ref(false);
const error = ref('');

// A group pins no years of its own, so its settings are only ever the rate.
const saveRate = (rate) => {
    error.value = '';

    router.put(`/finance/scenarios/${props.scenarioId}/flows/${props.flow.id}`, { annual_growth_rate: rate, overrides: {} }, {
        preserveScroll: true,
        preserveState: true,
        onError: (errors) => { error.value = Object.values(errors)[0] ?? 'That could not be saved.'; },
    });
};

const setRate = (event) => saveRate(numberOrNull(event));
</script>

<template>
    <div class="border-t border-fin-grey-100">
        <button type="button" class="flex w-full flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-left hover:bg-fin-cream-50" :aria-expanded="open" @click="open = !open">
            <span class="min-w-[14rem] flex-1">
                <span class="font-medium text-fin-black">{{ flow.name }}</span>
                <span class="ml-2 text-xs text-fin-grey-500">{{ flow.items.length }} {{ flow.items.length === 1 ? 'item' : 'items' }}, projected together</span>
                <span v-if="flow.account_name" class="ml-2 whitespace-nowrap rounded-full bg-fin-navy-100 px-2 py-0.5 text-[11px] text-fin-navy-700">from {{ flow.account_name }}</span>
            </span>

            <span v-if="flow.has_scenario_rate" class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-medium" :class="rateTone(flow.direction, flow.rate)">{{ percentSigned(flow.rate) }} / yr</span>
            <span v-if="flow.pinned_count" class="whitespace-nowrap rounded-full bg-fin-gold-100 px-2 py-0.5 text-[11px] font-medium text-fin-gold-600">
                {{ flow.pinned_count }} {{ flow.pinned_count === 1 ? 'year' : 'years' }} by hand
            </span>

            <span class="w-28 text-right text-sm tabular-nums text-fin-charcoal">{{ money(flow.series[0]?.amount) }}<span class="block text-[11px] text-fin-grey-500">this year</span></span>
            <span class="w-24 text-right text-sm font-medium tabular-nums text-fin-black">{{ moneyShort(flow.total) }}<span class="block text-[11px] font-normal text-fin-grey-500">whole plan</span></span>

            <IconChevronDown :size="18" class="shrink-0 text-fin-grey-400 transition-transform" :class="{ 'rotate-180': open }" />
        </button>

        <div v-if="open" class="border-t border-fin-grey-100 bg-fin-cream-50/50">
            <div class="flex flex-wrap items-end gap-x-4 gap-y-3 px-5 py-4">
                <label class="block">
                    <span class="mb-1 block text-xs font-medium text-fin-grey-600">Change every item each year</span>
                    <span class="relative block w-36">
                        <input :value="flow.rate" type="number" min="-50" max="50" step="0.1" class="!pr-14" placeholder="—" @change="setRate">
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-fin-grey-400">% / yr</span>
                    </span>
                </label>

                <button v-if="flow.has_scenario_rate" type="button" class="fin-btn fin-btn-quiet" @click="saveRate(null)">Let each item use its own rate</button>
                <p v-else class="max-w-md pb-2 text-xs text-fin-grey-500">Blank leaves each item on its own rate. Set one here to move the whole household together; an item given its own rate below keeps it.</p>
            </div>

            <p v-if="error" class="px-5 pb-3 text-xs text-fin-red-600" role="alert">{{ error }}</p>

            <div class="ml-5 border-l-2 border-fin-grey-200 bg-fin-white">
                <ScenarioFlowRow
                    v-for="item in flow.items" :key="item.id"
                    :flow="item" :scenario-id="scenarioId" :retirement-year="retirementYear" :color="color"
                />
            </div>
        </div>
    </div>
</template>
