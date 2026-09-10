<script setup>
import { IconX } from '@tabler/icons-vue';
import { router } from '@inertiajs/vue3';
import EditableNumber from './EditableNumber.vue';
import ModuleResearchPicker from './ModuleResearchPicker.vue';
import NationFlag from './NationFlag.vue';

/**
 * The tanks currently being played, and what each still owes.
 *
 * Shared by the Grinding page and the dashboard rather than written twice.
 * Every figure on it is the XP board's, read off that board's own cells by
 * GrindBoard, so the two pages and the four tabs cannot quote different numbers
 * — which is the same reason the tracked-target tables are gone.
 *
 * The rows are editable wherever it appears. Banked XP is the one number no API
 * can supply, and having to leave a page to type it in was the friction the
 * spreadsheet this replaced never had.
 */
const props = defineProps({
    rows: { type: Array, required: true },
    totals: { type: Object, required: true },
    /*
     * The props a write should bring back, which differ per page: the Grinding
     * page holds these rows under `active` beside four boards that move with
     * them, the dashboard holds the lot under `grinding`. Supplied by the
     * parent for the same reason EditableNumber takes one — the component
     * cannot know what the page around it calls things.
     */
    only: { type: Array, required: true },
});

const stopGrinding = (tankId) => router.patch(`/wot/grinding/purchases/${tankId}`, { is_playing: false }, {
    preserveScroll: true,
    only: props.only,
});

const n = (v) => new Intl.NumberFormat().format(v ?? 0);

// Tiers are Roman in game and in every community tool.
const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];
</script>

<template>
    <p v-if="!rows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
        <slot name="empty">Nothing being ground.</slot>
    </p>

    <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
        <table class="min-w-full divide-y divide-wot-border text-sm">
            <thead class="bg-wot-sunken">
                <tr>
                    <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Tank</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">XP banked</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To max</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To next tank</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                    <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Progress</th>
                    <th scope="col" class="w-10 px-4 py-3" />
                </tr>
            </thead>
            <tbody class="divide-y divide-wot-border-soft">
                <tr v-for="row in rows" :key="row.tank_id" class="hover:bg-wot-sunken">
                    <td class="px-4 py-2">
                        <NationFlag :nation="row.nation" class="me-2" />
                        <span class="text-wot-heading">{{ row.name }}</span>
                        <span class="ms-2 text-xs text-wot-dim">{{ ROMAN[row.tier] }}</span>
                    </td>
                    <!-- The one number no API can supply. -->
                    <td class="px-4 py-2 text-right">
                        <EditableNumber
                            field="banked_xp"
                            :model-value="row.banked_xp"
                            :url="`/wot/grinding/purchases/${row.tank_id}`"
                            :only="only"
                        />
                    </td>
                    <!-- The XP board's own picker, against the XP board's own
                         store: a module ticked here is ticked there, which is
                         the whole point of building this table out of its
                         cells. -->
                    <td class="px-4 py-2 text-right">
                        <ModuleResearchPicker :cell="row" :only="only" />
                    </td>
                    <!-- Every unlock this tank leads to that is still owed.
                         Usually one; a tank under two tier Xs owes both, and
                         both are grinds you would do from this seat. -->
                    <td class="px-4 py-2 text-right">
                        <span v-if="!row.unlocks.length" class="text-wot-muted">—</span>
                        <div v-for="u in row.unlocks" :key="u.tank_id" class="whitespace-nowrap tabular-nums text-wot-muted">
                            <span v-if="row.unlocks.length > 1" class="me-2 text-xs text-wot-dim">{{ u.name }}</span>
                            {{ n(u.xp) }}
                        </div>
                    </td>
                    <td class="px-4 py-2 text-right tabular-nums text-wot-heading">{{ n(row.xp_remaining) }}</td>
                    <td class="px-4 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <span class="h-1.5 w-16 bg-wot-sunken">
                                <span class="block h-full bg-wot-gold" :style="{ width: `${row.progress}%` }" />
                            </span>
                            <span class="w-12 text-right tabular-nums text-wot-muted">{{ row.progress }}%</span>
                        </div>
                    </td>
                    <td class="px-4 py-2 text-right">
                        <button
                            type="button"
                            class="inline-flex items-center justify-center border border-wot-border p-1.5 text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                            :title="`Stop grinding ${row.name}`"
                            :aria-label="`Stop grinding ${row.name}`"
                            @click="stopGrinding(row.tank_id)"
                        >
                            <IconX :size="18" stroke-width="2.25" />
                        </button>
                    </td>
                </tr>
            </tbody>

            <tfoot v-if="rows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                <tr>
                    <th scope="row" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">
                        Total
                        <span class="ms-2 font-normal normal-case tracking-normal text-wot-dim">{{ totals.tanks }} tanks</span>
                    </th>
                    <td class="px-4 py-3 text-right tabular-nums text-wot-good">{{ n(totals.banked_xp) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-wot-muted">{{ n(totals.module_xp) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums text-wot-muted">{{ n(totals.research_cost) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(totals.xp_remaining) }}</td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <span class="h-1.5 w-16 bg-wot-panel">
                                <span class="block h-full bg-wot-gold" :style="{ width: `${totals.progress}%` }" />
                            </span>
                            <span class="w-12 text-right tabular-nums text-wot-muted">{{ totals.progress }}%</span>
                        </div>
                    </td>
                    <td class="px-4 py-3" />
                </tr>
            </tfoot>
        </table>
    </div>
</template>
