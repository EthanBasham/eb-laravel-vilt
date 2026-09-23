<script setup>
import { n } from '../../lib/format';

/**
 * What each skill level costs, as the game charges it.
 *
 * Recorded here rather than computed against: nothing spends these yet, and the
 * board's job for now is to have them written down where the training they
 * describe is being planned. The levels are also what the editor's select is
 * built from, so that control and this table can never offer different
 * ceilings.
 */
defineProps({
    steps: { type: Array, required: true },
});
</script>

<template>
    <div class="mb-3 border border-wot-border bg-wot-panel p-3">
        <h3 class="text-xs font-bold uppercase tracking-wider text-wot-dim">Crew XP progression</h3>

        <div class="mt-2 overflow-x-auto">
            <table class="text-sm">
                <thead>
                    <tr>
                        <th
                            v-for="step in steps"
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
                            v-for="step in steps"
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
</template>
