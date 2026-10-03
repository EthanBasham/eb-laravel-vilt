<script setup>
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { IconArrowLeft, IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import Card from '../Components/Card.vue';
import Field from '../Components/Field.vue';
import FinDialog from '../Components/FinDialog.vue';
import FinShell from '../Components/FinShell.vue';
import FlowForm from '../Components/FlowForm.vue';
import FlowTable from '../Components/FlowTable.vue';
import HoldingForm from '../Components/HoldingForm.vue';
import StatTile from '../Components/StatTile.vue';
import { money, moneySigned, percent } from '../lib/format';

const props = defineProps({
    holding: Object,
    parent: Object,
    children: Array,
    positions: Array,
    flows: Array,
    secured_by: Object,
    secured_debts: Array,
    summary: Object,
    assets: Array,
});

const isAsset = computed(() => props.holding.side === 'asset');
const classes = computed(() => usePage().props.lists.position_classes);

const types = computed(() => usePage().props.lists.holding_types);

// A compound type is one config gives a `holds` list — retirement accounts,
// today. It can hold other accounts; an account already inside one cannot.
const canHoldAccounts = computed(() => !props.parent && (types.value[props.holding.type]?.holds?.length ?? 0) > 0);
const isCompound = computed(() => props.children.length > 0);
const addingChild = ref(false);

/*
 * Positions are for accounts that hold investments directly. A house or a car
 * has none to itemise, and an account that holds other accounts is itemised
 * by them instead — so the card is offered to neither, unless positions are
 * already there to be seen.
 */
const takesPositions = computed(() => props.positions.length > 0
    || (!isCompound.value && (types.value[props.holding.type]?.investable || Boolean(props.parent))));

const valueHint = computed(() => {
    if (isCompound.value) return `Sum of ${props.children.length} ${props.children.length === 1 ? 'account' : 'accounts'} inside`;

    return props.holding.positions_count ? `Sum of ${props.holding.positions_count} positions` : '';
});

const rateHint = computed(() => {
    if (isCompound.value) return 'Weighted across the accounts inside';

    return isAsset.value && props.holding.positions_count ? 'Weighted across positions, net of fees' : 'A year';
});

const editing = ref(false);

// The flow form: `flowForm.flow` is the one being edited, or null with
// `direction` set when adding.
const flowForm = ref({ open: false, flow: null, direction: 'income' });
const addFlow = (direction) => { flowForm.value = { open: true, flow: null, direction }; };
const editFlow = (flow) => { flowForm.value = { open: true, flow, direction: flow.direction }; };

// The position form lives here rather than in a component of its own: this
// is the only page positions are edited on.
const positionDialog = ref(null);
const positionOpen = ref(false);
const position = ref(null);
const positionForm = useForm({ name: '', symbol: '', asset_class: 'index_fund', value: 0, expected_return: 7, expense_ratio: 0 });

const openPosition = (existing = null) => {
    position.value = existing;
    positionOpen.value = true;
};

watch(positionOpen, (open) => {
    if (!open) return;

    positionForm.clearErrors();

    if (position.value) {
        Object.keys(positionForm.data()).forEach((key) => {
            positionForm[key] = position.value[key] ?? '';
        });

        return;
    }

    positionForm.reset();
    positionForm.expected_return = classes.value[positionForm.asset_class].return;
});

const onClassChange = () => {
    if (!position.value) {
        positionForm.expected_return = classes.value[positionForm.asset_class].return;
    }
};

const savePosition = () => {
    const options = { preserveScroll: true, onSuccess: () => positionDialog.value?.close() };

    if (position.value) {
        positionForm.patch(`/finance/positions/${position.value.id}`, options);
    } else {
        positionForm.post(`/finance/fleet/${props.holding.id}/positions`, options);
    }
};

const removePosition = (existing) => {
    if (window.confirm(`Remove ${existing.name}?`)) {
        router.delete(`/finance/positions/${existing.id}`, { preserveScroll: true });
    }
};

const removeHolding = () => {
    if (window.confirm(`Remove ${props.holding.name}? Everything inside it — accounts, positions, income and expenses — goes with it.`)) {
        router.delete(`/finance/fleet/${props.holding.id}`);
    }
};

const income = computed(() => props.flows.filter((flow) => flow.direction === 'income'));
const expenses = computed(() => props.flows.filter((flow) => flow.direction === 'expense'));
</script>

<template>
    <FinShell :title="holding.name" :subtitle="[holding.type_label, holding.institution].filter(Boolean).join(' · ')">
        <template #actions>
            <Link v-if="parent" :href="`/finance/fleet/${parent.id}`" class="fin-btn fin-btn-quiet"><IconArrowLeft :size="16" /> {{ parent.name }}</Link>
            <Link v-else href="/finance/fleet" class="fin-btn fin-btn-quiet"><IconArrowLeft :size="16" /> Fleet</Link>
            <button type="button" class="fin-btn fin-btn-quiet" @click="editing = true"><IconPencil :size="16" /> Edit</button>
            <button type="button" class="fin-btn fin-btn-danger" @click="removeHolding"><IconTrash :size="16" /> Remove</button>
        </template>

        <div class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile
                    feature :label="isAsset ? 'Value' : 'Balance owed'" :value="money(holding.value)"
                    :hint="valueHint"
                />
                <StatTile
                    :label="isAsset ? 'Expected growth' : 'Interest rate'" :value="percent(holding.expected_rate)"
                    :hint="rateHint"
                />
                <StatTile
                    v-if="isAsset && secured_debts.length" label="Equity" :value="money(summary.equity)"
                    :hint="`After ${money(summary.debt)} borrowed against it`"
                />
                <StatTile v-else :label="isAsset ? 'Monthly contribution' : 'Monthly payment'" :value="money(holding.contribution)" />
                <StatTile
                    label="Monthly cash flow" :value="moneySigned(summary.monthly_net)"
                    :hint="summary.cash_yield !== null && flows.length ? `${percent(summary.cash_yield)} cash yield a year` : 'From the income and costs below'"
                    :tone="summary.monthly_net >= 0 ? 'good' : 'bad'"
                />
            </div>

            <p v-if="parent" class="text-sm text-fin-grey-600">
                Held inside
                <Link :href="`/finance/fleet/${parent.id}`" class="font-medium text-fin-green-600 hover:underline">{{ parent.name }}</Link>,
                and taxed as it is.
            </p>
            <p v-if="secured_by" class="text-sm text-fin-grey-600">
                Secured against
                <Link :href="`/finance/fleet/${secured_by.id}`" class="font-medium text-fin-green-600 hover:underline">{{ secured_by.name }}</Link>.
            </p>
            <p v-if="secured_debts.length" class="text-sm text-fin-grey-600">
                Borrowed against it:
                <template v-for="(debt, index) in secured_debts" :key="debt.id">
                    <Link :href="`/finance/fleet/${debt.id}`" class="font-medium text-fin-green-600 hover:underline">{{ debt.name }}</Link>
                    ({{ money(debt.value) }})<template v-if="index < secured_debts.length - 1">, </template>
                </template>
            </p>
            <p v-if="holding.notes" class="max-w-3xl whitespace-pre-line rounded-xl bg-fin-cream-100 px-4 py-3 text-sm text-fin-charcoal">{{ holding.notes }}</p>

            <Card
                v-if="canHoldAccounts || isCompound" title="Accounts inside" flush
                subtitle="Optional. Split this account into the accounts it is made of — a brokerage account at each custodian, say — and it is worth their sum."
            >
                <template #actions>
                    <button v-if="canHoldAccounts" type="button" class="fin-btn fin-btn-quiet" @click="addingChild = true"><IconPlus :size="16" /> Account</button>
                </template>

                <p v-if="!isCompound" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">
                    None — this account stands for itself, at the value you gave it{{ holding.positions_count ? ' through its positions' : '' }}.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-5 py-2.5 font-medium">Account</th>
                                <th class="px-3 py-2.5 text-right font-medium">Growth</th>
                                <th class="px-3 py-2.5 text-right font-medium">Adding</th>
                                <th class="px-5 py-2.5 text-right font-medium">Value</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="child in children" :key="child.id" class="border-b border-fin-grey-100 last:border-0 hover:bg-fin-cream-50">
                                <td class="px-5 py-3">
                                    <Link :href="`/finance/fleet/${child.id}`" class="font-medium text-fin-black hover:text-fin-green-600 hover:underline">{{ child.name }}</Link>
                                    <span class="block text-xs text-fin-grey-500">
                                        {{ child.type_label }}<template v-if="child.institution"> · {{ child.institution }}</template>
                                        <template v-if="child.positions_count"> · {{ child.positions_count }} positions</template>
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-right text-fin-grey-600">{{ percent(child.expected_rate) }}</td>
                                <td class="px-3 py-3 text-right text-fin-grey-600">{{ child.contribution ? money(child.contribution) : '—' }}</td>
                                <td class="px-5 py-3 text-right font-medium text-fin-black">{{ money(child.value) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <Card v-if="isAsset && takesPositions" title="Positions" subtitle="What the account holds. Optional — with none, the account is worth the balance you gave it." flush>
                <template #actions>
                    <button type="button" class="fin-btn fin-btn-quiet" @click="openPosition()"><IconPlus :size="16" /> Position</button>
                </template>

                <p v-if="!positions.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">
                    Not itemised. Add funds, stocks or coins to have the value and the growth rate worked out from them.
                </p>

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-5 py-2.5 font-medium">Position</th>
                                <th class="px-3 py-2.5 font-medium">Class</th>
                                <th class="px-3 py-2.5 text-right font-medium">Return</th>
                                <th class="px-3 py-2.5 text-right font-medium">Fee</th>
                                <th class="px-3 py-2.5 text-right font-medium">Value</th>
                                <th class="px-5 py-2.5"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in positions" :key="row.id" class="border-b border-fin-grey-100 last:border-0">
                                <td class="px-5 py-3">
                                    <span class="font-medium text-fin-black">{{ row.name }}</span>
                                    <span v-if="row.symbol" class="ml-2 rounded bg-fin-grey-100 px-1.5 py-0.5 text-[11px] font-medium text-fin-grey-600">{{ row.symbol }}</span>
                                </td>
                                <td class="px-3 py-3 text-fin-grey-600">{{ row.asset_class_label }}</td>
                                <td class="px-3 py-3 text-right text-fin-grey-600">{{ percent(row.expected_return) }}</td>
                                <td class="px-3 py-3 text-right text-fin-grey-600">{{ percent(row.expense_ratio) }}</td>
                                <td class="px-3 py-3 text-right font-medium text-fin-black">{{ money(row.value) }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right">
                                    <button type="button" class="fin-icon-btn" :aria-label="`Edit ${row.name}`" @click="openPosition(row)"><IconPencil :size="16" /></button>
                                    <button type="button" class="fin-icon-btn" :aria-label="`Remove ${row.name}`" @click="removePosition(row)"><IconTrash :size="16" /></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>

            <div class="grid items-start gap-5 xl:grid-cols-2">
                <Card title="Income it produces" :subtitle="`${money(summary.monthly_income)} a month`" flush>
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="addFlow('income')"><IconPlus :size="16" /> Income</button>
                    </template>

                    <p v-if="!income.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">None. Rent, dividends, distributions — whatever this throws off.</p>
                    <FlowTable v-else :flows="income" :show-holding="false" @edit="editFlow" />
                </Card>

                <Card title="What it costs" :subtitle="`${money(summary.monthly_expenses)} a month`" flush>
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="addFlow('expense')"><IconPlus :size="16" /> Expense</button>
                    </template>

                    <p v-if="!expenses.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">None. Insurance, upkeep, management fees, service contracts.</p>
                    <FlowTable v-else :flows="expenses" :show-holding="false" @edit="editFlow" />
                </Card>
            </div>
        </div>

        <HoldingForm :open="editing" :holding="holding" :assets="assets" :parent="parent" @close="editing = false" />
        <HoldingForm :open="addingChild" :parent="holding" @close="addingChild = false" />

        <FlowForm
            :open="flowForm.open" :flow="flowForm.flow" :direction="flowForm.direction" :holding-id="holding.id"
            @close="flowForm.open = false"
        />

        <FinDialog ref="positionDialog" :open="positionOpen" :title="position ? `Edit ${position.name}` : 'Add a position'" @close="positionOpen = false">
            <form class="flex flex-col gap-4" @submit.prevent="savePosition">
                <div class="grid gap-4 sm:grid-cols-3">
                    <Field class="sm:col-span-2" label="Name" :error="positionForm.errors.name">
                        <input v-model="positionForm.name" type="text" required maxlength="80" placeholder="e.g. Total Market ETF">
                    </Field>
                    <Field label="Symbol" hint="Optional" :error="positionForm.errors.symbol">
                        <input v-model="positionForm.symbol" type="text" maxlength="12">
                    </Field>
                </div>

                <Field label="Asset class" :error="positionForm.errors.asset_class">
                    <select v-model="positionForm.asset_class" @change="onClassChange">
                        <option v-for="(assetClass, key) in classes" :key="key" :value="key">{{ assetClass.label }}</option>
                    </select>
                </Field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <Field label="Value" prefix="$" :error="positionForm.errors.value">
                        <input v-model.number="positionForm.value" type="number" min="0" step="0.01" required>
                    </Field>
                    <Field label="Expected return" suffix="% / yr" :error="positionForm.errors.expected_return">
                        <input v-model.number="positionForm.expected_return" type="number" step="0.01" required>
                    </Field>
                    <Field label="Expense ratio" suffix="% / yr" :error="positionForm.errors.expense_ratio">
                        <input v-model.number="positionForm.expense_ratio" type="number" min="0" step="0.001" required>
                    </Field>
                </div>

                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="fin-btn fin-btn-quiet" @click="positionDialog?.close()">Cancel</button>
                    <button type="submit" class="fin-btn fin-btn-primary" :disabled="positionForm.processing">{{ position ? 'Save changes' : 'Add position' }}</button>
                </div>
            </form>
        </FinDialog>
    </FinShell>
</template>
