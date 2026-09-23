<script setup>
import NationFlag from './NationFlag.vue';
import VehicleTypeIcon from './VehicleTypeIcon.vue';

/**
 * A board row's first cell: whose line it is, what it is called, and what rolls
 * off the end of it.
 *
 * Sticky, so the name stays put while eleven tier columns scroll past it. That
 * is also why the background is the *solid* panel colour rather than the
 * translucent one the row wears — a transparent sticky cell lets the columns
 * slide visibly underneath it.
 */
defineProps({
    row: { type: Object, required: true },
});
</script>

<template>
    <td class="sticky left-0 z-10 whitespace-nowrap border-e border-wot-border bg-wot-panel-solid px-4 py-2 group-hover:bg-wot-sunken-solid">
        <NationFlag :nation="row.nation" class="me-2" />
        <span class="text-wot-heading">{{ row.name }}</span>
        <VehicleTypeIcon :type="row.type" class="ms-2 text-wot-dim" />
    </td>
</template>
