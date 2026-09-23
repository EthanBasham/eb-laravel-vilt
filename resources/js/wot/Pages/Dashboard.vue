<script setup>
import { Deferred, Head, Link } from '@inertiajs/vue3';
import ActiveGrindingTable from '../Components/ActiveGrindingTable.vue';
import AppShell from '../Components/AppShell.vue';
import NewsPanel from '../Components/NewsPanel.vue';
import PeriodTable from '../Components/PeriodTable.vue';
import StatTile from '../Components/StatTile.vue';
import UpcomingPanel from '../Components/UpcomingPanel.vue';
import AccountHeader from '../Components/Dashboard/AccountHeader.vue';
import AchievementPanels from '../Components/Dashboard/AchievementPanels.vue';
import GarageTable from '../Components/Dashboard/GarageTable.vue';
import { asDate, number } from '../lib/format';

defineProps({
    account: { type: Object, required: true },
    grinding: { type: Object, default: () => ({ rows: [], totals: {} }) },
    summary: { type: Object, default: null },
    achievements: { type: Object, default: null },
    history: { type: Object, default: () => ({ history_since: null, periods: [] }) },
    vehicles: { type: Array, default: () => [] },
    error: { type: String, default: null },
    news: { type: Object, default: () => ({ latest: [], pinned: [] }) },
    upcoming: { type: Object, default: () => ({ days: [], ongoing: [] }) },
});
</script>

<template>
    <Head :title="`${account.nickname} · Dashboard`" />

    <AppShell>
        <AccountHeader :account="account" :summary="summary" />

        <p v-if="error" class="mt-6 border-l-2 border-wot-bad bg-wot-panel px-4 py-3 text-sm text-wot-bad" role="alert">
            {{ error }}
        </p>

        <!-- Both read local tables, so they render even when the block below
             failed because Wargaming was unreachable. -->
        <!-- items-start: a grid row stretches its items to match by default,
             which padded whichever panel had fewer rows with dead space to the
             other's height. Each sizes to its own content instead. -->
        <div class="mt-8 grid items-start gap-6 lg:grid-cols-2">
            <NewsPanel :news="news" />
            <UpcomingPanel :upcoming="upcoming" />
        </div>

        <!-- The Grinding page's own table, the same component against the same
             rows. Banked XP is typed by hand after a session, and the dashboard
             is where you land — so it is editable here rather than a read-only
             copy you would have to leave to update. -->
        <section class="mt-8" aria-labelledby="grinding-heading">
            <h2 id="grinding-heading" class="text-base">Active grinding</h2>

            <div class="mt-2">
                <Deferred data="grinding">
                    <!-- Deferred server-side, so the page paints before the XP
                         board is built. A block the table's own height, so
                         nothing below it jumps when the rows arrive. -->
                    <template #fallback>
                        <div class="animate-pulse border border-wot-border bg-wot-panel p-4" aria-hidden="true">
                            <div class="h-4 w-40 bg-wot-sunken" />
                            <div v-for="row in 3" :key="row" class="mt-3 h-4 w-full bg-wot-sunken/70" />
                        </div>
                        <span class="sr-only">Loading what you are grinding…</span>
                    </template>

                    <ActiveGrindingTable :rows="grinding.rows" :totals="grinding.totals" :only="['grinding']">
                        <template #empty>
                            Nothing being ground. Add a tank on the <Link href="/wot/grinding" class="text-wot-gold hover:underline">Grinding</Link> page.
                        </template>
                    </ActiveGrindingTable>
                </Deferred>
            </div>
        </section>

        <template v-if="summary">
            <dl class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile label="Battles" :value="number(summary.battles)" :hint="`Avg tier ${summary.avg_tier}`" />
                <StatTile
                    label="Win rate"
                    :value="summary.win_rate"
                    suffix="%"
                    :tone="summary.win_rate >= 50 ? 'good' : 'bad'"
                    :hint="`${number(summary.wins)} wins`"
                />
                <StatTile label="Avg damage" :value="number(summary.avg_damage)" :hint="`${number(summary.avg_assist)} assisted`" />
                <StatTile label="Survival" :value="summary.survival_rate" suffix="%" />
                <StatTile label="Damage ratio" :value="summary.damage_ratio ?? '—'" :tone="summary.damage_ratio >= 1 ? 'good' : 'bad'" />
                <StatTile label="K/D ratio" :value="summary.kd_ratio ?? '—'" :tone="summary.kd_ratio >= 1 ? 'good' : 'bad'" />
                <StatTile label="Accuracy" :value="summary.accuracy" suffix="%" :hint="`${number(summary.avg_blocked)} blocked`" />
                <StatTile label="Global rating" :value="number(summary.global_rating)" tone="gold" :hint="`Last battle ${asDate(summary.last_battle_at)}`" />
            </dl>

            <p v-if="summary.wn8_unrated_battles > 0" class="mt-3 text-xs text-wot-dim">
                {{ number(summary.wn8_unrated_battles) }} battles excluded from WN8 — XVM publishes no
                expected values for those vehicles.
            </p>

            <!-- Only present when a valid access token was sent with the
                 request, so it is absent rather than zeroed when the token has
                 expired. -->
            <dl v-if="summary.private" class="mt-4 grid gap-4 sm:grid-cols-3">
                <StatTile label="Credits" :value="number(summary.private.credits)" />
                <StatTile label="Gold" :value="number(summary.private.gold)" tone="gold" />
                <StatTile label="Free XP" :value="number(summary.private.free_xp)" />
            </dl>

            <div class="mt-12">
                <PeriodTable :overall="summary" :history="history" />
            </div>

            <section v-if="achievements" class="mt-12" aria-labelledby="achievements-heading">
                <h2 id="achievements-heading" class="text-xl">Achievements</h2>

                <AchievementPanels :achievements="achievements" class="mt-4" />
            </section>
        </template>

        <GarageTable :vehicles="vehicles" class="mt-12" />
    </AppShell>
</template>
