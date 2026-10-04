<script setup>
import { Link, router } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight, IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';
import { ref } from 'vue';
import Card from '../Components/Card.vue';
import DonutChart from '../Components/DonutChart.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import FlowForm from '../Components/FlowForm.vue';
import StatTile from '../Components/StatTile.vue';
import { money, moneyExact, moneySigned, percent } from '../lib/format';

const props = defineProps({
    month: String,
    label: String,
    previous: String,
    next: String,
    income: Array,
    expenses: Array,
    households: Array,
    categories: Array,
    totals: Object,
    split: Array,
});

/*
 * Recording an actual is one small PUT per field, sent when the field is left
 * (the `change` event), not on every keystroke. An emptied field sends null,
 * which the server reads as "forget this figure" — distinct from typing 0,
 * which is a month where the bill really was nothing.
 */
const record = (row, event) => {
    const typed = event.target.value.trim();

    router.put(`/finance/budget/actuals/${row.id}`, { month: props.month, amount: typed === '' ? null : Number(typed) }, {
        preserveScroll: true,
        preserveState: true,
    });
};

/*
 * Household expenses are set up here. `parent` on the form makes a new flow
 * an item of that household expense; with neither a flow nor a parent it
 * adds the household expense itself.
 */
const form = ref({ open: false, flow: null, parent: null, category: null });

const setUp = () => { form.value = { open: true, flow: null, parent: null, category: 'household' }; };
const addItem = (household) => { form.value = { open: true, flow: null, parent: { id: household.id, name: household.name }, category: null }; };
const edit = (flow) => { form.value = { open: true, flow, parent: null, category: null }; };

const remove = (row) => {
    const items = row.items?.length;

    if (window.confirm(items ? `Remove ${row.name} and the ${items} items inside it?` : `Remove ${row.name}?`)) {
        router.delete(`/finance/flows/${row.id}`, { preserveScroll: true });
    }
};

const varianceClass = (variance) => (variance === null ? 'text-fin-grey-400' : (variance >= 0 ? 'text-fin-green-600' : 'text-fin-red-600'));

const sections = [
    { key: 'income', title: 'Income', rows: () => props.income },
    { key: 'expenses', title: 'Expenses', rows: () => props.expenses },
];

// How a share of income compares with its guideline. For needs and wants,
// under is good; for savings, over is.
const splitTone = (part) => {
    const good = part.key === 'savings' ? part.share >= part.target : part.share <= part.target;

    return good ? 'bg-fin-green-500' : 'bg-fin-gold-400';
};
</script>

<template>
    <FinShell title="Monthly budget" subtitle="Each income stream and expense at its planned monthly amount. Type what really happened beside it to see the difference.">
        <template #actions>
            <Link :href="`/finance/budget?month=${previous}`" class="fin-btn fin-btn-quiet" aria-label="Previous month"><IconChevronLeft :size="16" /></Link>
            <span class="min-w-32 text-center text-sm font-semibold text-fin-black">{{ label }}</span>
            <Link :href="`/finance/budget?month=${next}`" class="fin-btn fin-btn-quiet" aria-label="Next month"><IconChevronRight :size="16" /></Link>
        </template>

        <EmptyState v-if="!income.length && !expenses.length && !households.length" title="Nothing planned for this month" body="The budget is built from your income and expenses. Add some and they appear here, or start with what the household spends.">
            <Link href="/finance/cashflow" class="fin-btn fin-btn-primary">Go to income &amp; expenses</Link>
            <button type="button" class="fin-btn fin-btn-quiet" @click="setUp"><IconPlus :size="16" /> Set up household expenses</button>
        </EmptyState>

        <div v-else class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile label="Planned income" :value="money(totals.planned_income)" />
                <StatTile label="Planned expenses" :value="money(totals.planned_expenses)" />
                <StatTile feature label="Planned to keep" :value="moneySigned(totals.planned_net)" />
                <StatTile label="Recorded so far" :value="`${totals.tracked} of ${totals.rows}`" hint="Lines with an actual figure" />
            </div>

            <div class="grid items-start gap-5 xl:grid-cols-3">
                <div class="flex flex-col gap-5 xl:col-span-2">
                    <Card v-for="section in sections" :key="section.key" :title="section.title" flush>
                        <p v-if="!section.rows().length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">Nothing here this month.</p>

                        <div v-else class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                        <th class="px-5 py-2.5 font-medium">Line</th>
                                        <th class="px-3 py-2.5 text-right font-medium">Planned</th>
                                        <th class="w-36 px-3 py-2.5 text-right font-medium">Actual</th>
                                        <th class="px-5 py-2.5 text-right font-medium">Difference</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in section.rows()" :key="row.id" class="border-b border-fin-grey-100 last:border-0">
                                        <td class="px-5 py-2.5">
                                            <span class="font-medium text-fin-black">{{ row.name }}</span>
                                            <span class="block text-xs text-fin-grey-500">
                                                {{ row.category_label }}<template v-if="row.holding_name"> · {{ row.holding_name }}</template>
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right text-fin-charcoal">{{ moneyExact(row.planned) }}</td>
                                        <td class="px-3 py-2.5">
                                            <input
                                                type="number" min="0" step="0.01" class="text-right"
                                                :value="row.actual" :aria-label="`Actual for ${row.name}`" placeholder="—"
                                                @change="record(row, $event)"
                                            >
                                        </td>
                                        <td
                                            class="px-5 py-2.5 text-right font-medium"
                                            :class="row.variance === null ? 'text-fin-grey-400' : (row.variance >= 0 ? 'text-fin-green-600' : 'text-fin-red-600')"
                                        >
                                            {{ row.variance === null ? '—' : moneySigned(row.variance) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </Card>

                    <Card
                        v-for="household in households" :key="household.id" :title="household.name" flush
                        :subtitle="household.is_itemised ? 'Itemised: it comes to what its items add up to, here and everywhere else.' : 'One estimate for now. Add items to itemise it — it then comes to their sum.'"
                    >
                        <template #actions>
                            <button type="button" class="fin-icon-btn" :aria-label="`Edit ${household.name}`" @click="edit(household.flow)"><IconPencil :size="16" /></button>
                            <button type="button" class="fin-icon-btn" :aria-label="`Remove ${household.name}`" @click="remove(household)"><IconTrash :size="16" /></button>
                            <button type="button" class="fin-btn fin-btn-quiet" @click="addItem(household)"><IconPlus :size="16" /> Item</button>
                        </template>

                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                        <th class="px-5 py-2.5 font-medium">{{ household.is_itemised ? 'Item' : 'Line' }}</th>
                                        <th class="px-3 py-2.5 text-right font-medium">Planned</th>
                                        <th class="w-36 px-3 py-2.5 text-right font-medium">Actual</th>
                                        <th class="px-3 py-2.5 text-right font-medium">Difference</th>
                                        <th class="px-5 py-2.5"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Not itemised: the one estimate, recorded against like any line. -->
                                    <tr v-if="!household.is_itemised" class="border-b border-fin-grey-100">
                                        <td class="px-5 py-2.5">
                                            <span class="font-medium text-fin-black">Everything, estimated</span>
                                            <span class="block text-xs text-fin-grey-500">Edit it to change the estimate</span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right text-fin-charcoal">{{ moneyExact(household.planned) }}</td>
                                        <td class="px-3 py-2.5">
                                            <input type="number" min="0" step="0.01" class="text-right" :value="household.actual" :aria-label="`Actual for ${household.name}`" placeholder="—" @change="record(household, $event)">
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-medium" :class="varianceClass(household.variance)">{{ household.variance === null ? '—' : moneySigned(household.variance) }}</td>
                                        <td class="px-5 py-2.5" />
                                    </tr>

                                    <tr v-for="row in household.items" :key="row.id" class="border-b border-fin-grey-100" :class="{ 'opacity-55': row.planned === 0 && row.actual === null }">
                                        <td class="px-5 py-2.5">
                                            <span class="font-medium text-fin-black">{{ row.name }}</span>
                                            <span class="block text-xs text-fin-grey-500">{{ row.category_label }}<template v-if="row.planned === 0"> · not this month</template></span>
                                        </td>
                                        <td class="px-3 py-2.5 text-right text-fin-charcoal">{{ moneyExact(row.planned) }}</td>
                                        <td class="px-3 py-2.5">
                                            <input type="number" min="0" step="0.01" class="text-right" :value="row.actual" :aria-label="`Actual for ${row.name}`" placeholder="—" @change="record(row, $event)">
                                        </td>
                                        <td class="px-3 py-2.5 text-right font-medium" :class="varianceClass(row.variance)">{{ row.variance === null ? '—' : moneySigned(row.variance) }}</td>
                                        <td class="whitespace-nowrap px-5 py-2.5 text-right">
                                            <button type="button" class="fin-icon-btn" :aria-label="`Edit ${row.name}`" @click="edit(row.flow)"><IconPencil :size="16" /></button>
                                            <button type="button" class="fin-icon-btn" :aria-label="`Remove ${row.name}`" @click="remove(row)"><IconTrash :size="16" /></button>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot v-if="household.is_itemised">
                                    <tr class="bg-fin-cream-50/60 font-semibold text-fin-black">
                                        <td class="px-5 py-2.5">Total</td>
                                        <td class="px-3 py-2.5 text-right">{{ moneyExact(household.planned) }}</td>
                                        <td class="px-3 py-2.5 pr-6 text-right">{{ household.actual === null ? '—' : moneyExact(household.actual) }}</td>
                                        <td colspan="2" />
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </Card>

                    <div v-if="!households.length" class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-dashed border-fin-grey-300 bg-fin-cream-100/60 px-5 py-4">
                        <p class="max-w-xl text-sm text-fin-grey-600">
                            <span class="font-semibold text-fin-black">Household expenses.</span>
                            Rather than list every bill under Income &amp; expenses, keep them here as one line that is either a single estimate or the sum of the items you give it.
                        </p>
                        <button type="button" class="fin-btn fin-btn-primary" @click="setUp"><IconPlus :size="16" /> Set up household expenses</button>
                    </div>
                </div>

                <div class="flex flex-col gap-5">
                    <Card title="Needs, wants, savings" subtitle="Against the 50 / 30 / 20 guideline, as shares of planned income.">
                        <ul class="flex flex-col gap-4">
                            <li v-for="part in split" :key="part.key">
                                <div class="flex items-baseline justify-between text-sm">
                                    <span class="font-medium text-fin-black">{{ part.label }}</span>
                                    <span class="tabular-nums text-fin-grey-500">
                                        <span class="font-medium text-fin-black">{{ percent(part.share, 1) }}</span> · {{ money(part.amount) }}
                                    </span>
                                </div>
                                <!-- The tick marks the guideline; the bar is where you are. -->
                                <div class="relative mt-1.5 h-2 rounded-full bg-fin-grey-100">
                                    <div class="h-full rounded-full" :class="splitTone(part)" :style="{ width: `${Math.min(100, Math.max(0, part.share))}%` }" />
                                    <span class="absolute -top-1 h-4 w-0.5 rounded bg-fin-charcoal" :style="{ left: `${part.target}%` }" />
                                </div>
                                <p class="mt-1 text-xs text-fin-grey-500">Guideline {{ part.target }}%</p>
                            </li>
                        </ul>
                    </Card>

                    <Card v-if="categories.length" title="Planned spending" subtitle="By category.">
                        <DonutChart :items="categories" center-label="Expenses" />
                    </Card>
                </div>
            </div>
        </div>

        <FlowForm :open="form.open" :flow="form.flow" :parent="form.parent" :category="form.category" direction="expense" @close="form.open = false" />
    </FinShell>
</template>
