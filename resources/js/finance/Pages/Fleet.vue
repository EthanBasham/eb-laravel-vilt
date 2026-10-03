<script setup>
import { Link } from '@inertiajs/vue3';
import { IconPlus } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import HoldingForm from '../Components/HoldingForm.vue';
import StatTile from '../Components/StatTile.vue';
import { money, moneyExact, percent } from '../lib/format';

const props = defineProps({
    assets: Array,
    liabilities: Array,
    totals: Object,
});

// `adding` is the side a new holding starts on, or null when the form is shut.
const adding = ref(null);

/*
 * Holdings arrive largest-first; this only buckets them under their group
 * heading ("Retirement", "Property") in the order each group first appears,
 * so the biggest group leads.
 */
const grouped = (holdings) => holdings.reduce((groups, holding) => {
    (groups[holding.group] ??= []).push(holding);

    return groups;
}, {});

const sides = computed(() => [
    { key: 'asset', title: 'Assets', total: props.totals.assets, groups: grouped(props.assets), rate: 'Growth', monthly: 'Adding', empty: 'No assets yet.' },
    { key: 'liability', title: 'Liabilities', total: props.totals.liabilities, groups: grouped(props.liabilities), rate: 'APR', monthly: 'Paying', empty: 'No debts recorded.' },
]);

const isEmpty = computed(() => !props.assets.length && !props.liabilities.length);
</script>

<template>
    <FinShell title="Fleet" subtitle="Every asset and every liability. Open one to itemise what is inside it and attach the income and costs that come with it.">
        <template #actions>
            <button type="button" class="fin-btn fin-btn-quiet" @click="adding = 'liability'"><IconPlus :size="16" /> Liability</button>
            <button type="button" class="fin-btn fin-btn-primary" @click="adding = 'asset'"><IconPlus :size="16" /> Asset</button>
        </template>

        <EmptyState v-if="isEmpty" title="Nothing in the fleet yet" body="Start with whatever is easiest — a savings account is a name, a balance and a rate.">
            <button type="button" class="fin-btn fin-btn-primary" @click="adding = 'asset'"><IconPlus :size="16" /> Add an asset</button>
            <Link href="/finance" class="fin-btn fin-btn-quiet">Or load the sample from the overview</Link>
        </EmptyState>

        <div v-else class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile feature label="Net worth" :value="money(totals.net_worth)" />
                <StatTile label="Assets" :value="money(totals.assets)" :hint="`${assets.length} holdings`" />
                <StatTile label="Liabilities" :value="money(totals.liabilities)" :hint="`${liabilities.length} debts`" />
            </div>

            <div class="grid items-start gap-5 xl:grid-cols-2">
                <Card v-for="side in sides" :key="side.key" :title="side.title" :subtitle="money(side.total)" flush>
                    <p v-if="!Object.keys(side.groups).length" class="border-t border-fin-grey-100 px-5 py-6 text-sm text-fin-grey-500">{{ side.empty }}</p>

                    <table v-else class="w-full text-sm">
                        <template v-for="(holdings, group) in side.groups" :key="group">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                    <th class="px-5 py-2 font-medium">{{ group }}</th>
                                    <th class="px-3 py-2 text-right font-medium">{{ side.rate }}</th>
                                    <th class="px-3 py-2 text-right font-medium">{{ side.monthly }}</th>
                                    <th class="px-5 py-2 text-right font-medium">{{ side.key === 'asset' ? 'Value' : 'Owed' }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="holding in holdings" :key="holding.id" class="border-b border-fin-grey-100 last:border-0 hover:bg-fin-cream-50">
                                    <td class="px-5 py-3">
                                        <Link :href="`/finance/fleet/${holding.id}`" class="font-medium text-fin-black hover:text-fin-green-600 hover:underline">{{ holding.name }}</Link>
                                        <span class="block text-xs text-fin-grey-500">
                                            {{ holding.type_label }}<template v-if="holding.institution"> · {{ holding.institution }}</template>
                                            <template v-if="holding.children_count"> · holds {{ holding.children_count }} {{ holding.children_count === 1 ? 'account' : 'accounts' }}</template>
                                            <template v-else-if="holding.positions_count"> · {{ holding.positions_count }} positions</template>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-right text-fin-grey-600">{{ percent(holding.expected_rate) }}</td>
                                    <td class="px-3 py-3 text-right text-fin-grey-600">{{ holding.contribution ? moneyExact(holding.contribution) : '—' }}</td>
                                    <td class="px-5 py-3 text-right font-medium text-fin-black">{{ money(holding.value) }}</td>
                                </tr>
                            </tbody>
                        </template>
                    </table>
                </Card>
            </div>
        </div>

        <HoldingForm :open="adding !== null" :side="adding ?? 'asset'" :assets="assets" @close="adding = null" />
    </FinShell>
</template>
