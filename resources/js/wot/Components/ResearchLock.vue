<script setup>
import { IconLock, IconLockOpen } from '@tabler/icons-vue';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * The XP Remaining board's research tick: whether the tank an unlock leads to
 * has been researched.
 *
 * It sits on the unlock rather than on the cell's own vehicle because that is
 * what a cell here is about — the XP owed to reach the next tank — so ticking
 * it zeroes the number standing next to it. It used to live on Tanks to
 * Purchase, a board that only ever read the flag to grey a lock and gate a
 * cart, and never spends a credit on it.
 *
 * The flag is stored on the tank purchase row all the same, so this patches the
 * purchase endpoint: that is where buying implies researching, and duplicating
 * those rules against a second route would be two answers to one question.
 */
const props = defineProps({
    unlocks: { type: Object, required: true },
});

const busy = ref(false);

/*
 * Bought tanks are researched by definition, and LineOwnership resolves them
 * that way whatever the stored flag says — so an un-tick here would write false
 * and change nothing on screen. Shown flat instead, and undone by un-buying it
 * on Tanks to Purchase, which is the statement that actually holds it.
 */
const isEditable = computed(() => !props.unlocks.is_shared && !props.unlocks.is_purchased);

const title = computed(() => {
    if (props.unlocks.is_purchased) {
        return `${props.unlocks.name} — bought, so it is researched. Un-tick it where it is bought, on Tanks to Purchase.`;
    }

    if (props.unlocks.is_shared) {
        return `${props.unlocks.name} — ticked on the line that counts it.`;
    }

    return props.unlocks.is_unlocked
        ? `${props.unlocks.name} — researched. Mark as not researched.`
        : `${props.unlocks.name} — not researched. Mark as researched.`;
});

const toggle = () => {
    busy.value = true;
    router.patch(`/wot/grinding/purchases/${props.unlocks.tank_id}`, {
        is_unlocked: !props.unlocks.is_unlocked,
    }, {
        preserveScroll: true,
        /*
         * Every board moves. The unlock settles here, the tank below it stops
         * owing module XP (researching past a vehicle takes its modules with
         * it), the Free XP plan follows the modules, Tanks to Purchase redraws
         * its lock and cart, and Blueprints counts the line as done. All of them
         * are built on every request anyway, so naming them costs nothing but
         * the bytes back.
         */
        only: ['xp', 'freexp', 'purchase', 'blueprints', 'totals'],
        onFinish: () => (busy.value = false),
    });
};
</script>

<template>
    <!-- Icon only, to keep the cell narrow, which leaves the title and the
         label as the only things naming the tank being researched. -->
    <button
        v-if="isEditable"
        type="button"
        class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 transition-colors"
        :class="[
            unlocks.is_unlocked ? 'text-wot-dim hover:text-wot-bad' : 'text-wot-dim hover:text-wot-text',
            busy ? 'opacity-50' : '',
        ]"
        :disabled="busy"
        :title="title"
        :aria-label="unlocks.is_unlocked
            ? `Mark ${unlocks.name} as not researched`
            : `Mark ${unlocks.name} as researched`"
        @click="toggle"
    >
        <component :is="unlocks.is_unlocked ? IconLock : IconLockOpen" :size="14" stroke-width="2.25" />
    </button>

    <!-- Same box, so a row of cells keeps its edges whether or not the tick is
         this board's to make. -->
    <span
        v-else
        class="inline-flex shrink-0 items-center justify-center border border-wot-border/50 p-1 text-wot-dim/50"
        :title="title"
    >
        <component :is="unlocks.is_unlocked ? IconLock : IconLockOpen" :size="14" stroke-width="2.25" aria-hidden="true" />
        <span class="sr-only">{{ title }}</span>
    </span>
</template>
