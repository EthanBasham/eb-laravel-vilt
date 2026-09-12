<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import NationFlag from './NationFlag.vue';
import VehicleTypeIcon from './VehicleTypeIcon.vue';

/**
 * Choose one vehicle out of every vehicle in the game.
 *
 * Built after Grinding's "Add a tank you're playing": the same nation, tier and
 * type chips and the same capped, scrolled list of tank chips. A dropdown of a
 * thousand options is a list you scroll; this is a list you narrow.
 *
 * One difference in how the chips behave: each row is single-choice, like a set
 * of radio buttons, where the grinding picker lets a row hold several. That one
 * is for browsing — what might I grind next — and several nations at once is a
 * reasonable question. This one is for finding a tank you already know, so
 * picking a second nation should replace the first rather than widen the list.
 * All clears a row.
 *
 * Unlike that picker there is no useful shortlist to open on. It can offer the
 * garage because a tank you are grinding is one you own and have XP on; nothing
 * here says which tank a Battle Pass tanker is likely to be posted to. So an
 * untouched picker asks for a filter rather than showing the whole game.
 *
 * Native <dialog>, like every other modal in the app. The filters survive
 * closing it: entering a season's worth of tankers usually means choosing among
 * the same nation several times running.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    // Every vehicle, or null while the deferred prop is still loading.
    vehicles: { type: Array, default: null },
    selectedId: { type: Number, default: null },
    // Who the tank is being chosen for, so the heading says what this is.
    title: { type: String, default: 'Choose a tank' },
});

const emit = defineEmits(['pick', 'close']);

const dialog = ref(null);

watch(() => props.open, async (open) => {
    if (!open) return;

    await nextTick();
    dialog.value?.showModal();
}, { immediate: true });

const close = () => dialog.value?.close();

const choose = (tankId) => {
    emit('pick', tankId);
    close();
};

// Tiers are Roman in game and in every community tool.
const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];

// Light to heavy, then the two that are not tanks — the same fixed order as the
// grinding picker, so the row does not reshuffle as the list changes.
const TYPES = ['lightTank', 'mediumTank', 'heavyTank', 'AT-SPG', 'SPG'];

// One value per row, or null for a row left open.
const pickedNation = ref(null);
const pickedTier = ref(null);
const pickedType = ref(null);

// An untouched row lets everything through while its chips sit unlit.
const allows = (picked, value) => picked === null || picked === value;

const all = computed(() => props.vehicles ?? []);

const page = usePage();

// In tech-tree order, like every other vehicle list here.
const nations = computed(() => {
    const present = new Set(all.value.map((v) => v.nation));

    return Object.keys(page.props.nations ?? {}).filter((nation) => present.has(nation));
});

const tiers = computed(() => [...new Set(all.value.map((v) => v.tier))].sort((a, b) => a - b));

const types = computed(() => {
    const present = new Set(all.value.map((v) => v.type));

    return TYPES.filter((type) => present.has(type));
});

const hasPick = computed(() => pickedNation.value !== null
    || pickedTier.value !== null
    || pickedType.value !== null);

const pickable = computed(() => (hasPick.value
    ? all.value.filter((v) => allows(pickedNation.value, v.nation)
        && allows(pickedTier.value, v.tier)
        && allows(pickedType.value, v.type))
    : []));

const selected = computed(() => all.value.find((v) => v.tank_id === props.selectedId) ?? null);
</script>

<template>
    <dialog
        v-if="open"
        ref="dialog"
        class="modal modal--dark modal--wide"
        :aria-label="title"
        @click.self="close"
        @close="emit('close')"
    >
        <div class="border border-wot-border bg-wot-panel-solid p-6">
            <h3 class="text-lg normal-case tracking-normal text-wot-heading">{{ title }}</h3>
            <p class="mt-1 text-xs text-wot-dim">
                <template v-if="selected">
                    Serving in the {{ selected.name }}.
                </template>
                {{ hasPick
                    ? 'Everything in the game that matches.'
                    : 'Pick a nation, tier or type to narrow the whole game down.' }}
            </p>

            <!-- The grinding picker's chips, but each row single-choice: lit
                 means picked, and picking another replaces it. Marked up as
                 radio groups so assistive tech announces them that way too. -->
            <div class="mt-3 space-y-2 border border-wot-border bg-wot-sunken p-3">
                <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-labelledby="tank-picker-nation">
                    <span id="tank-picker-nation" class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Nation</span>
                    <button
                        v-for="nation in nations"
                        :key="nation"
                        type="button"
                        role="radio"
                        class="border p-1 leading-none transition-colors"
                        :class="pickedNation === nation ? 'border-wot-gold' : 'border-wot-border opacity-30 hover:opacity-70'"
                        :aria-checked="pickedNation === nation"
                        @click="pickedNation = nation"
                    >
                        <NationFlag :nation="nation" />
                    </button>
                    <button v-if="pickedNation !== null" type="button"
                            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                            @click="pickedNation = null">
                        All
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-labelledby="tank-picker-tier">
                    <span id="tank-picker-tier" class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Tier</span>
                    <button
                        v-for="tier in tiers"
                        :key="tier"
                        type="button"
                        role="radio"
                        class="min-w-9 border px-2 py-0.5 text-xs font-bold tracking-wider transition-colors"
                        :class="pickedTier === tier ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                        :aria-checked="pickedTier === tier"
                        :aria-label="`Tier ${ROMAN[tier]}`"
                        @click="pickedTier = tier"
                    >
                        {{ ROMAN[tier] }}
                    </button>
                    <button v-if="pickedTier !== null" type="button"
                            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                            @click="pickedTier = null">
                        All
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-1.5" role="radiogroup" aria-labelledby="tank-picker-type">
                    <span id="tank-picker-type" class="w-12 shrink-0 text-xs font-bold uppercase tracking-wider text-wot-dim">Type</span>
                    <button
                        v-for="type in types"
                        :key="type"
                        type="button"
                        role="radio"
                        class="inline-flex h-6 min-w-9 items-center justify-center border px-2 transition-colors"
                        :class="pickedType === type ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-dim hover:text-wot-text'"
                        :aria-checked="pickedType === type"
                        @click="pickedType = type"
                    >
                        <VehicleTypeIcon :type="type" />
                    </button>
                    <button v-if="pickedType !== null" type="button"
                            class="ms-1 text-xs uppercase tracking-wider text-wot-dim hover:text-wot-text"
                            @click="pickedType = null">
                        All
                    </button>
                </div>
            </div>

            <!-- Capped and scrolled, like the grinding picker's: a single nation
                 still runs to a hundred-odd vehicles. -->
            <div class="mt-3 max-h-72 overflow-y-auto">
                <p v-if="!vehicles" class="border border-dashed border-wot-border p-6 text-center text-sm text-wot-dim">
                    Loading vehicles…
                </p>

                <p v-else-if="!hasPick" class="border border-dashed border-wot-border p-6 text-center text-sm text-wot-dim">
                    Pick a nation, tier or type to see tanks.
                </p>

                <p v-else-if="!pickable.length" class="border border-dashed border-wot-border p-6 text-center text-sm text-wot-dim">
                    Nothing in that nation, tier and type.
                </p>

                <ul v-else role="list" class="flex flex-wrap gap-1.5">
                    <li v-for="vehicle in pickable" :key="vehicle.tank_id">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 whitespace-nowrap border px-2 py-1 text-xs transition-colors hover:border-wot-gold hover:text-wot-gold"
                            :class="vehicle.tank_id === selectedId ? 'border-wot-gold text-wot-gold' : 'border-wot-border text-wot-text'"
                            :aria-pressed="vehicle.tank_id === selectedId"
                            @click="choose(vehicle.tank_id)"
                        >
                            <NationFlag :nation="vehicle.nation" />
                            {{ vehicle.name }}
                            <span class="text-wot-dim">{{ ROMAN[vehicle.tier] }}</span>
                            <VehicleTypeIcon :type="vehicle.type" class="text-wot-dim" />
                        </button>
                    </li>
                </ul>
            </div>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-wot-dim">
                    <template v-if="hasPick">{{ pickable.length }} of {{ all.length }} tanks</template>
                </p>

                <div class="flex flex-wrap gap-3">
                    <!-- Only offered once there is something to take away. -->
                    <button
                        v-if="selectedId !== null"
                        type="button"
                        class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                        @click="choose(null)"
                    >
                        No tank
                    </button>
                    <button
                        type="button"
                        class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-muted transition-colors hover:border-wot-gold hover:text-wot-gold"
                        @click="close"
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </dialog>
</template>
