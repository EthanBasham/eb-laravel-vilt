<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';
import { ref, watch } from 'vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import Field from '../Components/Field.vue';
import FinDialog from '../Components/FinDialog.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import ToolNumber from '../Components/ToolNumber.vue';
import { useToolQuery } from '../composables/useToolQuery';
import { asMonth, chartColors, duration, money, moneyExact, moneySigned, percent } from '../lib/format';

const props = defineProps({
    options: Object,
    goals: Array,
});

const params = useToolQuery(props.options);

const dialog = ref(null);
const open = ref(false);
const editing = ref(null);
const form = useForm({ name: '', target_amount: null, saved_amount: 0, target_date: '' });

const show = (goal = null) => {
    editing.value = goal;
    open.value = true;
};

watch(open, (isOpen) => {
    if (!isOpen) return;

    form.clearErrors();

    if (editing.value) {
        form.name = editing.value.name;
        form.target_amount = editing.value.target_amount;
        form.saved_amount = editing.value.saved_amount;
        form.target_date = editing.value.target_date;

        return;
    }

    form.reset();
});

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (editing.value) {
        form.patch(`/finance/goals/${editing.value.id}`, options);
    } else {
        form.post('/finance/goals', options);
    }
};

const remove = (goal) => {
    if (window.confirm(`Remove ${goal.name}?`)) {
        router.delete(`/finance/goals/${goal.id}`, { preserveScroll: true });
    }
};

// The three saving strategies as lines; "finance it" has no balance to draw.
const lines = (goal) => goal.strategies
    .filter((strategy) => strategy.series.length)
    .map((strategy, index) => ({
        label: strategy.label,
        color: chartColors[index],
        points: strategy.series.map((point) => ({ x: point.month, y: point.value })),
    }));

const cheapest = (goal) => goal.strategies.filter((strategy) => strategy.key !== 'finance').reduce((best, strategy) => (strategy.monthly < best.monthly ? strategy : best));
</script>

<template>
    <FinShell title="Savings goals" subtitle="Set a target and a date, then compare the ways of getting there: plain saving, a high-yield account, investing it, or buying now on a loan.">
        <template #actions>
            <button type="button" class="fin-btn fin-btn-primary" @click="show()"><IconPlus :size="16" /> Goal</button>
        </template>

        <div class="flex flex-col gap-5">
            <Card title="Assumptions" subtitle="Shared by every goal below. Change one and the plans recalculate.">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <ToolNumber v-model="params.savings_rate" label="High-yield savings rate" suffix="% / yr" step="0.1" />
                    <ToolNumber v-model="params.market_return" label="Expected market return" suffix="% / yr" step="0.1" />
                    <ToolNumber v-model="params.loan_rate" label="Loan rate" suffix="% APR" step="0.1" />
                    <ToolNumber v-model="params.loan_years" label="Loan length" suffix="years" step="1" />
                </div>
            </Card>

            <EmptyState v-if="!goals.length" title="No goals yet" body="A car, a down payment, a trip, a cushion. Anything with a price and a date.">
                <button type="button" class="fin-btn fin-btn-primary" @click="show()"><IconPlus :size="16" /> Add a goal</button>
            </EmptyState>

            <Card v-for="goal in goals" :key="goal.id" :title="goal.name" :subtitle="`${money(goal.target_amount)} by ${asMonth(goal.target_date)} · ${duration(goal.months_remaining)} away`">
                <template #actions>
                    <button type="button" class="fin-icon-btn" :aria-label="`Edit ${goal.name}`" @click="show(goal)"><IconPencil :size="16" /></button>
                    <button type="button" class="fin-icon-btn" :aria-label="`Remove ${goal.name}`" @click="remove(goal)"><IconTrash :size="16" /></button>
                </template>

                <div class="mb-5">
                    <div class="flex items-baseline justify-between text-xs text-fin-grey-500">
                        <span>{{ money(goal.saved_amount) }} saved</span>
                        <span>{{ percent(goal.progress, 1) }}</span>
                    </div>
                    <div class="mt-1.5 h-2 rounded-full bg-fin-gold-100">
                        <div class="h-full rounded-full bg-fin-gold-400" :style="{ width: `${goal.progress}%` }" />
                    </div>
                </div>

                <div class="grid items-start gap-6 xl:grid-cols-5">
                    <div class="overflow-x-auto xl:col-span-3">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-fin-grey-200 text-left text-xs text-fin-grey-500">
                                    <th class="py-2 pr-3 font-medium">Strategy</th>
                                    <th class="px-3 py-2 text-right font-medium">Rate</th>
                                    <th class="whitespace-nowrap px-3 py-2 text-right font-medium">Per month</th>
                                    <th class="whitespace-nowrap px-3 py-2 text-right font-medium">You pay in</th>
                                    <th class="py-2 pl-3 text-right font-medium">Interest</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="strategy in goal.strategies" :key="strategy.key" class="border-b border-fin-grey-100 align-top last:border-0">
                                    <td class="py-3 pr-3">
                                        <span class="font-medium text-fin-black">{{ strategy.label }}</span>
                                        <span v-if="strategy.key === cheapest(goal).key" class="ml-2 rounded-full bg-fin-green-100 px-2 py-0.5 text-[11px] font-medium text-fin-green-700">Lowest monthly</span>
                                        <span class="mt-0.5 block text-xs text-fin-grey-500">
                                            {{ strategy.note }}
                                            <template v-if="strategy.downside_shortfall">
                                                If returns come in {{ strategy.downside_points }} points lower you end {{ money(strategy.downside_shortfall) }} short.
                                            </template>
                                            <template v-if="strategy.key === 'finance'">Paid over {{ duration(strategy.months) }}.</template>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-right text-fin-grey-600">{{ percent(strategy.rate, 1) }}</td>
                                    <td class="px-3 py-3 text-right font-semibold text-fin-black">{{ moneyExact(strategy.monthly) }}</td>
                                    <td class="px-3 py-3 text-right text-fin-charcoal">{{ money(strategy.total_paid) }}</td>
                                    <td class="whitespace-nowrap py-3 pl-3 text-right font-medium" :class="strategy.growth >= 0 ? 'text-fin-green-600' : 'text-fin-red-600'">{{ moneySigned(strategy.growth) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="xl:col-span-2">
                        <LineChart :series="lines(goal)" :height="210" :format-x="(month) => `${month} mo`" :x-ticks="5" />
                    </div>
                </div>
            </Card>
        </div>

        <FinDialog ref="dialog" :open="open" :title="editing ? `Edit ${editing.name}` : 'Add a goal'" @close="open = false">
            <form class="flex flex-col gap-4" @submit.prevent="save">
                <Field label="What for" :error="form.errors.name">
                    <input v-model="form.name" type="text" required maxlength="80" placeholder="e.g. Replace the car">
                </Field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <Field label="Target" prefix="$" :error="form.errors.target_amount">
                        <input v-model.number="form.target_amount" type="number" min="1" step="0.01" required>
                    </Field>
                    <Field label="Saved so far" prefix="$" :error="form.errors.saved_amount">
                        <input v-model.number="form.saved_amount" type="number" min="0" step="0.01" required>
                    </Field>
                    <Field label="By" :error="form.errors.target_date">
                        <input v-model="form.target_date" type="date" required>
                    </Field>
                </div>

                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                    <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ editing ? 'Save changes' : 'Add goal' }}</button>
                </div>
            </form>
        </FinDialog>
    </FinShell>
</template>
