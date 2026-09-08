<script setup>
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import StatTile from '../Components/StatTile.vue';

const props = defineProps({
    account: { type: Object, required: true },
    summary: { type: Object, default: null },
    vehicles: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

// --- Garage table state -----------------------------------------------------
// All client-side. The whole garage arrives in one payload (a few hundred rows
// at most), so filtering and sorting here is instant and costs no round trips.

const search = ref('');
const tier = ref('');
const nation = ref('');
const type = ref('');
const premiumOnly = ref(false);
const sortKey = ref('battles');
const sortAsc = ref(false);

const unique = (key) => [...new Set(props.vehicles.map((v) => v[key]))].sort();

const tiers = computed(() => [...new Set(props.vehicles.map((v) => v.tier))].sort((a, b) => a - b));
const nations = computed(() => unique('nation'));
const types = computed(() => unique('type'));

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.vehicles.filter((v) => {
        if (term && !v.name.toLowerCase().includes(term)) return false;
        if (tier.value !== '' && v.tier !== Number(tier.value)) return false;
        if (nation.value !== '' && v.nation !== nation.value) return false;
        if (type.value !== '' && v.type !== type.value) return false;
        if (premiumOnly.value && !v.is_premium) return false;

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
};

const columns = [
    { key: 'name', label: 'Vehicle', align: 'text-left' },
    { key: 'tier', label: 'Tier', align: 'text-center' },
    { key: 'battles', label: 'Battles', align: 'text-right' },
    { key: 'win_rate', label: 'Win rate', align: 'text-right' },
    { key: 'avg_damage', label: 'Avg dmg', align: 'text-right' },
    { key: 'avg_xp', label: 'Avg XP', align: 'text-right' },
    { key: 'mastery', label: 'Mastery', align: 'text-center' },
];

const masteryLabels = ['—', '3rd', '2nd', '1st', 'Ace'];

const number = (value) => new Intl.NumberFormat().format(value ?? 0);

const asDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');

const disconnect = () => {
    if (window.confirm('Disconnect this Wargaming account?')) {
        router.delete('/wot/connect');
    }
};
</script>

<template>
    <Head :title="`${account.nickname} · Dashboard`" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">{{ account.nickname }}</h1>
                <p class="mt-1 text-sm text-gray-500">
                    Account {{ account.account_id }} · synced {{ asDate(account.last_synced_at) }}
                </p>
            </div>

            <button
                type="button"
                class="rounded-md border border-gray-300 px-3 py-2 text-sm font-medium text-gray-600 hover:bg-gray-100"
                @click="disconnect"
            >
                Disconnect
            </button>
        </div>

        <p v-if="error" class="mt-6 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            {{ error }}
        </p>

        <template v-if="summary">
            <dl class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Battles" :value="number(summary.battles)" />
                <StatTile label="Win rate" :value="summary.win_rate" suffix="%" :hint="`${number(summary.wins)} wins`" />
                <StatTile label="Avg damage" :value="number(summary.avg_damage)" />
                <StatTile label="Survival" :value="summary.survival_rate" suffix="%" />
                <StatTile label="Avg XP" :value="number(summary.avg_xp)" />
                <StatTile label="Avg frags" :value="summary.avg_frags" />
                <StatTile label="Max damage" :value="number(summary.max_damage)" />
                <StatTile label="Global rating" :value="number(summary.global_rating)" :hint="`Last battle ${asDate(summary.last_battle_at)}`" />
            </dl>

            <!-- Only present when a valid access token was sent with the
                 request, so it is absent rather than zeroed when the token has
                 expired. -->
            <dl v-if="summary.private" class="mt-4 grid gap-4 sm:grid-cols-3">
                <StatTile label="Credits" :value="number(summary.private.credits)" />
                <StatTile label="Gold" :value="number(summary.private.gold)" />
                <StatTile label="Free XP" :value="number(summary.private.free_xp)" />
            </dl>
        </template>

        <section class="mt-12" aria-labelledby="garage-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="garage-heading" class="text-xl font-semibold tracking-tight text-gray-900">Garage</h2>
                <p class="text-sm text-gray-500" aria-live="polite">
                    {{ number(totals.shown) }} of {{ number(totals.all) }} vehicles ·
                    {{ number(totals.battles) }} battles shown
                </p>
            </div>

            <div class="mt-4 flex flex-wrap items-end gap-3 rounded-lg border border-gray-200 bg-white p-4">
                <div class="flex-1 min-w-48">
                    <label for="search" class="block text-xs font-medium text-gray-600">Search</label>
                    <input id="search" v-model="search" type="search" placeholder="Vehicle name" class="mt-1 w-full rounded-md border-gray-300 text-sm">
                </div>

                <div>
                    <label for="tier" class="block text-xs font-medium text-gray-600">Tier</label>
                    <select id="tier" v-model="tier" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        <option v-for="t in tiers" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>

                <div>
                    <label for="nation" class="block text-xs font-medium text-gray-600">Nation</label>
                    <select id="nation" v-model="nation" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        <option v-for="n in nations" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>

                <div>
                    <label for="type" class="block text-xs font-medium text-gray-600">Type</label>
                    <select id="type" v-model="type" class="mt-1 rounded-md border-gray-300 text-sm">
                        <option value="">All</option>
                        <option v-for="t in types" :key="t" :value="t">{{ t }}</option>
                    </select>
                </div>

                <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
                    <input v-model="premiumOnly" type="checkbox" class="rounded border-gray-300 text-brand-600">
                    Premium only
                </label>

                <button type="button" class="pb-2 text-sm font-medium text-brand-600 hover:text-brand-700" @click="resetFilters">
                    Reset
                </button>
            </div>

            <!-- Its own scroll container so a wide table never makes the page
                 itself scroll sideways. -->
            <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
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
                                    class="font-medium text-gray-600 hover:text-gray-900"
                                    @click="sortBy(column.key)"
                                >
                                    {{ column.label }}
                                    <span v-if="sortKey === column.key" aria-hidden="true">{{ sortAsc ? '▲' : '▼' }}</span>
                                </button>
                            </th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="vehicle in sorted" :key="vehicle.tank_id" class="hover:bg-gray-50">
                            <td class="px-4 py-2">
                                <span class="font-medium text-gray-900">{{ vehicle.name }}</span>
                                <span v-if="vehicle.is_premium" class="ms-2 rounded bg-accent-100 px-1.5 py-0.5 text-xs font-medium text-accent-900">
                                    Premium
                                </span>
                                <span class="ms-2 text-xs text-gray-500">{{ vehicle.nation }} · {{ vehicle.type }}</span>
                            </td>
                            <td class="px-4 py-2 text-center tabular-nums">{{ vehicle.tier }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number(vehicle.battles) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ vehicle.win_rate }}%</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number(vehicle.avg_damage) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums">{{ number(vehicle.avg_xp) }}</td>
                            <td class="px-4 py-2 text-center">{{ masteryLabels[vehicle.mastery] }}</td>
                        </tr>

                        <tr v-if="!sorted.length">
                            <td :colspan="columns.length" class="px-4 py-8 text-center text-gray-500">
                                No vehicles match those filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AppShell>
</template>
