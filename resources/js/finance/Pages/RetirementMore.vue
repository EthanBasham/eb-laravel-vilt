<script setup>
import EmptyState from '../Components/EmptyState.vue';
import FinShell from '../Components/FinShell.vue';
import RetirementTabs from '../Components/RetirementTabs.vue';
</script>

<template>
    <FinShell title="Retirement strategizer" subtitle="Tools for the years after the paycheque stops.">
        <RetirementTabs current="more" />

        <!-- A placeholder: the tab exists so the next tool has somewhere to go. -->
        <EmptyState title="Nothing here yet" body="This tab is being held for the next retirement tool. Roth conversions is the one that works today." />
    </FinShell>
</template>
