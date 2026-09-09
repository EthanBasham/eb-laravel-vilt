<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * The Free XP board's cell: which of a vehicle's upgrade modules to buy with
 * Free XP.
 *
 * Distinct from ModulePicker, which ticks a module *researched* on a tracked
 * grind step and spends banked XP doing it. This one records an intention and
 * changes nothing else.
 */
const props = defineProps({
    cell: { type: Object, required: true },
});

const open = ref(false);
const busy = ref(null);

const planned = computed(() => props.cell.modules.filter((m) => m.is_planned).length);

/*
 * The top gun's chain is server-computed, and the button only ever adds to the
 * plan. Ticking a module by hand still says nothing about what it sits behind,
 * which is the same blind spot as before this button existed — this makes the
 * common case one click rather than fixing that in general.
 */
const planTopGun = () => {
    busy.value = 'top-gun';
    router.patch(`/wot/grinding/modules/${props.cell.tank_id}/top-gun`, {}, {
        preserveScroll: true,
        only: ['freexp', 'totals'],
        onFinish: () => (busy.value = null),
    });
};

const toggle = (module) => {
    busy.value = module.module_id;
    router.patch(`/wot/grinding/modules/${props.cell.tank_id}`, {
        module_id: module.module_id,
        planned: !module.is_planned,
    }, {
        preserveScroll: true,
        // The row, tier and grand totals all derive from the board, and the
        // headline card reads totals, so both come back rather than being
        // patched in the client.
        only: ['freexp', 'totals'],
        onFinish: () => (busy.value = null),
    });
};

const n = (v) => new Intl.NumberFormat().format(v ?? 0);
</script>

<template>
    <div class="relative inline-block text-right">
        <!-- Nothing to plan: the encyclopedia lists no upgrade modules for this
             vehicle, which is a real state (stock-only tanks) and not the same
             as having planned none. A dash rather than a dead button. -->
        <span v-if="!cell.modules.length" class="text-wot-muted">—</span>

        <button
            v-else
            type="button"
            class="tabular-nums transition-colors"
            :class="cell.planned_xp ? 'text-wot-gold hover:text-wot-heading' : 'text-wot-muted hover:text-wot-text'"
            :aria-expanded="open"
            :title="`${cell.name} — ${planned} of ${cell.modules.length} modules planned, ${n(cell.total_xp)} to max`"
            @click="open = !open"
        >
            {{ n(cell.planned_xp) }}
            <span aria-hidden="true" class="ms-1 text-xs text-wot-dim">{{ open ? '▴' : '▾' }}</span>
        </button>

        <!--
            Anchored to the cell rather than rendered inline, so opening it
            doesn't reflow the row underneath. z-30 clears both sticky columns,
            which sit at z-20 and would otherwise slice the panel in half.
        -->
        <div
            v-if="open && cell.modules.length"
            class="absolute right-0 z-30 mt-1 w-72 border border-wot-border bg-wot-panel-solid p-2 text-left shadow-lg"
        >
            <p class="px-1 pb-1 text-xs uppercase tracking-wider text-wot-dim">
                {{ cell.name }} — tick what Free XP buys
            </p>

            <!-- The chain that unlocks the top gun, as one click. Adds; never
                 removes — so it reads as "and this too" rather than as a mode
                 that would take your other ticks away.

                 Disabled once nothing is outstanding rather than hidden: a
                 vanishing control leaves you wondering whether you imagined it,
                 where a spent one says the plan already covers the gun. -->
            <div v-if="cell.top_gun" class="mb-2 border-b border-wot-border-soft pb-2">
                <button
                    type="button"
                    class="w-full border px-2 py-1 text-start text-xs transition-colors disabled:opacity-40"
                    :class="cell.top_gun.outstanding
                        ? 'border-wot-gold text-wot-gold hover:bg-wot-sunken'
                        : 'border-wot-border text-wot-dim'"
                    :disabled="busy !== null || !cell.top_gun.outstanding"
                    :title="`${cell.top_gun.name} — ${cell.top_gun.module_ids.length} module${cell.top_gun.module_ids.length === 1 ? '' : 's'}, ${n(cell.top_gun.xp)} XP in total`"
                    @click="planTopGun"
                >
                    <span class="font-bold uppercase tracking-wider">
                        {{ cell.top_gun.outstanding ? 'Plan top gun' : 'Top gun planned' }}
                    </span>
                    <span v-if="cell.top_gun.outstanding" class="tabular-nums">+{{ n(cell.top_gun.outstanding) }}</span>
                    <span class="mt-0.5 block truncate normal-case tracking-normal text-wot-dim">{{ cell.top_gun.name }}</span>
                </button>
            </div>

            <ul role="list" class="space-y-0.5">
                <li v-for="module in cell.modules" :key="module.module_id">
                    <label
                        class="flex cursor-pointer items-center gap-2 px-1 py-1 text-sm transition-colors hover:bg-wot-sunken"
                        :class="busy === module.module_id ? 'opacity-50' : ''"
                    >
                        <input
                            type="checkbox"
                            class="border"
                            :checked="module.is_planned"
                            :disabled="busy !== null"
                            @change="toggle(module)"
                        >
                        <span class="w-14 shrink-0 text-xs uppercase tracking-wider text-wot-dim">{{ module.slot }}</span>
                        <span class="min-w-0 flex-1 truncate" :class="module.is_planned ? 'text-wot-gold' : 'text-wot-text'">
                            {{ module.name }}
                        </span>
                        <span class="shrink-0 tabular-nums" :class="module.is_planned ? 'text-wot-gold' : 'text-wot-muted'">
                            {{ n(module.price_xp) }}
                        </span>
                    </label>
                </li>
            </ul>

            <div class="mt-2 flex items-center justify-between border-t border-wot-border-soft pt-2 text-xs">
                <span class="tabular-nums text-wot-dim">{{ n(cell.planned_xp) }} of {{ n(cell.total_xp) }}</span>
                <button type="button" class="uppercase tracking-wider text-wot-dim hover:text-wot-gold" @click="open = false">
                    Close
                </button>
            </div>
        </div>
    </div>
</template>
