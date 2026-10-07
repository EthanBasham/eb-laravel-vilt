/**
 * Axis ticks that land on round numbers.
 *
 * Given the range of the data, picks a step from the 1 / 2 / 2.5 / 5 / 10
 * family so that about `count` ticks span it, then returns the ticks and the
 * rounded range they cover. The charts draw to that range rather than to the
 * raw extremes, so the top gridline is a number someone can read.
 */
export function niceScale(min, max, count = 4) {
    if (min === max) {
        max = min + 1;
    }

    const rough = (max - min) / count;
    const magnitude = 10 ** Math.floor(Math.log10(rough));
    const step = [1, 2, 2.5, 5, 10].map((multiple) => multiple * magnitude).find((candidate) => candidate >= rough);

    const low = Math.floor(min / step) * step;
    const high = Math.ceil(max / step) * step;
    const ticks = [];

    for (let tick = low; tick <= high + step / 2; tick += step) {
        ticks.push(tick);
    }

    return { low, high, ticks };
}
