<script setup>
import { asDate, asDateTime } from '../../lib/format';
import { hasExactTimes } from '../../lib/events';

/**
 * When an event runs.
 *
 * Written out five times before this, which is five places the rule could have
 * been got wrong: an event's times are only printed when the event is one that
 * published times. A window carries a pair of timestamps that mean "some time
 * on this day", and rendering those as 00:00 would invent a precision the
 * source never had.
 */
const props = defineProps({
    event: { type: Object, required: true },
    /*
     * Force the date-only form. The long campaigns are listed as spans of days
     * whatever they were parsed from — a three-month Battle Pass has a start
     * time in the sense that a season has one, which is not a time worth
     * printing in a list of what is running this month.
     */
    datesOnly: { type: Boolean, default: false },
});

const when = (iso) => (!props.datesOnly && hasExactTimes(props.event) ? asDateTime(iso) : asDate(iso));
</script>

<template>
    <span>
        {{ when(event.starts_at) }}<template v-if="event.ends_at"> &ndash; {{ when(event.ends_at) }}</template>
    </span>
</template>
