<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { IconTrash } from '@tabler/icons-vue';
import { computed, reactive, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import CrewCell from '../Components/CrewCell.vue';
import CrewEditor from '../Components/CrewEditor.vue';
import EditableNumber from '../Components/EditableNumber.vue';
import NationFlag from '../Components/NationFlag.vue';
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
 */
const rowTotal = (row) => props.books.types.reduce((sum, type) => sum + (row.quantities[type.key] ?? 0), 0);

/* Battle Pass. */
const blank = () => ({ name: '', nation: '', season: null, gender: '', status: 'uncollected', tank_id: null, crew_role: '' });
const adding = reactive(blank());

const BATTLE_PASS_ONLY = { preserveScroll: true, only: ['battle_pass'] };

const addCrew = () => {
    if (!adding.name.trim()) return;

    router.post('/wot/crews/battle-pass', { ...adding, name: adding.name.trim() }, {
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

                <!-- The legend earns its place here more than on any other
                     board: a cell is five letters, and every other thing it
                     says is said by how they are drawn. -->
                <dl class="grid gap-x-6 gap-y-1 border-t border-wot-border-soft pt-2 text-xs text-wot-dim sm:grid-cols-2 lg:grid-cols-3">
                    <div class="flex items-baseline gap-2">
                        <dt class="font-bold text-wot-bad">C G D</dt>
                        <dd>no crew in the tank</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="font-bold text-wot-heading">C G D</dt>
                        <dd>none of them zero-skill</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="font-bold text-wot-gold">C G D</dt>
                        <dd>some zero-skill, some not</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="font-bold text-wot-good">C G D</dt>
                        <dd>every one zero-skill</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="bg-wot-good/15 px-1 font-bold text-wot-good">C G D</dt>
                        <dd>the whole set is maxed</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="italic text-wot-text">C G D</dt>
                        <dd>not well balanced</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="text-wot-text"><span class="font-bold">C</span> G D</dt>
                        <dd>that member is maxed</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="text-wot-text">C<sup class="text-[0.65em] opacity-70">4</sup></dt>
                        <dd>skills trained</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="text-wot-text"><span class="underline underline-offset-2">C</span></dt>
                        <dd>one zeroed XP step on that member</dd>
                    </div>
                    <div class="flex items-baseline gap-2">
                        <dt class="text-wot-text"><span class="underline decoration-double underline-offset-2">C</span></dt>
                        <dd>two of them</dd>
                    </div>
                </dl>
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
        <section v-else-if="view === 'inventory'" class="mt-4 grid gap-6 lg:grid-cols-2" aria-labelledby="inventory-heading">
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
                                    field="quantity"
                                    :model-value="row.quantity"
                                    :url="`/wot/crews/recruits/${row.key}`"
                                    :only="['recruits']"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="border border-wot-border bg-wot-panel">
                <div class="flex items-baseline justify-between border-b border-wot-border px-4 py-3">
                    <h3 class="text-sm">Books</h3>
                    <span class="text-xs uppercase tracking-wider text-wot-dim">{{ n(books.totals.total) }} held</span>
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
                                </th>
                                <th scope="col" class="px-4 py-2 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
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
                                        field="quantity"
                                        :model-value="row.quantities[type.key]"
                                        :url="`/wot/crews/books/${type.key}/${row.nation}`"
                                        :only="['books']"
                                    />
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums"
                                    :class="rowTotal(row) ? 'text-wot-heading' : 'text-wot-dim'">
                                    {{ n(rowTotal(row)) }}
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
                                        field="quantity"
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
                                <td class="px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(books.totals.total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </section>

        <!-- 3. Battle Pass ------------------------------------------------------->
        <section v-else-if="view === 'battle-pass'" class="mt-4" aria-labelledby="battle-pass-heading">
            <h2 id="battle-pass-heading" class="sr-only">Battle Pass crew</h2>

            <div class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Name</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Season</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Gender</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Status</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">In tank</th>
                            <th scope="col" class="px-3 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Role</th>
                            <th scope="col" class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                                <span class="sr-only">Remove</span>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="crew in battle_pass" :key="crew.id" class="hover:bg-wot-sunken">
                            <td class="px-4 py-2">
                                <input
                                    :value="crew.name"
                                    type="text"
                                    class="w-40 border border-wot-border bg-wot-sunken px-1 py-0.5 text-sm focus:border-wot-gold"
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
                                <input
                                    :value="crew.season ?? ''"
                                    type="text"
                                    inputmode="numeric"
                                    class="w-14 border border-wot-border bg-wot-sunken px-1 py-0.5 text-sm tabular-nums focus:border-wot-gold"
                                    :aria-label="`Season of ${crew.name}`"
                                    @change="saveCrew(crew, { season: $event.target.value === '' ? null : Number($event.target.value.replace(/[^\d]/g, '')) })"
                                >
                            </td>

                            <td class="px-3 py-2">
                                <select
                                    :value="crew.gender ?? ''"
                                    class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm"
                                    :aria-label="`Gender of ${crew.name}`"
                                    @change="saveCrew(crew, { gender: $event.target.value || null })"
                                >
                                    <option value="">—</option>
                                    <option v-for="(label, key) in genders" :key="key" :value="key">{{ label }}</option>
                                </select>
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
                                <select
                                    :value="crew.tank_id ?? ''"
                                    class="max-w-44 border border-wot-border bg-wot-sunken px-2 py-1 text-sm disabled:opacity-40"
                                    :disabled="!isPosted(crew) || !vehicles"
                                    :aria-label="`Tank ${crew.name} is serving in`"
                                    @change="saveCrew(crew, { tank_id: $event.target.value === '' ? null : Number($event.target.value) })"
                                >
                                    <option value="">{{ vehicles ? '—' : 'Loading…' }}</option>
                                    <option v-for="vehicle in vehicles ?? []" :key="vehicle.tank_id" :value="vehicle.tank_id">
                                        {{ ROMAN[vehicle.tier] }} · {{ vehicle.name }}
                                    </option>
                                </select>
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
                                No Battle Pass crew recorded yet. Add one below.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <form class="mt-3 flex flex-wrap items-end gap-2 border border-wot-border bg-wot-panel p-3" @submit.prevent="addCrew">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-bold uppercase tracking-wider text-wot-dim">Name</span>
                    <input v-model="adding.name" type="text" required
                           class="w-40 border border-wot-border bg-wot-sunken px-1 py-0.5 text-sm focus:border-wot-gold">
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                    <select v-model="adding.nation" class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm">
                        <option value="">—</option>
                        <option v-for="(label, slug) in page.props.nations" :key="slug" :value="slug">{{ label }}</option>
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-bold uppercase tracking-wider text-wot-dim">Season</span>
                    <input v-model.number="adding.season" type="text" inputmode="numeric"
                           class="w-14 border border-wot-border bg-wot-sunken px-1 py-0.5 text-sm tabular-nums focus:border-wot-gold">
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-bold uppercase tracking-wider text-wot-dim">Gender</span>
                    <select v-model="adding.gender" class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm">
                        <option value="">—</option>
                        <option v-for="(label, key) in genders" :key="key" :value="key">{{ label }}</option>
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-bold uppercase tracking-wider text-wot-dim">Status</span>
                    <select v-model="adding.status" class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm">
                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                    </select>
                </label>

                <button
                    type="submit"
                    class="border border-wot-gold px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-gold transition-colors hover:bg-wot-gold hover:text-wot-abyss"
                >
                    Add crew member
                </button>
            </form>
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
