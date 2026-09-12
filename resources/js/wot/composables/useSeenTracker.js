import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, reactive } from 'vue';

/**
 * Marks article cards seen once the pointer has rested on one.
 *
 *   hoverMs  How long the pointer must stay on the card. Sweeping across the
 *            grid on the way to something else shouldn't mark a row seen, so
 *            the timer starts on mouseenter and is cancelled by mouseleave.
 *
 * This replaced a visibility-dwell tracker (IntersectionObserver, 60% visible
 * for 1.5s), which marked cards seen without anyone having looked at them.
 * Hovering is a deliberate act, so it is the better signal.
 *
 * Touch devices fire no mouseenter, so nothing marks itself seen there — "mark
 * all as seen" is the path on those, the same as when the tracker is inert.
 *
 * Marks are batched and flushed on a timer rather than sent per card, so
 * working down a full page is one request instead of twenty-four.
 *
 * `isMarked(id)` reports what this visit has counted, ahead of the server
 * knowing it. Callers use it to clear a card's unseen dot the moment the hover
 * lands: waiting for the flush (up to flushMs later) or for the round trip
 * would leave the dot sitting there long enough to look broken.
 */
export function useSeenTracker({
    hoverMs = 1500,
    flushMs = 2000,
    onMarked = null,
} = {}) {
    const timers = new Map();
    // Weak: rows come and go as tabs switch and partial reloads land, and a
    // strong map here would pin every detached node for the page's lifetime.
    const listeners = new WeakMap();
    const pending = new Set();
    const marked = reactive(new Set());

    let flushHandle = null;

    const isMarked = (id) => marked.has(id);

    const flush = () => {
        if (pending.size === 0) {
            return;
        }

        const ids = [...pending];
        pending.clear();

        router.post('/wot/news/seen', { ids }, {
            preserveScroll: true,
            preserveState: true,
            // Only the counter comes back, and on pages without one that is an
            // empty payload. The NEW badges deliberately stay put for the rest
            // of this visit — cards restyling wholesale under the pointer is
            // distracting — and clear on the next load. The unseen dot is the
            // exception: `marked` clears it immediately, as the acknowledgement
            // that the hover registered.
            only: ['unseenCount'],
        });

        onMarked?.(ids);
    };

    const cancelTimer = (element) => {
        const timer = timers.get(element);

        if (timer) {
            window.clearTimeout(timer);
            timers.delete(element);
        }
    };

    const releaseListeners = (element) => {
        const handlers = listeners.get(element);

        if (handlers) {
            element.removeEventListener('mouseenter', handlers.enter);
            element.removeEventListener('mouseleave', handlers.leave);
            listeners.delete(element);
        }
    };

    /**
     * Template ref callback. Vue passes the element on mount and null on
     * unmount, and re-runs on every render, so the guards below have to hold or
     * a card collects a second pair of listeners each time it re-renders.
     *
     * The `marked` check covers the other repeat: `is_seen` still reads false
     * until the next full load, and the same article can appear twice (the
     * dashboard's Latest and Pinned tabs), so the id — not the element — is
     * what says whether this visit already counted it.
     */
    const track = (element, id, isSeen) => {
        if (!element || isSeen || marked.has(id) || listeners.has(element)) {
            return;
        }

        const enter = () => {
            if (timers.has(element) || marked.has(id)) {
                return;
            }

            timers.set(element, window.setTimeout(() => {
                cancelTimer(element);
                pending.add(id);
                marked.add(id);
                releaseListeners(element);
            }, hoverMs));
        };

        const leave = () => cancelTimer(element);

        element.addEventListener('mouseenter', enter);
        element.addEventListener('mouseleave', leave);
        listeners.set(element, { enter, leave });
    };

    onMounted(() => {
        flushHandle = window.setInterval(flush, flushMs);
    });

    onBeforeUnmount(() => {
        timers.forEach((timer) => window.clearTimeout(timer));
        timers.clear();

        if (flushHandle) {
            window.clearInterval(flushHandle);
        }

        // Anything queued but not yet sent goes now, so navigating away
        // mid-interval doesn't lose it. The listeners need no removal: they go
        // with the nodes they're attached to.
        flush();
    });

    return { track, isMarked };
}
