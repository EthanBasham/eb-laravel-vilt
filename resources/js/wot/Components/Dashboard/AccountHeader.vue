<script setup>
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '../PageHeader.vue';
import { asDate, number } from '../../lib/format';

/**
 * Whose account this is, how it is doing, and the two things you can do to the
 * connection itself.
 *
 * WN8 sits in the header rather than among the stat tiles below because it is
 * the one figure that stands for all of them — a player quotes it the way they
 * quote a rank. Its colour band is the community-standard palette, which is
 * why it is a class rather than a tone prop: those colours are not this app's
 * to choose.
 */
defineProps({
    account: { type: Object, required: true },
    summary: { type: Object, default: null },
});

const refreshing = ref(false);

/*
 * Stats are cached for thirty minutes because a cold fetch costs a couple of
 * seconds; this drops that entry so the next render is live again. The disabled
 * state stops a second click stacking another fetch on one already in flight.
 */
const refresh = () => {
    refreshing.value = true;
    router.post('/wot/refresh', {}, {
        preserveScroll: true,
        onFinish: () => (refreshing.value = false),
    });
};

// Confirmed, because it drops the stored token: reconnecting means going back
// through Wargaming's login.
const disconnect = () => {
    if (window.confirm('Disconnect this Wargaming account?')) {
        router.delete('/wot/connect');
    }
};
</script>

<template>
    <PageHeader
        :title="account.nickname"
        :subtitle="`Account ${account.account_id} · synced ${asDate(account.last_synced_at)}`"
    >
        <div v-if="summary" class="text-right">
            <p class="text-xs font-bold uppercase tracking-wider text-wot-dim">WN8</p>
            <p class="text-4xl tabular-nums" :class="`wn8-${summary.wn8_band}`">{{ number(summary.wn8) }}</p>
            <p class="text-xs capitalize text-wot-dim">{{ summary.wn8_band.replace('-', ' ') }}</p>
        </div>

        <div class="flex gap-2">
            <button
                type="button"
                class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-gold hover:text-wot-gold disabled:opacity-40"
                :disabled="refreshing"
                @click="refresh"
            >
                {{ refreshing ? 'Refreshing…' : 'Refresh' }}
            </button>

            <button
                type="button"
                class="border border-wot-border px-4 py-2 text-xs font-bold uppercase tracking-wider text-wot-dim transition-colors hover:border-wot-bad hover:text-wot-bad"
                @click="disconnect"
            >
                Disconnect
            </button>
        </div>
    </PageHeader>
</template>
