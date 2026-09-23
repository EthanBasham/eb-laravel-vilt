<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppShell from '../Components/AppShell.vue';
import EmptyState from '../Components/EmptyState.vue';
import PageHeader from '../Components/PageHeader.vue';
import StatTile from '../Components/StatTile.vue';
import ViewTabs from '../Components/ViewTabs.vue';
import BattlePassTab from '../Components/Crews/BattlePassTab.vue';
import BooksTable from '../Components/Crews/BooksTable.vue';
import CrewBoard from '../Components/Crews/CrewBoard.vue';
import RecruitsPanel from '../Components/Crews/RecruitsPanel.vue';
import { n, short } from '../lib/format';

defineProps({
    crews: { type: Object, required: true },
    recruits: { type: Object, required: true },
    books: { type: Object, required: true },
    battle_pass: { type: Array, default: () => [] },
    settings: { type: Object, required: true },
    xp_progression: { type: Array, default: () => [] },
    roles: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
    genders: { type: Object, default: () => ({}) },
    // Deferred: a thousand vehicles that only the Battle Pass tab needs.
    vehicles: { type: Array, default: null },
});

const views = [
    { key: 'crews', label: 'Crews' },
    { key: 'inventory', label: 'Recruits & Books' },
    { key: 'battle-pass', label: 'Battle Pass' },
    { key: 'guide', label: 'Guide' },
];
const view = ref('crews');
</script>

<template>
    <Head title="Crews" />

    <AppShell>
        <PageHeader title="Crews" />

        <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile dense label="Crews recorded" :value="n(crews.totals.crews)" />
            <StatTile dense tone="good" label="All zero-skill" :value="n(crews.totals.zero_skill_crews)" />
            <StatTile dense tone="gold" label="Maxed crews" :value="n(crews.totals.max_crews)" />
            <StatTile dense label="Banked crew XP" :value="short(crews.totals.banked_xp)" />
        </dl>

        <ViewTabs v-model="view" :views="views" label="Crew views" />

        <CrewBoard
            v-if="view === 'crews'"
            :crews="crews"
            :settings="settings"
            :xp-progression="xp_progression"
        />

        <!--
            A third to the recruits and two thirds to the books, rather than
            half each: one is a label and a number, the other is five columns of
            them, and an even split left the books table scrolling sideways
            while the recruits table ran to whitespace.

            items-start so neither panel is stretched to the other's height.
            Grid items fill their row by default, which gave the shorter table a
            long empty tail below its last row and made it read as a table
            missing rows.
        -->
        <section
            v-else-if="view === 'inventory'"
            class="mt-4 grid items-start gap-6 lg:grid-cols-3"
            aria-labelledby="inventory-heading"
        >
            <h2 id="inventory-heading" class="sr-only">Recruits and books</h2>

            <RecruitsPanel :recruits="recruits" />
            <BooksTable :books="books" />
        </section>

        <BattlePassTab
            v-else-if="view === 'battle-pass'"
            :crews="battle_pass"
            :roles="roles"
            :statuses="statuses"
            :genders="genders"
            :vehicles="vehicles"
        />

        <section v-else class="mt-4" aria-labelledby="guide-heading">
            <h2 id="guide-heading" class="sr-only">Guide</h2>

            <EmptyState>Nothing here yet.</EmptyState>
        </section>
    </AppShell>
</template>
