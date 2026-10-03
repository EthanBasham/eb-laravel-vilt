<script setup>
import { computed } from 'vue';
import { money } from '../lib/format';

/**
 * A ranked list with a bar behind each figure — a breakdown by category.
 *
 * One hue for the lot: the bars are comparing sizes, not naming things, and
 * the label beside each already says which is which. The value is printed on
 * every row, so nothing depends on judging a bar's length.
 */
const props = defineProps({
    // [{ label, value }], already in the order to print.
    items: { type: Array, required: true },
    color: { type: String, default: 'var(--color-fin-green-500)' },
    format: { type: Function, default: money },
});

const max = computed(() => Math.max(...props.items.map((item) => item.value), 1));
const width = (item) => `${Math.max(1.5, (item.value / max.value) * 100)}%`;
</script>

<template>
    <ul class="flex flex-col gap-3">
        <li v-for="item in items" :key="item.label">
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="truncate text-fin-charcoal">{{ item.label }}</span>
                <span class="font-medium tabular-nums text-fin-black">{{ format(item.value) }}</span>
            </div>
            <div class="mt-1.5 h-1.5 rounded-full bg-fin-grey-100">
                <div class="h-full rounded-full" :style="{ width: width(item), backgroundColor: color }" />
            </div>
        </li>
    </ul>
</template>
