/**
 * Wargaming's standard sale structure, as a discount per tier.
 *
 * Tier I is free already, and tier XI is not part of the published structure,
 * so neither is listed — a tier missing from here is simply not discounted.
 *
 * This is a preview, not a stored price: it applies to whatever a cell already
 * costs, so a price you have typed over for a specific offer gets discounted
 * along with the rest. Nothing is written, which is why the previewed figure
 * shows as text rather than in the field — saving it would record the sale
 * price as though it were the real one.
 */
const SALE = { 2: 0.5, 3: 0.5, 4: 0.5, 5: 0.5, 6: 0.3, 7: 0.3, 8: 0.15, 9: 0.15, 10: 0.15 };

/**
 * What a vehicle costs with the preview applied, or its plain price with the
 * preview off.
 *
 * Takes the flag rather than reading it, so the same function serves the cell
 * that prints the figure and the totals that add it up — one answer, not two
 * that have to be kept in step.
 */
export const salePrice = (cell, showSale) => (showSale
    ? Math.round(cell.price * (1 - (SALE[cell.tier] ?? 0)))
    : cell.price);
