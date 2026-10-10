<script setup>
/**
 * The tabs a board page is split into — the views the spreadsheet each of these
 * replaced had, kept as tabs so a board stays one page.
 *
 * Client-side only: every tab's data is already on the page, so switching one
 * is a ref write rather than a visit. That is also why the state lives with the
 * page rather than in the URL — there is nothing to fetch, and a tab is not a
 * place you link someone to.
 *
 * The `aside` slot sits at the far end of the row, for a control that belongs
 * to whichever tab is up rather than to the tabs. It is outside the tablist on
 * purpose: a tablist's children are tabs.
 */
defineProps({
    // The key of the visible tab. v-model, so the page owns it — several pages
    // show a different panel underneath depending on which tab is up.
    modelValue: { type: String, required: true },
    // [{ key, label }], in the order they are shown.
    views: { type: Array, required: true },
    label: { type: String, required: true },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="mt-8 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-2" role="tablist" :aria-label="label">
            <button
                v-for="view in views"
                :key="view.key"
                type="button"
                role="tab"
                :aria-selected="modelValue === view.key"
                class="border px-3 py-1.5 text-xs font-bold uppercase tracking-wider transition-colors"
                :class="modelValue === view.key
                    ? 'border-wot-gold text-wot-gold'
                    : 'border-wot-border text-wot-dim hover:text-wot-text'"
                @click="$emit('update:modelValue', view.key)"
            >
                {{ view.label }}
            </button>
        </div>

        <slot name="aside" />
    </div>
</template>
