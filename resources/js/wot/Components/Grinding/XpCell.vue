<script setup>
import { router } from '@inertiajs/vue3';
import { IconEngine, IconRestore } from '@tabler/icons-vue';
import EditableNumber from '../EditableNumber.vue';
import ModuleResearchPicker from '../ModuleResearchPicker.vue';
import ResearchLock from '../ResearchLock.vue';
import { n } from '../../lib/format';

/**
 * What one vehicle still owes: its own modules, and the unlock ahead of it.
 *
 * Modules above the unlock, in the order the grind actually happens — you
 * research a tank's modules on the way to affording the next tank.
 *
 * The two halves are shared independently. A vehicle's modules are researched
 * once, so a shared cell shows them as text; but two lines diverging from that
 * vehicle owe two different unlocks, so the unlock can still be this row's to
 * edit.
 */
defineProps({
    cell: { type: Object, required: true },
});

// Clearing the override hands the cell back to the encyclopedia's figure, so it
// is a null rather than a zero — a tank you hold fragments enough to unlock
// outright is a real, different state.
const resetResearchXp = (tankId) => router.patch(`/wot/grinding/research/${tankId}`, { research_xp: null }, {
    preserveScroll: true,
    only: ['xp', 'totals'],
});
</script>

<template>
    <div class="space-y-1">
        <div class="flex items-center justify-end gap-1.5">
            <IconEngine
                :size="14"
                stroke-width="2"
                class="shrink-0"
                :class="cell.module_xp ? 'text-wot-dim' : 'text-wot-dim/50'"
                aria-hidden="true"
            />
            <span
                v-if="cell.is_shared"
                class="tabular-nums text-wot-dim/60"
                :title="`${cell.name} — modules counted and ticked on ${cell.shared_with}.`"
            >
                {{ n(cell.module_xp) }}
            </span>
            <ModuleResearchPicker v-else :cell="cell" />
        </div>

        <div v-if="cell.unlocks" class="flex items-center justify-end gap-1.5">
            <!-- The tick that settles this unlock, leading the row rather than
                 trailing it: it is the thing you do, and the number beside it is
                 what it zeroes. -->
            <ResearchLock :unlocks="cell.unlocks" />

            <!-- Already researched, so nothing is owed however much it lists
                 at. -->
            <span
                v-if="cell.unlocks.is_unlocked"
                class="tabular-nums text-wot-dim/50"
                :title="`${cell.unlocks.name} — already researched`"
            >
                0
            </span>

            <span
                v-else-if="cell.unlocks.is_shared"
                class="tabular-nums text-wot-dim/60"
                :title="`${cell.unlocks.name} — counted and edited on ${cell.shared_with ?? 'another line'}.`"
            >
                {{ n(cell.unlocks.xp) }}
            </span>

            <template v-else>
                <EditableNumber
                    field="research_xp"
                    :model-value="cell.unlocks.xp"
                    :url="`/wot/grinding/research/${cell.unlocks.tank_id}`"
                    :only="['xp', 'totals']"
                    :tone="cell.unlocks.is_discounted
                        ? 'border-wot-gold/50 bg-wot-sunken text-wot-gold'
                        : 'border-wot-border bg-wot-sunken text-wot-text'"
                />

                <!-- What the fragments recorded on the Blueprints board say this
                     should cost. Shown only where it disagrees with the figure
                     beside it: one of the two is stale, and which is not this
                     board's to decide. -->
                <span
                    v-if="cell.unlocks.blueprint_xp != null && cell.unlocks.blueprint_xp !== cell.unlocks.xp"
                    class="shrink-0 text-xs tabular-nums text-wot-dim"
                    :title="`The fragments built against the ${cell.unlocks.name} come to ${n(cell.unlocks.blueprint_xp)} XP. The figure beside it is what was typed in.`"
                >
                    {{ n(cell.unlocks.blueprint_xp) }}
                </span>

                <!-- Only offered once the figure has been typed over; there is
                     nothing to reset back to otherwise. -->
                <button
                    v-if="cell.unlocks.is_discounted"
                    type="button"
                    class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 text-wot-dim transition-colors hover:text-wot-bad"
                    :title="`Reset to the ${n(cell.unlocks.full_xp)} full cost of the ${cell.unlocks.name}`"
                    :aria-label="`Reset to the full cost of the ${cell.unlocks.name}`"
                    @click="resetResearchXp(cell.unlocks.tank_id)"
                >
                    <IconRestore :size="14" stroke-width="2.25" />
                </button>
            </template>
        </div>
    </div>
</template>
