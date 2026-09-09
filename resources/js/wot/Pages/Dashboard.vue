<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import NationFlag from '../Components/NationFlag.vue';
import NewsPanel from '../Components/NewsPanel.vue';
import PeriodTable from '../Components/PeriodTable.vue';
import StatTile from '../Components/StatTile.vue';
import UpcomingPanel from '../Components/UpcomingPanel.vue';

const props = defineProps({
    account: { type: Object, required: true },
    summary: { type: Object, default: null },
    achievements: { type: Object, default: null },
    history: { type: Object, default: () => ({ history_since: null, periods: [] }) },
    vehicles: { type: Array, default: () => [] },
    error: { type: String, default: null },
    news: { type: Object, default: () => ({ latest: [], pinned: [] }) },
    upcoming: { type: Object, default: () => ({ days: [], ongoing: [] }) },
});

// --- Garage table state -----------------------------------------------------
// All client-side. The whole garage arrives in one payload (a few hundred rows
// at most), so filtering and sorting here is instant and costs no round trips.

const search = ref('');
const tier = ref('');
const nation = ref('');
const type = ref('');
const premiumOnly = ref(false);
const minBattles = ref(0);
const sortKey = ref('battles');
const sortAsc = ref(false);

const unique = (key) => [...new Set(props.vehicles.map((v) => v[key]))].sort();

const tiers = computed(() => [...new Set(props.vehicles.map((v) => v.tier))].sort((a, b) => a - b));
// Shared from config('wargaming.nations'), already in tech-tree order.
const page = usePage();
const nationName = (slug) => page.props.nations?.[slug] ?? slug;

// The filter follows the same tech-tree order as every other vehicle list,
// with anything the config doesn't know about appended.
const nations = computed(() => {
    const order = Object.keys(page.props.nations ?? {});
    const present = new Set(props.vehicles.map((v) => v.nation));

    return [
        ...order.filter((n) => present.has(n)),
        ...[...present].filter((n) => !order.includes(n)).sort(),
    ];
});
const types = computed(() => unique('type'));

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.vehicles.filter((v) => {
        if (term && !v.name.toLowerCase().includes(term)) return false;
        if (tier.value !== '' && v.tier !== Number(tier.value)) return false;
        if (nation.value !== '' && v.nation !== nation.value) return false;
        if (type.value !== '' && v.type !== type.value) return false;
        if (premiumOnly.value && !v.is_premium) return false;
        if (v.battles < Number(minBattles.value)) return false;

        return true;
    });
});

const sorted = computed(() => {
    const direction = sortAsc.value ? 1 : -1;

    // Copied before sorting: Array.prototype.sort mutates, and `filtered` is a
    // computed whose value must not be rewritten in place.
    return [...filtered.value].sort((a, b) => {
        const x = a[sortKey.value];
        const y = b[sortKey.value];

        if (typeof x === 'string') return x.localeCompare(y) * direction;

        // Tanks with no WN8 (no XVM expected values) sort last either way,
        // rather than being treated as a score of zero.
        if (x === null) return 1;
        if (y === null) return -1;

        return (x - y) * direction;
    });
});

const totals = computed(() => ({
    shown: sorted.value.length,
    all: props.vehicles.length,
    battles: sorted.value.reduce((sum, v) => sum + v.battles, 0),
}));

const sortBy = (key) => {
    if (sortKey.value === key) {
        sortAsc.value = !sortAsc.value;

        return;
    }

    sortKey.value = key;
    // Names read best A–Z; every numeric column is more useful highest-first.
    sortAsc.value = key === 'name';
};

const resetFilters = () => {
    search.value = '';
    tier.value = '';
    nation.value = '';
    type.value = '';
    premiumOnly.value = false;
    minBattles.value = 0;
};

const columns = [
    { key: 'name', label: 'Vehicle', align: 'text-left' },
    { key: 'tier', label: 'Tier', align: 'text-center' },
    { key: 'battles', label: 'Battles', align: 'text-right' },
    { key: 'win_rate', label: 'Win rate', align: 'text-right' },
    { key: 'avg_damage', label: 'Avg dmg', align: 'text-right' },
    { key: 'avg_assist', label: 'Assist', align: 'text-right' },
    { key: 'kd_ratio', label: 'K/D', align: 'text-right' },
    { key: 'wn8', label: 'WN8', align: 'text-right' },
    { key: 'marks', label: 'MoE', align: 'text-center' },
    { key: 'mastery', label: 'Mastery', align: 'text-center' },
];

const masteryLabels = ['—', '3rd', '2nd', '1st', 'Ace'];

const number = (value) => (value === null || value === undefined ? '—' : new Intl.NumberFormat().format(value));

const asDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');

const refreshing = ref(false);

// Stats are cached for thirty minutes because a cold fetch costs a couple of
// seconds; this drops that entry so the next render is live again.
const refresh = () => {
    refreshing.value = true;
    router.post('/wot/refresh', {}, {
        preserveScroll: true,
        onFinish: () => (refreshing.value = false),
    });
};

const disconnect = () => {
    if (window.confirm('Disconnect this Wargaming account?')) {
        router.delete('/wot/connect');
    }
};
</script>

<template>
    <Head :title="`${account.nickname} · Dashboard`" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <div>
                <h1 class="text-3xl">{{ account.nickname }}</h1>
                <p class="mt-1 text-sm text-wot-dim">
                    Account {{ account.account_id }} · synced {{ asDate(account.last_synced_at) }}
                </p>
            </div>

            <div v-if="summary" class="text-right">
                <p class="text-xs font-bold uppercase tracking-wider text-wot-dim">WN8</p>
                <p class="text-4xl tabular-nums" :class="`wn8-${summary.wn8_band}`">{{ number(summary.wn8) }}</p>
                <p class="text-xs capitalize text-wot-dim">{{ summary.wn8_band.replace('-', ' ') }}</p>
            </div>

            <div class="flex gap-2">
                <button
                    type="button"
                    class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:opacity-40"
                    :disabled="refreshing"
                    @click="refresh"
                >
                    {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                </button>

                <button
                    type="button"
                    class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                    @click="disconnect"
                >
                    Disconnect
                </button>
            </div>
        </div>

        <p v-if="error" class="mt-6 border-l-2 border-wot-bad bg-wot-panel px-4 py-3 text-sm text-wot-bad" role="alert">
            {{ error }}
        </p>

        <!-- Both read local tables, so they render even when the block below
             failed because Wargaming was unreachable. -->
        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <NewsPanel :news="news" />
            <UpcomingPanel :upcoming="upcoming" />
        </div>

        <template v-if="summary">
            <dl class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Battles" :value="number(summary.battles)" :hint="`Avg tier ${summary.avg_tier}`" />
                <StatTile
                    label="Win rate"
                    :value="summary.win_rate"
                    suffix="%"
                    :tone="summary.win_rate >= 50 ? 'good' : 'bad'"
                    :hint="`${number(summary.wins)} wins`"
                />
                <StatTile label="Avg damage" :value="number(summary.avg_damage)" :hint="`${number(summary.avg_assist)} assisted`" />
                <StatTile label="Survival" :value="summary.survival_rate" suffix="%" />
                <StatTile label="Damage ratio" :value="summary.damage_ratio ?? '—'" :tone="summary.damage_ratio >= 1 ? 'good' : 'bad'" />
                <StatTile label="K/D ratio" :value="summary.kd_ratio ?? '—'" :tone="summary.kd_ratio >= 1 ? 'good' : 'bad'" />
                <StatTile label="Accuracy" :value="summary.accuracy" suffix="%" :hint="`${number(summary.avg_blocked)} blocked`" />
                <StatTile label="Global rating" :value="number(summary.global_rating)" tone="gold" :hint="`Last battle ${asDate(summary.last_battle_at)}`" />
            </dl>

            <p v-if="summary.wn8_unrated_battles > 0" class="mt-3 text-xs text-wot-dim">
                {{ number(summary.wn8_unrated_battles) }} battles excluded from WN8 — XVM publishes no
                expected values for those vehicles.
            </p>

            <!-- Only present when a valid access token was sent with the
                 request, so it is absent rather than zeroed when the token has
                 expired. -->
            <dl v-if="summary.private" class="mt-4 grid gap-4 sm:grid-cols-3">
                <StatTile label="Credits" :value="number(summary.private.credits)" />
                <StatTile label="Gold" :value="number(summary.private.gold)" tone="gold" />
                <StatTile label="Free XP" :value="number(summary.private.free_xp)" />
            </dl>

            <div class="mt-12">
                <PeriodTable :overall="summary" :history="history" />
            </div>

            <section v-if="achievements" class="mt-12" aria-labelledby="achievements-heading">
                <h2 id="achievements-heading" class="text-xl">Achievements</h2>

                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="border border-wot-border bg-wot-panel p-4">
                        <h3 class="text-sm">Marks of Excellence</h3>
                        <dl class="mt-3 flex gap-6">
                            <div v-for="(count, label) in achievements.marks_of_excellence" :key="label">
                                <dt class="text-xs uppercase tracking-wider text-wot-dim">{{ label }}</dt>
                                <dd class="text-2xl tabular-nums text-wot-gold">{{ count }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="border border-wot-border bg-wot-panel p-4">
                        <h3 class="text-sm">Mastery badges</h3>
                        <dl class="mt-3 flex gap-6">
                            <div v-for="(count, label) in achievements.mastery" :key="label">
                                <dt class="text-xs uppercase tracking-wider text-wot-dim">{{ label }}</dt>
                                <dd class="text-2xl tabular-nums text-wot-heading">{{ count }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            </section>
        </template>

        <section class="mt-12" aria-labelledby="garage-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="garage-heading" class="text-xl">Garage</h2>
                <p class="text-sm text-wot-dim" aria-live="polite">
                    {{ number(totals.shown) }} of {{ number(totals.all) }} vehicles ·
                    {{ number(totals.battles) }} battles shown
                </p>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-3 border border-wot-border bg-wot-panel p-4">
                <div class="min-w-48 flex-1">
                    <label for="search" class="block text-xs font-medium uppercase tracking-wider text-wot-dim">Search</label>
                    <input id="search" v-model="search" type="search" placeholder="Vehicle name" class="mt-1 w-full border px-2 py-1.5 text-sm">
                </div>

                <div>
                    <label for="tier" class="block text-xs font-medium uppercase tracking-wider text-wot-dim">Tier</label>
                    <select id="tier" v-model="tier" class="mt-1 border px-2 py-1.5 text-sm">
                        <option value="">All</option>
                        <option v-for="t in tiers" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>

                <div>
                    <label for="nation" class="block text-xs font-medium uppercase tracking-wider text-wot-dim">Nation</label>
                    <select id="nation" v-model="nation" class="mt-1 border px-2 py-1.5 text-sm">
                        <option value="">All</option>
                        <option v-for="n in nations" :key="n" :value="n">{{ nationName(n) }}</option>
                    </select>
                </div>

                <div>
                    <label for="type" class="block text-xs font-medium uppercase tracking-wider text-wot-dim">Type</label>
                    <select id="type" v-model="type" class="mt-1 border px-2 py-1.5 text-sm">
                        <option value="">All</option>
                        <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>

                <div>
                    <label for="min-battles" class="block text-xs font-medium uppercase tracking-wider text-wot-dim">Min battles</label>
                    <input id="min-battles" v-model="minBattles" type="number" min="0" step="25" class="mt-1 w-24 border px-2 py-1.5 text-sm">
                </div>

                <label class="flex items-center gap-2 pb-2 text-sm text-wot-text">
                    <input v-model="premiumOnly" type="checkbox" class="border">
                    Premium only
                </label>

                <button type="button" class="pb-2 text-sm font-medium text-wot-gold hover:text-wot-gold-bright" @click="resetFilters">
                    Reset
                </button>
            </div>

            <!-- Its own scroll container so a wide table never makes the page
                 itself scroll sideways. -->
            <div class="mt-4 overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th
                                v-for="column in columns"
                                :key="column.key"
                                scope="col"
                                :class="column.align"
                                :aria-sort="sortKey === column.key ? (sortAsc ? 'ascending' : 'descending') : 'none'"
                                class="px-4 py-3"
                            >
                                <button
                                    type="button"
                                    class="text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:text-wot-gold"
                                    :class="{ 'text-wot-gold': sortKey === column.key }"
                                    @click="sortBy(column.key)"
                                >
                                    {{ column.label }}
                                    <span v-if="sortKey === column.key" aria-hidden="true">{{ sortAsc ? '▲' : '▼' }}</span>
                                </button>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="vehicle in sorted" :key="vehicle.tank_id" class="transition-colors hover:bg-wot-sunken">
                            <td class="px-4 py-2">
                                <span class="font-medium text-wot-heading">{{ vehicle.name }}</span>
                                <span v-if="vehicle.is_premium" class="ms-2 border border-wot-gold px-1.5 py-0.5 text-xs font-bold uppercase text-wot-gold">
                                    Premium
                                </span>
                                <NationFlag :nation="vehicle.nation" class="ms-2" />
                                <span class="ms-1.5 text-xs text-wot-dim">{{ vehicle.type }}</span>
                            </td>
                            <td class="px-4 py-2 text-center tabular-nums text-wot-muted">{{ vehicle.tier }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ number(vehicle.battles) }}</td>
                            <!-- Coloured against the 50% line, the only number here
                                 a player reads as pass/fail at a glance. -->
                            <td
                                class="px-4 py-2 text-right tabular-nums"
                                :class="vehicle.win_rate >= 50 ? 'text-wot-good' : 'text-wot-bad'"
                            >{{ vehicle.win_rate }}%</td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ number(vehicle.avg_damage) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ number(vehicle.avg_assist) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ vehicle.kd_ratio ?? '—' }}</td>
                            <td class="px-4 py-2 text-right tabular-nums" :class="`wn8-${vehicle.wn8_band}`">
                                {{ number(vehicle.wn8) }}
                            </td>
                            <td class="px-4 py-2 text-center text-wot-gold">
                                {{ vehicle.marks ? '★'.repeat(vehicle.marks) : '' }}
                            </td>
                            <td
                                class="px-4 py-2 text-center"
                                :class="vehicle.mastery === 4 ? 'text-wot-gold' : 'text-wot-dim'"
                            >{{ masteryLabels[vehicle.mastery] }}</td>
                        </tr>

                        <tr v-if="!sorted.length">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-wot-dim">
                                No vehicles match those filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppShell>
</template>
