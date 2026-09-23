<script setup>
/**
 * Laravel's own paginator links, drawn as chips.
 *
 * `links` arrives from a LengthAwarePaginator: Previous, a numbered link per
 * page, Next. A link with no `url` is a boundary or an ellipsis, and renders as
 * a span — a disabled anchor is still in the tab order and still announces as a
 * link.
 *
 * `v-html` on the label is deliberate and safe here: the labels are Laravel's
 * own, and Previous and Next arrive as `&laquo;`/`&raquo;` entities that would
 * otherwise print literally.
 */
defineProps({
    links: { type: Array, required: true },
});
</script>

<template>
    <!-- Three links is Previous, one page, Next — a paginator for a single page
         of results, which is nothing to navigate. -->
    <nav v-if="links.length > 3" class="flex flex-wrap gap-1" aria-label="Pagination">
        <component
            :is="link.url ? 'a' : 'span'"
            v-for="link in links"
            :key="link.label"
            :href="link.url"
            class="border px-3 py-1.5 text-sm"
            :class="link.active
                ? 'border-wot-gold text-wot-gold'
                : link.url ? 'border-wot-border text-wot-muted hover:text-wot-gold' : 'border-wot-border-soft text-wot-dim'"
            v-html="link.label"
        />
    </nav>
</template>
