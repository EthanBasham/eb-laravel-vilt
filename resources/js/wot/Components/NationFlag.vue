<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    nation: { type: String, default: '' },
});

const page = usePage();

// Shared from config('wargaming.nations') — see HandleInertiaRequests.
const label = computed(() => page.props.nations?.[props.nation] ?? props.nation);

// A nation added by a future patch has no flag yet; fall back to the slug
// rather than requesting an image that 404s.
const known = computed(() => props.nation in (page.props.nations ?? {}));
</script>

<template>
    <img
        v-if="known"
        :src="`/images/nations/${nation}.png`"
        :alt="label"
        :title="label"
        width="29"
        height="18"
        class="inline-block h-[13px] w-[21px] shrink-0 align-[-0.15em]"
        loading="lazy"
        decoding="async"
    />
    <span v-else class="text-xs text-wot-dim">{{ label }}</span>
</template>
