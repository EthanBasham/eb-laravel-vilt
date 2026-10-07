<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import { IconCopy, IconPencil, IconPlus, IconTrash } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import Card from '../Components/Card.vue';
import CashflowSparkline from '../Components/CashflowSparkline.vue';
import EmptyState from '../Components/EmptyState.vue';
import Field from '../Components/Field.vue';
import FinDialog from '../Components/FinDialog.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import { colorOf, money, moneyBrief, moneyBriefSigned, moneySigned } from '../lib/format';

const props = defineProps({
    horizon: Object,
    has_flows: Boolean,
    baseline: Object,
    scenarios: Array,
});

const dialog = ref(null);
const open = ref(false);
const editing = ref(null);
const form = useForm({ name: '', description: '' });

const show = (scenario = null) => {
    editing.value = scenario;
    open.value = true;
};

watch(open, (isOpen) => {
    if (!isOpen) return;

    form.clearErrors();

    if (editing.value) {
        form.name = editing.value.name;
        form.description = editing.value.description ?? '';

        return;
    }

    form.reset();
});

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (editing.value) {
        form.patch(`/finance/scenarios/${editing.value.id}`, options);
    } else {
        form.post('/finance/scenarios', options);
    }
};

const duplicate = (scenario) => router.post(`/finance/scenarios/${scenario.id}/duplicate`);

const remove = (scenario) => {
    if (window.confirm(`Remove ${scenario.name}? Your incomes and expenses are not touched.`)) {
        router.delete(`/finance/scenarios/${scenario.id}`, { preserveScroll: true });
    }
};

// A left-over figure: ink when there is money left, bold red when there is not.
const tone = (amount) => (amount < 0 ? 'font-bold text-fin-red-600' : 'text-fin-black');

// A year on a chart axis, with the age it is reached at: "2038 (62)".
const ages = computed(() => Object.fromEntries(props.baseline.totals.map((year) => [year.year, year.age])));
const yearAndAge = (year) => (year in ages.value ? `${year} (${ages.value[year]})` : String(year));

const net = (totals) => totals.map((year) => ({ x: year.year, y: year.net }));

// What is left each year: the baseline dashed, a line per scenario over it.
const lines = computed(() => [
    { label: 'Nothing adjusted', color: 'var(--color-fin-grey-400)', dashed: true, points: net(props.baseline.totals) },
    ...props.scenarios.map((scenario, index) => ({ label: scenario.name, color: colorOf(index), points: net(scenario.totals) })),
]);
</script>

<template>
    <FinShell title="Projections & scenarios" :subtitle="`Different futures for the same incomes and expenses, from ${horizon.from} to ${horizon.to}: give each a rate of its own, then move individual years by hand.`">
        <template #actions>
            <Link href="/finance/cashflow" class="fin-btn fin-btn-quiet">Income &amp; expenses</Link>
            <button type="button" class="fin-btn fin-btn-primary" @click="show()"><IconPlus :size="16" /> Scenario</button>
        </template>

        <div class="flex flex-col gap-5">
            <p v-if="!has_flows" class="rounded-xl border border-fin-gold-300 bg-fin-gold-100 px-4 py-3 text-sm text-fin-charcoal">
                There are no incomes or expenses to project yet. <Link href="/finance/cashflow" class="font-medium underline">Add some</Link> and every scenario picks them up.
            </p>

            <EmptyState v-if="!scenarios.length" title="No scenarios yet" body="Start with one — Optimistic, say, with raises every year — then copy it and change what differs.">
                <button type="button" class="fin-btn fin-btn-primary" @click="show()"><IconPlus :size="16" /> Add a scenario</button>
            </EmptyState>

            <template v-else>
                <Card v-if="has_flows" title="What is left each year" subtitle="Income less tax and expenses, in the dollars of each year. Green is money to spare and red is a shortfall; a ring marks where a line crosses between them. The dashed line is every flow as entered, with nothing adjusted.">
                    <LineChart :series="lines" :height="260" :x-ticks="6" :format-x="yearAndAge" zero-bands />
                </Card>

                <Card title="Scenarios" :subtitle="`Over the whole plan, ${horizon.from}–${horizon.to}. Left over is income less tax and expenses.`" flush>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                    <th class="px-5 py-2.5 font-medium">Name</th>
                                    <th class="whitespace-nowrap px-3 py-2.5 text-right font-medium">Against nothing adjusted</th>
                                    <th class="px-3 py-2.5 font-medium">Left over <span class="font-normal">· whole plan [leanest year — best year]</span></th>
                                    <th class="px-3 py-2.5 font-medium">Income after tax against expenses</th>
                                    <th class="px-5 py-2.5"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(scenario, index) in scenarios" :key="scenario.id" class="border-b border-fin-grey-100 align-middle last:border-0 hover:bg-fin-cream-50">
                                    <td class="px-5 py-3">
                                        <Link :href="`/finance/scenarios/${scenario.id}`" class="flex items-center gap-2 font-medium text-fin-black hover:underline">
                                            <span class="h-2.5 w-2.5 shrink-0 rounded-full" :style="{ backgroundColor: colorOf(index) }" aria-hidden="true" />
                                            {{ scenario.name }}
                                        </Link>
                                        <span class="mt-0.5 block pl-[1.125rem] text-xs text-fin-grey-500">
                                            {{ scenario.description || (scenario.adjusted_count ? '' : 'Nothing adjusted yet.') }}
                                            <template v-if="scenario.adjusted_count">{{ scenario.description ? ' · ' : '' }}{{ scenario.adjusted_count }} adjusted</template>
                                        </span>
                                    </td>
                                    <td class="px-3 py-3 text-right font-medium" :class="scenario.net_vs_baseline >= 0 ? 'text-fin-green-600' : 'text-fin-red-600'" :title="moneySigned(scenario.net_vs_baseline)">
                                        {{ scenario.net_vs_baseline === 0 ? '—' : moneyBriefSigned(scenario.net_vs_baseline) }}
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-3 text-fin-grey-500">
                                        <template v-if="scenario.left_over">
                                            <span :class="tone(scenario.left_over.total)" :title="money(scenario.left_over.total)">{{ moneyBrief(scenario.left_over.total) }}</span>
                                            [<span :class="tone(scenario.left_over.min.amount)" :title="money(scenario.left_over.min.amount)">{{ moneyBrief(scenario.left_over.min.amount) }}</span> ({{ scenario.left_over.min.year }})
                                            &mdash;
                                            <span :class="tone(scenario.left_over.max.amount)" :title="money(scenario.left_over.max.amount)">{{ moneyBrief(scenario.left_over.max.amount) }}</span> ({{ scenario.left_over.max.year }})]
                                        </template>
                                        <template v-else>—</template>
                                    </td>
                                    <td class="px-3 py-2">
                                        <CashflowSparkline v-if="scenario.totals.length" :totals="scenario.totals" />
                                    </td>
                                    <td class="whitespace-nowrap px-5 py-3 text-right">
                                        <button type="button" class="fin-icon-btn" :aria-label="`Rename ${scenario.name}`" @click="show(scenario)"><IconPencil :size="16" /></button>
                                        <button type="button" class="fin-icon-btn" :aria-label="`Copy ${scenario.name}`" @click="duplicate(scenario)"><IconCopy :size="16" /></button>
                                        <button type="button" class="fin-icon-btn" :aria-label="`Remove ${scenario.name}`" @click="remove(scenario)"><IconTrash :size="16" /></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>
            </template>
        </div>

        <FinDialog ref="dialog" :open="open" :title="editing ? `Rename ${editing.name}` : 'Add a scenario'" @close="open = false">
            <form class="flex flex-col gap-4" @submit.prevent="save">
                <Field label="Name" :error="form.errors.name">
                    <input v-model="form.name" type="text" required maxlength="80" placeholder="e.g. Optimistic">
                </Field>
                <Field label="What it assumes" hint="Optional. A line to remember it by." :error="form.errors.description">
                    <input v-model="form.description" type="text" maxlength="200" placeholder="e.g. Raises every year, rent keeps pace">
                </Field>

                <div class="mt-2 flex justify-end gap-2">
                    <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                    <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ editing ? 'Save changes' : 'Add scenario' }}</button>
                </div>
            </form>
        </FinDialog>
    </FinShell>
</template>
