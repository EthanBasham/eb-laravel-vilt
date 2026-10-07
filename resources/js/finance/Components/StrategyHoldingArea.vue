<script setup>
import { router } from '@inertiajs/vue3';
import { IconCopy, IconPencil, IconPlus, IconReplace, IconReportAnalytics, IconSparkles, IconTrash } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { colorOf, moneyBrief } from '../lib/format';

/**
 * The holding area: conversion strategies that exist but are not in the
 * report. They arrive as settings alone — the server runs only what is in
 * the report — so there can be as many here as anyone cares to build.
 *
 * A card can be edited, copied, brought into the report, or removed. Adding
 * is the page header's job; the area offers it only while it is empty, when
 * it has nothing else to show. To swap the report wholesale, narrow the cards with the two filters, or
 * pick some — tick them, or Ctrl-click (Cmd-click on a Mac) anywhere on a
 * card — and replace the report with what is filtered or what is picked.
 *
 * "Report" is the page's word; the prop, the routes and the column still
 * say comparison and compare.
 */
const props = defineProps({
    held: { type: Array, required: true },
    // Config's kinds of strategy, for the labels and the filter.
    kinds: { type: Object, required: true },
    scenarios: { type: Array, required: true },
    // { count, default, max }: how many are in the report, and how many can be.
    comparison: { type: Object, required: true },
});

const emit = defineEmits(['build', 'edit']);

/*
 * The filters. 'all' is no filter; for the projection, `null` is a real
 * choice — strategies run on the income and expenses as entered — so it
 * cannot double as "any".
 */
const projection = ref('all');
const kind = ref('all');

// Where a projection comes in the pickers: none first, then as listed.
const projectionOrder = (scenarioId) => props.scenarios.findIndex((scenario) => scenario.id === scenarioId);

// Cards on the same projection sit together, by order alone; within one they
// stay as they arrived, which is as added.
const filtered = computed(() => props.held
    .filter((strategy) => (projection.value === 'all' || strategy.scenario_id === projection.value)
        && (kind.value === 'all' || strategy.kind === kind.value))
    .sort((first, second) => projectionOrder(first.scenario_id) - projectionOrder(second.scenario_id)));

const isFiltering = computed(() => projection.value !== 'all' || kind.value !== 'all');

// Only the choices that would show something.
const projectionsInUse = computed(() => {
    const ids = new Set(props.held.map((strategy) => strategy.scenario_id));

    return {
        asEntered: ids.has(null),
        scenarios: props.scenarios.filter((scenario) => ids.has(scenario.id)),
    };
});

const kindsInUse = computed(() => Object.entries(props.kinds).filter(([key]) => props.held.some((strategy) => strategy.kind === key)));

// A filter whose choice has just left the holding area — its last card was
// added to the report — would show nothing while cards are still held.
watch(() => props.held.map((strategy) => `${strategy.scenario_id}:${strategy.kind}`).join(), () => {
    if (projection.value !== 'all' && !props.held.some((strategy) => strategy.scenario_id === projection.value)) projection.value = 'all';
    if (kind.value !== 'all' && !props.held.some((strategy) => strategy.kind === kind.value)) kind.value = 'all';
});

// The cards picked, by id. Dropped when a card leaves the holding area or is
// filtered out of sight, so nothing unseen is ever sent.
const picked = ref([]);

watch(filtered, (visible) => {
    const ids = visible.map((strategy) => strategy.id);

    picked.value = picked.value.filter((id) => ids.includes(id));
});

const toggle = (strategy) => {
    picked.value = picked.value.includes(strategy.id)
        ? picked.value.filter((id) => id !== strategy.id)
        : [...picked.value, strategy.id];
};

// Ctrl- or Cmd-click anywhere on a card picks it. A plain click does nothing,
// so reading a card never selects it by accident.
const onCardClick = (event, strategy) => {
    if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
        toggle(strategy);
    }
};

/*
 * One of each kind of strategy, made in one go on the projection chosen.
 * `null` is the income and expenses as entered. They land like any new
 * strategy: in the report while it has room, in here once it has not.
 */
const setFor = ref(null);

const addSet = () => router.post('/finance/retirement/strategies/starters', { scenario_id: setFor.value }, { preserveScroll: true });

const tooMany = (count) => count > props.comparison.max;
const isFull = computed(() => props.comparison.count >= props.comparison.max);

const replaceWith = (ids, what) => {
    if (!ids.length || tooMany(ids.length)) return;

    if (!props.comparison.count || window.confirm(`Replace the report with ${what}? The ${props.comparison.count} in it now move to the holding area.`)) {
        router.put('/finance/retirement/strategies/comparison', { strategies: ids }, {
            preserveScroll: true,
            onSuccess: () => { picked.value = []; },
        });
    }
};

const replaceWithFiltered = () => replaceWith(filtered.value.map((strategy) => strategy.id), `the ${filtered.value.length} shown`);
const replaceWithPicked = () => replaceWith(picked.value, `the ${picked.value.length} picked`);

const compare = (strategy) => router.post(`/finance/retirement/strategies/${strategy.id}/compare`, {}, { preserveScroll: true });
const duplicate = (strategy) => router.post(`/finance/retirement/strategies/${strategy.id}/duplicate`, {}, { preserveScroll: true });

const remove = (strategy) => {
    if (window.confirm(`Remove ${strategy.label}?`)) {
        router.delete(`/finance/retirement/strategies/${strategy.id}`, { preserveScroll: true });
    }
};

/*
 * A projection's colour: the one it has on the Projections & scenarios page,
 * where each takes the next chart colour in the order they are listed and
 * "nothing adjusted" is grey. `scenarios` arrives in that same order, so the
 * position is the colour. A dot of it sits before the card's name, so
 * cards on the same projection read as a set.
 */
const projectionColor = (scenarioId) => {
    const index = props.scenarios.findIndex((scenario) => scenario.id === scenarioId);

    return index === -1 ? 'var(--color-fin-grey-400)' : colorOf(index);
};

// The settings worth a line on the card, blanks left out.
const details = (strategy) => {
    const kindOf = props.kinds[strategy.kind] ?? {};
    const from = strategy.convert_from_age;
    const until = strategy.convert_until_age;

    return [
        kindOf.ages === 'at' && from !== null ? `at ${from}` : null,
        kindOf.ages === 'window' && (from !== null || until !== null) ? `ages ${from ?? '…'}–${until ?? '…'}` : null,
        kindOf.fills ? (strategy.fill_rate === null ? 'the bracket it is in' : `to the ${strategy.fill_rate}% bracket`) : null,
        kindOf.amount && strategy.conversion_amount !== null ? `${moneyBrief(strategy.conversion_amount)} a year` : null,
        strategy.inflation_rate !== null ? `${strategy.inflation_rate}% inflation` : null,
        strategy.growth_rate !== null ? `${strategy.growth_rate}% growth` : null,
    ].filter(Boolean).join(' · ');
};
</script>

<template>
    <section class="rounded-2xl border border-dashed border-fin-grey-300 bg-fin-cream-100/60" aria-label="Holding area">
        <!-- Everything is in the report: the area is just somewhere to add another. -->
        <div v-if="!held.length" class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <p class="text-sm text-fin-grey-600">
                <span class="font-semibold text-fin-black">Holding area.</span>
                Every strategy is in the report below. Once {{ comparison.default }} are in it, new ones wait here until you bring them in.
            </p>
            <div class="flex flex-wrap items-center gap-2">
                <form class="flex items-center gap-2" @submit.prevent="addSet">
                    <select v-model="setFor" class="!w-52" aria-label="Projection to make one of each strategy type for">
                        <option :value="null">No projection</option>
                        <option v-for="scenario in scenarios" :key="scenario.id" :value="scenario.id">{{ scenario.name }}</option>
                    </select>
                    <button type="submit" class="fin-btn fin-btn-quiet"><IconSparkles :size="16" /> Add one of each type</button>
                </form>
                <button type="button" class="fin-btn fin-btn-primary" @click="emit('build')"><IconPlus :size="16" /> Add strategy</button>
            </div>
        </div>

        <template v-else>
            <header class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3 px-5 pt-5">
                <div>
                    <h2 class="text-sm font-semibold text-fin-black">Holding area <span class="font-normal text-fin-grey-500">· {{ held.length }} not in the report · {{ comparison.count }} / {{ comparison.max }} are in the report</span></h2>
                    <p class="mt-0.5 max-w-2xl text-xs text-fin-grey-500">
                        Strategies here are not run, so they cost the page nothing. Tick cards, or Ctrl-click them, to pick several.
                    </p>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <button
                            type="button" class="fin-btn fin-btn-quiet" :disabled="!filtered.length || tooMany(filtered.length)"
                            :title="tooMany(filtered.length) ? `At most ${comparison.max} can be in the report. Narrow the filters first.` : ''" @click="replaceWithFiltered"
                        >
                            <IconReplace :size="16" /> Replace report with {{ isFiltering ? 'the filtered' : 'all' }} {{ filtered.length }}
                        </button>
                        <button
                            type="button" class="fin-btn fin-btn-quiet" :disabled="!picked.length || tooMany(picked.length)"
                            :title="tooMany(picked.length) ? `At most ${comparison.max} can be in the report.` : ''" @click="replaceWithPicked"
                        >
                            <IconReplace :size="16" /> Replace report with the picked {{ picked.length }}
                        </button>
                        <button v-if="picked.length" type="button" class="text-xs text-fin-grey-600 underline hover:text-fin-black" @click="picked = []">Clear picks</button>
                        <p v-if="tooMany(filtered.length) && !picked.length" class="text-xs text-fin-grey-500">More than {{ comparison.max }} shown: narrow the filters, or pick some.</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-end gap-2">
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-fin-grey-600">Projection</span>
                        <select v-model="projection" class="!w-48">
                            <option value="all">Any projection</option>
                            <option v-if="projectionsInUse.asEntered" :value="null">No projection</option>
                            <option v-for="scenario in projectionsInUse.scenarios" :key="scenario.id" :value="scenario.id">{{ scenario.name }}</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="mb-1 block text-xs font-medium text-fin-grey-600">Strategy type</span>
                        <select v-model="kind" class="!w-56">
                            <option value="all">Any type</option>
                            <option v-for="[key, option] in kindsInUse" :key="key" :value="key">{{ option.label }}</option>
                        </select>
                    </label>
                </div>
            </header>

            <p v-if="!filtered.length" class="px-5 py-6 text-sm text-fin-grey-500">Nothing in the holding area matches those filters.</p>

            <ul v-else class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                <li
                    v-for="strategy in filtered" :key="strategy.id"
                    class="flex flex-col gap-2 rounded-xl border bg-fin-white p-4"
                    :class="picked.includes(strategy.id) ? 'border-fin-green-500 ring-2 ring-fin-green-200' : 'border-fin-grey-200'"
                    @click="onCardClick($event, strategy)"
                >
                    <div class="flex items-start gap-2.5">
                        <input type="checkbox" class="mt-0.5 shrink-0" :checked="picked.includes(strategy.id)" :aria-label="`Pick ${strategy.label}`" @change="toggle(strategy)" @click.stop>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-fin-black" :title="strategy.label">
                                <span class="mr-1 inline-block h-2 w-2 rounded-full" :style="{ backgroundColor: projectionColor(strategy.scenario_id) }" aria-hidden="true" />
                                {{ strategy.label }}
                            </p>
                            <p v-if="details(strategy)" class="text-xs text-fin-grey-600">Specs: {{ details(strategy) }}</p>
                        </div>
                    </div>

                    <div class="mt-auto flex items-center justify-between gap-2 pt-1">
                        <button
                            type="button" class="fin-btn fin-btn-quiet !px-2.5 !py-1 text-xs" :disabled="isFull"
                            :title="isFull ? `The report is full at ${comparison.max}. Move one out first.` : ''" @click.stop="compare(strategy)"
                        >
                            <IconReportAnalytics :size="14" /> Add to report
                        </button>
                        <span class="flex" @click.stop>
                            <button type="button" class="fin-icon-btn" :aria-label="`Edit ${strategy.label}`" @click="emit('edit', strategy)"><IconPencil :size="15" /></button>
                            <button type="button" class="fin-icon-btn" :aria-label="`Copy ${strategy.label}`" @click="duplicate(strategy)"><IconCopy :size="15" /></button>
                            <button type="button" class="fin-icon-btn" :aria-label="`Remove ${strategy.label}`" @click="remove(strategy)"><IconTrash :size="15" /></button>
                        </span>
                    </div>
                </li>
            </ul>
        </template>
    </section>
</template>
