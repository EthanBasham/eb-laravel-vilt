<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EditableNumber from './EditableNumber.vue';
import NationFlag from './NationFlag.vue';
import WotDialog from './WotDialog.vue';
import { n, roman } from '../lib/format';

/**
 * One vehicle's blueprint: what has been built, and where the rest comes from.
 *
 * The grid cell has room for two lines and no controls, so everything the
 * fragments mean is said here — what the tank costs, what a fragment is worth
 * against that, and what each nation that could pay would charge for the ones
 * still missing.
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
    // Blueprints held, straight from the board, so each nation's line can say
    // what is in the stack it would spend. Nothing is measured against it.
    stock: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

const close = () => dialog.value?.close();

const url = computed(() => `/wot/grinding/purchases/${props.cell?.tank_id}`);

const held = (nation) => props.stock.find((stack) => stack.nation === nation)?.quantity ?? 0;

/**
 * The nations that could pay for a fragment, own first, each with what its
 * share of the plan comes to.
 *
 * Every figure is the server's: which nations may pay, what one fragment costs
 * each of them, and what the counter multiplies out to. A fragment is never
 * bought from one source — it takes national blueprints *and* universal ones —
 * so a line is a pair, and the only choice is whose national half it is.
 *
 * Own nation and the peers in its group, which is the whole of the choice: a
 * blueprint never leaves its group. The peers are the dear ones, at six to one.
 */
const lines = computed(() => (props.cell?.planned.lines ?? []).map((line) => ({
    ...line,
    // Its own URL, so a stepper writes one nation and leaves the rest alone.
    url: `/wot/grinding/purchases/${props.cell.tank_id}/blueprint-plan/${line.nation}`,
    held: held(line.nation),
})));

// Built and planned together can overshoot what the blueprint takes. Worth
// saying, not worth refusing: a plan is written before it is spent, and the
// excess is the player's to sort out.
const overPlanned = computed(() => Math.max(
    0,
    (props.cell?.fragments ?? 0) + (props.cell?.planned.fragments ?? 0) - (props.cell?.fragments_needed ?? 0),
));

/*
 * One request per nation that has anything on it, rather than a clear-all
 * endpoint. Each is the same write the stepper beside it makes — a nation set
 * back to zero — so there is no second path into the plan that could disagree
 * with the first about what zero means.
 */
const clearPlan = () => lines.value
    .filter((line) => line.fragments)
    .forEach((line) => router.patch(line.url, { fragments: 0 }, {
        preserveScroll: true,
        only: ['blueprints', 'totals'],
    }));
</script>

<template>
    <WotDialog ref="dialog" wide :open="Boolean(cell)" :label="cell ? `Blueprint of the ${cell.name}` : ''" @close="emit('close')">
        <h3 class="text-lg normal-case tracking-normal text-wot-heading">
            {{ cell.name }} <span class="text-wot-dim">— blueprint</span>
            <span class="ms-1 text-sm text-wot-dim">Tier {{ roman(cell.tier) }}</span>
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
                        buttons
                        align="text-center"
                        :model-value="cell.fragments"
                        :max="cell.fragments_needed"
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

        <h4 class="mt-5 text-xs font-bold uppercase tracking-wider text-wot-dim">Fragments Planned</h4>

        <!--
            One line per nation that could pay, own first, then the peers in
            its group at six to one. Read as: this many fragments, bought
            with this many of that nation's blueprints and this many
            universal ones.

            Both halves, always. A fragment is never crafted from one stack
            or the other — the national blueprints and the universal ones
            are both spent on every one of them — so the "+" is an addition
            rather than a choice, which is the thing the row this replaced
            got wrong.
        -->
        <ul role="list" class="mt-2 space-y-1">
            <li
                v-for="line in lines"
                :key="line.nation"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 border border-wot-border-soft px-3 py-2"
            >
                <EditableNumber
                    field="fragments"
                    stepper
                    buttons
                    align="text-center"
                    :model-value="line.fragments"
                    :max="cell.fragments_needed"
                    :url="line.url"
                    :only="['blueprints', 'totals']"
                />

                <span aria-hidden="true" class="text-wot-dim">:</span>

                <span
                    class="flex items-center gap-2 tabular-nums"
                    :class="line.fragments ? 'text-wot-text' : 'text-wot-dim'"
                    :title="`${n(line.held)} held`"
                >
                    <NationFlag :nation="line.nation" />
                    {{ n(line.national) }}
                    <!-- The rate behind the figure, as blueprints to the
                         fragment. A peer nation's six to one needs no words
                         next to a (24:1) beside the own nation's (4:1). -->
                    <span class="text-xs text-wot-dim">({{ line.per_fragment.national }}:1)</span>
                </span>

                <span aria-hidden="true" class="text-wot-dim">+</span>

                <span
                    class="flex items-center gap-2 tabular-nums"
                    :class="line.fragments ? 'text-wot-text' : 'text-wot-dim'"
                >
                    <!-- Universal blueprints spend anywhere, so a globe
                         rather than a flag. Inline rather than an image for
                         the reason VehicleTypeIcon gives: currentColor
                         takes a utility class, which an <img> could not.
                         Blue, and not the line's own colour — it holds
                         still while the figure beside it dims, which is
                         what the flags opposite do. -->
                    <svg
                        viewBox="0 0 16 16"
                        width="13"
                        height="13"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.3"
                        aria-hidden="true"
                        class="shrink-0 text-wot-blue-light"
                    >
                        <circle cx="8" cy="8" r="6.4" />
                        <ellipse cx="8" cy="8" rx="2.7" ry="6.4" />
                        <path d="M1.9 5.9h12.2M1.9 10.1h12.2" />
                    </svg>
                    {{ n(line.universal) }}
                    <span class="text-xs text-wot-dim">({{ line.per_fragment.universal }}:1)</span>
                </span>
            </li>
        </ul>

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
    </WotDialog>
</template>
