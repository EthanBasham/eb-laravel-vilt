import { onBeforeUnmount, onMounted, ref, toValue } from 'vue';

/**
 * The pixel width a chart has to draw in.
 *
 * Every chart here is an SVG drawn at its real width rather than scaled by a
 * viewBox, so text stays the size it was set at on any screen. That means
 * knowing the width of the element the chart sits in, and hearing when it
 * changes: put `frame` on that element as its `ref`, and read `width`.
 *
 * `minWidth` is the narrowest the chart will draw; it may be a ref or a
 * getter, for a chart whose minimum depends on how much it has to show.
 */
export function useChartWidth(minWidth = 280) {
    const frame = ref(null);
    const width = ref(640);
    let observer = null;

    onMounted(() => {
        observer = new ResizeObserver(([entry]) => {
            width.value = Math.max(toValue(minWidth), entry.contentRect.width);
        });
        observer.observe(frame.value);
    });

    onBeforeUnmount(() => observer?.disconnect());

    return { frame, width };
}
