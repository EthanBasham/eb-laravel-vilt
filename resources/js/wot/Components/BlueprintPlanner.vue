<script setup>
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import EditableNumber from './EditableNumber.vue';

/**
 * One vehicle's blueprint: what has been built, and where the rest comes from.
 *
 * The grid cell has room for two lines and no controls, so everything the
 * fragments mean is said here — what the tank costs, what a fragment is worth
 * against that, and what each of the three sources would charge for the ones
 * still missing.
 *
 * Native <dialog>, like every other modal in the app: the platform supplies the
 * focus trap, Escape handling and backdrop.
 *
 * Unlike CrewEditor, there is no working copy. A crew is a set where an omitted
 * seat means "emptied", so it is sent whole and saved once; every field here is
 * an independent absolute value against an endpoint that already exists, and
 * the planner stays open across several of them. EditableNumber writing
 * straight through is what keeps the modal and the board agreeing — a draft
 * would only add a way for them to differ.
 *
 * Every figure shown is the server's. There is no JS test runner in this
 * project, so arithmetic done here would be arithmetic nothing checks.
 */
const props = defineProps({
    cell: { type: Object, default: null },
    // Blueprints held, straight from the board, so a source row can say what is
    // in the stack it would spend. Nothing is measured against it.
    stock: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

// 1-indexed, like the page's own: a tier is a numeral, and index 0 is never
// asked for.
const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];

const dialog = ref(null);

const n = (v) => new Intl.NumberFormat().format(v ?? 0);

/*
 * showModal() rather than the `open` attribute, for the reason the rest of the
 * app gives: `open` renders the dialog in normal flow, with no backdrop and no
 * focus trap. After nextTick because the element is behind a v-if.
 */
watch(() => props.cell, async (cell) => {
    if (!cell) return;

    await nextTick();
    if (!dialog.value?.open) dialog.value?.showModal();
}, { immediate: true });

const close = () => dialog.value?.close();

const url = computed(() => `/wot/grinding/purchases/${props.cell?.tank_id}`);

const held = (nation) => props.stock.find((stack) => stack.nation === nation)?.quantity ?? 0;

/**
 * The three ways to craft a fragment, in the order the game charges for them.
 *
 * Alternatives rather than a breakdown: a fragment is paid for out of one of
 * these, and a blueprint may take a different one each time. The group row is
 * the odd one — it spends the *other* nations in the group, six blueprints for
 * one, and says so rather than naming a stack it could draw on.
 */
const sources = computed(() => {
    if (!props.cell) return [];

    const peers = (props.cell.group?.nations ?? []).filter((nation) => nation !== props.cell.nation);

    return [
        {
            key: 'own',
            field: 'blueprint_plan_own',
            label: 'Own nation',
            note: `${props.cell.cost.national} blueprint${props.cell.cost.national === 1 ? '' : 's'} a fragment`,
            stack: `${n(held(props.cell.nation))} held`,
            planned: props.cell.planned.own,
            blueprints: props.cell.planned.own * props.cell.cost.national,
        },
        {
            key: 'group',
            field: 'blueprint_plan_group',
            label: props.cell.group ? props.cell.group.name : 'Same group',
            note: `${props.cell.cost.group} a fragment, at six to one`,
            stack: peers.length ? peers.join(', ') : 'no other nation in the group',
            planned: props.cell.planned.group,
            blueprints: props.cell.planned.group * props.cell.cost.group,
        },
        {
            key: 'universal',
            field: 'blueprint_plan_universal',
            label: 'Universal',
            note: `${props.cell.cost.universal} a fragment`,
            stack: `${n(held('universal'))} held`,
            planned: props.cell.planned.universal,
            blueprints: props.cell.planned.universal * props.cell.cost.universal,
        },
    ];
});

// Built and planned together can overshoot what the blueprint takes. Worth
// saying, not worth refusing: a plan is written before it is spent, and the
// excess is the player's to sort out.
const overPlanned = computed(() => Math.max(
    0,
    (props.cell?.fragments ?? 0) + (props.cell?.planned.fragments ?? 0) - (props.cell?.fragments_needed ?? 0),
));

const clearPlan = () => router.patch(url.value, {
    blueprint_plan_own: 0,
    blueprint_plan_group: 0,
    blueprint_plan_universal: 0,
}, { preserveScroll: true, only: ['blueprints', 'totals'] });
</script>

<template>
    <dialog
        v-if="cell"
        ref="dialog"
        class="modal modal--dark modal--wide"
        :aria-label="`Blueprint of the ${cell.name}`"
        @click.self="close"
        @close="emit('close')"
    >
        <div class="border border-wot-border bg-wot-panel-solid p-6">
            <h3 class="text-lg normal-case tracking-normal text-wot-heading">
                {{ cell.name }} <span class="text-wot-dim">— blueprint</span>
                <span class="ms-1 text-sm text-wot-dim">Tier {{ ROMAN[cell.tier] }}</span>
            </h3>

            <!-- The rule that is not obvious from the percentage: the last
                 fragment is not worth what the others are, it is worth whatever
                 is left. -->
            <p class="mt-1 text-xs text-wot-dim">
                {{ n(cell.base_xp) }} XP to research · {{ cell.fragments_needed }} fragments ·
                {{ cell.percent_per_fragment }}% each, the last covering the rest
            </p>

            <p v-if="cell.is_unlocked" class="mt-2 text-xs text-wot-dim">
                Already researched — what is recorded here is a record of what was held, not a plan.
            </p>

            <div class="mt-4 border border-wot-border-soft p-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-3 text-sm text-wot-text">
                        <span class="text-xs font-bold uppercase tracking-wider text-wot-dim">Fragments built</span>
                        <EditableNumber
                            field="blueprint_fragments"
                            stepper
                            :model-value="cell.fragments"
                            :url="url"
                            :only="['blueprints', 'totals']"
                        />
                        <span class="text-xs text-wot-dim">of {{ cell.fragments_needed }}</span>
                    </label>

                    <p class="text-sm tabular-nums" :class="cell.xp_saved ? 'text-wot-gold' : 'text-wot-dim'">
                        {{ n(cell.xp_saved) }} off · {{ n(cell.xp_remaining) }} left to research
                    </p>
                </div>
            </div>

            <h4 class="mt-5 text-xs font-bold uppercase tracking-wider text-wot-dim">Where the rest comes from</h4>
            <p class="mt-1 text-xs text-wot-dim">
                One fragment is crafted from any one of these, and a blueprint can mix them.
            </p>

            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wider text-wot-dim">
                            <th scope="col" class="py-2 pe-3 text-left font-bold">Source</th>
                            <th scope="col" class="px-3 py-2 text-left font-bold">Rate</th>
                            <th scope="col" class="px-3 py-2 text-center font-bold">Fragments</th>
                            <th scope="col" class="ps-3 py-2 text-right font-bold">Blueprints</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="source in sources" :key="source.key">
                            <td class="py-2 pe-3">
                                <span class="text-wot-text">{{ source.label }}</span>
                                <span class="block text-xs text-wot-dim">{{ source.stack }}</span>
                            </td>
                            <td class="px-3 py-2 text-xs text-wot-dim">{{ source.note }}</td>
                            <td class="px-3 py-2 text-center">
                                <EditableNumber
                                    :field="source.field"
                                    stepper
                                    align="text-center"
                                    :model-value="source.planned"
                                    :url="url"
                                    :only="['blueprints', 'totals']"
                                />
                            </td>
                            <td class="ps-3 py-2 text-right tabular-nums"
                                :class="source.blueprints ? 'text-wot-text' : 'text-wot-dim'">
                                {{ n(source.blueprints) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 border-t border-wot-border-soft pt-3 text-sm">
                <p class="text-wot-text">
                    <span class="tabular-nums text-wot-gold">{{ n(cell.planned.national_blueprints) }}</span> national
                    +
                    <span class="tabular-nums text-wot-gold">{{ n(cell.planned.universal_blueprints) }}</span> universal
                    <span class="text-wot-dim">
                        for {{ cell.planned.fragments }} more fragment{{ cell.planned.fragments === 1 ? '' : 's' }},
                        leaving {{ n(cell.xp_after_plan) }} XP
                    </span>
                </p>

                <p v-if="overPlanned" class="mt-1 text-xs text-wot-bad">
                    That is {{ overPlanned }} fragment{{ overPlanned === 1 ? '' : 's' }} more than the blueprint takes.
                </p>
            </div>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <button
                    v-if="cell.planned.fragments"
                    type="button"
                    class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                    @click="clearPlan"
                >
                    Clear the plan
                </button>
                <span v-else></span>

                <button
                    type="button"
                    class="border border-wot-gold px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-gold transition-colors hover:bg-wot-gold hover:text-wot-abyss"
                    @click="close"
                >
                    Done
                </button>
            </div>
        </div>
    </dialog>
</template>
