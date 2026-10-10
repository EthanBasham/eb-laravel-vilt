<script setup>
import { computed, ref } from 'vue';
import { IconPlus, IconTank, IconWorld } from '@tabler/icons-vue';
import TankerIcon from './TankerIcon.vue';
import NationFlag from '../NationFlag.vue';

/**
 * One tanker on the Battle Pass roster, for reading rather than editing.
 *
 * The roster table is a row of controls, which is what keeping it up to date
 * needs and not what looking someone up needs. This says the same things with
 * almost nothing to click: who they are, and where they are.
 *
 * What it does offer are the two moves a tanker makes through the roster, one
 * button each and only on the card it applies to: someone not yet collected can
 * be marked as collected, which puts them in the barracks, and someone in the
 * barracks gets a small tank button that opens the tank picker and posts them.
 * Someone in a tank has their role as a button — "N/A" until they have one —
 * which opens a row of role letters; choosing one sets it and puts the letters
 * away again. Everything else is the table's.
 */
const props = defineProps({
    crew: { type: Object, required: true },
    roles: { type: Object, required: true },
    statuses: { type: Object, required: true },
    genders: { type: Object, required: true },
    // The tank they are posted to, resolved by the roster, or null for none —
    // the vehicle list is deferred, so this can be 'Loading…' for a moment.
    tankName: { type: String, default: null },
});

const genderName = computed(() => props.genders[props.crew.gender]?.name ?? props.crew.gender);

const statusLabel = computed(() => props.statuses[props.crew.status] ?? props.crew.status);

// Someone in a tank is described by the tank; "In tank" is only what is left
// to say when no tank has been chosen for them yet.
const posting = computed(() => (props.crew.status === 'in_tank' ? props.tankName : null));

const roleName = computed(() => (posting.value ? props.roles[props.crew.crew_role]?.name ?? null : null));

const isInBarracks = computed(() => props.crew.status === 'in_barracks');

const emit = defineEmits(['chooseTank', 'collect', 'setRole']);

const isInTank = computed(() => props.crew.status === 'in_tank');

// The role letters stay out of sight until the role is clicked: most of the
// time a card is being read, and five buttons on every posted card is a lot of
// control for something set once.
const choosingRole = ref(false);

// The lit letter again takes the role away, so a wrong click can be undone
// from the card it was made on.
const pickRole = (role) => {
    emit('setRole', props.crew.crew_role === role ? null : role);

    choosingRole.value = false;
};

// Not collected yet is the one status that is about the tanker rather than
// about where they are, so the card steps back for it. The fade goes on what
// describes them rather than on the card, or it would take the button that
// collects them down with it.
const isUncollected = computed(() => props.crew.status === 'uncollected');

const fade = computed(() => (isUncollected.value ? 'opacity-50' : ''));
</script>

<template>
    <li class="relative flex items-center gap-3 border border-wot-border bg-wot-panel p-3">
        <TankerIcon
            :gender="crew.gender"
            class="shrink-0"
            :class="[crew.gender === 'female' ? 'text-wot-pink' : 'text-wot-blue-light', fade]"
            role="img"
            :aria-label="genderName"
        />

        <div class="min-w-0 flex-1" :class="fade">
            <!-- pr-8 keeps a long name out from under the season. -->
            <p class="flex items-center gap-2 pr-8 text-sm font-bold text-wot-heading">
                <NationFlag v-if="crew.nation" :nation="crew.nation" />
                <IconWorld
                    v-else
                    :size="16"
                    stroke-width="1.75"
                    class="shrink-0 text-wot-dim"
                    role="img"
                    aria-label="No nation"
                />
                <span class="min-w-0 truncate">{{ crew.name }}</span>
            </p>

            <p class="mt-1 flex items-center gap-2 text-xs text-wot-dim">
                <span class="min-w-0 truncate">
                    <template v-if="posting">
                        <span class="text-wot-text">{{ posting }}</span>
                        ·
                        <button
                            type="button"
                            class="underline decoration-dotted underline-offset-2 transition-colors hover:text-wot-gold"
                            :class="choosingRole ? 'text-wot-gold' : ''"
                            :aria-expanded="choosingRole"
                            :aria-label="`Role ${crew.name} serves as: ${roleName ?? 'none'}. Change`"
                            @click="choosingRole = !choosingRole"
                        >
                            {{ roleName ?? 'N/A' }}
                        </button>
                    </template>
                    <template v-else>{{ statusLabel }}</template>
                </span>

                <button
                    v-if="isInBarracks"
                    type="button"
                    class="inline-flex shrink-0 items-center justify-center border border-wot-border p-0.5 text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold"
                    :title="`Choose a tank for ${crew.name}`"
                    :aria-label="`Choose a tank for ${crew.name}`"
                    @click="$emit('chooseTank')"
                >
                    <IconTank :size="14" stroke-width="1.75" />
                </button>
            </p>

            <!-- One letter per role, as the crew board spells them. Buttons
                 rather than radios because the lit one can be switched off. -->
            <div v-if="isInTank && choosingRole" class="mt-1.5 flex gap-1" role="group" :aria-label="`Role ${crew.name} serves as`">
                <button
                    v-for="(role, key) in roles"
                    :key="key"
                    type="button"
                    class="w-5 border text-center text-[10px] font-bold leading-4 transition-colors"
                    :class="crew.crew_role === key
                        ? 'border-wot-gold text-wot-gold'
                        : 'border-wot-border text-wot-dim hover:text-wot-text'"
                    :title="role.name"
                    :aria-label="role.name"
                    :aria-pressed="crew.crew_role === key"
                    @click="pickRole(key)"
                >
                    {{ role.letter }}
                </button>
            </div>
        </div>

        <span
            v-if="crew.season !== null"
            class="absolute right-2 top-1.5 text-[10px] font-bold tabular-nums tracking-wider text-wot-dim"
            :class="fade"
            :title="`Season ${crew.season}`"
        >
            <span class="sr-only">Season </span><span aria-hidden="true">S</span>{{ crew.season }}
        </span>

        <button
            v-if="isUncollected"
            type="button"
            class="absolute bottom-1.5 right-2 inline-flex items-center justify-center border border-wot-border p-0.5 text-wot-dim transition-colors hover:border-wot-good hover:text-wot-good"
            :title="`Mark ${crew.name} as collected (in barracks)`"
            :aria-label="`Mark ${crew.name} as collected, in barracks`"
            @click="$emit('collect')"
        >
            <IconPlus :size="14" stroke-width="2.25" />
        </button>
    </li>
</template>
