<script setup>
import { Link } from '@inertiajs/vue3';
import { IconPlus, IconTimeline } from '@tabler/icons-vue';
import { ref } from 'vue';
import BarList from '../Components/BarList.vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import FlowForm from '../Components/FlowForm.vue';
import FlowTable from '../Components/FlowTable.vue';
import StatTile from '../Components/StatTile.vue';
import { money, moneySigned, percent } from '../lib/format';

defineProps({
    income: Array,
    expenses: Array,
    cashflow: Object,
    income_by_category: Array,
    expenses_by_category: Array,
    holdings: Array,
});

const form = ref({ open: false, flow: null, direction: 'income' });
const add = (direction) => { form.value = { open: true, flow: null, direction }; };
const edit = (flow) => { form.value = { open: true, flow, direction: flow.direction }; };
</script>

<template>
    <FinShell title="Income & expenses" subtitle="Everything that comes in or goes out on a schedule — a salary, an hourly contract, a pension that has not started yet, a streaming subscription.">
        <template #actions>
            <Link href="/finance/scenarios" class="fin-btn fin-btn-quiet"><IconTimeline :size="16" /> Projections &amp; scenarios</Link>
            <button type="button" class="fin-btn fin-btn-quiet" @click="add('expense')"><IconPlus :size="16" /> Expense</button>
            <button type="button" class="fin-btn fin-btn-primary" @click="add('income')"><IconPlus :size="16" /> Income</button>
        </template>

        <EmptyState v-if="!income.length && !expenses.length" title="No income or expenses yet" body="Add a paycheque and a few bills, and the budget, the overview and the retirement tools all start to have something to say.">
            <button type="button" class="fin-btn fin-btn-primary" @click="add('income')"><IconPlus :size="16" /> Add income</button>
            <button type="button" class="fin-btn fin-btn-quiet" @click="add('expense')"><IconPlus :size="16" /> Add an expense</button>
        </EmptyState>

        <div v-else class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile label="Monthly income" :value="money(cashflow.income)" hint="Before tax" />
                <StatTile label="Estimated tax" :value="money(cashflow.taxes)" :hint="`${percent(cashflow.tax_rate, 1)} of income, by how each is taxed`" />
                <StatTile label="Monthly expenses" :value="money(cashflow.expenses)" />
                <StatTile
                    feature label="Left each month" :value="moneySigned(cashflow.net)"
                    :hint="`${percent(cashflow.savings_rate, 1)} savings rate, after tax`"
                />
            </div>

            <div class="grid items-start gap-5 xl:grid-cols-3">
                <div class="flex flex-col gap-5 xl:col-span-2">
                    <Card title="Income" subtitle="One-time amounts, and anything that has not started yet, are listed but not in the monthly totals." flush>
                        <p v-if="!income.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">No income yet.</p>
                        <FlowTable v-else :flows="income" @edit="edit" />
                    </Card>

                    <Card title="Expenses" flush>
                        <p v-if="!expenses.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">No expenses yet.</p>
                        <FlowTable v-else :flows="expenses" @edit="edit" />
                    </Card>
                </div>

                <div class="flex flex-col gap-5">
                    <Card v-if="income_by_category.length" title="Where it comes from" subtitle="Per month, by category.">
                        <BarList :items="income_by_category" />
                    </Card>
                    <Card v-if="expenses_by_category.length" title="Where it goes" subtitle="Per month, by category.">
                        <BarList :items="expenses_by_category" color="var(--color-fin-chart-2)" />
                    </Card>
                </div>
            </div>
        </div>

        <FlowForm :open="form.open" :flow="form.flow" :direction="form.direction" :holdings="holdings" @close="form.open = false" />
    </FinShell>
</template>
