<script setup>
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ActiveGrindingTable from '../Components/ActiveGrindingTable.vue';
import AppShell from '../Components/AppShell.vue';
import PageHeader from '../Components/PageHeader.vue';
import StatTile from '../Components/StatTile.vue';
import ViewTabs from '../Components/ViewTabs.vue';
import BlueprintsBoard from '../Components/Grinding/BlueprintsBoard.vue';
import FreeXpBoard from '../Components/Grinding/FreeXpBoard.vue';
import GrindPicker from '../Components/Grinding/GrindPicker.vue';
import PurchaseBoard from '../Components/Grinding/PurchaseBoard.vue';
import XpBoard from '../Components/Grinding/XpBoard.vue';
import { n, short } from '../lib/format';
import { salePrice } from '../lib/sale';
import { useGrindBoard } from '../composables/useGrindBoard';

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
 * What a write to a grinding row has to bring back on this page. Ticking a
 * module spends banked XP and moves the XP and Free XP boards with it; adding
 * or dropping a tank moves the picker. All of them are built on every request
 * anyway, so naming them costs only the bytes back.
 */
const GRIND_RELOAD = ['active', 'options', 'xp', 'freexp', 'totals'];

/*
 * The four boards, built here rather than inside the components that draw them.
 *
 * Two of the headline cards below report a *filtered* board total, and they are
 * on show whichever tab is up — while only one tab's table is mounted at a
 * time. So the state has to outlive the view of it. See useGrindBoard.
 *
 * What each board counts, and which lines it considers settled, is the whole of
 * what differs between them; it is spelled out per board here so the four sit
 * side by side and can be read against each other.
 */

/*
 * XP Remaining. What one cell still owes: the unlock ahead of it, plus its own
 * modules. Mirrors XpBoard::cellXp so the footer and the server's row figure
 * cannot drift.
 *
 * The Lines checkbox matches the purchase board's rather than the Free XP
 * board's: a line with nothing left to research is settled in the same sense a
 * bought-out line is, so hiding it by default is the same judgement.
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

const xpBoard = useGrindBoard('xp', props.settings.xp_filters, {
    rows: () => props.xp.rows,
    tiers: () => props.xp.tiers,
    extra: { hide_done: true },
    rules: ({ hiddenNations, hide_done: hideDone }) => ({
        cellValue: xpCellXp,
        isDone: (row, remaining) => remaining === 0,
        keepRow: (row, remaining) => !hiddenNations.value.includes(row.nation)
            && !(hideDone.value && remaining === 0),
    }),
});

/*
 * Tanks to Purchase.
 *
 * Only cells in a visible column count. Hiding a tier takes its price off the
 * bill — otherwise the filter would change what you see but not what you owe. A
 * shared cell costs this row nothing: the row that owns the vehicle is paying
 * for it, which is what keeps the row totals summing to the grand total and
 * agreeing with the server's credits_remaining cell for cell.
 *
 * "Owned" means nothing left to buy in the visible tiers, so it tracks the
 * Remaining column rather than a separate server flag: if the row reads 0, the
 * filter treats it as owned.
 *
 * The tier row is seeded from the tiers the server reports as settled, but only
 * on a board whose filters have never been saved — a stored selection supersedes
 * the seed outright, so a bought-out column you deliberately turned back on
 * stays on. The cost is that the seed only ever runs on the very first visit: a
 * tier bought out after that is a column of zeroes until you hide it yourself.
 */
const purchaseBoard = useGrindBoard('purchase', props.settings.purchase_filters, {
    rows: () => props.purchase.rows,
    tiers: () => props.purchase.tiers,
    hiddenTiers: props.purchase.bought_tiers ?? [],
    extra: { hide_owned: true, show_sale: false },
    rules: ({ hiddenNations, hide_owned: hideOwned, show_sale: showSale }) => ({
        cellValue: (cell) => (cell && !cell.is_purchased && !cell.is_shared ? salePrice(cell, showSale.value) : 0),
        isDone: (row, remaining) => remaining === 0,
        keepRow: (row, remaining) => !hiddenNations.value.includes(row.nation)
            && !(hideOwned.value && remaining === 0),
    }),
});

/*
 * Free XP.
 *
 * A shared cell is planned and counted on the row that owns it. A finished
 * vehicle counts for nothing, mirroring FreeXpBoard: it reads as a dash, so any
 * plan it still carries must not surface in a total.
 *
 * The third control does not work like the other boards': there is no settled
 * state for a module plan — nothing records which modules you have already
 * researched off a tracked line — so there is nothing to hide by default. It
 * narrows to lines you have planned something on instead, which makes it a
 * review tool rather than a tidy-up, and it starts off because the whole tree is
 * what you plan against.
 *
 * hide_researched starts on, the opposite of only_planned beside it: a line with
 * nothing left to buy is settled in the sense XP Remaining's hide_done means,
 * and gets the same default. The stored key still says researched, which is what
 * it hid by until the two came apart — see isMaxed. Renaming it would orphan the
 * filter row every account has already saved, to no one's benefit.
 */

/*
 * A line no Free XP can go to: every vehicle on it maxed, meaning every upgrade
 * module researched or none there to begin with.
 *
 * is_maxed rather than is_researched, which is the stricter of the two and the
 * wrong question here. A tier X with a stock gun under a tier XI nobody has
 * unlocked is not researched — it owes the unlock — but no part of that debt is
 * payable in Free XP, and reading the strict flag kept fifteen finished lines on
 * the board, each over a single cell with nothing in it to buy.
 *
 * Every cell, not the visible ones, for the reason blueprintLineDone gives:
 * hiding a tier column must not decide which lines the board has. That is also
 * why filtering the tier XI column away never shook those lines loose — the cell
 * holding them was the tier X below it.
 */
const isMaxed = (row) => Object.values(row.cells).every((cell) => cell.is_maxed);

const freexpBoard = useGrindBoard('freexp', props.settings.freexp_filters, {
    rows: () => props.freexp.rows,
    tiers: () => props.freexp.tiers,
    extra: { only_planned: false, hide_researched: true },
    rules: ({ hiddenNations, only_planned: onlyPlanned, hide_researched: hideResearched }) => ({
        cellValue: (cell) => (cell && !cell.is_shared && !cell.is_researched ? cell.planned_xp : 0),
        keepRow: (row, planned) => !hiddenNations.value.includes(row.nation)
            && !(onlyPlanned.value && planned === 0)
            /*
             * Never hidden while it still carries a plan, whatever else is true
             * of it. row.planned_xp is the server's own figure — the one summed
             * into the headline card — so a line holding any part of that total
             * stays where the total can be read against it.
             *
             * A plan on a maxed vehicle is stale rather than wrong: researching
             * a module through the app drops it, but a module that only *became*
             * researched, because a successor was unlocked, keeps whatever was
             * planned against it. Showing that line is how it gets cleared.
             */
            && !(hideResearched.value && isMaxed(row) && row.planned_xp === 0),
    }),
});

/*
 * Blueprints.
 *
 * "Done" is about research, not about fragments: a line you have finished is one
 * blueprints cannot help, however many you happen to hold against it. Read off
 * this board's own cells rather than the XP tab's rows, which is what it did
 * until the board narrowed to tiers II-X. XP Remaining still runs tier I to XI
 * and still counts module XP, and fragments buy none of that — so a line
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
const blueprintCellDone = (cell) => cell.is_shared || !cell.is_researchable || cell.is_unlocked;
const blueprintLineDone = (row) => Object.values(row.cells).every(blueprintCellDone);

const blueprintsBoard = useGrindBoard('blueprints', props.settings.blueprints_filters, {
    rows: () => props.blueprints.rows,
    tiers: () => props.blueprints.tiers,
    extra: { hide_done: true },
    rules: ({ hiddenNations, hide_done: hideDone }) => ({
        // A shared cell is held and edited on the row that owns it, which keeps
        // the row totals summing to the grand total.
        cellValue: (cell) => (cell && !cell.is_shared ? cell.fragments : 0),
        isDone: (row) => blueprintLineDone(row),
        keepRow: (row) => !hiddenNations.value.includes(row.nation)
            && !(hideDone.value && blueprintLineDone(row)),
    }),
});

// Floored rather than rounded, so a tree one vehicle short of finished never
// reads 100%. Null on an empty tree, where a percentage means nothing.
const researchedPercent = computed(() => (props.totals.tanks_total
    ? Math.floor((props.totals.tanks_researched / props.totals.tanks_total) * 100)
    : null));
</script>

<template>
    <Head title="Grinding" />

    <AppShell>
        <PageHeader title="Grinding" />

        <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <!-- Vehicles with nothing left for XP Remaining to count, over the
                 vehicles on the tree — the Free XP board's own definition of
                 fully researched, counted once per vehicle. The whole tree, not
                 a filtered board: progress through the game should not move
                 because a nation chip was switched off. -->
            <StatTile dense label="Tanks fully researched">
                <span class="text-wot-dim">{{ n(totals.tanks_researched) }} / </span>{{ n(totals.tanks_total) }}
                <span v-if="researchedPercent !== null" class="ms-1 text-sm text-wot-good">{{ researchedPercent }}%</span>
            </StatTile>

            <!-- Banked over remaining, the balance-first order of the two cards
                 after it. Remaining is the filtered board's figure, like Credits
                 needed: the server bills the whole tree, and narrowing to a
                 nation is what turns that into a number worth reading. Banked is
                 summed over Active Grinding, the only place it is kept. -->
            <StatTile dense label="Banked XP / XP remaining">
                <span class="text-wot-dim">{{ short(totals.banked_xp) }} / </span>{{ short(xpBoard.grandTotal) }}
            </StatTile>

            <!-- Available over planned, the same order as the credits card: the
                 balance you already have leads, and the board's figure is what
                 it is measured against. Planned alone when Wargaming does not
                 report a balance — it needs a valid token — rather than under a
                 zero nobody reported. -->
            <StatTile
                dense
                tone="gold"
                :label="totals.free_xp_available != null ? 'Free XP available / planned' : 'Free XP planned'"
            >
                <span v-if="totals.free_xp_available != null" class="text-wot-dim">{{ n(totals.free_xp_available) }} / </span>{{ n(totals.free_xp_planned) }}
            </StatTile>

            <!-- Available over needed, the same order as the Free XP card.
                 Needed alone when Wargaming does not report a balance. -->
            <StatTile
                dense
                :label="totals.credits_available != null ? 'Credits available / needed' : 'Credits needed'"
            >
                <span v-if="totals.credits_available != null" class="text-wot-dim">{{ short(totals.credits_available) }} / </span>{{ short(purchaseBoard.grandTotal) }}
            </StatTile>
        </dl>

        <ViewTabs v-model="view" :views="views" label="Grinding views" />

        <section v-if="view === 'active'" class="mt-4" aria-labelledby="active-heading">
            <h2 id="active-heading" class="sr-only">Active grinding</h2>

            <ActiveGrindingTable :rows="active" :totals="totals.active" :only="GRIND_RELOAD">
                <template #empty>Nothing being ground. Add a tank below.</template>
            </ActiveGrindingTable>
        </section>

        <XpBoard v-else-if="view === 'xp'" :board="xpBoard" :tiers="xp.tiers" />

        <FreeXpBoard
            v-else-if="view === 'freexp'"
            :board="freexpBoard"
            :rows="freexp.rows"
            :tiers="freexp.tiers"
        />

        <PurchaseBoard
            v-else-if="view === 'purchase'"
            :board="purchaseBoard"
            :rows="purchase.rows"
            :tiers="purchase.tiers"
        />

        <BlueprintsBoard
            v-else
            :board="blueprintsBoard"
            :rows="blueprints.rows"
            :tiers="blueprints.tiers"
            :stock="blueprints.stock ?? []"
        />

        <!-- Active Grinding only. Saying what you are playing is not a question
             you ask of the tech tree, which is all the other four tabs are. -->
        <div v-if="view === 'active'" class="mt-8">
            <GrindPicker :options="options" :only="GRIND_RELOAD" />
        </div>
    </AppShell>
</template>
