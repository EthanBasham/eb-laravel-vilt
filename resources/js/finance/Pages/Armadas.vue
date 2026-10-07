<script setup>
import { Link, router } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed, reactive, ref } from 'vue';
import ArmadaForm from '../Components/ArmadaForm.vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import StatTile from '../Components/StatTile.vue';
import { money, moneyExact, moneySigned, signTone } from '../lib/format';

/**
 * The fleet in named parts. Each armada is a card with what it comes to;
 * whatever is in none of them is listed underneath, each row with a select
 * that sends it to one.
 */
const props = defineProps({
    armadas: Array,
    unassigned: Object,
    totals: Object,
    is_empty: Boolean,
});

const launching = ref(false);

const hasUnassigned = computed(() => props.unassigned.holdings.length + props.unassigned.flows.length > 0);

// Which rows are ticked, to be sent to an armada together.
const picked = reactive({ holdings: [], flows: [] });
const pickedCount = computed(() => picked.holdings.length + picked.flows.length);
const target = ref(null);

const assign = () => {
    if (target.value === null || !pickedCount.value) return;

    router.put('/finance/armadas/assign', { armada_id: target.value, holdings: picked.holdings, flows: picked.flows }, {
        preserveScroll: true,
        onSuccess: () => {
            picked.holdings = [];
            picked.flows = [];
        },
    });
};
</script>

<template>
    <FinShell title="Armadas" subtitle="Your fleet in parts you name yourself — the household, the rentals, retirement, a business — each with the assets, debts, income and expenses that belong together.">
        <template #actions>
            <button type="button" class="fin-btn fin-btn-primary" @click="launching = true"><IconPlus :size="16" /> Armada</button>
        </template>

        <EmptyState v-if="!armadas.length" title="No armadas yet" :body="is_empty ? 'An armada groups holdings with their income and expenses. Add something to your fleet first, or launch an armada and fill it as you go.' : 'Launch one and bring in what belongs together: a Real estate armada for the rentals and their insurance, a Foundational one for the house, the cars, the salary and the bills.'">
            <button type="button" class="fin-btn fin-btn-primary" @click="launching = true"><IconPlus :size="16" /> Launch an armada</button>
        </EmptyState>

        <div v-else class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile feature label="Whole fleet" :value="money(totals.net_worth)" hint="Net worth, every armada and none" />
                <StatTile label="Armadas" :value="String(armadas.length)" />
                <StatTile
                    label="Not in an armada" :value="String(unassigned.holdings.length + unassigned.flows.length)"
                    :hint="hasUnassigned ? 'Listed below' : 'Everything has a home'" :tone="hasUnassigned ? 'gold' : 'good'"
                />
            </div>

            <div class="grid items-start gap-5 lg:grid-cols-2 2xl:grid-cols-3">
                <Card v-for="armada in armadas" :key="armada.id" :title="armada.name" :subtitle="armada.description ?? ''">
                    <template #actions>
                        <Link :href="`/finance/armadas/${armada.id}`" class="fin-btn fin-btn-quiet">Open</Link>
                    </template>

                    <dl class="mt-2 grid grid-cols-3 gap-3 text-sm">
                        <div>
                            <dt class="text-xs text-fin-grey-500">Net worth</dt>
                            <dd class="mt-0.5 font-semibold text-fin-black">{{ money(armada.totals.net_worth) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fin-grey-500">Assets</dt>
                            <dd class="mt-0.5 text-fin-charcoal">{{ money(armada.totals.assets) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fin-grey-500">Debts</dt>
                            <dd class="mt-0.5 text-fin-charcoal">{{ money(armada.totals.liabilities) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fin-grey-500">Left a month</dt>
                            <dd class="mt-0.5 font-semibold" :class="signTone(armada.cashflow.net)">{{ moneySigned(armada.cashflow.net) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fin-grey-500">Income</dt>
                            <dd class="mt-0.5 text-fin-charcoal">{{ money(armada.cashflow.income) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fin-grey-500">Expenses</dt>
                            <dd class="mt-0.5 text-fin-charcoal">{{ money(armada.cashflow.expenses) }}</dd>
                        </div>
                    </dl>

                    <ul v-if="armada.top_holdings.length" class="mt-4 flex flex-col gap-1.5 border-t border-fin-grey-100 pt-3 text-sm">
                        <li v-for="holding in armada.top_holdings" :key="holding.id" class="flex justify-between gap-3">
                            <Link :href="`/finance/fleet/${holding.id}`" class="truncate text-fin-charcoal hover:text-fin-green-600 hover:underline">{{ holding.name }}</Link>
                            <span class="tabular-nums" :class="holding.side === 'asset' ? 'text-fin-black' : 'text-fin-grey-500'">{{ holding.side === 'asset' ? '' : '−' }}{{ money(holding.value) }}</span>
                        </li>
                    </ul>
                    <p v-else class="mt-4 border-t border-fin-grey-100 pt-3 text-sm text-fin-grey-500">Nothing in it yet.</p>

                    <p class="mt-3 text-xs text-fin-grey-500">
                        {{ armada.holdings_count }} {{ armada.holdings_count === 1 ? 'holding' : 'holdings' }} · {{ armada.flows_count }} income and expense {{ armada.flows_count === 1 ? 'line' : 'lines' }}
                    </p>
                </Card>
            </div>

            <Card v-if="hasUnassigned" title="Not in an armada" subtitle="Tick what belongs together and send it to one. Income and costs hung off a holding go where the holding goes." flush>
                <template #actions>
                    <form class="flex items-center gap-2" @submit.prevent="assign">
                        <select v-model="target" class="!w-44" aria-label="Armada to send the ticked rows to" required>
                            <option :value="null" disabled>Send to…</option>
                            <option v-for="armada in armadas" :key="armada.id" :value="armada.id">{{ armada.name }}</option>
                        </select>
                        <button type="submit" class="fin-btn fin-btn-primary" :disabled="!pickedCount || target === null">Move {{ pickedCount || '' }}</button>
                    </form>
                </template>

                <table class="w-full text-sm">
                    <tbody>
                        <tr v-for="holding in unassigned.holdings" :key="`h${holding.id}`" class="border-t border-fin-grey-100">
                            <td class="w-10 py-2.5 pl-5"><input v-model="picked.holdings" type="checkbox" :value="holding.id" :aria-label="`Pick ${holding.name}`"></td>
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-fin-black">{{ holding.name }}</span>
                                <span class="block text-xs text-fin-grey-500">{{ holding.type_label }}</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-fin-charcoal">{{ holding.side === 'asset' ? '' : '−' }}{{ money(holding.value) }}</td>
                        </tr>
                        <tr v-for="flow in unassigned.flows" :key="`f${flow.id}`" class="border-t border-fin-grey-100">
                            <td class="w-10 py-2.5 pl-5"><input v-model="picked.flows" type="checkbox" :value="flow.id" :aria-label="`Pick ${flow.name}`"></td>
                            <td class="px-3 py-2.5">
                                <span class="font-medium text-fin-black">{{ flow.name }}</span>
                                <span class="block text-xs text-fin-grey-500">{{ flow.direction === 'income' ? 'Income' : 'Expense' }} · {{ flow.category_label }}</span>
                            </td>
                            <td class="px-5 py-2.5 text-right tabular-nums text-fin-charcoal">{{ moneyExact(flow.monthly_amount) }} <span class="text-xs text-fin-grey-500">/ mo</span></td>
                        </tr>
                    </tbody>
                </table>
            </Card>
        </div>

        <ArmadaForm :open="launching" @close="launching = false" />
    </FinShell>
</template>
