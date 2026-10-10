<script setup>
import { router } from '@inertiajs/vue3';
import { IconCopy, IconPencil, IconReplace, IconReportAnalytics, IconTrash } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { colorOf, moneyBrief } from '../lib/format';

/**
 * Every conversion strategy the user has built, in the report or not. They
 * arrive as settings alone — the server runs only the report's columns — so
 * there can be as many here as anyone cares to build.
 *
 * A strategy is in the report once for each projection it is run on, so the
 * same one can be set against itself across projections and several against
 * each other on one. "Run on" picks the projections that adding and
 * replacing apply to: a card's Add puts that strategy in the report on each
 * of them, and the two Replace buttons make the report the strategies shown,
 * or the ones picked — tick them, or Ctrl-click (Cmd-click on a Mac)
 * anywhere on a card — on each of them.
 *
 * Building a strategy is the page header's job. "Report" is the page's word;
 * the `comparison` prop still says what the config key does.
 */
const props = defineProps({
    // Each with `projections`: the ids it is reported on, null being the
    // income and expenses as entered.
    strategies: { type: Array, required: true },
    // Config's kinds of strategy, for the labels and the filter.
    kinds: { type: Object, required: true },
    scenarios: { type: Array, required: true },
    // { count, default, max }: how many columns the report has, and can have.
    comparison: { type: Object, required: true },
});

const emit = defineEmits(['edit']);

// 'all' is no filter.
const kind = ref('all');

const filtered = computed(() => props.strategies.filter((strategy) => kind.value === 'all' || strategy.kind === kind.value));

// Only the choices that would show something.
const kindsInUse = computed(() => Object.entries(props.kinds).filter(([key]) => props.strategies.some((strategy) => strategy.kind === key)));

// A filter whose last strategy has just been removed would show nothing.
watch(() => props.strategies.map((strategy) => strategy.kind).join(), () => {
    if (kind.value !== 'all' && !props.strategies.some((strategy) => strategy.kind === kind.value)) kind.value = 'all';
});

// The projections to choose from: none first, then as listed.
const projections = computed(() => [{ id: null, name: 'No projection' }, ...props.scenarios]);

const projectionName = (id) => projections.value.find((projection) => projection.id === id)?.name ?? 'No projection';

/*
 * A projection's colour: the one it has on the Projections & scenarios page,
 * where each takes the next chart colour in the order they are listed and
 * "nothing adjusted" is grey. `scenarios` arrives in that same order, so the
 * position is the colour.
 */
const projectionColor = (id) => {
    const index = props.scenarios.findIndex((scenario) => scenario.id === id);

    return index === -1 ? 'var(--color-fin-grey-400)' : colorOf(index);
};

/*
 * The projections adding and replacing apply to. They open as the ones the
 * report is already on — or the income and expenses as entered, for an
 * empty one — and are the user's to change from there.
 */
const reportedOn = () => projections.value.map((projection) => projection.id)
    .filter((id) => props.strategies.some((strategy) => strategy.projections.includes(id)));

const runOn = ref(reportedOn().length ? reportedOn() : [null]);

const toggleRunOn = (id) => {
    runOn.value = runOn.value.includes(id) ? runOn.value.filter((other) => other !== id) : [...runOn.value, id];
};

// A projection that has been removed can no longer be run on.
watch(() => props.scenarios.map((scenario) => scenario.id).join(), () => {
    runOn.value = runOn.value.filter((id) => projections.value.some((projection) => projection.id === id));
});

// The strategies picked, by id. Dropped when one is removed or filtered out
// of sight, so nothing unseen is ever sent.
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

// How many columns so many strategies come to, on the projections chosen.
const columnsFor = (count) => count * runOn.value.length;
const tooMany = (count) => columnsFor(count) > props.comparison.max;

const whyNot = (count) => {
    if (!runOn.value.length) return 'Choose a projection to run them on first.';
    if (tooMany(count)) return `That is ${columnsFor(count)} columns, and the report takes ${props.comparison.max}. Pick fewer strategies or projections.`;

    return '';
};

const replaceWith = (ids, what) => {
    if (!ids.length || whyNot(ids.length)) return;

    if (!props.comparison.count || window.confirm(`Replace the report with ${what}, on ${runOn.value.length === 1 ? projectionName(runOn.value[0]) : `${runOn.value.length} projections`}? The ${props.comparison.count} in it now leave it; no strategy is removed.`)) {
        router.put('/finance/retirement/report', { strategies: ids, projections: runOn.value }, {
            preserveScroll: true,
            onSuccess: () => { picked.value = []; },
        });
    }
};

const replaceWithFiltered = () => replaceWith(filtered.value.map((strategy) => strategy.id), `the ${filtered.value.length} shown`);
const replaceWithPicked = () => replaceWith(picked.value, `the ${picked.value.length} picked`);

// The projections chosen that a strategy is not yet reported on.
const missing = (strategy) => runOn.value.filter((id) => !strategy.projections.includes(id));

const cannotAdd = (strategy) => {
    if (!runOn.value.length) return 'Choose a projection to run it on first.';
    if (!missing(strategy).length) return 'Already in the report on every projection chosen.';
    if (props.comparison.count + missing(strategy).length > props.comparison.max) return `The report takes ${props.comparison.max} columns. Take some out first.`;

    return '';
};

const add = (strategy) => router.post('/finance/retirement/report', { strategy_id: strategy.id, projections: missing(strategy) }, { preserveScroll: true });
const duplicate = (strategy) => router.post(`/finance/retirement/strategies/${strategy.id}/duplicate`, {}, { preserveScroll: true });

const remove = (strategy) => {
    const columns = strategy.projections.length;

    if (window.confirm(`Remove ${strategy.label}?${columns ? ` It leaves the report too, where it is ${columns === 1 ? 'one column' : `${columns} columns`}.` : ''}`)) {
        router.delete(`/finance/retirement/strategies/${strategy.id}`, { preserveScroll: true });
    }
};

// The settings worth a line on the card, blanks left out.
const details = (strategy) => {
    const kindOf = props.kinds[strategy.kind] ?? {};
    const from = strategy.convert_from_age;
    const until = strategy.convert_until_age;

    return [
        // A name of its own leaves the kind unsaid.
        strategy.name ? strategy.kind_label : null,
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
    <section class="rounded-2xl border border-dashed border-fin-grey-300 bg-fin-cream-100/60" aria-label="Strategies">
        <header class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3 px-5 pt-5">
            <div>
                <h2 class="text-sm font-semibold text-fin-black">Strategies <span class="font-normal text-fin-grey-500">· {{ strategies.length }} built · {{ comparison.count }} / {{ comparison.max }} columns in the report</span></h2>
                <p class="mt-0.5 max-w-2xl text-xs text-fin-grey-500">
                    A strategy is in the report once for each projection it is run on, and editing it changes every one. Only the report is run, so strategies kept out of it cost the page nothing. Tick cards, or Ctrl-click them, to pick several.
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-1.5" role="group" aria-label="Projections to run on">
                    <span class="mr-1 text-xs font-medium text-fin-grey-600">Run on</span>
                    <button v-for="projection in projections" :key="projection.id ?? 'none'" type="button" class="fin-pill" :aria-pressed="runOn.includes(projection.id)" @click="toggleRunOn(projection.id)">
                        <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: projectionColor(projection.id) }" aria-hidden="true" />
                        {{ projection.name }}
                    </button>
                    <button v-if="scenarios.length && runOn.length < projections.length" type="button" class="ml-1 text-xs text-fin-grey-600 underline hover:text-fin-black" @click="runOn = projections.map((projection) => projection.id)">All</button>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" class="fin-btn fin-btn-quiet" :disabled="!filtered.length || !!whyNot(filtered.length)" :title="whyNot(filtered.length)" @click="replaceWithFiltered">
                        <IconReplace :size="16" /> Replace report with {{ kind === 'all' ? 'all' : 'the filtered' }} {{ filtered.length }}
                    </button>
                    <button type="button" class="fin-btn fin-btn-quiet" :disabled="!picked.length || !!whyNot(picked.length)" :title="whyNot(picked.length)" @click="replaceWithPicked">
                        <IconReplace :size="16" /> Replace report with the picked {{ picked.length }}
                    </button>
                    <button v-if="picked.length" type="button" class="text-xs text-fin-grey-600 underline hover:text-fin-black" @click="picked = []">Clear picks</button>
                    <p v-if="whyNot(picked.length || filtered.length)" class="text-xs text-fin-grey-500">{{ whyNot(picked.length || filtered.length) }}</p>
                </div>
            </div>

            <label class="block">
                <span class="mb-1 block text-xs font-medium text-fin-grey-600">Strategy type</span>
                <select v-model="kind" class="!w-56">
                    <option value="all">Any type</option>
                    <option v-for="[key, option] in kindsInUse" :key="key" :value="key">{{ option.label }}</option>
                </select>
            </label>
        </header>

        <ul class="grid gap-3 p-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            <li
                v-for="strategy in filtered" :key="strategy.id"
                class="flex flex-col gap-2 rounded-xl border bg-fin-white p-4"
                :class="picked.includes(strategy.id) ? 'border-fin-green-500 ring-2 ring-fin-green-200' : 'border-fin-grey-200'"
                @click="onCardClick($event, strategy)"
            >
                <div class="flex items-start gap-2.5">
                    <input type="checkbox" class="mt-0.5 shrink-0" :checked="picked.includes(strategy.id)" :aria-label="`Pick ${strategy.label}`" @change="toggle(strategy)" @click.stop>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-fin-black" :title="strategy.label">{{ strategy.label }}</p>
                        <p v-if="details(strategy)" class="text-xs text-fin-grey-600">Specs: {{ details(strategy) }}</p>
                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-fin-grey-500">
                            <template v-if="strategy.projections.length">
                                In the report on
                                <span v-for="id in strategy.projections" :key="id ?? 'none'" class="inline-flex items-center gap-1 text-fin-charcoal">
                                    <span class="h-2 w-2 rounded-full" :style="{ backgroundColor: projectionColor(id) }" aria-hidden="true" />
                                    {{ projectionName(id) }}
                                </span>
                            </template>
                            <template v-else>Not in the report</template>
                        </p>
                    </div>
                </div>

                <div class="mt-auto flex items-center justify-between gap-2 pt-1">
                    <button type="button" class="fin-btn fin-btn-quiet !px-2.5 !py-1 text-xs" :disabled="!!cannotAdd(strategy)" :title="cannotAdd(strategy)" @click.stop="add(strategy)">
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
    </section>
</template>
