<script setup>
/**
 * What a page shows when there is nothing to show yet: a line saying so,
 * and a slot for the button that fixes it.
 */
defineProps({
    title: { type: String, required: true },
    body: { type: String, default: '' },
});
</script>

<template>
    <div class="rounded-2xl border border-dashed border-fin-grey-300 bg-fin-cream-100/60 px-6 py-12 text-center">
        <p class="text-base font-semibold text-fin-black">{{ title }}</p>
        <p v-if="body" class="mx-auto mt-2 max-w-md text-sm text-fin-grey-500">{{ body }}</p>

        <div class="mt-5 flex flex-wrap justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
