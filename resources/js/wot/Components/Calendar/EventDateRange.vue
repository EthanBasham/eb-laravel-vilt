<script setup>
import { asDate, asDateTime } from '../../lib/format';
import { eventHasExactTimes } from '../../lib/events';

const props = defineProps({
    event: { type: Object, required: true },
    datesOnly: { type: Boolean, default: false },
});

const when = (iso) => (!props.datesOnly && eventHasExactTimes(props.event) ? asDateTime(iso) : asDate(iso));
</script>

<template>
    <span>
        {{ when(event.starts_at) }}<template v-if="event.ends_at"> &ndash; {{ when(event.ends_at) }}</template>
    </span>
</template>
