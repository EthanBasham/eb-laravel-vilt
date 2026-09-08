<script setup>
defineProps({
    label: { type: String, required: true },
    value: { type: [String, Number], required: true },
    suffix: { type: String, default: '' },
    hint: { type: String, default: '' },
});
</script>

<template>
    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">{{ label }}</dt>
        <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">
            {{ value }}<span v-if="suffix" class="text-base font-normal text-gray-500">{{ suffix }}</span>
        </dd>
        <p v-if="hint" class="mt-1 text-xs text-gray-500">{{ hint }}</p>
    </div>
</template>
