<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import EditableNumber from '../Components/EditableNumber.vue';
import ModulePicker from '../Components/ModulePicker.vue';
import NationFlag from '../Components/NationFlag.vue';

const props = defineProps({
    active: { type: Array, default: () => [] },
    targets: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    totals: { type: Object, required: true },
    purchase: { type: Object, required: true },
    options: { type: Array, default: () => [] },
});

// The five views the spreadsheet had, kept as tabs so the board stays one page.
const views = [
    { key: 'active', label: 'Active Grinding' },
    { key: 'xp', label: 'XP Remaining' },
    { key: 'freexp', label: 'Free XP' },
    { key: 'purchase', label: 'Tanks to Purchase' },
    { key: 'blueprints', label: 'Blueprints' },
];
const view = ref('active');

const expanded = ref([]);
const toggle = (id) => {
    expanded.value = expanded.value.includes(id)
        ? expanded.value.filter((x) => x !== id)
        : [...expanded.value, id];
};

const open = computed(() => props.targets.filter((t) => !t.is_complete));

const addForm = useForm({ tank_id: '' });
const addTarget = () => addForm.post('/wot/grinding/targets', {
    preserveScroll: true,
    onSuccess: () => addForm.reset(),
});

const settingsForm = useForm({
    credits_available: props.settings.credits_available,
    garage_slots_vacant: props.settings.garage_slots_vacant,
});
const saveSettings = () => settingsForm.patch('/wot/grinding/settings', { preserveScroll: true });

const removeTarget = (t) => {
    if (window.confirm(`Stop tracking ${t.name}?`)) {
        router.delete(`/wot/grinding/targets/${t.id}`, { preserveScroll: true });
    }
};

const toggleComplete = (t) => router.patch(`/wot/grinding/targets/${t.id}/complete`, {}, { preserveScroll: true });

const n = (v) => new Intl.NumberFormat().format(v ?? 0);

// Tiers are Roman in game and in every community tool; Arabic column headers
// here would read as a different quantity entirely.
const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];

const setPurchase = (tankId, payload) => router.patch(`/wot/grinding/purchases/${tankId}`, payload, {
    preserveScroll: true,
    only: ['purchase', 'totals'],
});
const short = (v) => (v >= 1_000_000 ? `${(v / 1_000_000).toFixed(1)}M` : n(v));

// Credits shortfall is the number that decides whether a plan is realistic.
const creditGap = computed(() => props.totals.credits_required - props.settings.credits_available);
</script>

<template>
    <Head title="Grinding" />

    <AppShell>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
            <div>
                <h1 class="text-3xl">Grinding</h1>
                <p class="mt-1 text-sm text-wot-dim">
                    {{ totals.open }} open {{ totals.open === 1 ? 'target' : 'targets' }} ·
                    {{ n(totals.xp_remaining) }} XP still to earn
                </p>
            </div>
        </div>

        <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">XP remaining</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-heading">{{ n(totals.xp_remaining) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Banked XP</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-good">{{ n(totals.banked_xp) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Free XP planned</dt>
                <dd class="mt-1 text-xl tabular-nums text-wot-gold">{{ n(totals.free_xp_planned) }}</dd>
            </div>
            <div class="border border-wot-border bg-wot-panel p-3">
                <dt class="text-xs uppercase tracking-wider text-wot-dim">Credits needed</dt>
                <dd class="mt-1 text-xl tabular-nums" :class="creditGap > 0 ? 'text-wot-bad' : 'text-wot-good'">
                    {{ short(totals.credits_required) }}
                </dd>
                <p class="mt-0.5 text-xs text-wot-dim">
                    {{ creditGap > 0 ? `${short(creditGap)} short` : 'covered' }}
                </p>
            </div>
        </dl>

        <div class="mt-8 flex flex-wrap gap-2" role="tablist" aria-label="Grinding views">
            <button
                v-for="v in views"
                :key="v.key"
                type="button"
                role="tab"
                :aria-selected="view === v.key"
                class="border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors"
                :class="view === v.key ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                @click="view = v.key"
            >
                {{ v.label }}
            </button>
        </div>

        <!-- 1. Active Grinding ------------------------------------------------->
        <section v-if="view === 'active'" class="mt-4" aria-labelledby="active-heading">
            <h2 id="active-heading" class="sr-only">Active grinding</h2>

            <p v-if="!active.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                No tanks marked as being played. Mark a step active on the XP Remaining tab.
            </p>

            <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Tank</th>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Towards</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">XP banked</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To max</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">To next tank</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in active" :key="row.id" class="hover:bg-wot-sunken">
                            <td class="px-4 py-2">
                                <NationFlag :nation="row.nation" class="me-2" />
                                <span class="text-wot-heading">{{ row.name }}</span>
                                <span class="ms-2 text-xs text-wot-dim">T{{ row.tier }}</span>
                            </td>
                            <td class="px-4 py-2 text-wot-muted">{{ row.target_name }}</td>
                            <!-- The one number no API can supply. -->
                            <td class="px-4 py-2 text-right">
                                <EditableNumber :step-id="row.id" field="banked_xp" :model-value="row.banked_xp" />
                            </td>
                            <td class="px-4 py-2 text-right">
                                <ModulePicker :step-id="row.id" :modules="row.modules" :outstanding="row.module_xp_remaining" />
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ n(row.research_cost) }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-wot-heading">{{ n(row.xp_remaining) }}</td>
                            <td class="px-4 py-2 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-16 bg-wot-sunken">
                                        <span class="block h-full bg-wot-gold" :style="{ width: `${row.progress}%` }" />
                                    </span>
                                    <span class="w-12 text-right tabular-nums text-wot-muted">{{ row.progress }}%</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>

                    <tfoot v-if="active.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                            <td class="px-4 py-3 text-xs text-wot-dim">{{ totals.active.steps }} tanks</td>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-good">{{ n(totals.active.banked_xp) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-muted">{{ n(totals.active.module_xp_remaining) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums text-wot-muted">{{ n(totals.active.research_cost) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(totals.active.xp_remaining) }}</td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <span class="h-1.5 w-16 bg-wot-panel">
                                        <span class="block h-full bg-wot-gold" :style="{ width: `${totals.active.progress}%` }" />
                                    </span>
                                    <span class="w-12 text-right tabular-nums text-wot-muted">{{ totals.active.progress }}%</span>
                                </div>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <!-- 2. Tanks to Purchase ------------------------------------------------>
        <!--
            Credits only. Every other figure on this page belongs to a different
            question, and a shopping list that also quotes XP is a shopping list
            you have to read twice.
        -->
        <section v-else-if="view === 'purchase'" class="mt-4" aria-labelledby="purchase-heading">
            <h2 id="purchase-heading" class="sr-only">Tanks to purchase</h2>

            <p v-if="!purchase.rows.length" class="border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                Nothing left to buy. Every tracked line has been bought out.
            </p>

            <div v-else class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Line</th>
                            <th v-for="tier in purchase.tiers" :key="tier" scope="col"
                                class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                                Tier {{ ROMAN[tier] }}
                            </th>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="row in purchase.rows" :key="row.id" class="hover:bg-wot-sunken">
                            <td class="whitespace-nowrap px-4 py-2">
                                <NationFlag :nation="row.nation" class="me-2" />
                                <span class="text-wot-heading">{{ row.name }}</span>
                            </td>

                            <td v-for="tier in purchase.tiers" :key="tier" class="px-3 py-2 text-right align-top">
                                <template v-if="row.cells[tier]">
                                    <!-- Owned: nothing left to pay, so the cell
                                         reads zero rather than restating a price
                                         that is no longer owed. -->
                                    <button
                                        v-if="row.cells[tier].is_purchased"
                                        type="button"
                                        class="tabular-nums text-wot-dim hover:text-wot-muted"
                                        :title="`${row.cells[tier].name} — bought. Mark as not bought.`"
                                        @click="setPurchase(row.cells[tier].tank_id, { is_purchased: false })"
                                    >
                                        0
                                    </button>

                                    <div v-else class="flex flex-col items-end gap-1">
                                        <div class="flex items-center justify-end">
                                            <EditableNumber
                                                :field="'price_credit'"
                                                :model-value="row.cells[tier].price"
                                                :url="`/wot/grinding/purchases/${row.cells[tier].tank_id}`"
                                                :only="['purchase', 'totals']"
                                                :tone="row.cells[tier].is_unlocked ? 'text-wot-good' : 'text-wot-text'"
                                            />
                                            <!-- Only offered once a price has been
                                                 overridden; there is nothing to
                                                 reset back to otherwise. -->
                                            <button
                                                v-if="row.cells[tier].is_discounted"
                                                type="button"
                                                class="ms-1 text-xs text-wot-dim hover:text-wot-bad"
                                                :title="`Reset to the ${n(row.cells[tier].api_price)} shop price`"
                                                @click="setPurchase(row.cells[tier].tank_id, { price_credit: null })"
                                            >
                                                &times;
                                            </button>
                                        </div>

                                        <button
                                            type="button"
                                            class="border px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider transition-colors"
                                            :class="row.cells[tier].is_unlocked
                                                ? 'border-wot-good/50 text-wot-good hover:bg-wot-good/15'
                                                : 'border-wot-border text-wot-dim hover:text-wot-text'"
                                            :title="row.cells[tier].name"
                                            @click="row.cells[tier].is_unlocked
                                                ? setPurchase(row.cells[tier].tank_id, { is_purchased: true })
                                                : setPurchase(row.cells[tier].tank_id, { is_unlocked: true })"
                                        >
                                            {{ row.cells[tier].is_unlocked ? 'Buy' : 'Unlock' }}
                                        </button>
                                    </div>
                                </template>
                            </td>

                            <td class="px-4 py-2 text-right tabular-nums font-bold text-wot-heading">{{ n(row.credits_remaining) }}</td>
                        </tr>
                    </tbody>

                    <tfoot v-if="purchase.rows.length > 1" class="border-t-2 border-wot-border bg-wot-sunken">
                        <tr>
                            <th scope="row" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Total</th>
                            <td v-for="tier in purchase.tiers" :key="tier" class="px-3 py-3" />
                            <td class="px-4 py-3 text-right tabular-nums font-bold text-wot-heading">{{ n(purchase.credits_required) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="mt-3 text-xs text-wot-dim">
                Prices come from the encyclopedia and can be typed over when a seasonal discount applies.
                A line disappears once its last vehicle is bought.
            </p>
        </section>

        <!-- 3-5. Target-driven views -------------------------------------------->
        <section v-else class="mt-4" aria-labelledby="targets-heading">
            <h2 id="targets-heading" class="sr-only">{{ views.find((v) => v.key === view).label }}</h2>

            <div class="overflow-x-auto border border-wot-border bg-wot-panel">
                <table class="min-w-full divide-y divide-wot-border text-sm">
                    <thead class="bg-wot-sunken">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim">Target</th>
                            <template v-if="view === 'xp'">
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Required</th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Remaining</th>
                            </template>
                            <template v-else-if="view === 'freexp'">
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Free XP planned</th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">XP remaining</th>
                            </template>
                            <template v-else>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Fragments</th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Full research XP</th>
                            </template>
                            <th scope="col" class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">Steps</th>
                            <th scope="col" class="w-24 px-4 py-3" />
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <template v-for="t in targets" :key="t.id">
                            <tr class="cursor-pointer hover:bg-wot-sunken" :class="t.is_complete ? 'opacity-50' : ''" @click="toggle(t.id)">
                                <td class="px-4 py-2">
                                    <span aria-hidden="true" class="me-1 inline-block w-3 text-wot-dim">{{ expanded.includes(t.id) ? '▾' : '▸' }}</span>
                                    <NationFlag :nation="t.nation" class="me-2" />
                                    <span class="text-wot-heading">{{ t.name }}</span>
                                    <span class="ms-2 text-xs text-wot-dim">T{{ t.tier }}</span>
                                </td>

                                <template v-if="view === 'xp'">
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ n(t.xp_required) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-heading">{{ n(t.xp_remaining) }}</td>
                                </template>
                                <template v-else-if="view === 'freexp'">
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-gold">{{ n(t.free_xp_planned) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-muted">{{ n(t.xp_remaining) }}</td>
                                </template>
                                <template v-else>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-gold">{{ t.blueprint_fragments }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums text-wot-muted">
                                        {{ n(t.steps.filter((s) => s.research_xp).slice(-1)[0]?.research_xp) }}
                                    </td>
                                </template>

                                <td class="px-4 py-2 text-right tabular-nums text-wot-dim">{{ t.steps.length }}</td>
                                <td class="px-4 py-2 text-right" @click.stop>
                                    <button type="button" class="text-xs uppercase tracking-wider text-wot-dim hover:text-wot-good" @click="toggleComplete(t)">
                                        {{ t.is_complete ? 'Reopen' : 'Done' }}
                                    </button>
                                    <button type="button" class="ms-2 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-bad" @click="removeTarget(t)">
                                        Remove
                                    </button>
                                </td>
                            </tr>

                            <tr v-if="expanded.includes(t.id)">
                                <td colspan="5" class="bg-wot-sunken/60 px-4 py-3">
                                    <table class="min-w-full text-xs">
                                        <thead>
                                            <tr class="text-wot-dim">
                                                <th scope="col" class="py-1 text-left font-bold uppercase tracking-wider">Step</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Modules</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Next tank XP</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Banked</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Free XP</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Frags</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Credits</th>
                                                <th scope="col" class="py-1 text-right font-bold uppercase tracking-wider">Playing</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="s in t.steps" :key="s.id" class="text-wot-muted">
                                                <td class="py-1">
                                                    <span class="text-wot-text">T{{ s.tier }} {{ s.name }}</span>
                                                    <!-- The API's undiscounted price, shown when a
                                                         blueprint-discounted figure has been entered. -->
                                                    <span v-if="s.research_xp && s.research_xp_remaining !== null && s.research_xp !== s.research_xp_remaining"
                                                          class="ms-2 text-wot-dim">full {{ n(s.research_xp) }}</span>
                                                </td>
                                                <td class="py-1 text-right">
                                                    <!-- Editable only where the encyclopedia has no
                                                         modules for this vehicle; otherwise the total
                                                         is a consequence of the ticks, and typing over
                                                         it would be undone by the next one. -->
                                                    <ModulePicker
                                                        v-if="s.modules.length"
                                                        :step-id="s.id"
                                                        :modules="s.modules"
                                                        :outstanding="s.module_xp_remaining"
                                                    />
                                                    <EditableNumber v-else :step-id="s.id" field="module_xp_remaining" :model-value="s.module_xp_remaining" />
                                                </td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="research_xp_remaining" :model-value="s.research_xp_remaining ?? s.research_xp ?? 0" /></td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="banked_xp" :model-value="s.banked_xp" /></td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="free_xp_planned" :model-value="s.free_xp_planned" /></td>
                                                <td class="py-1 text-right"><EditableNumber :step-id="s.id" field="blueprint_fragments" :model-value="s.blueprint_fragments" /></td>
                                                <td class="py-1 text-right tabular-nums">{{ n(s.price_credit) }}</td>
                                                <td class="py-1 text-right">
                                                    <input
                                                        type="checkbox"
                                                        class="border"
                                                        :checked="s.is_active"
                                                        :aria-label="`Currently playing ${s.name}`"
                                                        @change="router.patch(`/wot/grinding/steps/${s.id}`, { is_active: $event.target.checked }, { preserveScroll: true, only: ['active', 'targets', 'totals'] })"
                                                    >
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>

                        <tr v-if="!targets.length">
                            <td colspan="5" class="px-4 py-8 text-center text-wot-dim">
                                No targets yet. Add one below, or run <code>php artisan wot:import-grind-sheet</code>.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="mt-8 grid gap-6 lg:grid-cols-2">
            <form class="border border-wot-border bg-wot-panel p-4" @submit.prevent="addTarget">
                <h2 class="text-base">Add a target</h2>
                <p class="mt-1 text-xs text-wot-dim">The path is built from the tech tree, starting at the last vehicle you've played.</p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <select v-model="addForm.tank_id" class="min-w-64 flex-1 border px-2 py-1.5 text-sm">
                        <option value="">Choose a vehicle…</option>
                        <option v-for="o in options" :key="o.tank_id" :value="o.tank_id">{{ o.label }}</option>
                    </select>
                    <button
                        type="submit"
                        class="border border-wot-gold bg-wot-gold px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-abyss disabled:opacity-40"
                        :disabled="!addForm.tank_id || addForm.processing"
                    >
                        Add
                    </button>
                </div>
            </form>

            <form class="border border-wot-border bg-wot-panel p-4" @submit.prevent="saveSettings">
                <h2 class="text-base">Planning</h2>
                <div class="mt-3 flex flex-wrap gap-4">
                    <label class="text-xs uppercase tracking-wider text-wot-dim">
                        Credits available
                        <input v-model="settingsForm.credits_available" type="number" min="0" class="mt-1 block w-40 border px-2 py-1.5 text-sm normal-case tracking-normal">
                    </label>
                    <label class="text-xs uppercase tracking-wider text-wot-dim">
                        Vacant garage slots
                        <input v-model="settingsForm.garage_slots_vacant" type="number" min="0" class="mt-1 block w-32 border px-2 py-1.5 text-sm normal-case tracking-normal">
                    </label>
                    <button type="submit" class="self-end border border-wot-border px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-wot-dim hover:border-wot-gold hover:text-wot-gold">
                        Save
                    </button>
                </div>
            </form>
        </div>
    </AppShell>
</template>
