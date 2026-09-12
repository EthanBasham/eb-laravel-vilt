<script setup>
import { computed } from 'vue';

/**
 * One vehicle's crew, as a short string of letters.
 *
 * Everything this cell has to say is carried by how the letters are drawn,
 * because a tech-tree grid has room for about five characters per tank and no
 * more:
 *
 *   colour      whether the crew is zero-skill — red none at all, white none
 *               zero-skill, yellow some, green every one of them
 *   bold        that member is maxed
 *   italic      the set is not well balanced
 *   green panel every member is maxed, which outranks the colour above
 *   underline   how many XP steps are zeroed out on that member — one rule for
 *               one, a double rule for two
 *   superscript skills trained, 1-6; a crew at base 100% shows none
 *
 * The letters themselves come from the encyclopedia — one per seat, in the
 * vehicle's own order — so the cell reads the way the garage panel does.
 */
const props = defineProps({
    cell: { type: Object, required: true },
});

defineEmits(['edit']);

const n = (v) => new Intl.NumberFormat().format(v ?? 0);

/**
 * The zeroed XP steps on one member, as a rule under the letter.
 *
 * Worn by the letter alone rather than by the seat: text-decoration inherits,
 * and a child cannot turn an ancestor's off — so underlining the wrapper would
 * drag the superscript skill level into it and produce a rule that ran under a
 * number meaning something else entirely.
 *
 * Offset so the rule clears the baseline. None of the five letters has a
 * descender, so there is nothing for it to collide with.
 */
const ZERO_MARK = {
    1: 'underline underline-offset-2',
    2: 'underline decoration-double underline-offset-2',
};

const zeroMark = (member) => ZERO_MARK[member.zero_skills] ?? '';

/*
 * The four states a crew can be in, as colours.
 *
 * White is the plain case rather than the good one: a crew with no zero-skill
 * members is perfectly serviceable, it is simply not what the board is hunting
 * for. Red is the absence of a crew, which is why it is not a shade of the
 * others.
 */
const TONE = {
    none: 'text-wot-bad',
    plain: 'text-wot-heading',
    mixed: 'text-wot-gold',
    all: 'text-wot-good',
};

/*
 * A maxed set overrides the zero-skill colour outright and takes a panel of its
 * own. Nothing more is owed on it, so what it needs to say is "finished" rather
 * than "this is how it was built" — and the eye should be able to skip it while
 * scanning a line for work left to do.
 */
const tone = computed(() => (props.cell.is_max
    ? 'bg-wot-good/15 text-wot-good'
    : TONE[props.cell.zero_state] ?? TONE.none));

// A recorded crew that has not been vouched for reads as unbalanced, because
// that is what the stored flag says. Nothing is italic until a crew exists.
const shape = computed(() => (props.cell.has_crew && !props.cell.is_balanced ? 'italic' : ''));

const summary = computed(() => {
    if (!props.cell.has_crew) {
        return `${props.cell.name} — no crew`;
    }

    const parts = [props.cell.is_max ? 'maxed' : null,
        props.cell.is_balanced ? 'well balanced' : 'not balanced',
        `${n(props.cell.banked_xp)} banked`];

    return `${props.cell.name} — ${parts.filter(Boolean).join(', ')}`;
});

/**
 * What one letter means, spelled out for the pointer and for assistive tech —
 * every cue above is visual, and this is where each of them is said in words.
 */
const memberTitle = (member) => {
    const role = member.also.length ? `${member.name} (also ${member.also.join(', ')})` : member.name;

    if (!props.cell.has_crew) {
        return `${role} — no crew`;
    }

    const skills = member.skill_level === 0 ? 'base 100%' : `${member.skill_level} skill${member.skill_level === 1 ? '' : 's'}`;
    const zero = member.zero_skills ? `${member.zero_skills} zero-skill` : 'not zero-skill';

    return [role, skills, zero, member.is_max ? 'max' : null, `${n(member.banked_xp)} banked`]
        .filter(Boolean)
        .join(' · ');
};
</script>

<template>
    <!-- A shared cell is the same vehicle as one already on the board: the crew
         is a fact about the tank, so the row that claims it is the one that
         edits it, and this one reports. -->
    <span
        v-if="cell.is_shared"
        class="inline-flex items-start gap-1 px-1 py-0.5 opacity-50"
        :class="[tone, shape]"
        :title="`${cell.name} — crew held on ${cell.shared_with}.`"
    >
        <span v-for="member in cell.members" :key="member.slot" :class="member.is_max ? 'font-bold' : ''">
            <span :class="zeroMark(member)">{{ member.letter }}</span><sup v-if="member.skill_level" class="text-[0.65em] font-normal opacity-70">{{ member.skill_level }}</sup>
        </span>
    </span>

    <button
        v-else-if="cell.members.length"
        type="button"
        class="inline-flex items-start gap-1 border border-transparent px-1 py-0.5 tracking-wide transition-colors hover:border-wot-gold"
        :class="[tone, shape]"
        :title="summary"
        :aria-label="`Edit the crew of the ${cell.name}`"
        @click="$emit('edit', cell)"
    >
        <span
            v-for="member in cell.members"
            :key="member.slot"
            :class="member.is_max ? 'font-bold' : ''"
            :title="memberTitle(member)"
        >
            <!-- The rule for zeroed steps goes on the letter and not on this
                 wrapper, so it stops short of the superscript beside it. -->
            <span :class="zeroMark(member)">{{ member.letter }}</span><sup v-if="member.skill_level" class="text-[0.65em] font-normal opacity-70">{{ member.skill_level }}</sup>
        </span>
    </button>

    <!-- A vehicle the encyclopedia publishes no crew for. Nothing to draw and
         nothing to edit, so it reads like an empty tier. -->
    <span v-else class="text-wot-muted">·</span>
</template>
