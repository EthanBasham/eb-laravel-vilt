<script setup>
/**
 * The title block every page opens with: a heading, an optional line under it,
 * and whatever the page keeps on the right.
 *
 * `items-end` rather than `items-center` so the heading and a link beside it
 * sit on the same baseline however tall the left side grows.
 */
defineProps({
    title: { type: String, required: true },
    // Plain text; anything with a link or an icon in it goes through the slot
    // of the same name instead.
    subtitle: { type: String, default: '' },
});
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-4 border-b border-wot-border pb-5">
        <div>
            <h1 class="text-3xl">{{ title }}</h1>

            <p v-if="subtitle || $slots.subtitle" class="mt-1 text-sm text-wot-dim">
                <slot name="subtitle">{{ subtitle }}</slot>
            </p>
        </div>

        <slot />
    </div>
</template>
