<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { IconLock, IconLockOpen, IconShoppingCart } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import EditableNumber from '../Components/EditableNumber.vue';
import ModulePicker from '../Components/ModulePicker.vue';
import NationFlag from '../Components/NationFlag.vue';
import VehicleTypeIcon from '../Components/VehicleTypeIcon.vue';

const props = defineProps({
    active: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    totals: { type: Object, required: true },
    purchase: { type: Object, required: true },
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

const expanded = ref([]);
const toggle = (id) => {
    expanded.value = expanded.value.includes(id)
        ? expanded.value.filter((x) => x !== id)
        : [...expanded.value, id];
};

const open = computed(() => props.targets.filter((t) => !t.is_complete));

const addForm = useForm({ tank_id: '' });
const addTarget = () => addForm.post('/wot/grinding/targets', {
    preserveScroll: true,
    onSuccess: () => addForm.reset(),
});

const settingsForm = useForm({
    credits_available: props.settings.credits_available,
    garage_slots_vacant: props.settings.garage_slots_vacant,
});
const saveSettings = () => settingsForm.patch('/wot/grinding/settings', { preserveScroll: true });

const removeTarget = (t) => {
    if (window.confirm(`Stop tracking ${t.name}?`)) {
        router.delete(`/wot/grinding/targets/${t.id}`, { preserveScroll: true });
    }
};

const toggleComplete = (t) => router.patch(`/wot/grinding/targets/${t.id}/complete`, {}, { preserveScroll: true });

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
    only: ['purchase', 'totals'],
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
 * Purchase filters.
 *
 * Held as what is *hidden* rather than what is selected, so everything starts on
 * and stays on: buying a tank can retire a nation or collapse a tier column, and
 * a selected-set would then have to guess whether a column that reappears later
 * was meant to be on. Deselections survive that; stale entries are harmless.
 */
const hiddenNations = ref([]);

/*
 * Seeded once, at setup, from the tiers the server reports as settled — never
 * watched. A column with nothing left to pay for is noise on arrival, but
 * buying the last tank in a column while you are looking at it must not make
 * the column vanish underneath you.
 *
 * Setup runs once per component instance, and every purchase on this tab is a
 * partial reload that updates props on the existing instance, so this fires on
 * a real visit and never again while you click. A watcher here would re-seed on
 * every response and do exactly the disappearing act described above.
 *
 * Copied rather than aliased, so toggling a filter never writes to the prop.
 */
const hiddenTiers = ref([...(props.purchase.bought_tiers ?? [])]);

/*
 * Lines with nothing left to buy, hidden by default.
 *
 * The board is the whole tech tree now, so most of what it holds on any given
 * visit is finished business — and the arithmetic above it reads the visible
 * rows, so leaving them in makes the headline figure the cost of the tree
 * rather than the cost of what is left. They are still there, one click away,
 * which is the part that was missing before: the server used to drop them and
 * there was nothing to click.
 */
const hideOwned = ref(true);

const page = usePage();

const drop = (list, value) => (list.includes(value)
    ? list.filter((x) => x !== value)
    : [...list, value]);

const toggleNation = (nation) => (hiddenNations.value = drop(hiddenNations.value, nation));
const toggleTier = (tier) => (hiddenTiers.value = drop(hiddenTiers.value, tier));

// In tech-tree order, like every other vehicle list here.
const purchaseNations = computed(() => {
    const present = new Set(props.purchase.rows.map((r) => r.nation));

    return Object.keys(page.props.nations ?? {}).filter((nation) => present.has(nation));
});

const shownTiers = computed(() => props.purchase.tiers.filter((t) => !hiddenTiers.value.includes(t)));

// Only cells in a visible column count. Hiding a tier takes its price off the
// bill — otherwise the filter would change what you see but not what you owe.
//
// A shared cell costs this row nothing: the row that owns the vehicle is paying
// for it, which is what keeps the row totals summing to the grand total and
// agreeing with the server's credits_remaining cell for cell.
const cellCost = (cell) => (cell && !cell.is_purchased && !cell.is_shared ? cell.price : 0);
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
 * Credits shortfall is the number that decides whether a plan is realistic, so
 * it follows the board rather than the server's total.
 *
 * The server now bills the entire tech tree, which is the honest number for a
 * board that shows the entire tech tree and a useless one to hold against your
 * balance. Reading the filtered total instead means narrowing to a nation, or
 * hiding what you own, answers "what would finishing this cost me?".
 *
 * Declared after grandTotal because it reads it.
 */
const creditGap = computed(() => grandTotal.value - props.settings.credits_available);
</script>

<template>
    <Head title="Grinding" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <div>
                <h1 class="text-3xl">Grinding</h1>
                <p class="mt-1 text-sm text-wot-dim">
                    {{ totals.open }} open {{ totals.open === 1 ? 'target' : 'targets' }} ·
                    {{ n(totals.xp_remaining) }} XP still to earn
                </p>
            </div>
        </div>

        <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">XP remaining</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-heading">{{ n(totals.xp_remaining) }}</dd>
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
                <dd class="mt-1 text-xl tabular-nums" :class="creditGap > 0 ? 'text-wot-bad' : 'text-wot-good'">
                    {{ short(grandTotal) }}
                </dd>
                <p class="mt-0.5 text-xs text-wot-dim">
                    {{ creditGap > 0 ? `${short(creditGap)} short` : 'covered' }}
                </p>
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
                No tanks marked as being played. Mark a step active on the XP Remaining tab.
            </p>

            <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Tank</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Towards</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">XP banked</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To max</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To next tank</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in active" :key="row.id" class="hover:bg-wot-sunken">
                            <td class="px-4 py-2">
                                <NationFlag :nation="row.nation" class="me-2" />
                                <span class="text-wot-heading">{{ row.name }}</span>
                                <span class="ms-2 text-xs text-wot-dim">T{{ row.tier }}</span>
                            </td>
                            <td class="px-4 py-2 text-wot-muted">{{ row.target_name }}</td>
                            <!-- The one number no API can supply. -->
                            <td class="px-4 py-2 text-right">
                                <EditableNumber :step-id="row.id" field="banked_xp" :model-value="row.banked_xp" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <ModulePicker :step-id="row.id" :modules="row.modules" :outstanding="row.module_xp_remaining" />
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ n(row.research_cost) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-heading">{{ n(row.xp_remaining) }}</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-16 bg-wot-sunken">
                                        <span class="block h-full bg-wot-gold" :style="{ width: `${row.progress}%` }" />
                                    </span>
                                    <span class="w-12 text-right tabular-nums text-wot-muted">{{ row.progress }}%</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="active.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                            <td class="px-4 py-3 text-xs text-wot-dim">{{ totals.active.steps }} tanks</td>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-good">{{ n(totals.active.banked_xp) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-muted">{{ n(totals.active.module_xp_remaining) }}</td>
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
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- 2. Tanks to Purchase ------------------------------------------------>
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
                                @click="hiddenNations = []">
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
                                @click="hiddenTiers = []">
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
                                            {{ n(row.cells[tier].is_purchased ? 0 : row.cells[tier].price) }}
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
                                                 which leaves title and aria-label as the
                                                 only things naming the action, the
                                                 tooltip for a pointer and the label for a
                                                 screen reader that would otherwise
                                                 announce nothing but "button". -->
                                            <button
                                                type="button"
                                                class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 text-wot-dim transition-colors"
                                                :class="row.cells[tier].is_unlocked ? 'hover:text-wot-bad' : 'hover:text-wot-text'"
                                                :title="row.cells[tier].is_unlocked
                                                    ? `${row.cells[tier].name} — researched. Mark as not researched.`
                                                    : `${row.cells[tier].name} — not researched. Mark as unlocked.`"
                                                :aria-label="row.cells[tier].is_unlocked
                                                    ? `Mark ${row.cells[tier].name} as not researched`
                                                    : `Mark ${row.cells[tier].name} as unlocked`"
                                                @click="setPurchase(row.cells[tier].tank_id, { is_unlocked: !row.cells[tier].is_unlocked })"
                                            >
                                                <component :is="row.cells[tier].is_unlocked ? IconLock : IconLockOpen" :size="14" stroke-width="2.25" />
                                            </button>

                                            <EditableNumber
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

            <p class="mt-3 text-xs text-wot-dim">
                Prices come from the encyclopedia and can be typed over when a seasonal discount applies.
                Every line stays on the board, including ones you have finished — use the Owned
                filter to put them away. Hiding a tier takes it out of the totals.
                Tiers you have already bought out start hidden — turn one back on to un-tick
                something in it. A tank that sits on more than one line is counted, and edited,
                only on the first line that shows it.
            </p>
        </section>

        <!-- 3-5. Target-driven views -------------------------------------------->
        <section v-else class="mt-4" aria-labelledby="targets-heading">
            <h2 id="targets-heading" class="sr-only">{{ views.find((v) => v.key === view).label }}</h2>

            <div class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Target</th>
                            <template v-if="view === 'xp'">
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Required</th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                            </template>
                            <template v-else-if="view === 'freexp'">
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Free XP planned</th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">XP remaining</th>
                            </template>
                            <template v-else>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Fragments</th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Full research XP</th>
                            </template>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Steps</th>
                            <th scope="col" class="w-24 px-4 py-3" />
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <template v-for="t in targets" :key="t.id">
                            <tr class="cursor-pointer hover:bg-wot-sunken" :class="t.is_complete ? 'opacity-50' : ''" @click="toggle(t.id)">
                                <td class="px-4 py-2">
                                    <span aria-hidden="true" class="me-1 inline-block w-3 text-wot-dim">{{ expanded.includes(t.id) ? '▾' : '▸' }}</span>
                                    <NationFlag :nation="t.nation" class="me-2" />
                                    <span class="text-wot-heading">{{ t.name }}</span>
                                    <span class="ms-2 text-xs text-wot-dim">T{{ t.tier }}</span>
                                </td>

                                <template v-if="view === 'xp'">
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ n(t.xp_required) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-heading">{{ n(t.xp_remaining) }}</td>
                                </template>
                                <template v-else-if="view === 'freexp'">
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-gold">{{ n(t.free_xp_planned) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ n(t.xp_remaining) }}</td>
                                </template>
                                <template v-else>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-gold">{{ t.blueprint_fragments }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-muted">
                                        {{ n(t.steps.filter((s) => s.research_xp).slice(-1)[0]?.research_xp) }}
                                    </td>
                                </template>

                                <td class="px-4 py-2 text-right tabular-nums text-wot-dim">{{ t.steps.length }}</td>
                                <td class="px-4 py-2 text-right" @click.stop>
                                    <button type="button" class="text-xs uppercase tracking-wider text-wot-dim hover:text-wot-good" @click="toggleComplete(t)">
                                        {{ t.is_complete ? 'Reopen' : 'Done' }}
                                    </button>
                                    <button type="button" class="ms-2 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-bad" @click="removeTarget(t)">
                                        Remove
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="expanded.includes(t.id)">
                                <td colspan="5" class="bg-wot-sunken/60 px-4 py-3">
                                    <table class="min-w-full text-xs">
                                        <thead>
                                            <tr class="text-wot-dim">
                                                <th scope="col" class="py-1 text-left font-bold uppercase tracking-wider">Step</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Modules</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Next tank XP</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Banked</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Free XP</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Frags</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Credits</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Playing</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="s in t.steps" :key="s.id" class="text-wot-muted">
                                                <td class="py-1">
                                                    <span class="text-wot-text">T{{ s.tier }} {{ s.name }}</span>
                                                    <!-- The API's undiscounted price, shown when a
                                                         blueprint-discounted figure has been entered. -->
                                                    <span v-if="s.research_xp && s.research_xp_remaining !== null && s.research_xp !== s.research_xp_remaining"
                                                          class="ms-2 text-wot-dim">full {{ n(s.research_xp) }}</span>
                                                </td>
                                                <td class="py-1 text-right">
                                                    <!-- Editable only where the encyclopedia has no
                                                         modules for this vehicle; otherwise the total
                                                         is a consequence of the ticks, and typing over
                                                         it would be undone by the next one. -->
                                                    <ModulePicker
                                                        v-if="s.modules.length"
                                                        :step-id="s.id"
                                                        :modules="s.modules"
                                                        :outstanding="s.module_xp_remaining"
                                                    />
                                                    <EditableNumber v-else :step-id="s.id" field="module_xp_remaining" :model-value="s.module_xp_remaining" />
                                                </td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="research_xp_remaining" :model-value="s.research_xp_remaining ?? s.research_xp ?? 0" /></td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="banked_xp" :model-value="s.banked_xp" /></td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="free_xp_planned" :model-value="s.free_xp_planned" /></td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="blueprint_fragments" :model-value="s.blueprint_fragments" /></td>
                                                <td class="py-1 text-right tabular-nums">{{ n(s.price_credit) }}</td>
                                                <td class="py-1 text-right">
                                                    <input
                                                        type="checkbox"
                                                        class="border"
                                                        :checked="s.is_active"
                                                        :aria-label="`Currently playing ${s.name}`"
                                                        @change="router.patch(`/wot/grinding/steps/${s.id}`, { is_active: $event.target.checked }, { preserveScroll: true, only: ['active', 'targets', 'totals'] })"
                                                    >
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="!targets.length">
                            <td colspan="5" class="px-4 py-8 text-center text-wot-dim">
                                No targets yet. Add one below, or run <code>php artisan wot:import-grind-sheet</code>.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <form class="border border-wot-border bg-wot-panel p-4" @submit.prevent="addTarget">
                <h2 class="text-base">Add a target</h2>
                <p class="mt-1 text-xs text-wot-dim">The path is built from the tech tree, starting at the last vehicle you've played.</p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <select v-model="addForm.tank_id" class="min-w-64 flex-1 border px-2 py-1.5 text-sm">
                        <option value="">Choose a vehicle…</option>
                        <option v-for="o in options" :key="o.tank_id" :value="o.tank_id">{{ o.label }}</option>
                    </select>
                    <button
                        type="submit"
                        class="border border-wot-gold bg-wot-gold px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-abyss disabled:opacity-40"
                        :disabled="!addForm.tank_id || addForm.processing"
                    >
                        Add
                    </button>
                </div>
            </form>

            <form class="border border-wot-border bg-wot-panel p-4" @submit.prevent="saveSettings">
                <h2 class="text-base">Planning</h2>
                <div class="mt-3 flex flex-wrap gap-4">
                    <label class="text-xs uppercase tracking-wider text-wot-dim">
                        Credits available
                        <input v-model="settingsForm.credits_available" type="number" min="0" class="mt-1 block w-40 border px-2 py-1.5 text-sm normal-case tracking-normal">
                    </label>
                    <label class="text-xs uppercase tracking-wider text-wot-dim">
                        Vacant garage slots
                        <input v-model="settingsForm.garage_slots_vacant" type="number" min="0" class="mt-1 block w-32 border px-2 py-1.5 text-sm normal-case tracking-normal">
                    </label>
                    <button type="submit" class="self-end border border-wot-border px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim hover:border-wot-gold hover:text-wot-gold">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </AppShell>
</template>
