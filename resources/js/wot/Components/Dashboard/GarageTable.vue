<script setup>
import { computed, ref } from 'vue';
import GarageFilters from './GarageFilters.vue';
import MasteryBadge from './MasteryBadge.vue';
import NationFlag from '../NationFlag.vue';
import { number, roman } from '../../lib/format';
import { MASTERY_KEYS } from '../../lib/achievements';
import { useNations } from '../../composables/useNations';
import { useTableSort } from '../../composables/useTableSort';

/**
 * Every vehicle in the garage, filtered and sorted.
 *
 * All client-side. The whole garage arrives in one payload — a few hundred rows
 * at most — so filtering and sorting here is instant and costs no round trips,
 * which is what makes six controls worth offering at once.
 */
const props = defineProps({
    vehicles: { type: Array, required: true },
});

const search = ref('');
const tier = ref('');
const nation = ref('');
const type = ref('');
const premiumOnly = ref(false);
const minBattles = ref(0);

const resetFilters = () => {
    search.value = '';
    tier.value = '';
    nation.value = '';
    type.value = '';
    premiumOnly.value = false;
    minBattles.value = 0;
};

const { nationName, nationsOf } = useNations();

const tiers = computed(() => [...new Set(props.vehicles.map((v) => v.tier))].sort((a, b) => a - b));
const types = computed(() => [...new Set(props.vehicles.map((v) => v.type))].sort());
// Tech-tree order like every other vehicle list, but keeping anything the
// config has not heard of: a vehicle you own is one you can filter to.
const nations = computed(() => nationsOf(props.vehicles, { includeUnknown: true }));

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.vehicles.filter((vehicle) => {
        if (term && !vehicle.name.toLowerCase().includes(term)) return false;
        if (tier.value !== '' && vehicle.tier !== Number(tier.value)) return false;
        if (nation.value !== '' && vehicle.nation !== nation.value) return false;
        if (type.value !== '' && vehicle.type !== type.value) return false;
        if (premiumOnly.value && !vehicle.is_premium) return false;
        if (vehicle.battles < Number(minBattles.value)) return false;

        return true;
    });
});

const { sortKey, sortAsc, sorted, sortBy } = useTableSort(() => filtered.value, 'battles', {
    ascendingKeys: ['name'],
});

const totals = computed(() => ({
    shown: sorted.value.length,
    all: props.vehicles.length,
    battles: sorted.value.reduce((sum, vehicle) => sum + vehicle.battles, 0),
}));

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
</script>

<template>
    <section aria-labelledby="tanks-heading">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="tanks-heading" class="text-xl">Tanks</h2>
            <p class="text-sm text-wot-dim" aria-live="polite">
                {{ number(totals.shown) }} of {{ number(totals.all) }} vehicles ·
                {{ number(totals.battles) }} battles shown
            </p>
        </div>

        <GarageFilters
            v-model:search="search"
            v-model:tier="tier"
            v-model:nation="nation"
            v-model:type="type"
            v-model:premium-only="premiumOnly"
            v-model:min-battles="minBattles"
            class="mt-4"
            :tiers="tiers"
            :nations="nations"
            :types="types"
            :nation-name="nationName"
            @reset="resetFilters"
        />

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
                            <NationFlag :nation="vehicle.nation" class="me-2" />
                            <span class="font-medium text-wot-heading">{{ vehicle.name }}</span>
                            <span v-if="vehicle.is_premium" class="ms-2 border border-wot-gold px-1.5 py-0.5 text-xs font-bold uppercase text-wot-gold">
                                Premium
                            </span>
                            <span class="ms-2 text-xs text-wot-dim">{{ vehicle.type }}</span>
                        </td>
                        <td class="px-4 py-2 text-center text-wot-muted">{{ roman(vehicle.tier) }}</td>
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
                        <td class="px-4 py-2 text-center">
                            <MasteryBadge
                                v-if="MASTERY_KEYS[vehicle.mastery]"
                                :badge="MASTERY_KEYS[vehicle.mastery]"
                                class="mx-auto h-6 w-auto"
                            />
                            <span v-else class="text-wot-dim">—</span>
                        </td>
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
</template>
