/**
 * How much an event's dates are trusted, and how that is drawn.
 *
 * Two kinds of thing end up on the calendar. A `calendar` event was lifted from
 * an article's own event table, so it carries real session times. A `window`
 * event is a coarse span derived from a pair of timestamps — it says an event
 * is on, not when you can play it.
 *
 * The difference in confidence is drawn rather than implied: gold for the exact
 * ones, blue for the spans. Keeping the mapping here is what stops a new panel
 * inventing a third colour, and it is why a window's times are never printed —
 * a start of "00:00" would be a claim nobody made.
 */
const isExact = (event) => event.source === 'calendar';

/** The whole bar, for an event drawn as a block of colour. */
export const eventBarClass = (event) => (isExact(event)
    ? 'border-l-2 border-wot-gold bg-wot-gold/10 text-wot-gold'
    : 'border-l-2 border-wot-blue bg-wot-blue/10 text-wot-blue-light');

/** Just the rule down the side, for an event listed as text. */
export const eventBorderClass = (event) => (isExact(event) ? 'border-wot-gold' : 'border-wot-blue');

/** Whether this event's timestamps are precise enough to print a time from. */
export const hasExactTimes = isExact;
