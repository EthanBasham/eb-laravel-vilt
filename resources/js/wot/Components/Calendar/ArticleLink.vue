<script setup>
/**
 * "Read article" — out to worldoftanks.com, where the dates on this page were
 * published in the first place.
 *
 * Every event on the calendar was parsed out of an article, so this is the way
 * back to the source for anything the parser flattened or got wrong.
 */
defineProps({
    url: { type: String, required: true },
});
</script>

<template>
    <a :href="url" target="_blank" rel="noopener noreferrer">
        <slot>Read article &rarr;</slot>
    </a>
</template>
