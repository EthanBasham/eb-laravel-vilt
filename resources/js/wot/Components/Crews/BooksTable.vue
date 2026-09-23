<script setup>
import EditableNumber from '../EditableNumber.vue';
import NationFlag from '../NationFlag.vue';
import { inK, n } from '../../lib/format';

/**
 * Books held, per nation and per size.
 *
 * The column totals come back from the server, but the row totals are
 * recomputed here so a figure updates the moment its cell saves rather than
 * after the round trip — the same reason the grinding boards re-total their own
 * visible cells.
 *
 * A row totals XP rather than books: a manual and a booklet are not one book
 * each in any sense worth adding up, since one is worth twelve and a half of
 * the other. The count still has a home — it is what the panel heading reports.
 *
 * The figure is per crew member, which is how a book's value is quoted: each
 * book gives its XP to every seat in the set it is spent on.
 */
const props = defineProps({
    books: { type: Object, required: true },
});

const rowXp = (row) => props.books.types.reduce(
    (sum, type) => sum + (row.quantities[type.key] ?? 0) * type.xp,
    0,
);
</script>

<template>
    <div class="border border-wot-border bg-wot-panel lg:col-span-2">
        <div class="flex items-baseline justify-between border-b border-wot-border px-4 py-3">
            <!-- Books here, XP in the table: the shelf is counted in books, but
                 what it is worth is not. -->
            <h3 class="text-sm">Books</h3>
            <span class="text-xs uppercase tracking-wider text-wot-dim">{{ n(books.totals.books) }} held</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-wot-border-soft text-sm">
                <thead class="bg-wot-sunken">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</th>
                        <th
                            v-for="type in books.types"
                            :key="type.key"
                            scope="col"
                            class="px-3 py-2 text-right text-xs font-bold uppercase tracking-wider text-wot-dim"
                            :title="`${n(type.xp)} XP to each member of a crew`"
                        >
                            {{ type.name }}
                            <!-- What one of them is worth, so the XP in the
                                 Total column is arithmetic the reader can
                                 follow rather than a figure they have to take
                                 on trust. -->
                            <span class="font-normal normal-case tracking-normal text-wot-muted">({{ inK(type.xp) }})</span>
                        </th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Total XP</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-wot-border-soft">
                    <tr v-for="row in books.rows" :key="row.nation" class="hover:bg-wot-sunken">
                        <th scope="row" class="whitespace-nowrap px-4 py-2 text-left font-normal text-wot-text">
                            <NationFlag v-if="row.nation !== 'universal'" :nation="row.nation" class="me-2" />
                            <span :class="row.nation === 'universal' ? 'text-wot-gold' : ''">{{ row.label }}</span>
                        </th>
                        <td v-for="type in books.types" :key="type.key" class="px-3 py-2 text-right">
                            <EditableNumber
                                field="quantity"
                                stepper
                                :model-value="row.quantities[type.key]"
                                :url="`/wot/crews/books/${type.key}/${row.nation}`"
                                :only="['books']"
                            />
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums" :class="rowXp(row) ? 'text-wot-heading' : 'text-wot-dim'">
                            {{ n(rowXp(row)) }}
                        </td>
                    </tr>

                    <!-- The two items that are not books. Neither is tied to a
                         nation and neither is a booklet, a guide or a manual, so
                         they sit under the table with their count in the total
                         column rather than in a type's. -->
                    <tr v-for="special in books.specials" :key="special.key" class="border-t-2 border-wot-border first:border-t-2 hover:bg-wot-sunken">
                        <th scope="row" class="whitespace-nowrap px-4 pb-2 pt-3 text-left font-normal text-wot-muted">
                            {{ special.name }}
                        </th>
                        <td :colspan="books.types.length"></td>
                        <td class="px-4 pb-2 pt-3 text-right">
                            <EditableNumber
                                field="quantity"
                                stepper
                                :model-value="special.quantity"
                                :url="`/wot/crews/books/${special.key}/universal`"
                                :only="['books']"
                            />
                        </td>
                    </tr>
                </tbody>

                <tfoot class="border-t-2 border-wot-border bg-wot-sunken">
                    <tr>
                        <th scope="row" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Books</th>
                        <td v-for="type in books.types" :key="type.key" class="px-3 py-3 text-right tabular-nums text-wot-muted">
                            {{ n(books.totals[type.key]) }}
                        </td>
                        <!-- The type columns count books; this one is what they
                             are worth, like the rows above it. The specials are
                             in neither: one is not a book, and neither carries a
                             per-member figure to be worth anything here. -->
                        <td class="px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(books.totals.xp) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</template>
