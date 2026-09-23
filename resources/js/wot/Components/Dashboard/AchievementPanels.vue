<script setup>
import { computed } from 'vue';
import MasteryBadge from './MasteryBadge.vue';
import { MARK_NAMES } from '../../lib/achievements';

/**
 * What the account has been awarded, counted by kind.
 *
 * Two panels of the same shape — art, then a tally — so they are one loop over
 * a pair of descriptions rather than the same markup written twice. The art is
 * the label: the marks are rendered at their native 24px because the source is
 * that size and upscaling it goes soft, while the mastery badges are a 67×71
 * original drawn down to the row height.
 */
const props = defineProps({
    achievements: { type: Object, required: true },
});

const panels = computed(() => [
    {
        key: 'marks',
        title: 'Marks of Excellence',
        counts: props.achievements.marks_of_excellence,
        tone: 'text-wot-gold',
    },
    {
        key: 'mastery',
        title: 'Mastery badges',
        counts: props.achievements.mastery,
        tone: 'text-wot-heading',
    },
]);
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <div v-for="panel in panels" :key="panel.key" class="border border-wot-border bg-wot-panel p-4">
            <h3 class="text-sm">{{ panel.title }}</h3>

            <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-3">
                <div v-for="(count, label) in panel.counts" :key="label" class="flex items-center gap-2">
                    <dt>
                        <img
                            v-if="panel.key === 'marks'"
                            :src="`/images/wot/achievements/moe-${label}.png`"
                            :alt="MARK_NAMES[label]"
                            :title="MARK_NAMES[label]"
                            width="24"
                            height="24"
                            class="h-6 w-6 shrink-0"
                            loading="lazy"
                            decoding="async"
                        >
                        <MasteryBadge v-else :badge="label" class="h-9 w-auto shrink-0" />
                    </dt>

                    <dd class="text-2xl tabular-nums" :class="panel.tone">{{ count }}</dd>
                </div>
            </dl>
        </div>
    </div>
</template>
