<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FilterChipRow from './FilterChipRow.vue';
import NationFlag from './NationFlag.vue';
import VehicleTypeIcon from './VehicleTypeIcon.vue';
import WotDialog from './WotDialog.vue';
import { roman } from '../lib/format';

/**
 * Choose one vehicle out of every vehicle in the game.
 *
 * Built after Grinding's "Add a tank you're playing": the same nation, tier and
 * type chips and the same capped, scrolled list of tank chips. A dropdown of a
 * thousand options is a list you scroll; this is a list you narrow.
 *
 * One difference in how the chips behave: each row is single-choice, like a set
 * of radio buttons, where the grinding picker lets a row hold several. That one
 * is for browsing — what might I grind next — and several nations at once is a
 * reasonable question. This one is for finding a tank you already know, so
 * picking a second nation should replace the first rather than widen the list.
 * All clears a row.
 *
 * Unlike that picker there is no useful shortlist to open on. It can offer the
 * garage because a tank you are grinding is one you own and have XP on; nothing
 * here says which tank a Battle Pass tanker is likely to be posted to. So an
 * untouched picker asks for a filter rather than showing the whole game.
 *
 * The filters survive closing it: entering a season's worth of tankers usually
 * means choosing among the same nation several times running.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // Every vehicle, or null while the deferred prop is still loading.
    vehicles: { type: Array, default: null },
    selectedId: { type: Number, default: null },
    // Who the tank is being chosen for, so the heading says what this is.
    title: { type: String, default: 'Choose a tank' },
});

const emit = defineEmits(['pick', 'close']);

const dialog = ref(null);

const close = () => dialog.value?.close();

const choose = (tankId) => {
    emit('pick', tankId);
    close();
};

// Light to heavy, then the two that are not tanks — the same fixed order as the
// grinding picker, so the row does not reshuffle as the list changes.
const TYPES = ['lightTank', 'mediumTank', 'heavyTank', 'AT-SPG', 'SPG'];

// One value per row, or null for a row left open.
const pickedNation = ref(null);
const pickedTier = ref(null);
const pickedType = ref(null);

// An untouched row lets everything through while its chips sit unlit.
const allows = (picked, value) => picked === null || picked === value;

const all = computed(() => props.vehicles ?? []);

const page = usePage();

// In tech-tree order, like every other vehicle list here.
const nations = computed(() => {
    const present = new Set(all.value.map((v) => v.nation));

    return Object.keys(page.props.nations ?? {}).filter((nation) => present.has(nation));
});

const tiers = computed(() => [...new Set(all.value.map((v) => v.tier))].sort((a, b) => a - b));

const types = computed(() => {
    const present = new Set(all.value.map((v) => v.type));

    return TYPES.filter((type) => present.has(type));
});

const hasPick = computed(() => pickedNation.value !== null
    || pickedTier.value !== null
    || pickedType.value !== null);

const pickable = computed(() => (hasPick.value
    ? all.value.filter((v) => allows(pickedNation.value, v.nation)
        && allows(pickedTier.value, v.tier)
        && allows(pickedType.value, v.type))
    : []));

const selected = computed(() => all.value.find((v) => v.tank_id === props.selectedId) ?? null);
</script>

<template>
    <WotDialog ref="dialog" wide :open="open" :label="title" @close="emit('close')">
        <h3 class="text-lg normal-case tracking-normal text-wot-heading">{{ title }}</h3>
        <p class="mt-1 text-xs text-wot-dim">
            <template v-if="selected">
                Serving in the {{ selected.name }}.
            </template>
            {{ hasPick
                ? 'Everything in the game that matches.'
                : 'Pick a nation, tier or type to narrow the whole game down.' }}
        </p>

        <!-- The grinding picker's chips, but each row single-choice: lit
             means picked, and picking another replaces it. FilterChipRow marks
             them up as radio groups, which is what they behave as. -->
        <div class="mt-3 space-y-2 border border-wot-border bg-wot-sunken p-3">
            <FilterChipRow
                label="Nation"
                mode="single"
                variant="flag"
                :items="nations"
                :selected="pickedNation"
                @toggle="pickedNation = $event"
                @clear="pickedNation = null"
            >
                <template #default="{ item }">
                    <NationFlag :nation="item" />
                </template>
            </FilterChipRow>

            <FilterChipRow
                label="Tier"
                mode="single"
                :items="tiers"
                :selected="pickedTier"
                :item-label="(tier) => `Tier ${roman(tier)}`"
                @toggle="pickedTier = $event"
                @clear="pickedTier = null"
            >
                <template #default="{ item }">{{ roman(item) }}</template>
            </FilterChipRow>

            <FilterChipRow
                label="Type"
                mode="single"
                variant="icon"
                :items="types"
                :selected="pickedType"
                @toggle="pickedType = $event"
                @clear="pickedType = null"
            >
                <template #default="{ item }">
                    <VehicleTypeIcon :type="item" />
                </template>
            </FilterChipRow>
        </div>

        <!-- Capped and scrolled, like the grinding picker's: a single nation
             still runs to a hundred-odd vehicles. -->
        <div class="mt-3 max-h-72 overflow-y-auto">
            <p v-if="!vehicles" class="border border-dashed border-wot-border p-6 text-center text-sm text-wot-dim">
                Loading vehicles…
            </p>

            <p v-else-if="!hasPick" class="border border-dashed border-wot-border p-6 text-center text-sm text-wot-dim">
                Pick a nation, tier or type to see tanks.
            </p>

            <p v-else-if="!pickable.length" class="border border-dashed border-wot-border p-6 text-center text-sm text-wot-dim">
                Nothing in that nation, tier and type.
            </p>

            <ul v-else role="list" class="flex flex-wrap gap-1.5">
                <li v-for="vehicle in pickable" :key="vehicle.tank_id">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap border px-2 py-1 text-xs transition-colors hover:border-wot-gold hover:text-wot-gold"
                        :class="vehicle.tank_id === selectedId ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-text'"
                        :aria-pressed="vehicle.tank_id === selectedId"
                        @click="choose(vehicle.tank_id)"
                    >
                        <NationFlag :nation="vehicle.nation" />
                        {{ vehicle.name }}
                        <span class="text-wot-dim">{{ ROMAN[vehicle.tier] }}</span>
                        <VehicleTypeIcon :type="vehicle.type" class="text-wot-dim" />
                    </button>
                </li>
            </ul>
        </div>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p class="text-xs text-wot-dim">
                <template v-if="hasPick">{{ pickable.length }} of {{ all.length }} tanks</template>
            </p>

            <div class="flex flex-wrap gap-3">
                <!-- Only offered once there is something to take away. -->
                <button
                    v-if="selectedId !== null"
                    type="button"
                    class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                    @click="choose(null)"
                >
                    No tank
                </button>
                <button
                    type="button"
                    class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-muted transition-colors hover:border-wot-gold hover:text-wot-gold"
                    @click="close"
                >
                    Cancel
                </button>
            </div>
        </div>
    </WotDialog>
</template>
