<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * The XP Remaining board's module control: which of a vehicle's upgrade modules
 * are already researched.
 *
 * A third module dropdown, and deliberately not a fourth prop on either of the
 * others. ModulePicker spends banked XP on a tracked grind step; ModulePlanPicker
 * records a Free XP intention; this one records a fact about the garage. They
 * write different columns and mean different things, and a single component
 * switching on a mode would read as one control with three personalities.
 */
const props = defineProps({
    cell: { type: Object, required: true },
});

const open = ref(false);
const busy = ref(null);

const done = computed(() => props.cell.modules.filter((m) => m.is_researched).length);

const toggle = (module) => {
    busy.value = module.module_id;
    router.patch(`/wot/grinding/research/${props.cell.tank_id}/modules`, {
        module_id: module.module_id,
        researched: !module.is_researched,
    }, {
        preserveScroll: true,
        // freexp too: researching a module takes it off the Free XP plan, so
        // that board's figures move even though nothing there was touched.
        only: ['xp', 'freexp', 'totals'],
        onFinish: () => (busy.value = null),
    });
};

const n = (v) => new Intl.NumberFormat().format(v ?? 0);
</script>

<template>
    <div class="relative inline-block text-right">
        <!-- Stock-only vehicle: nothing to research, which is a real state and
             not the same as having researched everything. -->
        <span v-if="!cell.modules.length" class="text-wot-muted">—</span>

        <button
            v-else
            type="button"
            class="tabular-nums transition-colors"
            :class="cell.module_xp ? 'text-wot-text hover:text-wot-gold' : 'text-wot-good'"
            :aria-expanded="open"
            :title="`${cell.name} — ${done} of ${cell.modules.length} modules researched, ${n(cell.module_xp)} XP left of ${n(cell.module_xp_total)}`"
            @click="open = !open"
        >
            {{ n(cell.module_xp) }}
            <span aria-hidden="true" class="ms-1 text-xs text-wot-dim">{{ open ? '▴' : '▾' }}</span>
        </button>

        <!--
            Anchored to the cell so opening it doesn't reflow the row. z-30
            clears both sticky columns, which sit at z-20.
        -->
        <div
            v-if="open && cell.modules.length"
            class="absolute right-0 z-30 mt-1 w-72 border border-wot-border bg-wot-panel-solid p-2 text-left shadow-lg"
        >
            <p class="px-1 pb-2 text-xs uppercase tracking-wider text-wot-dim">
                {{ cell.name }} — tick what is researched
            </p>

            <ul role="list" class="space-y-0.5">
                <li v-for="module in cell.modules" :key="module.module_id">
                    <label
                        class="flex cursor-pointer items-center gap-2 px-1 py-1 text-sm transition-colors hover:bg-wot-sunken"
                        :class="busy === module.module_id ? 'opacity-50' : ''"
                    >
                        <input
                            type="checkbox"
                            class="border"
                            :checked="module.is_researched"
                            :disabled="busy !== null"
                            @change="toggle(module)"
                        >
                        <span class="w-14 shrink-0 text-xs uppercase tracking-wider text-wot-dim">{{ module.slot }}</span>
                        <span class="min-w-0 flex-1 truncate" :class="module.is_researched ? 'text-wot-dim line-through' : 'text-wot-text'">
                            {{ module.name }}
                        </span>
                        <span class="shrink-0 tabular-nums" :class="module.is_researched ? 'text-wot-dim' : 'text-wot-muted'">
                            {{ n(module.price_xp) }}
                        </span>
                    </label>
                </li>
            </ul>

            <div class="mt-2 flex items-center justify-between border-t border-wot-border-soft pt-2 text-xs">
                <span class="tabular-nums text-wot-dim">{{ n(cell.module_xp) }} of {{ n(cell.module_xp_total) }} left</span>
                <button type="button" class="uppercase tracking-wider text-wot-dim hover:text-wot-gold" @click="open = false">
                    Close
                </button>
            </div>
        </div>
    </div>
</template>
