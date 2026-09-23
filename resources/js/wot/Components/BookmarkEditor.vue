<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import WotDialog from './WotDialog.vue';

/**
 * The bookmarks bar, edited as a whole.
 *
 * Reordering and removing are most of what happens here and neither expresses
 * well as a diff, so the list is sent entire and the server replaces what it
 * holds — the same bargain the crew editor strikes.
 *
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    bookmarks: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

// The list itself, so a freshly added row can be found and focused without
// reaching through the dialog for it.
const rows = ref(null);

// A working copy, so cancelling leaves the bar as it was and so a row being
// typed into does not move the strip behind the modal on every keystroke.
const form = useForm({ bookmarks: [] });

const load = () => props.bookmarks.map((bookmark) => ({
    label: bookmark.label ?? '',
    url: bookmark.url ?? '',
    title: bookmark.title ?? '',
}));

watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();
    form.bookmarks = load();
}, { immediate: true });

const close = () => dialog.value?.close();

const add = async () => {
    form.bookmarks.push({ label: '', url: '', title: '' });

    // Land the cursor in the row that just appeared, so adding three in a row
    // is three clicks and three names rather than a click each time.
    await nextTick();
    rows.value?.querySelector('[data-row]:last-of-type input')?.focus();
};

const remove = (index) => form.bookmarks.splice(index, 1);

/** Swap a row with its neighbour. `to` is out of range at the two ends. */
const move = (index, to) => {
    if (to < 0 || to >= form.bookmarks.length) return;

    const [row] = form.bookmarks.splice(index, 1);

    form.bookmarks.splice(to, 0, row);
};

/*
 * Validation comes back keyed by the row's index — `bookmarks.3.url` — so the
 * message can be printed against the row that earned it rather than in a pile
 * at the top. Note these keys go stale the moment a row moves, which is why
 * every reorder and removal clears them.
 */
const errorFor = (index) => form.errors[`bookmarks.${index}.label`] ?? form.errors[`bookmarks.${index}.url`] ?? null;

watch(() => form.bookmarks.length, () => form.clearErrors());

const isBlank = (row) => !row.label.trim() && !row.url.trim();

/*
 * An untouched row left behind by the Add button is a mistake, not an empty
 * bookmark — dropping it is friendlier than refusing the whole save.
 *
 * It is dropped from the draft rather than inside transform() so that what is
 * posted and what is on screen are indexed alike. Errors come back keyed by
 * position, and filtering only on the way out would print row 4's message
 * against row 5 whenever a blank sat above it.
 */
const save = () => {
    form.bookmarks = form.bookmarks.filter((row) => !isBlank(row));

    form
        .transform((data) => ({
            bookmarks: data.bookmarks.map((row) => ({
                label: row.label.trim(),
                url: row.url.trim(),
                title: row.title.trim() || null,
            })),
        }))
        .put('/wot/bookmarks', {
            preserveScroll: true,
            onSuccess: close,
        });
};

const count = computed(() => form.bookmarks.filter((row) => !isBlank(row)).length);
</script>

<template>
    <WotDialog ref="dialog" wide :open="open" label="Manage bookmarks" @close="emit('close')">
        <h3 class="text-lg normal-case tracking-normal text-wot-heading">
            Bookmarks <span class="text-wot-dim">— {{ count }} in the bar</span>
        </h3>

        <p class="mt-1 text-xs text-wot-dim">
            The name is what the strip prints; the description is its hover tooltip and can be left blank.
        </p>

        <p v-if="!form.bookmarks.length" class="mt-6 border border-dashed border-wot-border px-4 py-6 text-center text-sm text-wot-dim">
            No bookmarks. The bar stays hidden until there is one.
        </p>

        <ul v-else ref="rows" role="list" class="mt-4 max-h-[26rem] space-y-3 overflow-y-auto pe-1">
            <li
                v-for="(row, index) in form.bookmarks"
                :key="index"
                data-row
                class="border border-wot-border-soft bg-wot-sunken p-3"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <input
                        v-model="row.label"
                        type="text"
                        class="w-full border px-2 py-1 text-sm sm:w-40"
                        placeholder="Name"
                        :aria-label="`Bookmark ${index + 1} name`"
                    >
                    <input
                        v-model="row.url"
                        type="text"
                        inputmode="url"
                        class="w-full min-w-0 flex-1 border px-2 py-1 text-sm"
                        placeholder="tanks.gg"
                        :aria-label="`Bookmark ${index + 1} address`"
                    >

                    <div class="ms-auto flex items-center gap-1">
                        <!-- Up and down rather than dragging: the bar is
                             short, and a drag would be a dependency and a
                             keyboard trap both. -->
                        <button
                            type="button"
                            class="border border-wot-border px-2 py-1 text-xs text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:opacity-30 disabled:hover:border-wot-border disabled:hover:text-wot-dim"
                            :disabled="index === 0"
                            :aria-label="`Move ${row.label || 'bookmark'} earlier`"
                            @click="move(index, index - 1)"
                        >
                            &uarr;
                        </button>
                        <button
                            type="button"
                            class="border border-wot-border px-2 py-1 text-xs text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:opacity-30 disabled:hover:border-wot-border disabled:hover:text-wot-dim"
                            :disabled="index === form.bookmarks.length - 1"
                            :aria-label="`Move ${row.label || 'bookmark'} later`"
                            @click="move(index, index + 1)"
                        >
                            &darr;
                        </button>
                        <button
                            type="button"
                            class="border border-wot-border px-2 py-1 text-xs text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                            :aria-label="`Remove ${row.label || 'bookmark'}`"
                            @click="remove(index)"
                        >
                            &times;
                        </button>
                    </div>
                </div>

                <input
                    v-model="row.title"
                    type="text"
                    class="mt-2 w-full border px-2 py-1 text-xs"
                    placeholder="Description (optional) — shown on hover"
                    :aria-label="`Bookmark ${index + 1} description`"
                >

                <p v-if="errorFor(index)" class="mt-2 text-xs text-wot-bad">
                    {{ errorFor(index) }}
                </p>
            </li>
        </ul>

        <div class="mt-6 flex flex-wrap items-center justify-between gap-3">
            <button
                type="button"
                class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-muted transition-colors hover:border-wot-gold hover:text-wot-gold"
                @click="add"
            >
                Add a bookmark
            </button>

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
                    :class="form.processing ? 'opacity-50' : ''"
                    :disabled="form.processing"
                    @click="save"
                >
                    Save bookmarks
                </button>
            </div>
        </div>
    </WotDialog>
</template>
