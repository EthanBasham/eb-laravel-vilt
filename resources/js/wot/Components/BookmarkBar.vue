<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

import BookmarkEditor from './BookmarkEditor.vue';

const page = usePage();
const bookmarks = computed(() => page.props.bookmarks ?? []);

const editing = ref(false);
</script>

<template>
    <!--
        A strip of links out to the community sites, sitting between the header
        and the page. Every entry leaves the app, so they are plain <a>s rather
        than Inertia <Link>s, and all open in a new tab: the point of the bar is
        to reach a second site without losing the dashboard you were reading.

        The links scroll sideways rather than wrapping. A wrapping bar changes
        height as the list grows, which pushes the page down by a line the first
        time someone adds an eleventh bookmark; scrolling keeps it exactly one
        line tall whatever the list holds — which matters now that the list is
        the user's to lengthen.
    -->
    <div class="border-b border-wot-border bg-wot-abyss">
        <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 sm:px-6 lg:px-8">
            <nav
                class="wot-bookmarks flex min-w-0 flex-1 items-center gap-1 overflow-x-auto"
                aria-label="Bookmarks"
            >
                <a
                    v-for="bookmark in bookmarks"
                    :key="bookmark.url"
                    :href="bookmark.url"
                    :title="bookmark.title"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="group flex shrink-0 items-center gap-1.5 whitespace-nowrap border-b-2 border-transparent px-2 py-2 text-xs font-medium text-wot-muted transition-colors hover:border-wot-gold hover:text-wot-gold-bright"
                >
                    {{ bookmark.label }}

                    <!-- The usual out-of-site arrow. Held at a low opacity so ten
                         of them don't read as louder than the labels. -->
                    <svg
                        class="h-2.5 w-2.5 opacity-40 transition-opacity group-hover:opacity-100"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M7 17 17 7M9 7h8v8" />
                    </svg>
                </a>

                <!-- An emptied bar still shows its rail, so the way back to the
                     editor is where it always was rather than gone with the
                     last link. -->
                <span v-if="!bookmarks.length" class="py-2 text-xs italic text-wot-dim">
                    No bookmarks yet.
                </span>
            </nav>

            <button
                type="button"
                class="shrink-0 border-b-2 border-transparent py-2 text-xs font-medium text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold-bright"
                @click="editing = true"
            >
                Manage
            </button>
        </div>
    </div>

    <BookmarkEditor :open="editing" :bookmarks="bookmarks" @close="editing = false" />
</template>

<style scoped>
/*
 * Firefox is the only engine that draws a scrollbar in the flow here rather
 * than over the content, which would add a permanent grey rule under the strip
 * on a list long enough to overflow. The row is dragged and swiped, not
 * scrolled by its bar.
 */
.wot-bookmarks {
    scrollbar-width: none;
}

.wot-bookmarks::-webkit-scrollbar {
    display: none;
}
</style>
