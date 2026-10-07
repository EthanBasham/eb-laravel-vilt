/**
 * Reading what was typed into a field.
 *
 * Kept apart from lib/format.js, which only writes figures out.
 */

/**
 * A number input's value as a number, or null when it was left empty or is
 * not one: an empty rate means "use its own", which is not the same as zero.
 * Takes the change event or the value itself.
 */
export const numberOrNull = (input) => {
    const raw = input?.target ? input.target.value : input;
    const value = Number(raw);

    return raw === '' || raw === null || raw === undefined || Number.isNaN(value) ? null : value;
};
