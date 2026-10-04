<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { IconArrowRight, IconPencil, IconPlus, IconTimeline, IconTrash } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import BarList from '../Components/BarList.vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import FlowForm from '../Components/FlowForm.vue';
import FlowTable from '../Components/FlowTable.vue';
import StatTile from '../Components/StatTile.vue';
import TransferForm from '../Components/TransferForm.vue';
import { money, moneySigned, percent } from '../lib/format';

const props = defineProps({
    income: Array,
    expenses: Array,
    cashflow: Object,
    income_by_category: Array,
    expenses_by_category: Array,
    holdings: Array,
    transfers: Array,
});

const form = ref({ open: false, flow: null, direction: 'income', category: null });
const add = (direction) => { form.value = { open: true, flow: null, direction, category: null }; };
const edit = (flow) => { form.value = { open: true, flow, direction: flow.direction, category: null }; };

// Automated transfers. They need two accounts to run between.
const transferForm = ref({ open: false, transfer: null });
const canTransfer = computed(() => (usePage().props.accounts ?? []).length > 1);
const nextOrder = computed(() => Math.max(0, ...props.transfers.map((transfer) => transfer.sort_order)) + 1);

const removeTransfer = (transfer) => {
    if (window.confirm(`Remove ${transfer.name}?`)) {
        router.delete(`/finance/transfers/${transfer.id}`, { preserveScroll: true });
    }
};

// What a transfer moves, in a line: "Everything above $6,000", "$300 a month".
const moves = (transfer) => {
    const limit = transfer.amount === null ? '' : `, at most ${money(transfer.amount)} a month`;

    if (transfer.kind === 'fixed') return `${money(transfer.amount)} a month`;
    if (transfer.kind === 'top_up') return `Refills the destination to ${money(transfer.keep_balance)}${limit}`;

    return `Everything above ${money(transfer.keep_balance)}${limit}`;
};
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

                    <Card title="Expenses" subtitle="Household expenses are one line here. Itemise them on the Monthly budget, or leave them as one estimate." flush>
                        <template #actions>
                            <button v-if="!expenses.some((flow) => flow.is_itemized)" type="button" class="fin-btn fin-btn-quiet" @click="form = { open: true, flow: null, direction: 'expense', category: 'household' }"><IconPlus :size="16" /> Household expenses</button>
                        </template>
                        <p v-if="!expenses.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">No expenses yet.</p>
                        <FlowTable v-else :flows="expenses" @edit="edit" />
                    </Card>

                    <Card title="Automated transfers" subtitle="What happens to left-over money each month, in order. They run in Projections & scenarios, where each account's balance is walked forward." flush>
                        <template #actions>
                            <button type="button" class="fin-btn fin-btn-quiet" :disabled="!canTransfer" :title="canTransfer ? '' : 'Needs two accounts in your fleet'" @click="transferForm = { open: true, transfer: null }"><IconPlus :size="16" /> Transfer</button>
                        </template>

                        <p v-if="!transfers.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">
                            None yet. Say which account an income is paid into and an expense is paid from — edit one to choose — then add a transfer to sweep what is left into savings, overpay a debt, or keep a cushion topped up.
                        </p>

                        <ol v-else>
                            <li v-for="transfer in transfers" :key="transfer.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-fin-grey-100 px-5 py-3 text-sm" :class="{ 'opacity-55': !transfer.is_active }">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-fin-cream-200 text-xs font-semibold text-fin-charcoal" :title="`Runs ${transfer.sort_order} in the order`">{{ transfer.sort_order }}</span>
                                <span class="min-w-48 flex-1">
                                    <span class="font-medium text-fin-black">{{ transfer.name }}</span>
                                    <span v-if="!transfer.is_active" class="ml-2 rounded-full bg-fin-grey-100 px-2 py-0.5 text-[11px] text-fin-grey-600">Paused</span>
                                    <span class="flex flex-wrap items-center gap-1.5 text-xs text-fin-grey-500">{{ transfer.from_name }} <IconArrowRight :size="12" /> {{ transfer.to_name }}</span>
                                </span>
                                <span class="text-fin-charcoal">{{ moves(transfer) }}</span>
                                <span class="whitespace-nowrap">
                                    <button type="button" class="fin-icon-btn" :aria-label="`Edit ${transfer.name}`" @click="transferForm = { open: true, transfer }"><IconPencil :size="16" /></button>
                                    <button type="button" class="fin-icon-btn" :aria-label="`Remove ${transfer.name}`" @click="removeTransfer(transfer)"><IconTrash :size="16" /></button>
                                </span>
                            </li>
                        </ol>
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

        <FlowForm :open="form.open" :flow="form.flow" :direction="form.direction" :category="form.category" :holdings="holdings" @close="form.open = false" />
        <TransferForm :open="transferForm.open" :transfer="transferForm.transfer" :next-order="nextOrder" @close="transferForm.open = false" />
    </FinShell>
</template>
