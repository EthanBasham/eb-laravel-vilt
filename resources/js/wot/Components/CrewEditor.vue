<script setup>
import { router } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';

/**
 * The crew of one vehicle, edited all at once.
 *
 * A cell carries four facts per seat and one about the set, which is more than
 * a tech-tree grid can hold controls for — so the board reports and this is
 * where it is written. The whole set is sent on save: a seat left out is one
 * that has been emptied, which is the rule the controller enforces.
 *
 * Native <dialog>, like every other modal in the app: the platform supplies the
 * focus trap, Escape handling and backdrop.
 */
const props = defineProps({
    cell: { type: Object, default: null },
    // 0-6, from the same config the board's progression table is drawn from.
    levels: { type: Array, default: () => [0, 1, 2, 3, 4, 5, 6] },
});

const emit = defineEmits(['close']);

/*
 * What a member can have zeroed out: none, or the one or two steps that make
 * them a zero-skill crew member. Spelled here rather than in the template so
 * the switch and the server's `max:2` are visibly the same three values.
 */
const ZERO_SKILLS = [0, 1, 2];

const dialog = ref(null);
const saving = ref(false);

// A working copy. Editing the prop in place would move the board underneath the
// modal as you typed, and leave the edits behind if you cancelled.
const draft = ref({ is_balanced: false, members: [] });

const load = (cell) => ({
    is_balanced: cell.is_balanced,
    members: cell.members.map((member) => ({
        slot: member.slot,
        name: member.name,
        also: member.also,
        zero_skills: member.zero_skills,
        skill_level: member.skill_level,
        is_max: member.is_max,
        banked_xp: member.banked_xp,
    })),
});

/*
 * showModal() rather than the `open` attribute, for the reason the rest of the
 * app gives: `open` renders the dialog in normal flow, with no backdrop and no
 * focus trap. After nextTick because the element is behind a v-if.
 */
watch(() => props.cell, async (cell) => {
    if (!cell) return;

    draft.value = load(cell);

    await nextTick();
    dialog.value?.showModal();
}, { immediate: true });

const close = () => dialog.value?.close();

/**
 * Write one attribute, to one seat or to all of them.
 *
 * A well balanced crew is trained as one — the whole point of calling it
 * balanced is that its members are at the same place — so while that box is
 * ticked, setting a seat's zero-skills, skill level or max sets every seat's.
 * Entering the same figure five times is what that tick is there to save.
 *
 * Banked XP is the exception, and stays per seat: it is the one attribute that
 * legitimately differs across a balanced crew, because a member recruited late
 * is genuinely behind the ones beside them.
 *
 * Ticking the box does not reach back and level a crew that is already
 * mismatched. It takes effect from the next edit, so nothing is quietly
 * overwritten by a tick — the first attribute you touch is what the set snaps
 * to.
 */
const setOnMembers = (member, field, value) => {
    const seats = draft.value.is_balanced ? draft.value.members : [member];

    seats.forEach((seat) => (seat[field] = value));
};

const n = (v) => new Intl.NumberFormat().format(v ?? 0);

/*
 * Grouped while idle, bare while editing — the same bargain EditableNumber
 * strikes on the boards. Separators are worth having on a seven-figure balance
 * and fight the cursor while you are typing one.
 */
const focused = ref(null);
const display = (member) => (focused.value === member.slot ? String(member.banked_xp || '') : n(member.banked_xp));

const onBankedInput = (member, event) => {
    const digits = event.target.value.replace(/[^\d]/g, '');

    member.banked_xp = Number(digits || 0);
    event.target.value = digits;
};

const onBankedFocus = (member, event) => {
    focused.value = member.slot;
    nextTick(() => event.target.select());
};

const save = () => {
    saving.value = true;

    router.put(`/wot/crews/tanks/${props.cell.tank_id}`, {
        is_balanced: draft.value.is_balanced,
        // Only what the server stores: the role and its letter came from the
        // encyclopedia and are the vehicle's to describe, not this form's.
        members: draft.value.members.map(({ slot, zero_skills, skill_level, is_max, banked_xp }) => ({
            slot,
            zero_skills,
            skill_level,
            is_max,
            banked_xp,
        })),
    }, {
        preserveScroll: true,
        only: ['crews'],
        onFinish: () => (saving.value = false),
        onSuccess: close,
    });
};

/*
 * Emptying a vehicle deletes the record rather than zeroing it: no crew is the
 * absence of one, and a set of zeroes would still paint the cell as a crew that
 * happens to be untrained.
 */
const clear = () => {
    saving.value = true;

    router.delete(`/wot/crews/tanks/${props.cell.tank_id}`, {
        preserveScroll: true,
        only: ['crews'],
        onFinish: () => (saving.value = false),
        onSuccess: close,
    });
};
</script>

<template>
    <dialog
        v-if="cell"
        ref="dialog"
        class="modal modal--dark modal--wide"
        :aria-label="`Crew of the ${cell.name}`"
        @click.self="close"
        @close="emit('close')"
    >
        <div class="border border-wot-border bg-wot-panel-solid p-6">
            <h3 class="text-lg normal-case tracking-normal text-wot-heading">
                {{ cell.name }} <span class="text-wot-dim">— crew</span>
            </h3>

            <p v-if="!cell.has_crew" class="mt-1 text-xs text-wot-bad">
                No crew recorded. Filling this in puts one in the vehicle.
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-xs uppercase tracking-wider text-wot-dim">
                            <th scope="col" class="py-2 pe-3 text-left font-bold">Seat</th>
                            <th scope="col" class="px-3 py-2 text-left font-bold">Zero-skills</th>
                            <th scope="col" class="px-3 py-2 text-center font-bold">Max</th>
                            <th scope="col" class="px-3 py-2 text-left font-bold">Skill level</th>
                            <th scope="col" class="ps-3 py-2 text-right font-bold">Banked XP</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-wot-border-soft">
                        <tr v-for="member in draft.members" :key="member.slot">
                            <td class="py-2 pe-3">
                                <span class="text-wot-text">{{ member.name }}</span>

                                <!-- One body, several jobs. The board spells a
                                     single letter for this seat, so the other
                                     roles are only ever said in words — stacked
                                     under the primary rather than run along one
                                     line, so a seat covering three reads as
                                     three things rather than as a sentence. -->
                                <ul v-if="member.also.length" role="list" class="text-xs text-wot-dim">
                                    <li v-for="role in member.also" :key="role">+ {{ role }}</li>
                                </ul>
                            </td>

                            <td class="px-3 py-2">
                                <!--
                                    Three values, all of them one character, so
                                    they sit out in the open rather than behind
                                    a dropdown: the answer is visible without
                                    opening anything, and setting one is a
                                    single click instead of two.

                                    Real radios under the labels rather than
                                    buttons wearing ARIA. The grouping, the
                                    arrow keys and the announcement all come
                                    free, and the input is only visually hidden
                                    — `sr-only`, never `hidden`, which would
                                    take it out of the tab order with it.

                                    :checked and @change rather than v-model,
                                    because a change here can land on every seat
                                    rather than on this one.
                                -->
                                <fieldset class="flex gap-1">
                                    <legend class="sr-only">Zero-skills on the {{ member.name }}</legend>

                                    <label
                                        v-for="count in ZERO_SKILLS"
                                        :key="count"
                                        class="cursor-pointer border px-2.5 py-0.5 text-xs font-bold tabular-nums transition-colors focus-within:ring-1 focus-within:ring-wot-gold"
                                        :class="member.zero_skills === count
                                            ? 'border-wot-gold text-wot-gold'
                                            : 'border-wot-border text-wot-dim hover:text-wot-text'"
                                    >
                                        <input
                                            type="radio"
                                            class="sr-only"
                                            :name="`zero-skills-${member.slot}`"
                                            :value="count"
                                            :checked="member.zero_skills === count"
                                            @change="setOnMembers(member, 'zero_skills', count)"
                                        >
                                        {{ count }}
                                    </label>
                                </fieldset>
                            </td>

                            <td class="px-3 py-2 text-center">
                                <input
                                    :checked="member.is_max"
                                    type="checkbox"
                                    class="border"
                                    :aria-label="`The ${member.name} is maxed`"
                                    @change="setOnMembers(member, 'is_max', $event.target.checked)"
                                >
                            </td>

                            <td class="px-3 py-2">
                                <select
                                    :value="member.skill_level"
                                    class="border border-wot-border bg-wot-sunken px-2 py-1 text-sm"
                                    :aria-label="`Skill level of the ${member.name}`"
                                    @change="setOnMembers(member, 'skill_level', Number($event.target.value))"
                                >
                                    <option v-for="level in levels" :key="level" :value="level">
                                        {{ level === 0 ? 'Base — 100%' : level }}
                                    </option>
                                </select>
                            </td>

                            <td class="ps-3 py-2 text-right">
                                <input
                                    :value="display(member)"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    class="w-28 border border-wot-border bg-wot-sunken px-1 py-0.5 text-right text-sm tabular-nums focus:border-wot-gold"
                                    :aria-label="`Banked XP on the ${member.name}`"
                                    @input="onBankedInput(member, $event)"
                                    @focus="onBankedFocus(member, $event)"
                                    @blur="focused = null"
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <label class="mt-4 flex items-center gap-2 text-sm text-wot-text">
                <input v-model="draft.is_balanced" type="checkbox" class="border">
                This crew is well balanced
            </label>

            <!-- Said out loud, because a control that quietly writes four other
                 rows is a surprise otherwise. -->
            <p class="mt-1 text-xs text-wot-dim">
                <template v-if="draft.is_balanced">
                    The set trains as one: zero-skills, skill level and max apply to every seat. Banked XP stays per member.
                </template>
                <template v-else>
                    Tick this to set zero-skills, skill level and max across the whole set at once.
                </template>
            </p>

            <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
                <button
                    v-if="cell.has_crew"
                    type="button"
                    class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                    :disabled="saving"
                    @click="clear"
                >
                    Empty the tank
                </button>
                <span v-else></span>

                <div class="flex flex-wrap gap-3">
                    <button
                        type="button"
                        class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-muted transition-colors hover:border-wot-gold hover:text-wot-gold"
                        @click="close"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="border border-wot-gold px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-gold transition-colors hover:bg-wot-gold hover:text-wot-abyss"
                        :class="saving ? 'opacity-50' : ''"
                        :disabled="saving"
                        @click="save"
                    >
                        Save crew
                    </button>
                </div>
            </div>
        </div>
    </dialog>
</template>
