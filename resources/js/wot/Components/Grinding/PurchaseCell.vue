<script setup>
import { router } from '@inertiajs/vue3';
import { IconLock, IconLockOpen, IconRestore, IconShoppingCart } from '@tabler/icons-vue';
import EditableNumber from '../EditableNumber.vue';
import { n } from '../../lib/format';

/**
 * One vehicle's place in the shopping list: whether it is researched, what it
 * costs, and the button that buys it.
 *
 * One input group — research state on the left, price in the middle, buying on
 * the right — so the cell reads in the order the two steps happen.
 *
 * `gap-px` leaves exactly one pixel between segments, enough to read them as
 * separate controls without spending the width a real gap costs in a
 * five-column table. `items-stretch` sizes the buttons from the input rather
 * than from their own padding, so the group has one flat top and bottom edge;
 * the buttons' vertical padding stops deciding their height, which is why they
 * need `justify-center` to hold the icon in the middle of the taller box.
 *
 * Icons only, to keep the cell narrow — which leaves the title as the only
 * thing naming the tank, the tooltip for a pointer and an sr-only line for a
 * screen reader.
 */
const props = defineProps({
    cell: { type: Object, required: true },
    // The price with the previewed sale applied, worked out by the board so the
    // cell and the column totals cannot disagree about what a tank costs.
    price: { type: Number, required: true },
    // Whether the sale preview is on. A previewed price is derived, not stored,
    // so it shows as text: typing into the field would save the discounted
    // figure as though it were the real one.
    showSale: { type: Boolean, default: false },
});

/**
 * Apply a pending change to one cell, in the shape the board renders.
 *
 * The wire names and the cell's own fields are not quite the same: the request
 * carries `price_credit`, the override, while the cell carries the resolved
 * `price` and whether it undercuts the shop. Buying also researches, mirroring
 * the same rule the controller enforces, so the optimistic cell matches what
 * comes back rather than flickering when it does.
 */
const applyToCell = (cell, payload) => {
    if ('price_credit' in payload) {
        return {
            ...cell,
            price: payload.price_credit ?? cell.api_price ?? 0,
            // Both halves of PurchaseBoard's rule: an override is only a
            // discount if it actually differs from the shop price. Dropping the
            // second half would flash the reset button on for a value the
            // server is about to call undiscounted.
            is_discounted: payload.price_credit !== null && payload.price_credit !== cell.api_price,
        };
    }

    return {
        ...cell,
        ...payload,
        is_unlocked: payload.is_purchased ? true : (payload.is_unlocked ?? cell.is_unlocked),
    };
};

/**
 * The `purchase` prop with this vehicle's cell changed, ready to hand to
 * Inertia's `optimistic` option.
 */
const patchPurchase = (pageProps, payload) => ({
    purchase: {
        ...pageProps.purchase,
        rows: pageProps.purchase.rows.map((row) => {
            const tier = Object.keys(row.cells).find((key) => row.cells[key]?.tank_id === props.cell.tank_id);

            return tier === undefined ? row : {
                ...row,
                cells: { ...row.cells, [tier]: applyToCell(row.cells[tier], payload) },
            };
        }),
    },
});

/*
 * Optimistic, because every figure on this tab is derived from `purchase` in
 * the template — the icons, the cell's own cost, and the row, tier and grand
 * totals all recompute from it. Flipping the one cell locally therefore redraws
 * the board immediately instead of after the round trip, and Inertia puts it
 * back on its own if the request fails.
 *
 * Two things are deliberately left to the server. The headline credits card
 * reads `totals`, and a line is settled whole when its last vehicle is bought —
 * including tiers never ticked off, which is PurchaseBoard's rule rather than
 * the client's. Reproducing it here would mean keeping two copies of it in
 * step, so a bought-out row instead lingers for the length of the request.
 */
const setPurchase = (payload) => router.patch(`/wot/grinding/purchases/${props.cell.tank_id}`, payload, {
    preserveScroll: true,
    /*
     * Buying a tank researches it, and researching moves the other three boards
     * — the unlock settles, the modules below it stop being owed, the Free XP
     * plan follows them and Blueprints counts the line done. They are all built
     * on every request anyway, so asking for them costs only bytes, where
     * leaving them out costs a tab that is quietly out of date.
     */
    only: ['xp', 'freexp', 'purchase', 'blueprints', 'totals'],
    optimistic: (pageProps) => patchPurchase(pageProps, payload),
});

// Same treatment for a typed price. The field shows what you typed either way,
// but the row, tier and grand totals are derived from `purchase`, so without
// this they sit on the old figure until the round trip lands.
const optimisticPrice = (pageProps, next) => patchPurchase(pageProps, { price_credit: next });

/*
 * A researched vehicle borrows the buy button's border and text, so the cell
 * reads as one control that is ready to spend rather than as a green button
 * beside an ordinary field. The background stays the shell's own in both
 * states — only the border and text carry the signal.
 */
const priceTone = (cell) => (cell.is_unlocked
    ? 'border-wot-good/50 bg-wot-sunken text-wot-good hover:border-wot-good'
    : 'border-wot-border bg-wot-sunken text-wot-text');

/*
 * The lock on this board reports; it does not set. Research is ticked on XP
 * Remaining, against the unlock leading to the tank — so the title says where
 * that is rather than describing a click this icon does not take.
 *
 * A line's first vehicle has no unlock leading to it and so no tick anywhere,
 * which is why the server settles it as researched and why it says so here
 * instead of showing a lock that could never be opened.
 */
const researchedTitle = (cell) => {
    if (cell.is_root) {
        return `${cell.name} — the start of the line, researched from nothing.`;
    }

    return cell.is_unlocked
        ? `${cell.name} — researched. Changed on XP Remaining.`
        : `${cell.name} — not researched. Tick it on XP Remaining, against the unlock that leads here.`;
};
</script>

<template>
    <!-- Shared with a line above, where it is the editable one. Text rather
         than a control: the same tank must never be two sets of buttons, and
         only the row that owns it pays for it.

         Ahead of the bought branch deliberately — a shared cell is read-only
         whatever its state, and the MS-1 sits on twelve lines. -->
    <span
        v-if="cell.is_shared"
        class="tabular-nums text-wot-dim/60"
        :title="`${cell.name} — shared with ${cell.shared_with}, where it is counted and edited.`"
    >
        {{ n(cell.is_purchased ? 0 : price) }}
    </span>

    <!-- Owned: nothing left to pay, so the cell reads zero rather than
         restating a price that is no longer owed. -->
    <button
        v-else-if="cell.is_purchased"
        type="button"
        class="tabular-nums text-wot-dim hover:text-wot-muted"
        :title="`${cell.name} — bought. Mark as not bought.`"
        @click="setPurchase({ is_purchased: false })"
    >
        0
    </button>

    <div v-else class="flex items-stretch justify-end gap-px">
        <!-- The lock reads, it does not set. It stays on show because it still
             gates the cart and tints the price, and hiding it would leave both
             unexplained. -->
        <span
            class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 text-wot-dim"
            :title="researchedTitle(cell)"
        >
            <component :is="cell.is_unlocked ? IconLock : IconLockOpen" :size="14" stroke-width="2.25" aria-hidden="true" />
            <span class="sr-only">{{ researchedTitle(cell) }}</span>
        </span>

        <!-- Sized like the input it replaces so the group does not shift when
             you toggle the sale preview. -->
        <span
            v-if="showSale"
            class="w-20 border border-wot-border bg-wot-sunken px-1 py-0.5 text-end text-sm tabular-nums text-wot-gold"
            :title="`${cell.name} — ${n(cell.price)} at full price`"
        >
            {{ n(price) }}
        </span>

        <EditableNumber
            v-else
            field="price_credit"
            :model-value="cell.price"
            :url="`/wot/grinding/purchases/${cell.tank_id}`"
            :only="['purchase', 'totals']"
            :optimistic="optimisticPrice"
            :tone="priceTone(cell)"
        />

        <!-- Only offered once a price has been overridden; there is nothing to
             reset back to otherwise. Bordered like the other segments now that
             it sits inside the group rather than floating beside the number. -->
        <button
            v-if="cell.is_discounted"
            type="button"
            class="inline-flex shrink-0 items-center justify-center border border-wot-border p-1 text-wot-dim transition-colors hover:text-wot-bad"
            :title="`Reset to the ${n(cell.api_price)} shop price`"
            :aria-label="`Reset ${cell.name} to the shop price`"
            @click="setPurchase({ price_credit: null })"
        >
            <IconRestore :size="14" stroke-width="2.25" />
        </button>

        <!-- Researched vehicles only: buying one that is not researched yet is
             not a move the game offers, and the server would force is_unlocked
             back on anyway. -->
        <button
            v-if="cell.is_unlocked"
            type="button"
            class="inline-flex shrink-0 items-center justify-center border border-wot-good/50 p-1 text-wot-good transition-colors hover:bg-wot-good/15"
            :title="`${cell.name} — researched. Mark as bought.`"
            :aria-label="`Mark ${cell.name} as bought`"
            @click="setPurchase({ is_purchased: true })"
        >
            <IconShoppingCart :size="14" stroke-width="2.25" />
        </button>
    </div>
</template>
