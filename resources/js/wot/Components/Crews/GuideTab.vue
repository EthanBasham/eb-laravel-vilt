<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { IconChevronDown, IconChevronRight, IconPlus, IconTrash } from '@tabler/icons-vue';
import EmptyState from '../EmptyState.vue';
import PerkOrganizer from './PerkOrganizer.vue';

/**
 * Crew guides: for each role, which perks to train and in what order.
 *
 * Several guides can be kept — what a heavy that brawls wants from its five
 * seats is not what a light that scouts does. They are listed one under
 * another, closed, and any number can be open at once: two side by side is how
 * one is checked against another. An open guide is one row per role: the role,
 * its perks sorted into the two buckets, and its notes.
 *
 * Saved as it is changed, a role at a time: a perk when it is dropped, a note
 * or the guide's name when its field is left.
 */
const props = defineProps({
    guides: { type: Array, default: () => [] },
    roles: { type: Object, default: () => ({}) },
    // The catalogue of perks, and which of them each role can train.
    perks: { type: Object, default: () => ({}) },
    rolePerks: { type: Object, default: () => ({}) },
});

const GUIDES_ONLY = { preserveScroll: true, only: ['guides'] };

// Which guides are open. Everything starts closed; the list is for finding one.
const openIds = ref(new Set());

const isOpen = (guide) => openIds.value.has(guide.id);

const toggleOpen = (guide) => {
    const next = new Set(openIds.value);

    next.has(guide.id) ? next.delete(guide.id) : next.add(guide.id);

    openIds.value = next;
};

const newName = ref('');

const addGuide = () => {
    const name = newName.value.trim();

    if (!name) {
        return;
    }

    const known = new Set(props.guides.map((item) => item.id));

    router.post('/wot/crews/guides', { name }, {
        ...GUIDES_ONLY,
        onSuccess: (page) => {
            newName.value = '';

            // Open the one just made — whichever id was not here before —
            // since filling it in is the reason it was added.
            const made = page.props.guides.find((item) => !known.has(item.id));

            if (made) {
                openIds.value = new Set([...openIds.value, made.id]);
            }
        },
    });
};

const rename = (guide, event) => {
    const name = event.target.value.trim();

    // An emptied field goes back to the name it had rather than being sent to
    // be refused.
    if (!name) {
        event.target.value = guide.name;

        return;
    }

    if (name !== guide.name) {
        router.patch(`/wot/crews/guides/${guide.id}`, { name }, GUIDES_ONLY);
    }
};

const removeGuide = (guide) => {
    if (window.confirm(`Delete the guide "${guide.name}"? Its perks and notes go with it.`)) {
        router.delete(`/wot/crews/guides/${guide.id}`, GUIDES_ONLY);
    }
};

const saveRole = (guide, role, payload) => router.put(`/wot/crews/guides/${guide.id}/roles/${role}`, payload, GUIDES_ONLY);

const saveNotes = (guide, role, event) => {
    const notes = event.target.value.trim() || null;

    if (notes !== (guide.roles[role].notes ?? null)) {
        saveRole(guide, role, { notes });
    }
};

/** How many perks each role trains, for the line a closed guide shows. */
const summary = (guide) => Object.entries(props.roles)
    .map(([key, role]) => `${role.letter} ${guide.roles[key].included.length}`)
    .join(' · ');

const field = 'border border-wot-border bg-wot-sunken px-2 py-1 text-sm focus:border-wot-gold';
</script>

<template>
    <section class="mt-4" aria-labelledby="guide-heading">
        <h2 id="guide-heading" class="sr-only">Guide</h2>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <form class="flex items-center gap-1" @submit.prevent="addGuide">
                <input
                    v-model="newName"
                    type="text"
                    maxlength="100"
                    placeholder="New guide"
                    class="w-56"
                    :class="field"
                    aria-label="Name of a new guide"
                >
                <button
                    type="submit"
                    class="inline-flex items-center justify-center border border-wot-gold p-1 text-wot-gold transition-colors hover:bg-wot-gold hover:text-wot-abyss"
                    title="Add guide"
                    aria-label="Add guide"
                >
                    <IconPlus :size="14" stroke-width="2.25" />
                </button>
            </form>

            <p class="text-right text-xs text-wot-dim">
                Drag a perk into the green to train it, in order from the left; into the red to leave it.
                Arrow keys and double click work too.
            </p>
        </div>

        <EmptyState v-if="!guides.length" class="mt-4">
            No guides yet. Name one above to start it — "Heavy brawler", say, or "Light scout".
        </EmptyState>

        <ul v-else role="list" class="mt-4 space-y-2">
            <li v-for="guide in guides" :key="guide.id" class="border border-wot-border bg-wot-panel">
                <!-- The whole heading opens and closes the guide, wherever it is
                     clicked. The chevron inside it is the real button — it is
                     what the keyboard and a screen reader get — and its own
                     click is stopped so the two do not cancel out. The name
                     field and the delete button stop theirs too: using either
                     is not asking to close the guide. -->
                <div
                    class="flex cursor-pointer items-center gap-2 px-3 py-2 transition-colors hover:bg-wot-sunken"
                    @click="toggleOpen(guide)"
                >
                    <button
                        type="button"
                        class="inline-flex min-w-0 items-center gap-2 text-left text-sm font-bold transition-colors hover:text-wot-gold"
                        :class="isOpen(guide) ? 'text-wot-gold' : 'flex-1 text-wot-text'"
                        :aria-expanded="isOpen(guide)"
                        :aria-label="isOpen(guide) ? `Close ${guide.name}` : `Open ${guide.name}`"
                        @click.stop="toggleOpen(guide)"
                    >
                        <component :is="isOpen(guide) ? IconChevronDown : IconChevronRight" :size="16" stroke-width="2" class="shrink-0" />
                        <span v-if="!isOpen(guide)" class="truncate">{{ guide.name }}</span>
                    </button>

                    <template v-if="isOpen(guide)">
                        <input
                            :value="guide.name"
                            type="text"
                            maxlength="100"
                            class="w-64 cursor-text font-bold"
                            :class="field"
                            aria-label="Name of this guide"
                            @click.stop
                            @change="rename(guide, $event)"
                        >

                        <button
                            type="button"
                            class="ms-auto inline-flex items-center justify-center border border-wot-border p-1 text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                            :title="`Delete ${guide.name}`"
                            :aria-label="`Delete ${guide.name}`"
                            @click.stop="removeGuide(guide)"
                        >
                            <IconTrash :size="14" stroke-width="2.25" />
                        </button>
                    </template>

                    <!-- What a closed guide can say for itself: how many
                         perks each role trains. -->
                    <span v-else class="shrink-0 text-xs tabular-nums text-wot-dim" :title="'Perks trained per role'">
                        {{ summary(guide) }}
                    </span>
                </div>

                <!-- One grid for all five rows, each row a subgrid of it: the
                     perk column is then as wide as the widest row's — the
                     Commander has a perk more than the rest — and every notes
                     field starts in the same place and runs the same length. -->
                <ul
                    v-if="isOpen(guide)"
                    role="list"
                    class="divide-y divide-wot-border-soft border-t border-wot-border lg:grid lg:grid-cols-[8rem_auto_minmax(0,1fr)]"
                >
                    <li
                        v-for="(role, key) in roles"
                        :key="key"
                        class="grid items-center gap-3 px-4 py-3 lg:col-span-3 lg:grid-cols-subgrid"
                    >
                        <h3 class="text-sm">{{ role.name }}</h3>

                        <PerkOrganizer
                            :included="guide.roles[key].included"
                            :trainable="rolePerks[key] ?? []"
                            :perks="perks"
                            :role-name="role.name"
                            @change="saveRole(guide, key, { included: $event })"
                        />

                        <!-- A few lines rather than one, in smaller type than
                             the rest of the row: it is commentary on the
                             perks, and should read as that. Set a little apart
                             from the buckets so it is not taken for a third. -->
                        <textarea
                            :value="guide.roles[key].notes ?? ''"
                            rows="2"
                            maxlength="255"
                            placeholder="Notes"
                            class="w-full resize-y border border-wot-border bg-wot-sunken px-2 py-1 text-xs focus:border-wot-gold lg:ms-4 lg:w-[calc(100%-1rem)]"
                            :aria-label="`Notes for the ${role.name}`"
                            @change="saveNotes(guide, key, $event)"
                        ></textarea>
                    </li>
                </ul>
            </li>
        </ul>
    </section>
</template>
