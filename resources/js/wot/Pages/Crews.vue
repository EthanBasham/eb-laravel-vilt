<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { IconPlus, IconTrash } from '@tabler/icons-vue';
import { computed, reactive, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import CrewCell from '../Components/CrewCell.vue';
import CrewEditor from '../Components/CrewEditor.vue';
import EditableNumber from '../Components/EditableNumber.vue';
import NationFlag from '../Components/NationFlag.vue';
import TankPicker from '../Components/TankPicker.vue';
import VehicleTypeIcon from '../Components/VehicleTypeIcon.vue';
import { useBoardFilters } from '../composables/useBoardFilters';

const props = defineProps({
    crews: { type: Object, required: true },
    recruits: { type: Object, required: true },
    books: { type: Object, required: true },
    battle_pass: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    xp_progression: { type: Array, default: () => [] },
    roles: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
    genders: { type: Object, default: () => ({}) },
    // Deferred: a thousand vehicles that only the Battle Pass tab needs.
    vehicles: { type: Array, default: null },
});

const views = [
    { key: 'crews', label: 'Crews' },
    { key: 'inventory', label: 'Recruits & Books' },
    { key: 'battle-pass', label: 'Battle Pass' },
    { key: 'guide', label: 'Guide' },
];
const view = ref('crews');

const n = (v) => new Intl.NumberFormat().format(v ?? 0);
const short = (v) => (v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)}M` : n(v));

/*
 * Thousands as the game writes them. A book's value is a label on a column
 * header rather than a figure to do arithmetic against, and "250k" sits in the
 * space a header has where "250,000" does not. The trailing .0 is dropped, so
 * 20k stays 20k and an odd 12,500 would read 12.5k.
 *
 * The exact figure is still a hover away, on the header's own title.
 */
const inK = (v) => (v >= 1000 ? `${+(v / 1000).toFixed(1)}k` : n(v));

// Tiers are Roman in game and in every community tool, like the grinding board.
const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];

const page = usePage();

// In tech-tree order, like every other vehicle list here.
const nationsOf = (rows) => {
    const present = new Set(rows.map((r) => r.nation));

    return Object.keys(page.props.nations ?? {}).filter((nation) => present.has(nation));
};

/*
 * Crews board filters.
 *
 * Nation and tier work as they do everywhere else. The third control narrows to
 * lines something has been recorded on, which makes it a review tool rather
 * than a tidy-up — so it starts off, like Free XP's. Most of the tree has no
 * crew in it, and hiding that by default would hide the work.
 */
const {
    hiddenNations,
    hiddenTiers,
    only_crewed: onlyCrewed,
    toggleNation,
    toggleTier,
    clearNations,
    clearTiers,
} = useBoardFilters('crews', props.settings.crews_filters, {
    extra: { only_crewed: false },
    url: '/wot/crews/filters',
});

const crewNations = computed(() => nationsOf(props.crews.rows));
const shownTiers = computed(() => props.crews.tiers.filter((t) => !hiddenTiers.value.includes(t)));

/*
 * A shared cell is counted on the row that owns the vehicle, which keeps the
 * row figures summing to the grand total — the same rule every board here
 * follows, and the reason a tier VIII under three tier Xs is one crew rather
 * than three.
 */
const isCounted = (cell) => Boolean(cell) && !cell.is_shared;
const rowCrews = (row) => shownTiers.value.filter((t) => isCounted(row.cells[t]) && row.cells[t].has_crew).length;
const rowSeats = (row) => shownTiers.value.filter((t) => isCounted(row.cells[t])).length;

const shownRows = computed(() => props.crews.rows.filter(
    (r) => !hiddenNations.value.includes(r.nation) && !(onlyCrewed.value && rowCrews(r) === 0),
));
const tierTotal = (tier) => shownRows.value.filter((r) => isCounted(r.cells[tier]) && r.cells[tier].has_crew).length;
const grandTotal = computed(() => shownRows.value.reduce((sum, r) => sum + rowCrews(r), 0));

// 0-6, the levels the progression table is keyed by — so the editor's select
// and that table can never offer different ceilings.
const levels = computed(() => props.xp_progression.map((step) => step.level));

const editing = ref(null);

/*
 * Books.
 *
 * The column totals come back from the server, but the row totals are
 * recomputed here so a figure updates the moment its cell saves rather than
 * after the round trip — the same reason the grinding boards re-total their own
 * visible cells.
 *
 * A row totals XP rather than books: a manual and a booklet are not one book
 * each in any sense worth adding up, since one is worth twelve and a half of
 * the other. The count still has a home — it is what the panel heading reports.
 *
 * The figure is per crew member, which is how a book's value is quoted: each
 * book gives its XP to every seat in the set it is spent on.
 */
const rowXp = (row) => props.books.types.reduce(
    (sum, type) => sum + (row.quantities[type.key] ?? 0) * type.xp,
    0,
);

/* Battle Pass. */
// Male by default, which is what most of the roster is; the switch is one click
// either way.
const blank = () => ({ name: '', nation: '', season: null, gender: 'male', status: 'uncollected', tank_id: null, crew_role: '' });
const adding = reactive(blank());

const BATTLE_PASS_ONLY = { preserveScroll: true, only: ['battle_pass'] };

/*
 * '-' is how a tanker with no season is written, and none is what gets stored:
 * the roster sorts newest season first with the season-less at the bottom, and
 * a null is what that ordering already puts there. A blank field means the same.
 *
 * Anything else is read for its digits, so a stray character never reaches the
 * server as a season.
 */
const toSeason = (value) => {
    const text = String(value ?? '').trim();

    if (text === '' || text === '-') {
        return null;
    }

    const digits = text.replace(/[^\d]/g, '');

    return digits === '' ? null : Number(digits);
};

/*
 * Writes the season and puts the field back in step with what was saved. The
 * prop only redraws the input when its value changes, so typing junk over a
 * season that was already none would otherwise leave the junk sitting there
 * looking accepted.
 */
const changeSeason = (crew, event) => {
    const season = toSeason(event.target.value);

    event.target.value = season ?? '-';

    saveCrew(crew, { season });
};

const addCrew = () => {
    if (!adding.name.trim()) return;

    router.post('/wot/crews/battle-pass', { ...adding, name: adding.name.trim(), season: toSeason(adding.season) }, {
        ...BATTLE_PASS_ONLY,
        onSuccess: () => Object.assign(adding, blank()),
    });
};

// One field at a time, as each control is left — a roster row is edited in
// place rather than opened, saved and closed.
const saveCrew = (crew, payload) => router.patch(`/wot/crews/battle-pass/${crew.id}`, payload, BATTLE_PASS_ONLY);

const removeCrew = (crew) => router.delete(`/wot/crews/battle-pass/${crew.id}`, BATTLE_PASS_ONLY);

// Where someone is serving only means anything while they are in a tank, and
// the server clears both fields when the status moves off it.
const isPosted = (crew) => crew.status === 'in_tank';

/*
 * The tank picker, one instance shared by every row and the add row.
 *
 * `picking` says who it is open for and what to do with the answer: a roster
 * row saves straight away, while the add row only fills in `adding`, which is
 * posted with the rest of the new tanker. Null while it is closed.
 */
const picking = ref(null);

const vehiclesById = computed(() => new Map((props.vehicles ?? []).map((v) => [v.tank_id, v])));

// What the button in the In tank column says. The vehicle list is deferred, so a
// posting can exist before there is a name to show for it.
const tankLabel = (tankId) => {
    if (tankId === null || tankId === undefined) {
        return 'Choose Tank';
    }

    return vehiclesById.value.get(tankId)?.name ?? 'Loading…';
};

const chooseTankFor = (crew) => (picking.value = {
    title: `Choose a tank for ${crew.name}`,
    selectedId: crew.tank_id,
    apply: (tankId) => saveCrew(crew, { tank_id: tankId }),
});

const chooseTankForNew = () => (picking.value = {
    title: 'Choose a tank for the new crew member',
    selectedId: adding.tank_id,
    apply: (tankId) => (adding.tank_id = tankId),
});
</script>

<template>
    <Head title="Crews" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <h1 class="text-3xl">Crews</h1>
        </div>

        <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Crews recorded</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-heading">{{ n(crews.totals.crews) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">All zero-skill</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-good">{{ n(crews.totals.zero_skill_crews) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Maxed crews</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-gold">{{ n(crews.totals.max_crews) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Banked crew XP</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-heading">{{ short(crews.totals.banked_xp) }}</dd>
            </div>
        </dl>

        <div class="mt-8 flex flex-wrap gap-2" role="tablist" aria-label="Crew views">
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

        <!-- 1. Crews ------------------------------------------------------------
             The tech tree, one cell per vehicle, each cell the crew sitting in
             it. Every cue is explained in the legend below the filters.
        -->
        <section v-if="view === 'crews'" class="mt-4" aria-labelledby="crews-heading">
            <h2 id="crews-heading" class="sr-only">Crews</h2>

            <!-- Recorded here rather than computed against: nothing spends these
                 yet, and the board's job for now is to have them written down
                 where the training they describe is being planned. -->
            <div class="mb-3 border border-wot-border bg-wot-panel p-3">
                <h3 class="text-xs font-bold uppercase tracking-wider text-wot-dim">Crew XP progression</h3>

                <div class="mt-2 overflow-x-auto">
                    <table class="text-sm">
                        <thead>
                            <tr>
                                <th
                                    v-for="step in xp_progression"
                                    :key="step.level"
                                    scope="col"
                                    class="border-b border-wot-border-soft px-3 py-1 text-right text-xs font-bold uppercase tracking-wider text-wot-dim"
                                >
                                    {{ step.level === 0 ? 'Base' : `Skill ${step.level}` }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td
                                    v-for="step in xp_progression"
                                    :key="step.level"
                                    class="px-3 py-1 text-right tabular-nums"
                                    :class="step.level === 0 ? 'text-wot-muted' : 'text-wot-text'"
                                >
                                    {{ n(step.xp) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mb-3 space-y-2 border border-wot-border bg-wot-panel p-3">
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                    <button
                        v-for="nation in crewNations"
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
                        v-for="tier in crews.tiers"
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

                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Lines</span>
                    <label class="flex items-center gap-2 text-xs text-wot-text">
                        <input v-model="onlyCrewed" type="checkbox" class="border">
                        Only lines with a crew recorded
                    </label>
                </div>

                <!--
                    The legend earns its place here more than on any other
                    board: a cell is five letters, and every other thing it says
                    is said by how they are drawn.

                    Grouped by what a cue applies to, broadest first — the order
                    the eye takes a cell in. Colour reads from across the board,
                    then the shape of the whole set, then the marks on a single
                    letter. It used to be one list flowed into three columns,
                    which split the four colours over two rows and put "whole set
                    maxed" nowhere near "member maxed".

                    Maxed leads both the Set and Member rows, so the two scopes of
                    the same fact sit one above the other; the member row then
                    runs through zeroed steps, one then two, and skills trained.
                    Each row wears the same label column as the filters above it.
                -->
                <div class="space-y-1 border-t border-wot-border-soft pt-2 text-xs text-wot-dim">
                    <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                        <span class="w-12 shrink-0 font-bold uppercase tracking-wider">Colour</span>
                        <dl class="contents">
                            <div class="flex items-baseline gap-2">
                                <dt class="font-bold text-wot-bad">C G D</dt>
                                <dd>no crew in the tank</dd>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <dt class="font-bold text-wot-heading">C G D</dt>
                                <dd>none zero-skill</dd>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <dt class="font-bold text-wot-gold">C G D</dt>
                                <dd>some zero-skill</dd>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <dt class="font-bold text-wot-good">C G D</dt>
                                <dd>all zero-skill</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                        <span class="w-12 shrink-0 font-bold uppercase tracking-wider">Set</span>
                        <dl class="contents">
                            <div class="flex items-baseline gap-2">
                                <dt class="bg-wot-good/15 px-1 font-bold text-wot-good">C G D</dt>
                                <dd>every member maxed</dd>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <dt class="italic text-wot-text">C G D</dt>
                                <dd>not well balanced</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="flex flex-wrap items-baseline gap-x-5 gap-y-1">
                        <span class="w-12 shrink-0 font-bold uppercase tracking-wider">Member</span>
                        <dl class="contents">
                            <div class="flex items-baseline gap-2">
                                <dt class="font-bold text-wot-text">C</dt>
                                <dd>maxed</dd>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <dt class="text-wot-text"><span class="underline underline-offset-2">C</span></dt>
                                <dd>one zeroed XP step</dd>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <dt class="text-wot-text"><span class="underline decoration-double underline-offset-2">C</span></dt>
                                <dd>two zeroed XP steps</dd>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <dt class="text-wot-text">C<sup class="text-[0.65em] opacity-70">4</sup></dt>
                                <dd>skills trained</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>

            <p v-if="!shownRows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                {{ onlyCrewed ? 'No crews recorded in the selected nations and tiers.' : 'No lines in the selected nations and tiers.' }}
            </p>

            <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full border-separate border-spacing-0 divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="sticky left-0 z-20 whitespace-nowrap border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Line</th>
                            <th v-for="tier in shownTiers" :key="tier" scope="col"
                                class="px-3 py-3 text-center text-xs font-bold uppercase tracking-wider text-wot-dim">
                                Tier {{ ROMAN[tier] }}
                            </th>
                            <th scope="col" class="sticky right-0 z-20 whitespace-nowrap border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Crews</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in shownRows" :key="row.key" class="group hover:bg-wot-sunken">
                            <td class="sticky left-0 z-10 whitespace-nowrap border-e border-wot-border bg-wot-panel-solid px-4 py-2 group-hover:bg-wot-sunken-solid">
                                <NationFlag :nation="row.nation" class="me-2" />
                                <span class="text-wot-heading">{{ row.name }}</span>
                                <VehicleTypeIcon :type="row.type" class="ms-2 text-wot-dim" />
                            </td>

                            <td v-for="tier in shownTiers" :key="tier" class="px-3 py-2 text-center">
                                <CrewCell
                                    v-if="row.cells[tier]"
                                    :cell="row.cells[tier]"
                                    @edit="editing = $event"
                                />
                                <span v-else class="text-wot-muted">·</span>
                            </td>

                            <td class="sticky right-0 z-10 whitespace-nowrap border-s border-wot-border bg-wot-panel-solid px-4 py-2 text-right tabular-nums group-hover:bg-wot-sunken-solid"
                                :class="rowCrews(row) ? 'text-wot-heading' : 'text-wot-dim'">
                                {{ rowCrews(row) }}<span class="text-wot-dim"> / {{ rowSeats(row) }}</span>
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="shownRows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="sticky left-0 z-20 border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                            <td v-for="tier in shownTiers" :key="tier" class="px-3 py-3 text-center tabular-nums text-wot-muted">
                                {{ tierTotal(tier) }}
                            </td>
                            <td class="sticky right-0 z-20 border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ grandTotal }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <CrewEditor :cell="editing" :levels="levels" @close="editing = null" />
        </section>

        <!-- 2. Recruits & Books -------------------------------------------------->
        <!--
            A third to the recruits and two thirds to the books, rather than
            half each: one is a label and a number, the other is five columns
            of them, and an even split left the books table scrolling sideways
            while the recruits table ran to whitespace.

            items-start so neither panel is stretched to the other's height.
            Grid items fill their row by default, which gave the shorter table
            a long empty tail below its last row and made it read as a table
            missing rows.
        -->
        <section v-else-if="view === 'inventory'" class="mt-4 grid items-start gap-6 lg:grid-cols-3" aria-labelledby="inventory-heading">
            <h2 id="inventory-heading" class="sr-only">Recruits and books</h2>

            <div class="border border-wot-border bg-wot-panel">
                <div class="flex items-baseline justify-between border-b border-wot-border px-4 py-3">
                    <h3 class="text-sm">Recruits</h3>
                    <span class="text-xs uppercase tracking-wider text-wot-dim">{{ n(recruits.total) }} held</span>
                </div>

                <table class="min-w-full divide-y divide-wot-border-soft text-sm">
                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in recruits.rows" :key="row.key" class="hover:bg-wot-sunken">
                            <th scope="row" class="px-4 py-2 text-left font-normal text-wot-text">{{ row.label }}</th>
                            <td class="px-4 py-2 text-right">
                                <EditableNumber
                                    field="quantity" stepper
                                    :model-value="row.quantity"
                                    :url="`/wot/crews/recruits/${row.key}`"
                                    :only="['recruits']"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="border border-wot-border bg-wot-panel lg:col-span-2">
                <div class="flex items-baseline justify-between border-b border-wot-border px-4 py-3">
                    <h3 class="text-sm">Books</h3>
                    <!-- Books here, XP in the table: the shelf is counted in
                         books, but what it is worth is not. -->
                    <span class="text-xs uppercase tracking-wider text-wot-dim">{{ n(books.totals.books) }} held</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-wot-border-soft text-sm">
                        <thead class="bg-wot-sunken">
                            <tr>
                                <th scope="col" class="px-4 py-2 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</th>
                                <th v-for="type in books.types" :key="type.key" scope="col"
                                    class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wider text-wot-dim"
                                    :title="`${n(type.xp)} XP to each member of a crew`">
                                    {{ type.name }}
                                    <!-- What one of them is worth, so the XP in
                                         the Total column is arithmetic the
                                         reader can follow rather than a figure
                                         they have to take on trust. -->
                                    <span class="font-normal normal-case tracking-normal text-wot-muted">({{ inK(type.xp) }})</span>
                                </th>
                                <th scope="col" class="px-4 py-2 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Total XP</th>
                            </tr>
                        </thead>

                        <tbody class="divide-y divide-wot-border-soft">
                            <tr v-for="row in books.rows" :key="row.nation" class="hover:bg-wot-sunken">
                                <th scope="row" class="whitespace-nowrap px-4 py-2 text-left font-normal text-wot-text">
                                    <NationFlag v-if="row.nation !== 'universal'" :nation="row.nation" class="me-2" />
                                    <span :class="row.nation === 'universal' ? 'text-wot-gold' : ''">{{ row.label }}</span>
                                </th>
                                <td v-for="type in books.types" :key="type.key" class="px-3 py-2 text-right">
                                    <EditableNumber
                                        field="quantity" stepper
                                        :model-value="row.quantities[type.key]"
                                        :url="`/wot/crews/books/${type.key}/${row.nation}`"
                                        :only="['books']"
                                    />
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums"
                                    :class="rowXp(row) ? 'text-wot-heading' : 'text-wot-dim'">
                                    {{ n(rowXp(row)) }}
                                </td>
                            </tr>

                            <!-- The two items that are not books. Neither is
                                 tied to a nation and neither is a booklet, a
                                 guide or a manual, so they sit under the table
                                 with their count in the total column rather
                                 than in a type's. -->
                            <tr v-for="special in books.specials" :key="special.key" class="border-t-2 border-wot-border first:border-t-2 hover:bg-wot-sunken">
                                <th scope="row" class="whitespace-nowrap px-4 pb-2 pt-3 text-left font-normal text-wot-muted">
                                    {{ special.name }}
                                </th>
                                <td :colspan="books.types.length"></td>
                                <td class="px-4 pb-2 pt-3 text-right">
                                    <EditableNumber
                                        field="quantity" stepper
                                        :model-value="special.quantity"
                                        :url="`/wot/crews/books/${special.key}/universal`"
                                        :only="['books']"
                                    />
                                </td>
                            </tr>
                        </tbody>

                        <tfoot class="border-t-2 border-wot-border bg-wot-sunken">
                            <tr>
                                <th scope="row" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Books</th>
                                <td v-for="type in books.types" :key="type.key" class="px-3 py-3 text-right tabular-nums text-wot-muted">
                                    {{ n(books.totals[type.key]) }}
                                </td>
                                <!-- The type columns count books; this one is
                                     what they are worth, like the rows above
                                     it. The specials are in neither: one is not
                                     a book, and neither carries a per-member
                                     figure to be worth anything here. -->
                                <td class="px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(books.totals.xp) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </section>

        <!-- 3. Battle Pass ------------------------------------------------------->
        <section v-else-if="view === 'battle-pass'" class="mt-4" aria-labelledby="battle-pass-heading">
            <h2 id="battle-pass-heading" class="sr-only">Battle Pass crew</h2>

            <!--
                The add row's form, declared outside the table and joined to its
                controls by id. A <form> cannot wrap a <tr>, and the row has to be
                a real row so its fields sit under the same column widths as the
                roster below. Only the fields that take part in submission need
                the `form` attribute — the name for `required` and Enter-to-add,
                the season beside it — since addCrew() reads everything from
                `adding` rather than from the form data.
            -->
            <form id="add-battle-pass-crew" @submit.prevent="addCrew"></form>

            <div class="overflow-x-auto border border-wot-border bg-wot-panel">
                <!-- Borders set per section rather than with divide-y on the
                     table. Tables collapse their borders, and a collapsed edge
                     picks solid over dashed — so a divide rule on the table would
                     have quietly overpainted the dashed line under the add row. -->
                <table class="min-w-full text-sm">
                    <thead class="border-b border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Name</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Season</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Gender</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Status</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">In tank</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Role</th>
                            <th scope="col" class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>

                    <!-- The add row, heading the roster rather than trailing it:
                         each field sits in the column it will land in, and a new
                         tanker is entered where the eye already is. The dashed
                         rule marks it as a row not yet written. -->
                    <tbody class="border-b border-dashed border-wot-border">
                        <tr>
                            <td class="px-4 py-3 align-top">
                                <input
                                    v-model="adding.name"
                                    form="add-battle-pass-crew"
                                    type="text"
                                    required
                                    placeholder="New crew member"
                                    class="w-40 border border-wot-border bg-wot-sunken px-2 py-1 text-sm focus:border-wot-gold"
                                    aria-label="Name of the new crew member"
                                >
                                <!-- A rejected add used to fail silently: the
                                     row never appeared and nothing said why. -->
                                <p v-if="page.props.errors?.name" class="mt-1 text-xs text-wot-bad" role="alert">
                                    {{ page.props.errors.name }}
                                </p>
                            </td>

                            <td class="px-3 py-3 align-top">
                                <select v-model="adding.nation" class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm" aria-label="Assumed nation of the new crew member">
                                    <option value="">—</option>
                                    <option v-for="(label, slug) in page.props.nations" :key="slug" :value="slug">{{ label }}</option>
                                </select>
                            </td>

                            <td class="px-3 py-3 align-top">
                                <input
                                    v-model="adding.season"
                                    form="add-battle-pass-crew"
                                    type="text"
                                    placeholder="-"
                                    class="w-14 border border-wot-border bg-wot-sunken px-2 py-1 text-sm tabular-nums focus:border-wot-gold"
                                    aria-label="Season of the new crew member"
                                >
                            </td>

                            <td class="px-3 py-3 align-top">
                                <fieldset class="flex gap-1">
                                    <legend class="sr-only">Gender of the new crew member</legend>

                                    <label
                                        v-for="(gender, key) in genders"
                                        :key="key"
                                        class="cursor-pointer border px-2.5 py-0.5 text-xs font-bold transition-colors focus-within:ring-1 focus-within:ring-wot-gold"
                                        :class="adding.gender === key
                                            ? 'border-wot-gold text-wot-gold'
                                            : 'border-wot-border text-wot-dim hover:text-wot-text'"
                                        :title="gender.name"
                                    >
                                        <input v-model="adding.gender" type="radio" class="sr-only" name="gender-new" :value="key">
                                        {{ gender.letter }}
                                    </label>
                                </fieldset>
                            </td>

                            <td class="px-3 py-3 align-top">
                                <select v-model="adding.status" class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm" aria-label="Status of the new crew member">
                                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </td>

                            <!-- Offered here too, so the row matches the roster
                                 column for column. Same rule as below: only
                                 meaningful in a tank, and cleared by the server
                                 otherwise. -->
                            <td class="px-3 py-3 align-top">
                                <button
                                    type="button"
                                    class="inline-flex max-w-44 items-center gap-1.5 border border-wot-border bg-wot-sunken px-2 py-1 text-sm transition-colors hover:border-wot-gold disabled:pointer-events-none disabled:opacity-40"
                                    :class="adding.tank_id ? 'text-wot-text' : 'text-wot-dim'"
                                    :disabled="adding.status !== 'in_tank'"
                                    :aria-label="`Tank the new crew member is serving in: ${tankLabel(adding.tank_id)}`"
                                    @click="chooseTankForNew"
                                >
                                    <NationFlag v-if="vehiclesById.get(adding.tank_id)" :nation="vehiclesById.get(adding.tank_id).nation" />
                                    <span class="min-w-0 truncate">{{ tankLabel(adding.tank_id) }}</span>
                                </button>
                            </td>

                            <td class="px-3 py-3 align-top">
                                <select
                                    v-model="adding.crew_role"
                                    class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm disabled:opacity-40"
                                    :disabled="adding.status !== 'in_tank'"
                                    aria-label="Role the new crew member serves as"
                                >
                                    <option value="">—</option>
                                    <option v-for="(role, key) in roles" :key="key" :value="key">{{ role.name }}</option>
                                </select>
                            </td>

                            <td class="px-3 py-3 text-right align-top">
                                <button
                                    type="submit"
                                    form="add-battle-pass-crew"
                                    class="inline-flex items-center justify-center border border-wot-gold p-1 text-wot-gold transition-colors hover:bg-wot-gold hover:text-wot-abyss"
                                    title="Add crew member"
                                    aria-label="Add crew member"
                                >
                                    <IconPlus :size="14" stroke-width="2.25" />
                                </button>
                            </td>
                        </tr>
                    </tbody>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="crew in battle_pass" :key="crew.id" class="hover:bg-wot-sunken">
                            <td class="px-4 py-2">
                                <!-- px-2 py-1 like the selects beside it: the
                                     row's controls are a line of boxes and one
                                     of them being two pixels shorter reads as a
                                     misalignment rather than as a difference. -->
                                <input
                                    :value="crew.name"
                                    type="text"
                                    class="w-40 border border-wot-border bg-wot-sunken px-2 py-1 text-sm focus:border-wot-gold"
                                    :aria-label="`Name of ${crew.name}`"
                                    @change="saveCrew(crew, { name: $event.target.value })"
                                >
                            </td>

                            <td class="px-3 py-2">
                                <select
                                    :value="crew.nation ?? ''"
                                    class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm"
                                    :aria-label="`Assumed nation of ${crew.name}`"
                                    @change="saveCrew(crew, { nation: $event.target.value || null })"
                                >
                                    <option value="">—</option>
                                    <option v-for="(label, slug) in page.props.nations" :key="slug" :value="slug">{{ label }}</option>
                                </select>
                            </td>

                            <td class="px-3 py-2">
                                <!-- No inputmode="numeric": the numeric keypad
                                     on a phone has no '-', and '-' is a value
                                     here. -->
                                <input
                                    :value="crew.season ?? '-'"
                                    type="text"
                                    class="w-14 border border-wot-border bg-wot-sunken px-2 py-1 text-sm tabular-nums focus:border-wot-gold"
                                    :aria-label="`Season of ${crew.name}`"
                                    @change="changeSeason(crew, $event)"
                                >
                            </td>

                            <td class="px-3 py-2">
                                <!-- Two values, one letter each, so they sit
                                     out in the open like the crew editor's
                                     zero-skills switch rather than behind a
                                     dropdown. Real radios under the labels: the
                                     grouping, the arrow keys and the
                                     announcement all come free. -->
                                <fieldset class="flex gap-1">
                                    <legend class="sr-only">Gender of {{ crew.name }}</legend>

                                    <label
                                        v-for="(gender, key) in genders"
                                        :key="key"
                                        class="cursor-pointer border px-2.5 py-0.5 text-xs font-bold transition-colors focus-within:ring-1 focus-within:ring-wot-gold"
                                        :class="crew.gender === key
                                            ? 'border-wot-gold text-wot-gold'
                                            : 'border-wot-border text-wot-dim hover:text-wot-text'"
                                        :title="gender.name"
                                    >
                                        <input
                                            type="radio"
                                            class="sr-only"
                                            :name="`gender-${crew.id}`"
                                            :value="key"
                                            :checked="crew.gender === key"
                                            @change="saveCrew(crew, { gender: key })"
                                        >
                                        {{ gender.letter }}
                                    </label>
                                </fieldset>
                            </td>

                            <td class="px-3 py-2">
                                <select
                                    :value="crew.status"
                                    class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm"
                                    :aria-label="`Status of ${crew.name}`"
                                    @change="saveCrew(crew, { status: $event.target.value })"
                                >
                                    <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                                </select>
                            </td>

                            <td class="px-3 py-2">
                                <!-- Only meaningful while they are in a tank,
                                     and the server clears both this and the
                                     role when the status moves off it. -->
                                <!-- A button into the tank picker rather than a
                                     thousand-option dropdown: the list is
                                     narrowed by nation, tier and type instead of
                                     scrolled. -->
                                <button
                                    type="button"
                                    class="inline-flex max-w-44 items-center gap-1.5 border border-wot-border bg-wot-sunken px-2 py-1 text-sm transition-colors hover:border-wot-gold disabled:pointer-events-none disabled:opacity-40"
                                    :class="crew.tank_id ? 'text-wot-text' : 'text-wot-dim'"
                                    :disabled="!isPosted(crew)"
                                    :aria-label="`Tank ${crew.name} is serving in: ${tankLabel(crew.tank_id)}`"
                                    @click="chooseTankFor(crew)"
                                >
                                    <NationFlag v-if="vehiclesById.get(crew.tank_id)" :nation="vehiclesById.get(crew.tank_id).nation" />
                                    <span class="min-w-0 truncate">{{ tankLabel(crew.tank_id) }}</span>
                                </button>
                            </td>

                            <td class="px-3 py-2">
                                <select
                                    :value="crew.crew_role ?? ''"
                                    class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm disabled:opacity-40"
                                    :disabled="!isPosted(crew)"
                                    :aria-label="`Role ${crew.name} serves as`"
                                    @change="saveCrew(crew, { crew_role: $event.target.value || null })"
                                >
                                    <option value="">—</option>
                                    <option v-for="(role, key) in roles" :key="key" :value="key">{{ role.name }}</option>
                                </select>
                            </td>

                            <td class="px-3 py-2 text-right">
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center border border-wot-border p-1 text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                                    :title="`Remove ${crew.name}`"
                                    :aria-label="`Remove ${crew.name}`"
                                    @click="removeCrew(crew)"
                                >
                                    <IconTrash :size="14" stroke-width="2.25" />
                                </button>
                            </td>
                        </tr>

                        <tr v-if="!battle_pass.length">
                            <td colspan="8" class="px-4 py-8 text-center text-sm text-wot-dim">
                                No Battle Pass crew recorded yet. Add one above.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <TankPicker
                :open="picking !== null"
                :vehicles="vehicles"
                :selected-id="picking?.selectedId ?? null"
                :title="picking?.title"
                @pick="(tankId) => picking?.apply(tankId)"
                @close="picking = null"
            />
        </section>

        <!-- 4. Guide ------------------------------------------------------------->
        <section v-else class="mt-4" aria-labelledby="guide-heading">
            <h2 id="guide-heading" class="sr-only">Guide</h2>

            <p class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                Nothing here yet.
            </p>
        </section>
    </AppShell>
</template>
