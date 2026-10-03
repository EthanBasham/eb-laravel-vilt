<script setup>
/**
 * The white panel everything sits on. A title row when it has a title, and
 * `flush` for a card whose body is a table that should run edge to edge.
 */
defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    flush: { type: Boolean, default: false },
});
</script>

<template>
    <section class="rounded-2xl border border-fin-grey-200 bg-fin-white shadow-[0_1px_2px_rgba(18,21,19,0.04)]">
        <header v-if="title || $slots.actions" class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5" :class="flush ? 'pb-4' : 'pb-1'">
            <div>
                <h2 class="text-sm font-semibold text-fin-black">{{ title }}</h2>
                <p v-if="subtitle" class="mt-0.5 text-xs text-fin-grey-500">{{ subtitle }}</p>
            </div>

            <div class="flex items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <div :class="flush ? '' : 'p-5'">
            <slot />
        </div>
    </section>
</template>
