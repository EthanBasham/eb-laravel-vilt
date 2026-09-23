<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from '../EmptyState.vue';
import FilterChipRow from '../FilterChipRow.vue';
import NationFlag from '../NationFlag.vue';
import VehicleTypeIcon from '../VehicleTypeIcon.vue';
import { n, roman } from '../../lib/format';
import { useNations } from '../../composables/useNations';

/**
 * Add a tank you're playing.
 *
 * Being on the Active Grinding list is the whole of "playing this tank" — there
 * is no separate tick for it anywhere. So adding and dropping are one flag, and
 * both go through the same per-tank endpoint every other board writes to.
 *
 * The chips are the board filter rows' with their polarity inverted: lit means
 * picked, and picking nothing opens on the garage rather than on the tree. The
 * boards are a view you come back to, so they start with everything on and
 * remember what you switched off; this is four hundred tanks you are trying to
 * find one in, where narrowing to a nation should cost one click rather than
 * nine.
 */
const props = defineProps({
    // Every vehicle on the tree, with what is known about your copy of it.
    options: { type: Array, default: () => [] },
    // What a write should bring back — the page's business, not this one's.
    only: { type: Array, required: true },
});

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
    ? picked.filter((each) => each !== value)
    : [...picked, value]);

// What a chip shows, against what a filter allows: an untouched dimension lets
// everything through while its chips all sit unlit.
const allows = (picked, value) => !picked.length || picked.includes(value);

const { nationsOf } = useNations();

const nations = computed(() => nationsOf(props.options));
const tiers = computed(() => [...new Set(props.options.map((option) => option.tier))].sort((a, b) => a - b));

// Fixed order rather than whatever the list happens to hold, so the row does
// not reshuffle as tanks come off it. Light to heavy, then the two that are not
// tanks — the tankopedia's own order, and the badges read as a progression.
const TYPES = ['lightTank', 'mediumTank', 'heavyTank', 'AT-SPG', 'SPG'];

const types = computed(() => {
    const present = new Set(props.options.map((option) => option.type));

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
const grindable = computed(() => props.options.filter((option) => option.is_purchased && option.xp_remaining > 0));

const pickable = computed(() => {
    if (! hasPick.value) {
        return grindable.value;
    }

    return props.options.filter((option) => allows(pickedNations.value, option.nation)
        && allows(pickedTiers.value, option.tier)
        && allows(pickedTypes.value, option.type));
});

/*
 * The four boards move with it: the tank leaves the picker, and its banked XP
 * joins the headline card.
 */
const startGrinding = (tankId) => router.patch(`/wot/grinding/purchases/${tankId}`, { is_playing: true }, {
    preserveScroll: true,
    only: props.only,
});
</script>

<template>
    <div class="border border-wot-border bg-wot-panel p-4">
        <h2 class="text-base">Add a tank you're playing</h2>
        <p class="mt-1 text-xs text-wot-dim">
            {{ hasPick
                ? 'Everything on the tree that matches. Its figures are read off the XP Remaining board.'
                : 'In your garage, part ground. Pick a nation, tier or type to search the whole tree.' }}
        </p>

        <div class="mt-3 space-y-2 border border-wot-border bg-wot-sunken p-3">
            <FilterChipRow
                label="Nation"
                mode="picked"
                variant="flag"
                :items="nations"
                :selected="pickedNations"
                @toggle="pickedNations = withPick(pickedNations, $event)"
                @clear="pickedNations = []"
            >
                <template #default="{ item }">
                    <NationFlag :nation="item" />
                </template>
            </FilterChipRow>

            <FilterChipRow
                label="Tier"
                mode="picked"
                :items="tiers"
                :selected="pickedTiers"
                :item-label="(tier) => `Tier ${roman(tier)}`"
                @toggle="pickedTiers = withPick(pickedTiers, $event)"
                @clear="pickedTiers = []"
            >
                <template #default="{ item }">{{ roman(item) }}</template>
            </FilterChipRow>

            <FilterChipRow
                label="Type"
                mode="picked"
                variant="icon"
                :items="types"
                :selected="pickedTypes"
                @toggle="pickedTypes = withPick(pickedTypes, $event)"
                @clear="pickedTypes = []"
            >
                <template #default="{ item }">
                    <VehicleTypeIcon :type="item" />
                </template>
            </FilterChipRow>
        </div>

        <!-- Capped and scrolled: unfiltered this is every tank on every line,
             and a list that long would push the page out from under the table
             it belongs to. -->
        <div class="mt-3 max-h-64 overflow-y-auto">
            <EmptyState v-if="!pickable.length" compact>
                {{ hasPick
                    ? 'Nothing left to add in those nations, tiers and types.'
                    : 'Nothing in the garage is part ground. Pick a nation, tier or type to search the whole tree.' }}
            </EmptyState>

            <ul v-else role="list" class="flex flex-wrap gap-1.5">
                <li v-for="option in pickable" :key="option.tank_id">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1.5 whitespace-nowrap border border-wot-border px-2 py-1 text-xs text-wot-text transition-colors hover:border-wot-gold hover:text-wot-gold"
                        :title="option.xp_remaining
                            ? `Start grinding ${option.name} — ${n(option.xp_remaining)} XP still on it`
                            : `Start grinding ${option.name}`"
                        @click="startGrinding(option.tank_id)"
                    >
                        <NationFlag :nation="option.nation" />
                        {{ option.name }}
                        <span class="text-wot-dim">{{ roman(option.tier) }}</span>
                        <VehicleTypeIcon :type="option.type" class="text-wot-dim" />
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
</template>
