/**
 * How much an event's dates are trusted, and how that is drawn.
 *
 * Two kinds of thing end up on the calendar. A `calendar` event was lifted from
 * an article's own event table, so it carries real session times. A `window`
 * event is a coarse span derived from a pair of timestamps — it says an event
 * is on, not when you can play it.
 *
 * The difference in confidence is drawn rather than implied: a gold rule down
 * the side for the exact ones, blue for the spans. Keeping the mapping here is what stops a new panel
 * inventing a third colour, and it is why a window's times are never printed —
 * a start of "00:00" would be a claim nobody made.
 */
const isExact = (event) => event.source === 'calendar';

/** Just the rule down the side, for an event listed as text. */
export const eventBorderClass = (event) => (isExact(event) ? 'border-wot-gold' : 'border-wot-blue');

/**
 * Colours for telling events apart in the grid, and nothing more: which one an
 * event gets says nothing about it. There are no golds or yellows here — gold
 * is kept for the events that carry rewards (see eventColorStyle). Neighbours in the list are far apart in
 * hue, because events synced from one article get consecutive ids and are the
 * ones most likely to sit side by side.
 *
 * Hex in an inline style rather than Tailwind classes: classes built from a
 * list at runtime are not in the source for Tailwind to find.
 */
const PALETTE = [
    '#f87171', '#38bdf8', '#a3e635', '#e879f9', '#818cf8',
    '#2dd4bf', '#fb7185', '#a5b4fc', '#fb923c', '#34d399',
    '#a78bfa', '#22d3ee', '#67e8f9', '#f472b6', '#4ade80',
    '#60a5fa', '#93c5fd', '#d8b4fe', '#bef264', '#fca5a5',
    '#5eead4', '#fda4af', '#7dd3fc', '#f0abfc', '#6ee7b7',
];

/**
 * An event's fill and text colour. One that carries rewards is always gold, so
 * the events worth playing for stand out from the rest; any other is keyed on
 * its id, so every bar of one event matches and it keeps its colour from one
 * month to the next.
 *
 * The gold is the theme's own token, mixed the way Tailwind's `bg-wot-gold/10`
 * mixes it.
 */
export const eventColorStyle = (event) => {
    if (event.metadata?.rewards?.length) {
        return {
            backgroundColor: 'color-mix(in oklab, var(--color-wot-gold) 10%, transparent)',
            color: 'var(--color-wot-gold)',
        };
    }

    const colour = PALETTE[event.id % PALETTE.length];

    return { backgroundColor: `${colour}26`, color: colour };
};

/** Whether this event's timestamps are precise enough to print a time from. */
export const hasExactTimes = isExact;
