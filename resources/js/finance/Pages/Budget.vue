<script setup>
import { Link, router } from '@inertiajs/vue3';
import { IconChevronLeft, IconChevronRight } from '@tabler/icons-vue';
import Card from '../Components/Card.vue';
import DonutChart from '../Components/DonutChart.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import StatTile from '../Components/StatTile.vue';
import { money, moneyExact, moneySigned, percent } from '../lib/format';

const props = defineProps({
    month: String,
    label: String,
    previous: String,
    next: String,
    income: Array,
    expenses: Array,
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

        <EmptyState v-if="!income.length && !expenses.length" title="Nothing planned for this month" body="The budget is built from your income and expenses. Add some and they appear here.">
            <Link href="/finance/cashflow" class="fin-btn fin-btn-primary">Go to income &amp; expenses</Link>
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
    </FinShell>
</template>
