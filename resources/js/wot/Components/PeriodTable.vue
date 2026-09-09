<script setup>
defineProps({
    overall: { type: Object, required: true },
    history: { type: Object, required: true },
});

const number = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat().format(v));
const pct = (v) => (v === null || v === undefined ? '—' : `${v}%`);
const raw = (v) => (v === null || v === undefined ? '—' : v);

const columns = [
    { key: 'battles', label: 'Battles', format: number },
    { key: 'avg_tier', label: 'Avg tier', format: raw },
    { key: 'win_rate', label: 'Win rate', format: pct },
    { key: 'survival_rate', label: 'Survival', format: pct },
    { key: 'avg_damage', label: 'Avg dmg', format: number },
    { key: 'avg_assist', label: 'Avg assist', format: number },
    { key: 'damage_ratio', label: 'Dmg ratio', format: raw },
    { key: 'avg_frags', label: 'Frags', format: raw },
    { key: 'kd_ratio', label: 'K/D', format: raw },
    { key: 'wn8', label: 'WN8', format: number },
];
</script>

<template>
    <section aria-labelledby="periods-heading">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="periods-heading" class="text-xl">Performance</h2>
            <p v-if="history.history_since" class="text-xs text-wot-dim">
                Tracking since {{ new Date(history.history_since).toLocaleString() }}
            </p>
        </div>

        <div class="mt-4 overflow-x-auto border border-wot-border bg-wot-panel">
            <table class="min-w-full divide-y divide-wot-border text-sm">
                <thead class="bg-wot-sunken">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">
                            Period
                        </th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-wot-border-soft">
                    <tr class="bg-wot-sunken/40">
                        <th scope="row" class="px-4 py-2 text-left font-bold text-wot-heading">Overall</th>
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            class="px-4 py-2 text-right tabular-nums"
                            :class="column.key === 'wn8' ? `wn8-${overall.wn8_band}` : 'text-wot-muted'"
                        >
                            {{ column.format(overall[column.key]) }}
                        </td>
                    </tr>

                    <tr v-for="period in history.periods" :key="period.label" class="hover:bg-wot-sunken">
                        <th scope="row" class="px-4 py-2 text-left font-medium text-wot-text">{{ period.label }}</th>

                        <!--
                            A period with no baseline capture from before it began
                            is reported as unavailable rather than computed from
                            whatever the oldest row happens to be — that would
                            silently present three days of play as a month's.
                        -->
                        <td
                            v-if="!period.available"
                            :colspan="columns.length"
                            class="px-4 py-2 text-right text-xs italic text-wot-dim"
                        >
                            not enough history yet
                        </td>

                        <template v-else>
                            <td
                                v-for="column in columns"
                                :key="column.key"
                                class="px-4 py-2 text-right tabular-nums"
                                :class="column.key === 'wn8' ? `wn8-${period.wn8_band ?? 'unknown'}` : 'text-wot-muted'"
                            >
                                {{ column.format(period[column.key]) }}
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </div>

        <p v-if="!history.history_since" class="mt-2 text-xs text-wot-dim">
            Period statistics are built from periodic captures — the Wargaming API only reports
            lifetime totals, so this history accrues going forward and can't be backfilled.
        </p>
    </section>
</template>
