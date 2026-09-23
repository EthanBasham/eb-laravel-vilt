<script setup>
import { n } from '../../lib/format';

/**
 * One vehicle's blueprint: what has been built against it, what the tank costs,
 * and what the plan would spend.
 *
 * Two lines and no controls: fragments built against what the tier takes, the
 * undiscounted cost of the tank, and the raw blueprints the plan would spend.
 * Everything else about the vehicle — what the fragments have already taken
 * off, which source pays for the rest — is a click away, because a cell that
 * carried an input for each of them would be five controls wide across nine
 * tiers.
 *
 * The XP never moves as fragments are built: it is what the tank costs, so the
 * column can be read down as which tanks the percentages are worth most
 * against.
 *
 * Every figure here arrives on the cell. There is no JS test runner in this
 * project, so fragment arithmetic done in a template would be arithmetic
 * nothing checks — the board computes, the page renders.
 */
defineProps({
    cell: { type: Object, required: true },
});

defineEmits(['edit']);
</script>

<template>
    <!-- A starter vehicle is researched from nothing, so fragments have nothing
         to discount. -->
    <span
        v-if="!cell.is_researchable"
        class="text-wot-muted"
        :title="`${cell.name} — the start of the line, nothing to research`"
    >—</span>

    <!-- Shared with a line above, where it is the editable one. Read-only here,
         but still spelled out: the figures belong to the tank, and a blank cell
         would read as a tank with nothing against it. -->
    <span
        v-else-if="cell.is_shared"
        class="block tabular-nums text-wot-dim/60"
        :title="`${cell.name} — counted and edited on ${cell.shared_with}.`"
    >
        <span class="block whitespace-nowrap">
            {{ cell.fragments }} / {{ cell.fragments_needed }}
            = {{ n(cell.base_xp) }}
        </span>
        <span class="block whitespace-nowrap text-xs">
            {{ n(cell.planned.national_blueprints) }}
            + {{ n(cell.planned.universal_blueprints) }}
        </span>
    </span>

    <button
        v-else
        type="button"
        class="block w-full whitespace-nowrap border border-wot-border bg-wot-sunken px-2 py-1 text-right tabular-nums transition-colors hover:border-wot-gold"
        :class="cell.is_unlocked ? 'text-wot-dim/50' : 'text-wot-text'"
        :title="`${cell.name} — ${cell.fragments} of ${cell.fragments_needed} fragments built at ${cell.percent_per_fragment}% each, against ${n(cell.base_xp)} XP`"
        @click="$emit('edit', cell.tank_id)"
    >
        <span class="block">
            <span :class="cell.fragments && !cell.is_unlocked ? 'text-wot-gold' : ''">
                {{ cell.fragments }} / {{ cell.fragments_needed }}
            </span>
            = {{ n(cell.base_xp) }}
        </span>
        <span class="block text-xs" :class="cell.planned.fragments ? 'text-wot-text' : 'text-wot-dim'">
            {{ n(cell.planned.national_blueprints) }}
            + {{ n(cell.planned.universal_blueprints) }}
        </span>
    </button>
</template>
