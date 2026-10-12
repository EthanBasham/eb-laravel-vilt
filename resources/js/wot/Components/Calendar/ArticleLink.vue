<script setup>

defineProps({
    url: { type: String, required: true },
});
</script>

<template>
    <a :href="url" target="_blank" rel="noopener noreferrer">
        <slot>Read article &rarr;</slot>
    </a>
</template>
