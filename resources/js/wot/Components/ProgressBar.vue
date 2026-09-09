<script setup>
defineProps({
    value: { type: Number, default: 0 },
    // The track has to sit against whatever row it lands in; a sunken track on
    // a sunken total row would be invisible.
    track: { type: String, default: 'bg-wot-sunken' },
});
</script>

<template>
    <div class="flex items-center justify-end gap-2">
        <span class="h-1.5 w-16" :class="track">
            <span class="block h-full bg-wot-gold" :style="{ width: `${Math.min(100, value)}%` }" />
        </span>
        <span class="w-12 text-right tabular-nums text-wot-muted">{{ value }}%</span>
    </div>
</template>
