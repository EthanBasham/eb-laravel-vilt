<script setup>
import { IconWorld } from '@tabler/icons-vue';
import FilterChipRow from '../FilterChipRow.vue';
import NationFlag from '../NationFlag.vue';

/**
 * The filter bar over the Battle Pass cards: status, nation, gender and season.
 *
 * Every row is `picked` — nothing lit, everything shown, and lighting a chip
 * is what narrows. The roster is a list you look someone up in, like the
 * grinding picker, rather than a board you come back to with columns switched
 * off: "who have I not collected from Germany" should cost two clicks. A row
 * can hold several, and the rows combine, so two nations and one status is
 * either nation in that status.
 *
 * Only the cards wear this. The table is where the roster is kept up to date,
 * and a row vanishing because the status just set on it is filtered out would
 * be a poor way to find that out.
 *
 * It holds no state: the roster owns the filters and does the filtering, since
 * it is also what has to say how many are left.
 */
defineProps({
    // { statuses, nations, genders, seasons } — what is lit in each row.
    filters: { type: Object, required: true },
    // What each row offers. Nations and seasons are only those on the roster,
    // each ending in NONE where someone has none.
    nations: { type: Array, required: true },
    seasons: { type: Array, required: true },
    statuses: { type: Object, required: true },
    genders: { type: Object, required: true },
    // The value standing in for "no nation" and "no season" in those rows.
    none: { type: String, required: true },
    shown: { type: Number, required: true },
    total: { type: Number, required: true },
});

defineEmits(['toggle', 'clear']);
</script>

<template>
    <div class="relative mb-3 space-y-2 border border-wot-border bg-wot-panel p-3">
        <!-- Out of the flow, in the corner beside the short Status row, so it
             costs the panel no height of its own. -->
        <p class="absolute right-3 top-3 text-xs tabular-nums text-wot-dim" aria-live="polite">
            {{ shown }} of {{ total }} crew
        </p>

        <FilterChipRow
            label="Status"
            mode="picked"
            label-width="w-20"
            :items="Object.keys(statuses)"
            :selected="filters.statuses"
            @toggle="$emit('toggle', 'statuses', $event)"
            @clear="$emit('clear', 'statuses')"
        >
            <template #default="{ item }">{{ statuses[item] }}</template>
        </FilterChipRow>

        <FilterChipRow
            label="Nation"
            mode="picked"
            label-width="w-20"
            variant="flag"
            :items="nations"
            :selected="filters.nations"
            :item-label="(nation) => (nation === none ? 'No nation' : null)"
            titled
            @toggle="$emit('toggle', 'nations', $event)"
            @clear="$emit('clear', 'nations')"
        >
            <template #default="{ item }">
                <!-- Sized to the flags beside it, so the row keeps one height. -->
                <IconWorld
                    v-if="item === none"
                    :size="13"
                    stroke-width="1.75"
                    class="mx-1 inline-block text-wot-text"
                />
                <NationFlag v-else :nation="item" />
            </template>
        </FilterChipRow>

        <FilterChipRow
            label="Gender"
            mode="picked"
            label-width="w-20"
            :items="Object.keys(genders)"
            :selected="filters.genders"
            @toggle="$emit('toggle', 'genders', $event)"
            @clear="$emit('clear', 'genders')"
        >
            <template #default="{ item }">{{ genders[item].name }}</template>
        </FilterChipRow>

        <FilterChipRow
            label="Season"
            mode="picked"
            label-width="w-20"
            :items="seasons"
            :selected="filters.seasons"
            :item-label="(season) => (season === none ? 'No season' : `Season ${season}`)"
            titled
            @toggle="$emit('toggle', 'seasons', $event)"
            @clear="$emit('clear', 'seasons')"
        >
            <template #default="{ item }">{{ item === none ? '–' : item }}</template>
        </FilterChipRow>
    </div>
</template>
