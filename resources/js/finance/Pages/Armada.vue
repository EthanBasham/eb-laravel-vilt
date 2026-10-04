<script setup>
import { Link, router } from '@inertiajs/vue3';
import { IconArrowLeft, IconLogout2, IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import ArmadaForm from '../Components/ArmadaForm.vue';
import Card from '../Components/Card.vue';
import FinShell from '../Components/FinShell.vue';
import FlowForm from '../Components/FlowForm.vue';
import FlowTable from '../Components/FlowTable.vue';
import HoldingForm from '../Components/HoldingForm.vue';
import LineChart from '../Components/LineChart.vue';
import StatTile from '../Components/StatTile.vue';
import { chartColors, money, moneyExact, moneyShort, moneySigned, percent } from '../lib/format';

/**
 * One armada: what is in it, what it comes to, and where it is headed with
 * everything projected as it stands.
 */
const props = defineProps({
    armada: Object,
    totals: Object,
    cashflow: Object,
    holdings_count: Number,
    flows_count: Number,
    top_holdings: Array,
    assets: Array,
    liabilities: Array,
    income: Array,
    expenses: Array,
    projection: Array,
    unassigned: Object,
    others: Array,
    holdings: Array,
});

const renaming = ref(false);
const addingHolding = ref(null);
const flowForm = ref({ open: false, flow: null, direction: 'income' });

const addFlow = (direction) => { flowForm.value = { open: true, flow: null, direction }; };
const editFlow = (flow) => { flowForm.value = { open: true, flow, direction: flow.direction }; };

const assign = (armadaId, holdings = [], flows = []) => router.put('/finance/armadas/assign', { armada_id: armadaId, holdings, flows }, { preserveScroll: true });

// "h12" or "f7" from the bring-in select: which kind of row, and its id.
const bringing = ref('');

const bringIn = () => {
    const id = Number(bringing.value.slice(1));

    assign(props.armada.id, bringing.value.startsWith('h') ? [id] : [], bringing.value.startsWith('f') ? [id] : []);
    bringing.value = '';
};

const hasUnassigned = computed(() => props.unassigned.holdings.length + props.unassigned.flows.length > 0);

const disband = () => {
    if (window.confirm(`Disband ${props.armada.name}? Everything in it stays in your fleet, unassigned.`)) {
        router.delete(`/finance/armadas/${props.armada.id}`);
    }
};

const sides = computed(() => [
    { key: 'asset', title: 'Assets', rows: props.assets, rate: 'Growth', monthly: 'Adding', value: 'Value', empty: 'No assets in this armada.' },
    { key: 'liability', title: 'Liabilities', rows: props.liabilities, rate: 'APR', monthly: 'Paying', value: 'Owed', empty: 'No debts in this armada.' },
]);

const line = (key) => props.projection.map((year) => ({ x: year.year, y: year[key] }));

const worthLines = computed(() => [
    { label: 'Assets', color: chartColors[0], points: line('assets') },
    { label: 'Debts', color: chartColors[1], points: line('liabilities') },
    { label: 'Net worth', color: chartColors[2], dashed: true, points: line('net_worth') },
]);

const cashLines = computed(() => [
    { label: 'Income', color: chartColors[0], points: line('income') },
    { label: 'Expenses', color: chartColors[1], points: line('expenses') },
    { label: 'Value gained', color: chartColors[3], dashed: true, points: line('growth') },
]);

const ages = computed(() => Object.fromEntries(props.projection.map((year) => [year.year, year.age])));
const yearAndAge = (year) => (year in ages.value ? `${year} (${ages.value[year]})` : String(year));

const last = computed(() => props.projection[props.projection.length - 1]);
</script>

<template>
    <FinShell :title="armada.name" :subtitle="armada.description || 'An armada: holdings, and the income and expenses that come with them.'">
        <template #actions>
            <Link href="/finance/armadas" class="fin-btn fin-btn-quiet"><IconArrowLeft :size="16" /> All armadas</Link>
            <button type="button" class="fin-btn fin-btn-quiet" @click="renaming = true"><IconPencil :size="16" /> Rename</button>
            <button type="button" class="fin-btn fin-btn-danger" @click="disband"><IconTrash :size="16" /> Disband</button>
        </template>

        <div class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatTile feature label="Net worth" :value="money(totals.net_worth)" :hint="last ? `${moneyShort(last.net_worth)} by ${last.year}, as it stands` : ''" />
                <StatTile label="Assets" :value="money(totals.assets)" :hint="`${assets.length} in this armada`" />
                <StatTile label="Debts" :value="money(totals.liabilities)" :hint="`${liabilities.length} in this armada`" />
                <StatTile label="Left each month" :value="moneySigned(cashflow.net)" :hint="`${money(cashflow.income)} in, ${money(cashflow.expenses)} out, before tax`" :tone="cashflow.net < 0 ? 'bad' : 'good'" />
            </div>

            <Card v-if="hasUnassigned" title="Bring something in" subtitle="What is in no armada yet. Income and costs hung off a holding come with it.">
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="bringIn">
                    <select v-model="bringing" class="!w-auto min-w-64" aria-label="Holding or flow to bring into this armada" required>
                        <option value="" disabled>Choose a holding, an income or an expense…</option>
                        <optgroup v-if="unassigned.holdings.length" label="Holdings">
                            <option v-for="holding in unassigned.holdings" :key="holding.id" :value="`h${holding.id}`">{{ holding.name }} · {{ holding.type_label }}</option>
                        </optgroup>
                        <optgroup v-if="unassigned.flows.length" label="Income and expenses">
                            <option v-for="flow in unassigned.flows" :key="flow.id" :value="`f${flow.id}`">{{ flow.name }} · {{ flow.category_label }}</option>
                        </optgroup>
                    </select>
                    <button type="submit" class="fin-btn fin-btn-primary" :disabled="!bringing">Bring in</button>
                </form>
            </Card>

            <div class="grid items-start gap-5 xl:grid-cols-2">
                <Card v-for="side in sides" :key="side.key" :title="side.title" flush>
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="addingHolding = side.key"><IconPlus :size="16" /> Add</button>
                    </template>

                    <p v-if="!side.rows.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">{{ side.empty }}</p>

                    <table v-else class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-5 py-2 font-medium">Holding</th>
                                <th class="px-3 py-2 text-right font-medium">{{ side.rate }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ side.monthly }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ side.value }}</th>
                                <th class="px-5 py-2"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="holding in side.rows" :key="holding.id" class="border-b border-fin-grey-100 last:border-0">
                                <td class="px-5 py-3">
                                    <Link :href="`/finance/fleet/${holding.id}`" class="font-medium text-fin-black hover:text-fin-green-600 hover:underline">{{ holding.name }}</Link>
                                    <span class="block text-xs text-fin-grey-500">{{ holding.type_label }}</span>
                                </td>
                                <td class="px-3 py-3 text-right text-fin-grey-600">{{ percent(holding.expected_rate) }}</td>
                                <td class="px-3 py-3 text-right text-fin-grey-600">{{ holding.contribution ? moneyExact(holding.contribution) : '—' }}</td>
                                <td class="px-3 py-3 text-right font-medium text-fin-black">{{ money(holding.value) }}</td>
                                <td class="px-5 py-3 text-right">
                                    <button type="button" class="fin-icon-btn" :aria-label="`Take ${holding.name} out of this armada`" title="Take out of this armada" @click="assign(null, [holding.id])"><IconLogout2 :size="16" /></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </Card>
            </div>

            <div class="grid items-start gap-5 xl:grid-cols-2">
                <Card title="Income" :subtitle="`${money(cashflow.income)} a month, before tax`" flush>
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="addFlow('income')"><IconPlus :size="16" /> Add</button>
                    </template>
                    <p v-if="!income.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">No income in this armada.</p>
                    <FlowTable v-else :flows="income" @edit="editFlow" />
                </Card>

                <Card title="Expenses" :subtitle="`${money(cashflow.expenses)} a month`" flush>
                    <template #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="addFlow('expense')"><IconPlus :size="16" /> Add</button>
                    </template>
                    <p v-if="!expenses.length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">No expenses in this armada.</p>
                    <FlowTable v-else :flows="expenses" @edit="editFlow" />
                </Card>
            </div>

            <div v-if="projection.length" class="grid items-start gap-5 xl:grid-cols-2">
                <Card title="What it is worth, year by year" subtitle="Every holding in the armada as it stands, in the dollars of each year.">
                    <LineChart :series="worthLines" :height="240" :x-ticks="6" :format-x="yearAndAge" />
                </Card>
                <Card title="Cash against value" subtitle="What the armada brings in and spends each year, beside what its holdings gain in value — two different kinds of return.">
                    <LineChart :series="cashLines" :height="240" :x-ticks="6" :format-x="yearAndAge" />
                </Card>
            </div>

            <p class="text-xs text-fin-grey-500">
                Income and expenses here are before tax: tax is worked out on all of a household's income together and has no share to hand one armada.
                To change how any of this is projected, use <Link href="/finance/scenarios" class="text-fin-green-600 hover:underline">Projections &amp; scenarios</Link>.
                <template v-if="others.length"> To move something to another armada, edit it and pick the armada there.</template>
            </p>
        </div>

        <ArmadaForm :open="renaming" :armada="armada" @close="renaming = false" />
        <HoldingForm :open="addingHolding !== null" :side="addingHolding ?? 'asset'" :assets="assets" :armada-id="armada.id" @close="addingHolding = null" />
        <FlowForm :open="flowForm.open" :flow="flowForm.flow" :direction="flowForm.direction" :holdings="holdings" :armada-id="armada.id" @close="flowForm.open = false" />
    </FinShell>
</template>
