/**
 * Formatting shared by every World of Tanks page.
 *
 * These were written out again in each page and component that needed them —
 * nine copies of `n`, six of the Roman numerals, four different ideas of what a
 * date looks like. One of those copies had already drifted: the dashboard's
 * tier list stopped at X, so a tier XI vehicle in the garage read as a bare
 * "11" while every board elsewhere called it XI.
 *
 * Nothing here formats for the server. Every figure the app persists is sent as
 * a number and rendered through these on the way out; `undefined` is passed to
 * every Intl call on purpose, so the *viewer's* locale and timezone decide,
 * never the server's.
 */

/*
 * One formatter rather than one per call. Intl.NumberFormat is expensive to
 * construct and these run inside table cells, so a board of four hundred rows
 * would otherwise build it four hundred times a render.
 */
const decimal = new Intl.NumberFormat();

/**
 * A count, where nothing recorded and zero are the same thing.
 *
 * Totals, fragment counts and XP all read this way: a row with no blueprints
 * against it holds zero of them, and a dash there would be a different claim.
 */
export const n = (value) => decimal.format(value ?? 0);

/**
 * A measurement, where nothing recorded and zero are *not* the same thing.
 *
 * The dashboard's stats come from Wargaming, and a vehicle with no WN8 has no
 * expected values published for it — which is not a score of zero. Kept apart
 * from `n` deliberately; merging the two would quietly turn every unknown on
 * that page into a nought.
 */
export const number = (value) => (value === null || value === undefined ? '—' : decimal.format(value));

/**
 * Millions abbreviated, for the headline cards.
 *
 * Credits and XP run to eight figures there, and a card is read at a glance
 * rather than audited — the exact figure is on the board below it.
 */
export const short = (value) => (value >= 1_000_000 ? `${(value / 1_000_000).toFixed(1)}M` : n(value));

/**
 * Thousands as the game writes them, for a column header.
 *
 * "250k" sits in the space a header has where "250,000" does not. The trailing
 * .0 is dropped, so 20k stays 20k and an odd 12,500 reads 12.5k.
 */
export const inK = (value) => (value >= 1000 ? `${+(value / 1000).toFixed(1)}k` : n(value));

/**
 * Tiers are Roman in game and in every community tool; Arabic column headers
 * would read as a different quantity entirely.
 *
 * 1-indexed, so index 0 is never asked for, and it runs to XI — the tier above
 * X that some lines now reach.
 */
export const ROMAN = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI'];

/** The numeral for a tier, falling back to the figure itself above XI. */
export const roman = (tier) => ROMAN[tier] ?? tier;

/** A date on its own — "11 Sep 2026". */
export const asDate = (iso) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');

/** A date with the time on it, for the events that publish one. */
export const asDateTime = (iso) => (iso
    ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' })
    : '—');

/** The compact form the side panels use, where the year is implied — "11 Sep". */
export const asShortDate = (iso) => (iso
    ? new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short' })
    : null);

/**
 * A calendar day, written out in full.
 *
 * Built from the parts rather than `new Date('2026-09-11')`, which the spec
 * parses as UTC midnight — west of Greenwich that renders as the day before.
 */
export const asDayLabel = (date) => {
    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, { dateStyle: 'full' });
};
