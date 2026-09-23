<script setup>
import { computed } from 'vue';
import { IconPlus, IconTrash } from '@tabler/icons-vue';
import GenderToggle from './GenderToggle.vue';
import TankButton from './TankButton.vue';

/**
 * One tanker on the Battle Pass roster — and, in `draft`, the row above it
 * where a new one is entered.
 *
 * The two were written out separately once: the same eight columns twice over,
 * which is eight chances for the add row's fields to stop lining up with the
 * roster's. They are one row now because they *are* one row — the draft's
 * fields sit in the columns its values will land in, which is the whole reason
 * the add row heads the table rather than trailing it.
 *
 * Every control reports a change the same way, as a patch of one field, and the
 * parent decides what that means: a saved row writes it straight to the server,
 * the draft only collects it until the row is added. That is why nothing here
 * uses v-model on the crew itself — the row states what changed, it does not
 * decide where it goes.
 */
const props = defineProps({
    // A saved tanker, or the draft being assembled.
    crew: { type: Object, required: true },
    draft: { type: Boolean, default: false },
    roles: { type: Object, required: true },
    statuses: { type: Object, required: true },
    genders: { type: Object, required: true },
    nations: { type: Object, required: true },
    // What the tank column shows, resolved by the roster — the vehicle list is
    // deferred, so a posting can exist before there is a name to show for it.
    tankLabel: { type: String, required: true },
    tankNation: { type: String, default: '' },
    /*
     * The draft's fields join a <form> declared outside the table, since a
     * <form> cannot wrap a <tr>. Only the fields that take part in submission
     * need it — the name for `required` and Enter-to-add, and the season beside
     * it.
     */
    formId: { type: String, default: null },
    // A rejected add used to fail silently: the row never appeared and nothing
    // said why.
    error: { type: String, default: null },
});

const emit = defineEmits(['save', 'remove', 'chooseTank']);

const emitSave = (payload) => emit('save', payload);

/** Who the row is about, for the labels every control needs one of. */
const who = computed(() => (props.draft ? 'the new crew member' : props.crew.name));

// Where someone is serving only means anything while they are in a tank, and
// the server clears both fields when the status moves off it.
const isPosted = computed(() => props.crew.status === 'in_tank');

/*
 * '-' is how a tanker with no season is written, and none is what gets stored:
 * the roster sorts newest season first with the season-less at the bottom, and
 * a null is what that ordering already puts there. A blank field means the same.
 *
 * Anything else is read for its digits, so a stray character never reaches the
 * server as a season.
 */
const toSeason = (value) => {
    const text = String(value ?? '').trim();

    if (text === '' || text === '-') {
        return null;
    }

    const digits = text.replace(/[^\d]/g, '');

    return digits === '' ? null : Number(digits);
};

/*
 * Writes the season and puts the field back in step with what was saved. The
 * bound value only redraws the input when it changes, so typing junk over a
 * season that was already none would otherwise leave the junk sitting there
 * looking accepted.
 */
const changeSeason = (event) => {
    const season = toSeason(event.target.value);

    event.target.value = season ?? (props.draft ? '' : '-');

    emitSave({ season });
};

const field = 'border border-wot-border bg-wot-sunken px-2 py-1 text-sm focus:border-wot-gold';
const select = 'border border-wot-border bg-wot-sunken px-2 py-1 text-sm';
</script>

<template>
    <tr :class="draft ? '' : 'hover:bg-wot-sunken'">
        <td class="px-4" :class="draft ? 'py-3 align-top' : 'py-2'">
            <!-- px-2 py-1 like the selects beside it: the row's controls are a
                 line of boxes and one of them being two pixels shorter reads as
                 a misalignment rather than as a difference. -->
            <input
                :value="crew.name"
                :form="formId"
                type="text"
                :required="draft"
                :placeholder="draft ? 'New crew member' : undefined"
                class="w-40"
                :class="field"
                :aria-label="`Name of ${who}`"
                @change="emitSave({ name: $event.target.value })"
            >

            <p v-if="error" class="mt-1 text-xs text-wot-bad" role="alert">{{ error }}</p>
        </td>

        <td class="px-3" :class="draft ? 'py-3 align-top' : 'py-2'">
            <select
                :value="crew.nation ?? ''"
                :class="select"
                :aria-label="`Assumed nation of ${who}`"
                @change="emitSave({ nation: $event.target.value || null })"
            >
                <option value="">—</option>
                <option v-for="(label, slug) in nations" :key="slug" :value="slug">{{ label }}</option>
            </select>
        </td>

        <td class="px-3" :class="draft ? 'py-3 align-top' : 'py-2'">
            <!-- No inputmode="numeric": the numeric keypad on a phone has no
                 '-', and '-' is a value here. -->
            <input
                :value="crew.season ?? (draft ? '' : '-')"
                :form="formId"
                type="text"
                :placeholder="draft ? '-' : undefined"
                class="w-14 tabular-nums"
                :class="field"
                :aria-label="`Season of ${who}`"
                @change="changeSeason"
            >
        </td>

        <td class="px-3" :class="draft ? 'py-3 align-top' : 'py-2'">
            <GenderToggle
                :genders="genders"
                :model-value="crew.gender"
                :group="`gender-${draft ? 'new' : crew.id}`"
                :legend="`Gender of ${who}`"
                @update:model-value="emitSave({ gender: $event })"
            />
        </td>

        <td class="px-3" :class="draft ? 'py-3 align-top' : 'py-2'">
            <select
                :value="crew.status"
                :class="select"
                :aria-label="`Status of ${who}`"
                @change="emitSave({ status: $event.target.value })"
            >
                <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
            </select>
        </td>

        <td class="px-3" :class="draft ? 'py-3 align-top' : 'py-2'">
            <TankButton
                :label="tankLabel"
                :nation="tankNation"
                :chosen="Boolean(crew.tank_id)"
                :disabled="!isPosted"
                :aria-label="`Tank ${who} is serving in: ${tankLabel}`"
                @choose="$emit('chooseTank')"
            />
        </td>

        <td class="px-3" :class="draft ? 'py-3 align-top' : 'py-2'">
            <select
                :value="crew.crew_role ?? ''"
                class="disabled:opacity-40"
                :class="select"
                :disabled="!isPosted"
                :aria-label="`Role ${who} serves as`"
                @change="emitSave({ crew_role: $event.target.value || null })"
            >
                <option value="">—</option>
                <option v-for="(role, key) in roles" :key="key" :value="key">{{ role.name }}</option>
            </select>
        </td>

        <td class="px-3 text-right" :class="draft ? 'py-3 align-top' : 'py-2'">
            <button
                v-if="draft"
                type="submit"
                :form="formId"
                class="inline-flex items-center justify-center border border-wot-gold p-1 text-wot-gold transition-colors hover:bg-wot-gold hover:text-wot-abyss"
                title="Add crew member"
                aria-label="Add crew member"
            >
                <IconPlus :size="14" stroke-width="2.25" />
            </button>

            <button
                v-else
                type="button"
                class="inline-flex items-center justify-center border border-wot-border p-1 text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                :title="`Remove ${crew.name}`"
                :aria-label="`Remove ${crew.name}`"
                @click="$emit('remove')"
            >
                <IconTrash :size="14" stroke-width="2.25" />
            </button>
        </td>
    </tr>
</template>
