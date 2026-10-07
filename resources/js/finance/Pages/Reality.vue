<script setup>
import { router, useForm } from '@inertiajs/vue3';
import { IconCamera, IconTrash } from '@tabler/icons-vue';
import { computed } from 'vue';
import Card from '../Components/Card.vue';
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import LineChart from '../Components/LineChart.vue';
import StatTile from '../Components/StatTile.vue';
import { asDate, chartColors, money, moneySigned } from '../lib/format';

const props = defineProps({
    current: Object,
    has_snapshot_today: Boolean,
    snapshots: Array,
    actual: Array,
    projected: Array,
    budget: Array,
});

const form = useForm({ note: '' });

const snap = () => form.post('/finance/reality/snapshots', { preserveScroll: true, onSuccess: () => form.reset() });

const remove = (snapshot) => {
    if (window.confirm(`Remove the snapshot from ${asDate(snapshot.taken_on)}?`)) {
        router.delete(`/finance/reality/snapshots/${snapshot.id}`, { preserveScroll: true });
    }
};

/*
 * Dates become timestamps so the chart can space them by how far apart they
 * really are — snapshots are taken whenever someone remembers, not monthly.
 * Local noon, for the reason lib/format.js gives.
 */
const at = (date) => new Date(`${date}T12:00:00`).getTime();
const monthYear = (stamp) => new Date(stamp).toLocaleDateString(undefined, { month: 'short', year: '2-digit' });

const lines = computed(() => [
    { label: 'Projected at the first snapshot', color: chartColors[1], dashed: true, points: props.projected.map((point) => ({ x: at(point.date), y: point.value })) },
    { label: 'Reality', color: chartColors[0], points: props.actual.map((point) => ({ x: at(point.date), y: point.value })) },
]);

const latest = computed(() => props.snapshots[0]);
</script>

<template>
    <FinShell title="Projected vs reality" subtitle="A snapshot records your totals on a day, along with where the projector thought they were heading. Take them now and then, and this page holds the forecast to account.">
        <template #actions>
            <form class="flex items-center gap-2" @submit.prevent="snap">
                <input v-model="form.note" type="text" maxlength="120" placeholder="Note (optional)" class="w-48" aria-label="Snapshot note">
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">
                    <IconCamera :size="16" /> {{ has_snapshot_today ? 'Retake today\'s snapshot' : 'Take a snapshot' }}
                </button>
            </form>
        </template>

        <EmptyState v-if="!snapshots.length" title="No snapshots yet" :body="`Your net worth today is ${money(current.net_worth)}. Take a snapshot to record it — the first one becomes the baseline every later one is measured against.`" />

        <div v-else class="flex flex-col gap-5">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile feature label="Net worth today" :value="money(current.net_worth)" />
                <StatTile label="Last snapshot" :value="money(latest.net_worth)" :hint="asDate(latest.taken_on)" />
                <StatTile
                    label="Against the projection" :value="latest.variance === null ? '—' : moneySigned(latest.variance)"
                    :hint="latest.variance === null ? 'Outside the projected window' : (latest.variance >= 0 ? 'Ahead of where you expected to be' : 'Behind where you expected to be')"
                    :tone="latest.variance === null ? undefined : (latest.variance >= 0 ? 'good' : 'bad')"
                />
            </div>

            <Card title="Net worth" subtitle="The dashed line is what the first snapshot projected. The solid one is what each snapshot since has found.">
                <LineChart :series="lines" :format-x="monthYear" :from-zero="false" :x-ticks="7" />
            </Card>

            <div class="grid items-start gap-5 xl:grid-cols-3">
                <Card class="xl:col-span-2" title="Snapshots" flush>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                    <th class="px-5 py-2.5 font-medium">Taken</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Assets</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Liabilities</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Net worth</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Projected</th>
                                    <th class="px-3 py-2.5 text-right font-medium">Difference</th>
                                    <th class="px-5 py-2.5"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="snapshot in snapshots" :key="snapshot.id" class="border-b border-fin-grey-100 last:border-0">
                                    <td class="px-5 py-3">
                                        <span class="font-medium text-fin-black">{{ asDate(snapshot.taken_on) }}</span>
                                        <span v-if="snapshot.note" class="block text-xs text-fin-grey-500">{{ snapshot.note }}</span>
                                    </td>
                                    <td class="px-3 py-3 text-right text-fin-charcoal">{{ money(snapshot.assets) }}</td>
                                    <td class="px-3 py-3 text-right text-fin-charcoal">{{ money(snapshot.liabilities) }}</td>
                                    <td class="px-3 py-3 text-right font-medium text-fin-black">{{ money(snapshot.net_worth) }}</td>
                                    <td class="px-3 py-3 text-right text-fin-grey-600">{{ money(snapshot.expected) }}</td>
                                    <td class="px-3 py-3 text-right font-medium" :class="snapshot.variance === null ? 'text-fin-grey-400' : (snapshot.variance >= 0 ? 'text-fin-green-600' : 'text-fin-red-600')">
                                        {{ moneySigned(snapshot.variance) }}
                                    </td>
                                    <td class="px-5 py-3 text-right">
                                        <button type="button" class="fin-icon-btn" :aria-label="`Remove the snapshot from ${asDate(snapshot.taken_on)}`" @click="remove(snapshot)"><IconTrash :size="16" /></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card title="Budget, month by month" subtitle="Net of the lines you recorded an actual for — planned against what happened." flush>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-5 py-2.5 font-medium">Month</th>
                                <th class="px-3 py-2.5 text-right font-medium">Planned</th>
                                <th class="px-3 py-2.5 text-right font-medium">Actual</th>
                                <th class="px-5 py-2.5 text-right font-medium">Lines</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="month in budget" :key="month.month" class="border-b border-fin-grey-100 last:border-0">
                                <td class="px-5 py-2.5 font-medium text-fin-black">{{ month.label }}</td>
                                <td class="px-3 py-2.5 text-right text-fin-charcoal">{{ month.tracked ? moneySigned(month.planned) : '—' }}</td>
                                <td class="px-3 py-2.5 text-right font-medium" :class="!month.tracked ? 'text-fin-grey-400' : (month.actual >= month.planned ? 'text-fin-green-600' : 'text-fin-red-600')">
                                    {{ month.tracked ? moneySigned(month.actual) : '—' }}
                                </td>
                                <td class="px-5 py-2.5 text-right text-fin-grey-500">{{ month.tracked }}</td>
                            </tr>
                        </tbody>
                    </table>
                </Card>
            </div>
        </div>
    </FinShell>
</template>
