<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { IconEngine, IconLock, IconLockOpen, IconShoppingCart } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import EditableNumber from '../Components/EditableNumber.vue';
import ModulePlanPicker from '../Components/ModulePlanPicker.vue';
import ModuleResearchPicker from '../Components/ModuleResearchPicker.vue';
import NationFlag from '../Components/NationFlag.vue';
import ResearchLock from '../Components/ResearchLock.vue';
import VehicleTypeIcon from '../Components/VehicleTypeIcon.vue';
import { useBoardFilters } from '../composables/useBoardFilters';

const props = defineProps({
    active: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    totals: { type: Object, required: true },
    purchase: { type: Object, required: true },
    freexp: { type: Object, required: true },
    xp: { type: Object, required: true },
    blueprints: { type: Object, required: true },
    options: { type: Array, default: () => [] },
});

// The five views the spreadsheet had, kept as tabs so the board stays one page.
const views = [
    { key: 'active', label: 'Active Grinding' },
    { key: 'xp', label: 'XP Remaining' },
    { key: 'freexp', label: 'Free XP' },
    { key: 'purchase', label: 'Tanks to Purchase' },
    { key: 'blueprints', label: 'Blueprints' },
];
const view = ref('active');

/*
 * Being on the Active Grinding list is the whole of "playing this tank" — there
 * is no separate tick for it anywhere. So adding and dropping are one flag, and
 * both go through the same per-tank endpoint every other board writes to.
 *
 * The four boards move with it: the tank leaves the picker, and its banked XP
 * joins or leaves the headline card.
 */
const setPlaying = (tankId, is_playing) => router.patch(`/wot/grinding/purchases/${tankId}`, { is_playing }, {
    preserveScroll: true,
    only: ['active', 'options', 'totals'],
});

/*
 * The picker's filters, and the only ones on this page held as a *selection*
 * rather than as a hidden set.
 *
 * The boards are a view you come back to, so they start with everything on and
 * remember what you switched off. This is four hundred tanks you are trying to
 * find one in, where narrowing to a nation should cost one click rather than
 * nine. So every chip starts unlit, and picking one is what cuts.
 */
const pickedNations = ref([]);
const pickedTiers = ref([]);
const pickedTypes = ref([]);

/*
 * Returns the next selection rather than mutating one: a ref reaching the
 * template is auto-unwrapped, so a helper called from a @click is handed the
 * array and has nothing to assign back through. The call sites assign, which
 * `<script setup>` compiles back onto the ref.
 */
const withPick = (picked, value) => (picked.includes(value)
    ? picked.filter((v) => v !== value)
    : [...picked, value]);

// What a chip shows, against what a filter allows: an untouched dimension lets
// everything through while its chips all sit unlit.
const allows = (picked, value) => !picked.length || picked.includes(value);

const pickerNations = computed(() => nationsOf(props.options));
const pickerTiers = computed(() => [...new Set(props.options.map((o) => o.tier))].sort((a, b) => a - b));

// Fixed order rather than whatever the list happens to hold, so the row does
// not reshuffle as tanks come off it. Light to heavy, then the two that are not
// tanks — the tankopedia's own order, and the badges read as a progression.
const TYPES = ['lightTank', 'mediumTank', 'heavyTank', 'AT-SPG', 'SPG'];
const pickerTypes = computed(() => {
    const present = new Set(props.options.map((o) => o.type));

    return TYPES.filter((type) => present.has(type));
});

const hasPick = computed(() => pickedNations.value.length
    + pickedTiers.value.length
    + pickedTypes.value.length > 0);

/*
 * What the picker opens on: tanks in the garage that still owe XP and are not
 * already being ground — which is the shape of "something I could start next".
 *
 * The whole tree is four hundred tanks and almost none of them are a real
 * answer, so it is behind the first filter click rather than in front of it.
 */
const grindable = computed(() => props.options.filter((o) => o.is_purchased && o.xp_remaining > 0));

const pickable = computed(() => {
    if (! hasPick.value) {
        return grindable.value;
    }

    return props.options.filter((o) => allows(pickedNations.value, o.nation)
        && allows(pickedTiers.value, o.tier)
        && allows(pickedTypes.value, o.type));
});


const n = (v) => new Intl.NumberFormat().format(v ?? 0);

// Tiers are Roman in game and in every community tool; Arabic column headers
// here would read as a different quantity entirely.
const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];

/**
 * Apply a pending change to one cell, in the shape the board renders.
 *
 * The wire names and the cell's own fields are not quite the same: the request
 * carries `price_credit`, the override, while the cell carries the resolved
 * `price` and whether it undercuts the shop. Buying also researches, mirroring
 * the same rule the controller enforces, so the optimistic cell matches what
 * comes back rather than flickering when it does.
 */
const applyToCell = (cell, payload) => {
    if ('price_credit' in payload) {
        return {
            ...cell,
            price: payload.price_credit ?? cell.api_price ?? 0,
            // Both halves of PurchaseBoard's rule: an override is only a
            // discount if it actually differs from the shop price. Dropping the
            // second half would flash the reset button on for a value the
            // server is about to call undiscounted.
            is_discounted: payload.price_credit !== null && payload.price_credit !== cell.api_price,
        };
    }

    return {
        ...cell,
        ...payload,
        is_unlocked: payload.is_purchased ? true : (payload.is_unlocked ?? cell.is_unlocked),
    };
};

/**
 * The `purchase` prop with one vehicle's cell changed, ready to hand to
 * Inertia's `optimistic` option.
 *
 * @param {object} pageProps  current page props
 * @param {number} tankId     the vehicle whose cell is changing
 * @param {object} payload    the same body being sent to the server
 */
const patchPurchase = (pageProps, tankId, payload) => ({
    purchase: {
        ...pageProps.purchase,
        rows: pageProps.purchase.rows.map((row) => {
            const tier = Object.keys(row.cells).find((t) => row.cells[t]?.tank_id === tankId);

            return tier === undefined ? row : {
                ...row,
                cells: { ...row.cells, [tier]: applyToCell(row.cells[tier], payload) },
            };
        }),
    },
});

/*
 * Optimistic, because every figure on this tab is derived from `purchase` in
 * the template — the icons, the cell's own cost, and the row, tier and grand
 * totals all recompute from it. Flipping the one cell locally therefore
 * redraws the board immediately instead of after the round trip, and Inertia
 * puts it back on its own if the request fails.
 *
 * Two things are deliberately left to the server. The headline credits card
 * reads `totals`, and a line is settled whole when its last vehicle is bought
 * — including tiers never ticked off, which is PurchaseBoard's rule rather
 * than the client's. Reproducing it here would mean keeping two copies of it
 * in step, so a bought-out row instead lingers for the length of the request.
 */
const setPurchase = (tankId, payload) => router.patch(`/wot/grinding/purchases/${tankId}`, payload, {
    preserveScroll: true,
    // Buying a tank researches it, and researching moves the other three
    // boards — the unlock settles, the modules below it stop being owed, the
    // Free XP plan follows them and Blueprints counts the line done. They are
    // all built on every request anyway, so asking for them costs only bytes,
    // where leaving them out costs a tab that is quietly out of date.
    only: ['xp', 'freexp', 'purchase', 'blueprints', 'totals'],
    optimistic: (pageProps) => patchPurchase(pageProps, tankId, payload),
});

// Same treatment for a typed price. The field shows what you typed either way,
// but the row, tier and grand totals are derived from `purchase`, so without
// this they sit on the old figure until the round trip lands.
const optimisticPrice = (tankId) => (pageProps, next) => patchPurchase(pageProps, tankId, { price_credit: next });

/*
 * A researched vehicle borrows the buy button's border and text, so the cell
 * reads as one control that is ready to spend rather than as a green button
 * beside an ordinary field. The background stays the shell's own in both
 * states — only the border and text carry the signal.
 */
const priceTone = (cell) => (cell.is_unlocked
    ? 'border-wot-good/50 bg-wot-sunken text-wot-good hover:border-wot-good'
    : 'border-wot-border bg-wot-sunken text-wot-text');

/*
 * The lock on this board reports; it does not set. Research is ticked on XP
 * Remaining, against the unlock leading to the tank — so the title says where
 * that is rather than describing a click this icon does not take.
 *
 * A line's first vehicle has no unlock leading to it and so no tick anywhere,
 * which is why the server settles it as researched and why it says so here
 * instead of showing a lock that could never be opened.
 */
const researchedTitle = (cell) => {
    if (cell.is_root) {
        return `${cell.name} — the start of the line, researched from nothing.`;
    }

    return cell.is_unlocked
        ? `${cell.name} — researched. Changed on XP Remaining.`
        : `${cell.name} — not researched. Tick it on XP Remaining, against the unlock that leads here.`;
};

/*
 * Purchase filters.
 *
 * Held as what is *hidden* rather than what is selected, so everything starts
 * on and stays on: buying a tank can retire a nation or collapse a tier column,
 * and a selected-set would then have to guess whether a column that reappears
 * later was meant to be on. Deselections survive that; stale entries are
 * harmless.
 *
 * The tier row is seeded from the tiers the server reports as settled, but only
 * on a board whose filters have never been saved — a stored selection
 * supersedes the seed outright, so a bought-out column you deliberately turned
 * back on stays on. The cost is that the seed only ever runs on the very first
 * visit: a tier bought out after that is a column of zeroes until you hide it
 * yourself.
 */
const {
    hiddenNations,
    hiddenTiers,
    hide_owned: hideOwned,
    show_sale: showSale,
    toggleNation,
    toggleTier,
    clearNations,
    clearTiers,
} = useBoardFilters('purchase', props.settings.purchase_filters, {
    hiddenTiers: props.purchase.bought_tiers ?? [],
    extra: { hide_owned: true, show_sale: false },
});

/*
 * Wargaming's standard sale structure, as a discount per tier.
 *
 * Tier I is free already, and tier XI is not part of the published structure,
 * so neither is listed — a tier missing from here is simply not discounted.
 *
 * This is a preview, not a stored price: it applies to whatever a cell already
 * costs, so a price you have typed over for a specific offer gets discounted
 * along with the rest.
 */
const SALE = { 2: 0.5, 3: 0.5, 4: 0.5, 5: 0.5, 6: 0.3, 7: 0.3, 8: 0.15, 9: 0.15, 10: 0.15 };

const salePrice = (cell) => (showSale.value
    ? Math.round(cell.price * (1 - (SALE[cell.tier] ?? 0)))
    : cell.price);

const page = usePage();

// In tech-tree order, like every other vehicle list here.
const nationsOf = (rows) => {
    const present = new Set(rows.map((r) => r.nation));

    return Object.keys(page.props.nations ?? {}).filter((nation) => present.has(nation));
};

const purchaseNations = computed(() => nationsOf(props.purchase.rows));

const shownTiers = computed(() => props.purchase.tiers.filter((t) => !hiddenTiers.value.includes(t)));

// Only cells in a visible column count. Hiding a tier takes its price off the
// bill — otherwise the filter would change what you see but not what you owe.
//
// A shared cell costs this row nothing: the row that owns the vehicle is paying
// for it, which is what keeps the row totals summing to the grand total and
// agreeing with the server's credits_remaining cell for cell.
const cellCost = (cell) => (cell && !cell.is_purchased && !cell.is_shared ? salePrice(cell) : 0);
const rowRemaining = (row) => shownTiers.value.reduce((sum, t) => sum + cellCost(row.cells[t]), 0);

// "Owned" means nothing left to buy in the visible tiers, so it tracks the
// Remaining column rather than a separate server flag: if the row reads 0, the
// filter treats it as owned.
const isOwned = (row) => rowRemaining(row) === 0;

// Only offer the checkbox when it would do something.
const hasOwnedLines = computed(() => props.purchase.rows.some(isOwned));

const shownRows = computed(() => props.purchase.rows.filter(
    (r) => !hiddenNations.value.includes(r.nation) && !(hideOwned.value && isOwned(r)),
));
const tierTotal = (tier) => shownRows.value.reduce((sum, r) => sum + cellCost(r.cells[tier]), 0);
const grandTotal = computed(() => shownRows.value.reduce((sum, r) => sum + rowRemaining(r), 0));
const short = (v) => (v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)}M` : n(v));

/*
 * Free XP filters.
 *
 * Nation and tier work exactly as they do on the purchase board. The third
 * control does not: there is no settled state for a module plan — nothing
 * records which modules you have already researched off a tracked line — so
 * there is nothing to hide by default. It narrows to lines you have planned
 * something on instead, which makes it a review tool rather than a tidy-up,
 * and it starts off because the whole tree is what you plan against.
 */
const {
    hiddenNations: fxHiddenNations,
    hiddenTiers: fxHiddenTiers,
    only_planned: onlyPlanned,
    toggleNation: fxToggleNation,
    toggleTier: fxToggleTier,
    clearNations: fxClearNations,
    clearTiers: fxClearTiers,
} = useBoardFilters('freexp', props.settings.freexp_filters, {
    extra: { only_planned: false },
});

const freexpNations = computed(() => nationsOf(props.freexp.rows));

const fxShownTiers = computed(() => props.freexp.tiers.filter((t) => !fxHiddenTiers.value.includes(t)));

// A shared cell is planned and counted on the row that owns it, which keeps the
// row totals summing to the grand total.
const fxCellXp = (cell) => (cell && !cell.is_shared ? cell.planned_xp : 0);
const fxRowPlanned = (row) => fxShownTiers.value.reduce((sum, t) => sum + fxCellXp(row.cells[t]), 0);

const fxShownRows = computed(() => props.freexp.rows.filter(
    (r) => !fxHiddenNations.value.includes(r.nation) && !(onlyPlanned.value && fxRowPlanned(r) === 0),
));
const fxTierTotal = (tier) => fxShownRows.value.reduce((sum, r) => sum + fxCellXp(r.cells[tier]), 0);
const fxGrandTotal = computed(() => fxShownRows.value.reduce((sum, r) => sum + fxRowPlanned(r), 0));

/*
 * XP Remaining filters.
 *
 * The Lines checkbox matches the purchase board's rather than the Free XP
 * board's: a line with nothing left to research is settled in the same sense a
 * bought-out line is, so hiding it by default is the same judgement.
 */
const {
    hiddenNations: xpHiddenNations,
    hiddenTiers: xpHiddenTiers,
    hide_done: hideDone,
    toggleNation: xpToggleNation,
    toggleTier: xpToggleTier,
    clearNations: xpClearNations,
    clearTiers: xpClearTiers,
} = useBoardFilters('xp', props.settings.xp_filters, {
    extra: { hide_done: true },
});

const xpNations = computed(() => nationsOf(props.xp.rows));

// Clearing the override hands the cell back to the encyclopedia's figure, so it
// is a null rather than a zero — a tank you hold fragments enough to unlock
// outright is a real, different state.
const resetResearchXp = (tankId) => router.patch(`/wot/grinding/research/${tankId}`, { research_xp: null }, {
    preserveScroll: true,
    only: ['xp', 'totals'],
});

const xpShownTiers = computed(() => props.xp.tiers.filter((t) => !xpHiddenTiers.value.includes(t)));

/*
 * What one cell still owes: the unlock ahead of it, plus its own modules.
 *
 * Mirrors XpBoard::cellXp so the footer and the server's row figure cannot
 * drift — the client's job is only to re-total the cells a filter leaves
 * visible.
 */
const xpCellXp = (cell) => {
    if (!cell) {
        return 0;
    }

    // Shared separately: a vehicle's modules are researched once, but two lines
    // diverging from it owe two different unlocks, so the two halves of a cell
    // can belong to different rows.
    const unlock = cell.unlocks && !cell.unlocks.is_shared && !cell.unlocks.is_unlocked ? cell.unlocks.xp : 0;

    return unlock + (cell.is_shared ? 0 : cell.module_xp);
};

const xpRowRemaining = (row) => xpShownTiers.value.reduce((sum, t) => sum + xpCellXp(row.cells[t]), 0);

const xpShownRows = computed(() => props.xp.rows.filter(
    (r) => !xpHiddenNations.value.includes(r.nation) && !(hideDone.value && xpRowRemaining(r) === 0),
));
const xpHasDoneLines = computed(() => props.xp.rows.some((r) => xpRowRemaining(r) === 0));
const xpTierTotal = (tier) => xpShownRows.value.reduce((sum, r) => sum + xpCellXp(r.cells[tier]), 0);
const xpGrandTotal = computed(() => xpShownRows.value.reduce((sum, r) => sum + xpRowRemaining(r), 0));

/*
 * Blueprint filters.
 *
 * Fragments only discount something you have yet to research, so the Lines
 * checkbox is XP Remaining's rather than Free XP's — a finished line is one
 * they cannot help, and hiding it by default is the same judgement.
 */
const {
    hiddenNations: bpHiddenNations,
    hiddenTiers: bpHiddenTiers,
    hide_done: bpHideDone,
    toggleNation: bpToggleNation,
    toggleTier: bpToggleTier,
    clearNations: bpClearNations,
    clearTiers: bpClearTiers,
} = useBoardFilters('blueprints', props.settings.blueprints_filters, {
    extra: { hide_done: true },
});

const blueprintNations = computed(() => nationsOf(props.blueprints.rows));

const bpShownTiers = computed(() => props.blueprints.tiers.filter((t) => !bpHiddenTiers.value.includes(t)));

// A shared cell is held and edited on the row that owns it, which keeps the row
// totals summing to the grand total.
const bpCellFragments = (cell) => (cell && !cell.is_shared ? cell.fragments : 0);
const bpRowFragments = (row) => bpShownTiers.value.reduce((sum, t) => sum + bpCellFragments(row.cells[t]), 0);

/*
 * "Done" is about research, not about fragments: a line you have finished is
 * one blueprints cannot help, however many you happen to hold against it.
 *
 * Read off this board's own cells rather than the XP tab's rows, which is what
 * it did until the board narrowed to tiers II-X. XP Remaining still runs tier I
 * to XI and still counts module XP, and fragments buy none of that — so a line
 * researched to the top read as unfinished here over an outstanding tier XI
 * unlock or an unresearched tier I gun.
 *
 * A cell is done in the three cases the grid already draws grey or as a dash:
 * the tank is researched, nothing researches into it, or another row owns it. A
 * row of nothing but those is a row fragments cannot touch.
 *
 * Every cell, not the visible ones: hiding a tier column must not decide which
 * lines the board has.
 */
const bpCellDone = (cell) => cell.is_shared || !cell.is_researchable || cell.is_unlocked;

const bpLineDone = (row) => Object.values(row.cells).every(bpCellDone);

const bpHasDoneLines = computed(() => props.blueprints.rows.some(bpLineDone));

const bpShownRows = computed(() => props.blueprints.rows.filter(
    (r) => !bpHiddenNations.value.includes(r.nation) && !(bpHideDone.value && bpLineDone(r)),
));
const bpTierTotal = (tier) => bpShownRows.value.reduce((sum, r) => sum + bpCellFragments(r.cells[tier]), 0);
const bpGrandTotal = computed(() => bpShownRows.value.reduce((sum, r) => sum + bpRowFragments(r), 0));
</script>

<template>
    <Head title="Grinding" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <div>
                <h1 class="text-3xl">Grinding</h1>
                <p class="mt-1 text-sm text-wot-dim">
                    {{ totals.playing }} {{ totals.playing === 1 ? 'tank' : 'tanks' }} being ground
                </p>
            </div>
        </div>

        <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">XP remaining</dt>
                <!-- The filtered board's figure, like Credits needed below it:
                     the server bills the whole tree, and narrowing to a nation
                     is what turns that into a number worth reading. -->
                <dd class="mt-1 text-xl tabular-nums text-wot-heading">{{ short(xpGrandTotal) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Banked XP</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-good">{{ n(totals.banked_xp) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Free XP planned</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-gold">{{ n(totals.free_xp_planned) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Credits needed</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-heading">{{ short(grandTotal) }}</dd>
            </div>
        </dl>

        <div class="mt-8 flex flex-wrap gap-2" role="tablist" aria-label="Grinding views">
            <button
                v-for="v in views"
                :key="v.key"
                type="button"
                role="tab"
                :aria-selected="view === v.key"
                class="border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors"
                :class="view === v.key ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                @click="view = v.key"
            >
                {{ v.label }}
            </button>
        </div>

        <!-- 1. Active Grinding ------------------------------------------------->
        <section v-if="view === 'active'" class="mt-4" aria-labelledby="active-heading">
            <h2 id="active-heading" class="sr-only">Active grinding</h2>

            <p v-if="!active.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                Nothing being ground. Add a tank below.
            </p>

            <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Tank</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">XP banked</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To max</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To next tank</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Progress</th>
                            <th scope="col" class="w-10 px-4 py-3" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in active" :key="row.tank_id" class="hover:bg-wot-sunken">
                            <td class="px-4 py-2">
                                <NationFlag :nation="row.nation" class="me-2" />
                                <span class="text-wot-heading">{{ row.name }}</span>
                                <span class="ms-2 text-xs text-wot-dim">T{{ row.tier }}</span>
                            </td>
                            <!-- The one number no API can supply. -->
                            <td class="px-4 py-2 text-right">
                                <EditableNumber
                                    field="banked_xp"
                                    :model-value="row.banked_xp"
                                    :url="`/wot/grinding/purchases/${row.tank_id}`"
                                />
                            </td>
                            <!-- The XP board's own picker, against the XP
                                 board's own store: a module ticked here is
                                 ticked there, which is the whole point of
                                 building this table out of its cells. -->
                            <td class="px-4 py-2 text-right">
                                <ModuleResearchPicker :cell="row" />
                            </td>
                            <!-- Every unlock this tank leads to that is still
                                 owed. Usually one; a tank under two tier Xs
                                 owes both, and both are grinds you would do
                                 from this seat. -->
                            <td class="px-4 py-2 text-right">
                                <span v-if="!row.unlocks.length" class="text-wot-muted">—</span>
                                <div v-for="u in row.unlocks" :key="u.tank_id" class="whitespace-nowrap tabular-nums text-wot-muted">
                                    <span v-if="row.unlocks.length > 1" class="me-2 text-xs text-wot-dim">{{ u.name }}</span>
                                    {{ n(u.xp) }}
                                </div>
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-heading">{{ n(row.xp_remaining) }}</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-16 bg-wot-sunken">
                                        <span class="block h-full bg-wot-gold" :style="{ width: `${row.progress}%` }" />
                                    </span>
                                    <span class="w-12 text-right tabular-nums text-wot-muted">{{ row.progress }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <button
                                    type="button"
                                    class="text-xs uppercase tracking-wider text-wot-dim transition-colors hover:text-wot-bad"
                                    :title="`Stop grinding ${row.name}`"
                                    :aria-label="`Stop grinding ${row.name}`"
                                    @click="setPlaying(row.tank_id, false)"
                                >
                                    &times;
                                </button>
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="active.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">
                                Total
                                <span class="ms-2 font-normal normal-case tracking-normal text-wot-dim">{{ totals.active.tanks }} tanks</span>
                            </th>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-good">{{ n(totals.active.banked_xp) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-muted">{{ n(totals.active.module_xp) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-muted">{{ n(totals.active.research_cost) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(totals.active.xp_remaining) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-16 bg-wot-panel">
                                        <span class="block h-full bg-wot-gold" :style="{ width: `${totals.active.progress}%` }" />
                                    </span>
                                    <span class="w-12 text-right tabular-nums text-wot-muted">{{ totals.active.progress }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-3" />
                        </tr>
                    </tfoot>
                </table>
            </div>

        </section>

        <!-- 2. XP Remaining ------------------------------------------------------
             Research XP, and nothing else. Two figures per cell, each behind its
             own icon: the tank ahead of you, and the modules under you.
        -->
        <section v-else-if="view === 'xp'" class="mt-4" aria-labelledby="xp-heading">
            <h2 id="xp-heading" class="sr-only">XP remaining</h2>

            <div class="mb-3 space-y-2 border border-wot-border bg-wot-panel p-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                    <button
                        v-for="nation in xpNations"
                        :key="nation"
                        type="button"
                        class="border p-1 leading-none transition-colors"
                        :class="xpHiddenNations.includes(nation)
                            ? 'border-wot-border opacity-30 hover:opacity-70'
                            : 'border-wot-gold'"
                        :aria-pressed="!xpHiddenNations.includes(nation)"
                        @click="xpToggleNation(nation)"
                    >
                        <NationFlag :nation="nation" />
                    </button>
                    <button v-if="xpHiddenNations.length" type="button"
                            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                            @click="xpClearNations">
                        All
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Tier</span>
                    <button
                        v-for="tier in xp.tiers"
                        :key="tier"
                        type="button"
                        class="min-w-9 border px-2 py-0.5 text-xs font-bold tracking-wider transition-colors"
                        :class="xpHiddenTiers.includes(tier)
                            ? 'border-wot-border text-wot-dim hover:text-wot-text'
                            : 'border-wot-gold text-wot-gold'"
                        :aria-pressed="!xpHiddenTiers.includes(tier)"
                        :aria-label="`Tier ${ROMAN[tier]}`"
                        @click="xpToggleTier(tier)"
                    >
                        {{ ROMAN[tier] }}
                    </button>
                    <button v-if="xpHiddenTiers.length" type="button"
                            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                            @click="xpClearTiers">
                        All
                    </button>
                </div>

                <div v-if="xpHasDoneLines" class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Lines</span>
                    <label class="flex items-center gap-2 text-xs text-wot-text">
                        <input v-model="hideDone" type="checkbox" class="border">
                        Hide lines with nothing left to research
                    </label>
                </div>

                <!-- The legend earns its place because the two figures in a cell
                     are both bare numbers, and which is which is otherwise only
                     discoverable by hovering. -->
                <div class="flex flex-wrap items-center gap-4 border-t border-wot-border-soft pt-2 text-xs text-wot-dim">
                    <span class="inline-flex items-center gap-1.5">
                        <IconEngine :size="15" stroke-width="2" aria-hidden="true" />
                        XP for this tank's modules
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <IconLockOpen :size="15" stroke-width="2" aria-hidden="true" />
                        XP to unlock the next tank — tick the lock once it is researched
                    </span>
                </div>
            </div>

            <p v-if="!xpShownRows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                {{ hideDone ? 'Nothing left to research in the selected nations and tiers.' : 'No lines in the selected nations and tiers.' }}
            </p>

            <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full border-separate border-spacing-0 divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="sticky left-0 z-20 whitespace-nowrap border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Line</th>
                            <th v-for="tier in xpShownTiers" :key="tier" scope="col"
                                class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                                Tier {{ ROMAN[tier] }}
                            </th>
                            <th scope="col" class="sticky right-0 z-20 whitespace-nowrap border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in xpShownRows" :key="row.key" class="group hover:bg-wot-sunken">
                            <td class="sticky left-0 z-10 whitespace-nowrap border-e border-wot-border bg-wot-panel-solid px-4 py-2 group-hover:bg-wot-sunken-solid">
                                <NationFlag :nation="row.nation" class="me-2" />
                                <span class="text-wot-heading">{{ row.name }}</span>
                                <VehicleTypeIcon :type="row.type" class="ms-2 text-wot-dim" />
                            </td>

                            <td v-for="tier in xpShownTiers" :key="tier" class="px-3 py-2 text-right align-top">
                                <template v-if="row.cells[tier]">
                                    <!-- Modules above the unlock, in the order
                                         the grind actually happens: you research
                                         a tank's modules on the way to affording
                                         the next tank.

                                         The two halves are shared independently.
                                         A vehicle's modules are researched once,
                                         so a shared cell shows them as text; but
                                         two lines diverging from that vehicle owe
                                         two different unlocks, so the unlock can
                                         still be this row's to edit. -->
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-end gap-1.5">
                                            <IconEngine
                                                :size="14"
                                                stroke-width="2"
                                                class="shrink-0"
                                                :class="row.cells[tier].module_xp ? 'text-wot-dim' : 'text-wot-dim/50'"
                                                aria-hidden="true"
                                            />
                                            <span
                                                v-if="row.cells[tier].is_shared"
                                                class="tabular-nums text-wot-dim/60"
                                                :title="`${row.cells[tier].name} — modules counted and ticked on ${row.cells[tier].shared_with}.`"
                                            >
                                                {{ n(row.cells[tier].module_xp) }}
                                            </span>
                                            <ModuleResearchPicker v-else :cell="row.cells[tier]" />
                                        </div>
                                        <div v-if="row.cells[tier].unlocks" class="flex items-center justify-end gap-1.5">
                                            <!-- The tick that settles this
                                                 unlock, leading the row rather
                                                 than trailing it: it is the
                                                 thing you do, and the number
                                                 beside it is what it zeroes. -->
                                            <ResearchLock :unlocks="row.cells[tier].unlocks" />

                                            <!-- Already researched, so nothing is
                                                 owed however much it lists at. -->
                                            <span
                                                v-if="row.cells[tier].unlocks.is_unlocked"
                                                class="tabular-nums text-wot-dim/50"
                                                :title="`${row.cells[tier].unlocks.name} — already researched`"
                                            >
                                                0
                                            </span>

                                            <span
                                                v-else-if="row.cells[tier].unlocks.is_shared"
                                                class="tabular-nums text-wot-dim/60"
                                                :title="`${row.cells[tier].unlocks.name} — counted and edited on ${row.cells[tier].shared_with ?? 'another line'}.`"
                                            >
                                                {{ n(row.cells[tier].unlocks.xp) }}
                                            </span>

                                            <template v-else>
                                                <EditableNumber
                                                    :field="'research_xp'"
                                                    :model-value="row.cells[tier].unlocks.xp"
                                                    :url="`/wot/grinding/research/${row.cells[tier].unlocks.tank_id}`"
                                                    :only="['xp', 'totals']"
                                                    :tone="row.cells[tier].unlocks.is_discounted
                                                        ? 'border-wot-gold/50 bg-wot-sunken text-wot-gold'
                                                        : 'border-wot-border bg-wot-sunken text-wot-text'"
                                                />
                                                <!-- Only offered once the figure
                                                     has been typed over; there is
                                                     nothing to reset back to
                                                     otherwise. -->
                                                <button
                                                    v-if="row.cells[tier].unlocks.is_discounted"
                                                    type="button"
                                                    class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 text-xs leading-none text-wot-dim transition-colors hover:text-wot-bad"
                                                    :title="`Reset to the ${n(row.cells[tier].unlocks.full_xp)} full cost of the ${row.cells[tier].unlocks.name}`"
                                                    @click="resetResearchXp(row.cells[tier].unlocks.tank_id)"
                                                >
                                                    &times;
                                                </button>
                                            </template>
                                        </div>

                                    </div>
                                </template>
                                <span v-else class="text-wot-muted">·</span>
                            </td>

                            <td class="sticky right-0 z-10 whitespace-nowrap border-s border-wot-border bg-wot-panel-solid px-4 py-2 text-right tabular-nums group-hover:bg-wot-sunken-solid"
                                :class="xpRowRemaining(row) ? 'text-wot-heading' : 'text-wot-dim'">
                                {{ n(xpRowRemaining(row)) }}
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="xpShownRows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="sticky left-0 z-20 border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                            <td v-for="tier in xpShownTiers" :key="tier" class="px-3 py-3 text-right tabular-nums text-wot-muted">
                                {{ n(xpTierTotal(tier)) }}
                            </td>
                            <td class="sticky right-0 z-20 border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(xpGrandTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- 3. Tanks to Purchase ------------------------------------------------>
        <!--
            Credits only. Every other figure on this page belongs to a different
            question, and a shopping list that also quotes XP is a shopping list
            you have to read twice.
        -->
        <section v-else-if="view === 'purchase'" class="mt-4" aria-labelledby="purchase-heading">
            <h2 id="purchase-heading" class="sr-only">Tanks to purchase</h2>

            <p v-if="!purchase.rows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                Nothing left to buy. Every tracked line has been bought out.
            </p>

            <template v-else>
                <div class="mb-3 space-y-2 border border-wot-border bg-wot-panel p-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span id="nation-filter" class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                        <button
                            v-for="nation in purchaseNations"
                            :key="nation"
                            type="button"
                            class="border p-1 leading-none transition-colors"
                            :class="hiddenNations.includes(nation)
                                ? 'border-wot-border opacity-30 hover:opacity-70'
                                : 'border-wot-gold'"
                            :aria-pressed="!hiddenNations.includes(nation)"
                            @click="toggleNation(nation)"
                        >
                            <NationFlag :nation="nation" />
                        </button>
                        <button v-if="hiddenNations.length" type="button"
                                class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                                @click="clearNations">
                            All
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Tier</span>
                        <button
                            v-for="tier in purchase.tiers"
                            :key="tier"
                            type="button"
                            class="min-w-9 border px-2 py-0.5 text-xs font-bold tracking-wider transition-colors"
                            :class="hiddenTiers.includes(tier)
                                ? 'border-wot-border text-wot-dim hover:text-wot-text'
                                : 'border-wot-gold text-wot-gold'"
                            :aria-pressed="!hiddenTiers.includes(tier)"
                            :aria-label="`Tier ${ROMAN[tier]}`"
                            @click="toggleTier(tier)"
                        >
                            {{ ROMAN[tier] }}
                        </button>
                        <button v-if="hiddenTiers.length" type="button"
                                class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                                @click="clearTiers">
                            All
                        </button>
                    </div>

                    <!-- A checkbox rather than a filter button. The two rows
                         above pick which of many values to show, where a
                         selected/unselected chip reads naturally; this is one
                         on/off decision, and a label can say outright what
                         ticking it does instead of leaving you to infer it from
                         which state looks active. Checked means hidden, so the
                         control and the flag it sets agree. -->
                    <div v-if="hasOwnedLines" class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Lines</span>
                        <label class="flex items-center gap-2 text-xs text-wot-text">
                            <input v-model="hideOwned" type="checkbox" class="border">
                            Hide lines that are fully owned
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Prices</span>
                        <label class="flex items-center gap-2 text-xs text-wot-text">
                            <input v-model="showSale" type="checkbox" class="border">
                            Show discounted prices
                        </label>
                    </div>
                </div>

                <p v-if="!shownRows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                    Nothing left to buy in the selected nations and tiers.
                </p>

                <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                    <table class="min-w-full border-separate border-spacing-0 divide-y divide-wot-border text-sm">
                        <thead class="bg-wot-sunken">
                            <tr>
                                <th scope="col" class="sticky left-0 z-20 whitespace-nowrap border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Line</th>
                                <th v-for="tier in shownTiers" :key="tier" scope="col"
                                    class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                                    Tier {{ ROMAN[tier] }}
                                </th>
                                <th scope="col" class="sticky right-0 z-20 whitespace-nowrap border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-wot-border-soft">
                            <tr v-for="row in shownRows" :key="row.key" class="group hover:bg-wot-sunken">
                                <td class="sticky left-0 z-10 whitespace-nowrap border-e border-wot-border bg-wot-panel-solid px-4 py-2 group-hover:bg-wot-sunken-solid">
                                    <NationFlag :nation="row.nation" class="me-2" />
                                    <span class="text-wot-heading">{{ row.name }}</span>
                                    <VehicleTypeIcon :type="row.type" class="ms-2 text-wot-dim" />
                                </td>

                                <td v-for="tier in shownTiers" :key="tier" class="px-3 py-2 text-right align-top">
                                    <template v-if="row.cells[tier]">
                                        <!-- Shared with a line above, where it is the
                                             editable one. Text rather than a control:
                                             the same tank must never be two sets of
                                             buttons, and only the row that owns it pays
                                             for it.

                                             Ahead of the bought branch deliberately — a
                                             shared cell is read-only whatever its state,
                                             and the MS-1 sits on twelve lines. -->
                                        <span
                                            v-if="row.cells[tier].is_shared"
                                            class="tabular-nums text-wot-dim/60"
                                            :title="`${row.cells[tier].name} — shared with ${row.cells[tier].shared_with}, where it is counted and edited.`"
                                        >
                                            {{ n(row.cells[tier].is_purchased ? 0 : salePrice(row.cells[tier])) }}
                                        </span>

                                        <!-- Owned: nothing left to pay, so the cell
                                             reads zero rather than restating a price
                                             that is no longer owed. -->
                                        <button
                                            v-else-if="row.cells[tier].is_purchased"
                                            type="button"
                                            class="tabular-nums text-wot-dim hover:text-wot-muted"
                                            :title="`${row.cells[tier].name} — bought. Mark as not bought.`"
                                            @click="setPurchase(row.cells[tier].tank_id, { is_purchased: false })"
                                        >
                                            0
                                        </button>

                                        <div v-else class="flex items-stretch justify-end gap-px">
                                            <!-- One input group: research state on the
                                                 left, price in the middle, buying on the
                                                 right, so the cell reads in the order the
                                                 two steps happen.

                                                 gap-px leaves exactly one pixel between
                                                 segments, enough to read them as separate
                                                 controls without spending the width a
                                                 real gap costs in a five-column table.

                                                 items-stretch sizes the buttons from the
                                                 input rather than from their own padding,
                                                 so the group has one flat top and bottom
                                                 edge; the buttons' vertical padding stops
                                                 deciding their height, which is why they
                                                 need justify-center to hold the icon in
                                                 the middle of the taller box.

                                                 Icons only, to keep the cell narrow —
                                                 which leaves the title as the only thing
                                                 naming the tank, the tooltip for a
                                                 pointer and an sr-only line for a screen
                                                 reader.

                                                 The lock reads, it does not set: research
                                                 is ticked on XP Remaining, against the
                                                 unlock that leads to the tank. It stays
                                                 on show because it still gates the cart
                                                 and tints the price, and hiding it would
                                                 leave both unexplained. -->
                                            <span
                                                class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 text-wot-dim"
                                                :title="researchedTitle(row.cells[tier])"
                                            >
                                                <component :is="row.cells[tier].is_unlocked ? IconLock : IconLockOpen" :size="14" stroke-width="2.25" aria-hidden="true" />
                                                <span class="sr-only">{{ researchedTitle(row.cells[tier]) }}</span>
                                            </span>

                                            <!-- A previewed sale price is derived, not
                                                 stored, so it shows as text: typing into
                                                 the field would save the discounted
                                                 figure as though it were the real one.
                                                 Sized like the input it replaces so the
                                                 group does not shift when you toggle. -->
                                            <span
                                                v-if="showSale"
                                                class="w-20 border border-wot-border bg-wot-sunken px-1 py-0.5 text-end text-sm tabular-nums text-wot-gold"
                                                :title="`${row.cells[tier].name} — ${n(row.cells[tier].price)} at full price`"
                                            >
                                                {{ n(salePrice(row.cells[tier])) }}
                                            </span>

                                            <EditableNumber
                                                v-else
                                                :field="'price_credit'"
                                                :model-value="row.cells[tier].price"
                                                :url="`/wot/grinding/purchases/${row.cells[tier].tank_id}`"
                                                :only="['purchase', 'totals']"
                                                :optimistic="optimisticPrice(row.cells[tier].tank_id)"
                                                :tone="priceTone(row.cells[tier])"
                                            />

                                            <!-- Only offered once a price has been
                                                 overridden; there is nothing to reset
                                                 back to otherwise. Bordered like the
                                                 other segments now that it sits inside
                                                 the group rather than floating beside
                                                 the number. -->
                                            <button
                                                v-if="row.cells[tier].is_discounted"
                                                type="button"
                                                class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 text-xs leading-none text-wot-dim transition-colors hover:text-wot-bad"
                                                :title="`Reset to the ${n(row.cells[tier].api_price)} shop price`"
                                                @click="setPurchase(row.cells[tier].tank_id, { price_credit: null })"
                                            >
                                                &times;
                                            </button>

                                            <!-- Researched vehicles only: buying one that
                                                 is not researched yet is not a move the
                                                 game offers, and the server would force
                                                 is_unlocked back on anyway. -->
                                            <button
                                                v-if="row.cells[tier].is_unlocked"
                                                type="button"
                                                class="inline-flex shrink-0 items-center justify-center border border-wot-good/50 p-1 text-wot-good transition-colors hover:bg-wot-good/15"
                                                :title="`${row.cells[tier].name} — researched. Mark as bought.`"
                                                :aria-label="`Mark ${row.cells[tier].name} as bought`"
                                                @click="setPurchase(row.cells[tier].tank_id, { is_purchased: true })"
                                            >
                                                <IconShoppingCart :size="14" stroke-width="2.25" />
                                            </button>
                                        </div>
                                </template>
                            </td>

                            <td class="sticky right-0 z-10 border-s border-wot-border bg-wot-panel-solid px-4 py-2 text-right tabular-nums font-bold text-wot-heading group-hover:bg-wot-sunken-solid">{{ n(rowRemaining(row)) }}</td>
                        </tr>
                    </tbody>

                    <tfoot v-if="shownRows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="sticky left-0 z-20 border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                            <td v-for="tier in shownTiers" :key="tier" class="px-3 py-3 text-right tabular-nums text-wot-muted">
                                {{ n(tierTotal(tier)) }}
                            </td>
                            <td class="sticky right-0 z-20 border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(grandTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            </template>
        </section>

        <!-- 4. Free XP ---------------------------------------------------------
             Modules, and nothing else. Laid out like Tanks to Purchase because
             it asks the same shape of question against the same tree, but a
             cell here is a list to tick rather than a price to pay — there is
             no discount, no owned state, and nothing to type.
        -->
        <section v-else-if="view === 'freexp'" class="mt-4" aria-labelledby="freexp-heading">
            <h2 id="freexp-heading" class="sr-only">Free XP</h2>

            <p v-if="!freexp.rows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                No lines to plan against. Run <code>php artisan wot:sync-vehicles</code> to fill the encyclopedia.
            </p>

            <template v-else>
                <div class="mb-3 space-y-2 border border-wot-border bg-wot-panel p-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                        <button
                            v-for="nation in freexpNations"
                            :key="nation"
                            type="button"
                            class="border p-1 leading-none transition-colors"
                            :class="fxHiddenNations.includes(nation)
                                ? 'border-wot-border opacity-30 hover:opacity-70'
                                : 'border-wot-gold'"
                            :aria-pressed="!fxHiddenNations.includes(nation)"
                            @click="fxToggleNation(nation)"
                        >
                            <NationFlag :nation="nation" />
                        </button>
                        <button v-if="fxHiddenNations.length" type="button"
                                class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                                @click="fxClearNations">
                            All
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Tier</span>
                        <button
                            v-for="tier in freexp.tiers"
                            :key="tier"
                            type="button"
                            class="min-w-9 border px-2 py-0.5 text-xs font-bold tracking-wider transition-colors"
                            :class="fxHiddenTiers.includes(tier)
                                ? 'border-wot-border text-wot-dim hover:text-wot-text'
                                : 'border-wot-gold text-wot-gold'"
                            :aria-pressed="!fxHiddenTiers.includes(tier)"
                            :aria-label="`Tier ${ROMAN[tier]}`"
                            @click="fxToggleTier(tier)"
                        >
                            {{ ROMAN[tier] }}
                        </button>
                        <button v-if="fxHiddenTiers.length" type="button"
                                class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                                @click="fxClearTiers">
                            All
                        </button>
                    </div>

                    <!-- Opposite polarity to the purchase board's Lines
                         checkbox, deliberately. There it hides what is settled;
                         a module plan is never settled, so this narrows to what
                         you have already planned instead — useful for reading
                         the plan back, useless as a default. -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Lines</span>
                        <label class="flex items-center gap-2 text-xs text-wot-text">
                            <input v-model="onlyPlanned" type="checkbox" class="border">
                            Only lines I have planned on
                        </label>
                    </div>
                </div>

                <p v-if="!fxShownRows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                    {{ onlyPlanned ? 'Nothing planned in the selected nations and tiers.' : 'No lines in the selected nations and tiers.' }}
                </p>

                <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                    <table class="min-w-full border-separate border-spacing-0 divide-y divide-wot-border text-sm">
                        <thead class="bg-wot-sunken">
                            <tr>
                                <th scope="col" class="sticky left-0 z-20 whitespace-nowrap border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Line</th>
                                <th v-for="tier in fxShownTiers" :key="tier" scope="col"
                                    class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                                    Tier {{ ROMAN[tier] }}
                                </th>
                                <th scope="col" class="sticky right-0 z-20 whitespace-nowrap border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Planned</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-wot-border-soft">
                            <tr v-for="row in fxShownRows" :key="row.key" class="group hover:bg-wot-sunken">
                                <td class="sticky left-0 z-10 whitespace-nowrap border-e border-wot-border bg-wot-panel-solid px-4 py-2 group-hover:bg-wot-sunken-solid">
                                    <NationFlag :nation="row.nation" class="me-2" />
                                    <span class="text-wot-heading">{{ row.name }}</span>
                                    <VehicleTypeIcon :type="row.type" class="ms-2 text-wot-dim" />
                                </td>

                                <td v-for="tier in fxShownTiers" :key="tier" class="px-3 py-2 text-right align-top">
                                    <template v-if="row.cells[tier]">
                                        <!-- Shared with a line above, where it is the
                                             editable one. The same tank must never be
                                             two dropdowns writing the same plan. -->
                                        <span
                                            v-if="row.cells[tier].is_shared"
                                            class="tabular-nums text-wot-dim/60"
                                            :title="`${row.cells[tier].name} — shared with ${row.cells[tier].shared_with}, where it is planned.`"
                                        >
                                            {{ n(row.cells[tier].planned_xp) }}
                                        </span>

                                        <ModulePlanPicker v-else :cell="row.cells[tier]" />
                                    </template>
                                    <span v-else class="text-wot-muted">·</span>
                                </td>

                                <td class="sticky right-0 z-10 whitespace-nowrap border-s border-wot-border bg-wot-panel-solid px-4 py-2 text-right tabular-nums group-hover:bg-wot-sunken-solid"
                                    :class="fxRowPlanned(row) ? 'text-wot-gold' : 'text-wot-muted'">
                                    {{ n(fxRowPlanned(row)) }}
                                </td>
                            </tr>
                        </tbody>

                        <tfoot v-if="fxShownRows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                            <tr>
                                <th scope="row" class="sticky left-0 z-20 border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                                <td v-for="tier in fxShownTiers" :key="tier" class="px-3 py-3 text-right tabular-nums text-wot-muted">
                                    {{ n(fxTierTotal(tier)) }}
                                </td>
                                <td class="sticky right-0 z-20 border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(fxGrandTotal) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </template>
        </section>

        <!-- 5. Blueprints -------------------------------------------------------
             Fragments held, and nothing else. The simplest of the four boards:
             one typed number per vehicle, because the encyclopedia publishes
             neither the fragments a tank needs nor the discount they buy. What
             they actually reduce the cost to is recorded on XP Remaining.
        -->
        <section v-else class="mt-4" aria-labelledby="blueprints-heading">
            <h2 id="blueprints-heading" class="sr-only">Blueprints</h2>

            <div class="mb-3 space-y-2 border border-wot-border bg-wot-panel p-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                    <button
                        v-for="nation in blueprintNations"
                        :key="nation"
                        type="button"
                        class="border p-1 leading-none transition-colors"
                        :class="bpHiddenNations.includes(nation)
                            ? 'border-wot-border opacity-30 hover:opacity-70'
                            : 'border-wot-gold'"
                        :aria-pressed="!bpHiddenNations.includes(nation)"
                        @click="bpToggleNation(nation)"
                    >
                        <NationFlag :nation="nation" />
                    </button>
                    <button v-if="bpHiddenNations.length" type="button"
                            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                            @click="bpClearNations">
                        All
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Tier</span>
                    <button
                        v-for="tier in blueprints.tiers"
                        :key="tier"
                        type="button"
                        class="min-w-9 border px-2 py-0.5 text-xs font-bold tracking-wider transition-colors"
                        :class="bpHiddenTiers.includes(tier)
                            ? 'border-wot-border text-wot-dim hover:text-wot-text'
                            : 'border-wot-gold text-wot-gold'"
                        :aria-pressed="!bpHiddenTiers.includes(tier)"
                        :aria-label="`Tier ${ROMAN[tier]}`"
                        @click="bpToggleTier(tier)"
                    >
                        {{ ROMAN[tier] }}
                    </button>
                    <button v-if="bpHiddenTiers.length" type="button"
                            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                            @click="bpClearTiers">
                        All
                    </button>
                </div>

                <!-- Fragments only discount something you have yet to research,
                     so a line you have finished is a line they cannot help. Same
                     polarity as XP Remaining's checkbox, and on by default for
                     the same reason. -->
                <div v-if="bpHasDoneLines" class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Lines</span>
                    <label class="flex items-center gap-2 text-xs text-wot-text">
                        <input v-model="bpHideDone" type="checkbox" class="border">
                        Hide lines with nothing left to research
                    </label>
                </div>
            </div>

            <p v-if="!bpShownRows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                {{ bpHideDone ? 'Nothing left to research in the selected nations and tiers.' : 'No lines in the selected nations and tiers.' }}
            </p>

            <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full border-separate border-spacing-0 divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="sticky left-0 z-20 whitespace-nowrap border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Line</th>
                            <th v-for="tier in bpShownTiers" :key="tier" scope="col"
                                class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                                Tier {{ ROMAN[tier] }}
                            </th>
                            <th scope="col" class="sticky right-0 z-20 whitespace-nowrap border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Held</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in bpShownRows" :key="row.key" class="group hover:bg-wot-sunken">
                            <td class="sticky left-0 z-10 whitespace-nowrap border-e border-wot-border bg-wot-panel-solid px-4 py-2 group-hover:bg-wot-sunken-solid">
                                <NationFlag :nation="row.nation" class="me-2" />
                                <span class="text-wot-heading">{{ row.name }}</span>
                                <VehicleTypeIcon :type="row.type" class="ms-2 text-wot-dim" />
                            </td>

                            <td v-for="tier in bpShownTiers" :key="tier" class="px-3 py-2 text-right align-top">
                                <template v-if="row.cells[tier]">
                                    <!-- A starter vehicle is researched from
                                         nothing, so fragments have nothing to
                                         discount. -->
                                    <span
                                        v-if="!row.cells[tier].is_researchable"
                                        class="text-wot-muted"
                                        :title="`${row.cells[tier].name} — the start of the line, nothing to research`"
                                    >—</span>

                                    <!-- Shared with a line above, where it is
                                         the editable one. -->
                                    <span
                                        v-else-if="row.cells[tier].is_shared"
                                        class="tabular-nums text-wot-dim/60"
                                        :title="`${row.cells[tier].name} — counted and edited on ${row.cells[tier].shared_with}.`"
                                    >
                                        {{ n(row.cells[tier].fragments) }}
                                    </span>

                                    <EditableNumber
                                        v-else
                                        :field="'blueprint_fragments'"
                                        :model-value="row.cells[tier].fragments"
                                        :url="`/wot/grinding/purchases/${row.cells[tier].tank_id}`"
                                        :only="['blueprints', 'totals']"
                                        :tone="row.cells[tier].is_unlocked
                                            ? 'border-wot-border bg-wot-sunken text-wot-dim/50'
                                            : 'border-wot-border bg-wot-sunken text-wot-text'"
                                    />
                                </template>
                                <span v-else class="text-wot-muted">·</span>
                            </td>

                            <td class="sticky right-0 z-10 whitespace-nowrap border-s border-wot-border bg-wot-panel-solid px-4 py-2 text-right tabular-nums group-hover:bg-wot-sunken-solid"
                                :class="bpRowFragments(row) ? 'text-wot-gold' : 'text-wot-dim'">
                                {{ n(bpRowFragments(row)) }}
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="bpShownRows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="sticky left-0 z-20 border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                            <td v-for="tier in bpShownTiers" :key="tier" class="px-3 py-3 text-right tabular-nums text-wot-muted">
                                {{ n(bpTierTotal(tier)) }}
                            </td>
                            <td class="sticky right-0 z-20 border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(bpGrandTotal) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- Active Grinding only. Saying what you are playing is not a question
             you ask of the tech tree, which is all the other four tabs are. -->
        <div v-if="view === 'active'" class="mt-8">
            <div class="border border-wot-border bg-wot-panel p-4">
                <h2 class="text-base">Add a tank you're playing</h2>
                <p class="mt-1 text-xs text-wot-dim">
                    {{ hasPick
                        ? 'Everything on the tree that matches. Its figures are read off the XP Remaining board.'
                        : 'In your garage, part ground. Pick a nation, tier or type to search the whole tree.' }}
                </p>

                <!-- The board filter rows' chips, with their polarity inverted:
                     lit means picked, and picking nothing opens on the garage
                     rather than on the tree. See the note on pickedNations. -->
                <div class="mt-3 space-y-2 border border-wot-border bg-wot-sunken p-3">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                        <button
                            v-for="nation in pickerNations"
                            :key="nation"
                            type="button"
                            class="border p-1 leading-none transition-colors"
                            :class="pickedNations.includes(nation) ? 'border-wot-gold' : 'border-wot-border opacity-30 hover:opacity-70'"
                            :aria-pressed="pickedNations.includes(nation)"
                            @click="pickedNations = withPick(pickedNations, nation)"
                        >
                            <NationFlag :nation="nation" />
                        </button>
                        <button v-if="pickedNations.length" type="button"
                                class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                                @click="pickedNations = []">
                            All
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Tier</span>
                        <button
                            v-for="tier in pickerTiers"
                            :key="tier"
                            type="button"
                            class="min-w-9 border px-2 py-0.5 text-xs font-bold tracking-wider transition-colors"
                            :class="pickedTiers.includes(tier) ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                            :aria-pressed="pickedTiers.includes(tier)"
                            :aria-label="`Tier ${ROMAN[tier]}`"
                            @click="pickedTiers = withPick(pickedTiers, tier)"
                        >
                            {{ ROMAN[tier] }}
                        </button>
                        <button v-if="pickedTiers.length" type="button"
                                class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                                @click="pickedTiers = []">
                            All
                        </button>
                    </div>

                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Type</span>
                        <button
                            v-for="type in pickerTypes"
                            :key="type"
                            type="button"
                            class="inline-flex h-6 min-w-9 items-center justify-center border px-2 transition-colors"
                            :class="pickedTypes.includes(type) ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                            :aria-pressed="pickedTypes.includes(type)"
                            @click="pickedTypes = withPick(pickedTypes, type)"
                        >
                            <VehicleTypeIcon :type="type" />
                        </button>
                        <button v-if="pickedTypes.length" type="button"
                                class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                                @click="pickedTypes = []">
                            All
                        </button>
                    </div>
                </div>

                <!-- Capped and scrolled: unfiltered this is every tank on every
                     line, and a list that long would push the page out from
                     under the table it belongs to. -->
                <div class="mt-3 max-h-64 overflow-y-auto">
                    <p v-if="!pickable.length" class="border border-dashed border-wot-border p-6 text-center text-sm text-wot-dim">
                        {{ hasPick
                            ? 'Nothing left to add in those nations, tiers and types.'
                            : 'Nothing in the garage is part ground. Pick a nation, tier or type to search the whole tree.' }}
                    </p>

                    <ul v-else role="list" class="flex flex-wrap gap-1.5">
                        <li v-for="o in pickable" :key="o.tank_id">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap border border-wot-border px-2 py-1 text-xs text-wot-text transition-colors hover:border-wot-gold hover:text-wot-gold"
                                :title="o.xp_remaining
                                    ? `Start grinding ${o.name} — ${n(o.xp_remaining)} XP still on it`
                                    : `Start grinding ${o.name}`"
                                @click="setPlaying(o.tank_id, true)"
                            >
                                <NationFlag :nation="o.nation" />
                                {{ o.name }}
                                <span class="text-wot-dim">T{{ o.tier }}</span>
                                <VehicleTypeIcon :type="o.type" class="text-wot-dim" />
                            </button>
                        </li>
                    </ul>
                </div>

                <p class="mt-2 text-xs text-wot-dim">
                    {{ hasPick
                        ? `${pickable.length} of ${options.length} tanks`
                        : `${pickable.length} ${pickable.length === 1 ? 'tank' : 'tanks'} with XP still on them` }}
                </p>
            </div>

        </div>
    </AppShell>
</template>
