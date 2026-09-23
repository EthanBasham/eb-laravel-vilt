<script setup>
import LineHeaderCell from './LineHeaderCell.vue';
import { roman } from '../lib/format';

/**
 * The grid all five boards are drawn on: one row per line, one column per tier,
 * the line's name pinned to the left and its total pinned to the right.
 *
 * The shape is the same every time because the question is: something is owed
 * per vehicle, and it is read both along a line and down a tier. What differs
 * is only what a cell holds — a price, a picker, a set of letters — which is
 * what the slots are for. Written out per board, the five copies had already
 * drifted in the small ways markup does: a stray class here, a missing dot
 * there.
 *
 * Everything arrives filtered. The board above decides which rows and which
 * tiers survive its own filters, and this draws what it is handed — so hiding a
 * tier takes its column off the grid *and* out of the totals, which is what
 * keeps the footer agreeing with the rows.
 *
 * `border-separate` rather than the collapsed default: a collapsed table drops
 * the borders on sticky cells as they scroll, because the edge belongs to the
 * neighbour it was shared with.
 */
defineProps({
    rows: { type: Array, required: true },
    tiers: { type: Array, required: true },
    // What the right-hand column is counting — Remaining, Planned, Held, Crews.
    totalLabel: { type: String, required: true },
    /*
     * Figures are read down a column, so they are flush right; the crews board
     * is the exception because its cells are a word-shaped set of letters
     * rather than a number, and a ragged left edge there reads as a misprint.
     */
    align: { type: String, default: 'right' }, // right | center
    /*
     * Whether a cell has anything to draw. Absent by default — a line simply
     * has no vehicle at that tier — but the crews board also blanks a cell
     * whose crew size is filtered out, which is the same "nothing here" as far
     * as the grid is concerned.
     */
    isVisible: { type: Function, default: (cell) => Boolean(cell) },
    // The row total's own tone, which each board decides from its own figure:
    // gold for a plan, heading for a debt, dim for nothing owed.
    rowTotalClass: { type: Function, default: () => '' },
});

const cellAlign = (align) => (align === 'center' ? 'text-center' : 'text-right align-top');
</script>

<template>
    <div class="overflow-x-auto border border-wot-border bg-wot-panel">
        <table class="min-w-full border-separate border-spacing-0 divide-y divide-wot-border text-sm">
            <thead class="bg-wot-sunken">
                <tr>
                    <th scope="col" class="sticky left-0 z-20 whitespace-nowrap border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">
                        Line
                    </th>
                    <th
                        v-for="tier in tiers"
                        :key="tier"
                        scope="col"
                        class="px-3 py-3 text-xs font-bold uppercase tracking-wider text-wot-dim"
                        :class="align === 'center' ? 'text-center' : 'text-right'"
                    >
                        Tier {{ roman(tier) }}
                    </th>
                    <th scope="col" class="sticky right-0 z-20 whitespace-nowrap border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                        {{ totalLabel }}
                    </th>
                </tr>
            </thead>

            <tbody class="divide-y divide-wot-border-soft">
                <tr v-for="row in rows" :key="row.key" class="group hover:bg-wot-sunken">
                    <LineHeaderCell :row="row" />

                    <td v-for="tier in tiers" :key="tier" class="px-3 py-2" :class="cellAlign(align)">
                        <slot v-if="isVisible(row.cells[tier])" name="cell" :row="row" :tier="tier" :cell="row.cells[tier]" />
                        <!-- No vehicle at this tier on this line, or none this
                             board is drawing. A dot rather than a blank, so an
                             empty cell reads as deliberate. -->
                        <span v-else class="text-wot-muted">·</span>
                    </td>

                    <td
                        class="sticky right-0 z-10 whitespace-nowrap border-s border-wot-border bg-wot-panel-solid px-4 py-2 text-right tabular-nums group-hover:bg-wot-sunken-solid"
                        :class="rowTotalClass(row)"
                    >
                        <slot name="rowTotal" :row="row" />
                    </td>
                </tr>
            </tbody>

            <!-- A single row totals to itself, and a footer repeating it says
                 nothing the row above has not. -->
            <tfoot v-if="rows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                <tr>
                    <th scope="row" class="sticky left-0 z-20 border-e border-wot-border bg-wot-sunken-solid px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">
                        Total
                    </th>
                    <td
                        v-for="tier in tiers"
                        :key="tier"
                        class="px-3 py-3 tabular-nums text-wot-muted"
                        :class="align === 'center' ? 'text-center' : 'text-right'"
                    >
                        <slot name="tierTotal" :tier="tier" />
                    </td>
                    <td class="sticky right-0 z-20 border-s border-wot-border bg-wot-sunken-solid px-4 py-3 text-right tabular-nums font-bold text-wot-heading">
                        <slot name="grandTotal" />
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</template>
