/**
 * Formatting shared by every Financial Fleet page.
 *
 * Nothing here calculates. Every figure on the site is worked out in PHP
 * (app/Services/Finance), where there is a test suite to hold it to account,
 * and arrives as a plain number; these only decide how it is written.
 *
 * `undefined` is passed as the locale on purpose, so the viewer's own grouping
 * and decimal marks are used. The currency is fixed at USD because the tax
 * tables behind the tools are.
 */
const whole = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
const exact = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', minimumFractionDigits: 2 });
const compact = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1 });
const brief = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD', notation: 'compact', maximumSignificantDigits: 3 });
const plain = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });

const missing = (value) => value === null || value === undefined || Number.isNaN(value);

/** Dollars, no cents — for balances and totals, where cents are noise. */
export const money = (value) => (missing(value) ? '—' : whole.format(value));

/** Dollars and cents — for a payment or a subscription, where they are the point. */
export const moneyExact = (value) => (missing(value) ? '—' : exact.format(value));

/** $1.2M, $340K — for chart axes and headline tiles. */
export const moneyShort = (value) => (missing(value) ? '—' : compact.format(value));

/** $35K, $1.57M — three figures and no more, for a dense table. */
export const moneyBrief = (value) => (missing(value) ? '—' : brief.format(value));

/** The same three figures with an explicit sign, for a change or a variance. */
export const moneyBriefSigned = (value) => {
    if (missing(value)) return '—';

    return `${value < 0 ? '−' : '+'}${brief.format(Math.abs(value))}`;
};

/** Dollars with an explicit sign, for a change or a variance. */
export const moneySigned = (value) => {
    if (missing(value)) return '—';

    return `${value < 0 ? '−' : '+'}${whole.format(Math.abs(value))}`;
};

/** A rate as it is stored: 4.25 reads "4.25%". */
export const percent = (value, digits = 2) => (missing(value) ? '—' : `${plain.format(Number(value).toFixed(digits))}%`);

/**
 * The pill colours for a yearly rate: green when it moves the plan in your
 * favour — an income or an asset rising, an expense or a debt's interest
 * falling — red when it moves against, and grey when it does not move at all.
 *
 * `kind` is a flow's direction or a holding's side.
 */
export const rateTone = (kind, rate) => {
    if (missing(rate) || Number(rate) === 0) return 'bg-fin-grey-100 text-fin-grey-600';

    if ((Number(rate) > 0) === ['income', 'asset'].includes(kind)) {
        return 'bg-fin-green-100 text-fin-green-700';
    }

    return 'bg-fin-red-100 text-fin-red-600';
};

export const number = (value) => (missing(value) ? '—' : plain.format(value));

/**
 * A stored date (YYYY-MM-DD), written for the viewer.
 *
 * Parsed as local noon rather than handed to `new Date()` bare: a date-only
 * string is read as UTC midnight, which is the previous evening anywhere west
 * of Greenwich, and the page would print the day before the one saved.
 */
export const asDate = (value, options = { month: 'short', day: 'numeric', year: 'numeric' }) => {
    if (!value) return '—';

    return new Date(`${value}T12:00:00`).toLocaleDateString(undefined, options);
};

export const asMonth = (value) => asDate(value, { month: 'short', year: 'numeric' });

/** 18 months reads "1 yr 6 mo". */
export const duration = (months) => {
    if (missing(months)) return '—';

    const years = Math.floor(months / 12);
    const rest = months % 12;

    return [years ? `${years} yr` : null, rest || !years ? `${rest} mo` : null].filter(Boolean).join(' ');
};

/** A rate with its direction written out: 3 reads "+3%". Zero takes no sign. */
export const percentSigned = (value, digits = 2) => `${value > 0 ? '+' : ''}${percent(value, digits)}`;

/**
 * The text colour for a figure that is good when it is above zero and bad
 * below: a surplus, a variance, a change. Nothing to colour reads plain.
 */
export const signTone = (value) => {
    if (missing(value)) return 'text-fin-charcoal';

    return value < 0 ? 'text-fin-red-600' : 'text-fin-green-600';
};

/** The fixed order chart series take their colours in. */
export const chartColors = [
    'var(--color-fin-chart-1)',
    'var(--color-fin-chart-2)',
    'var(--color-fin-chart-3)',
    'var(--color-fin-chart-4)',
    'var(--color-fin-chart-5)',
    'var(--color-fin-chart-6)',
];

/** The colour of the series at a position, going round again past the last. */
export const colorOf = (index) => chartColors[index % chartColors.length];
