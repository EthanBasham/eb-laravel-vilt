<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    stepId: { type: Number, required: true },
    modules: { type: Array, default: () => [] },
    outstanding: { type: Number, default: 0 },
});

const open = ref(false);
const busy = ref(null);

const remaining = computed(() => props.modules.filter((m) => !m.is_researched).length);

const toggle = (module) => {
    busy.value = module.module_id;
    router.patch(`/wot/grinding/steps/${props.stepId}/modules`, {
        module_id: module.module_id,
        researched: !module.is_researched,
    }, {
        preserveScroll: true,
        // Banked XP, module XP and every total move together, so the whole
        // board comes back rather than patching pieces in the client.
        only: ['active', 'targets', 'totals'],
        onFinish: () => (busy.value = null),
    });
};

const n = (v) => new Intl.NumberFormat().format(v ?? 0);
</script>

<template>
    <div class="relative inline-block text-right">
        <button
            type="button"
            class="tabular-nums transition-colors"
            :class="modules.length ? 'text-wot-text hover:text-wot-gold' : 'text-wot-muted'"
            :disabled="!modules.length"
            :aria-expanded="open"
            :title="modules.length ? `${remaining} of ${modules.length} modules left to research` : 'No upgrade modules'"
            @click="open = !open"
        >
            {{ n(outstanding) }}
            <span v-if="modules.length" aria-hidden="true" class="ms-1 text-xs text-wot-dim">{{ open ? '▴' : '▾' }}</span>
        </button>

        <!--
            Anchored to the cell rather than rendered inline, so opening it
            doesn't reflow the table row underneath. z-20 clears the sticky
            header without competing with the app's modals.
        -->
        <div
            v-if="open && modules.length"
            class="absolute right-0 z-20 mt-1 w-72 border border-wot-border bg-wot-panel-solid p-2 text-left shadow-lg"
        >
            <p class="px-1 pb-2 text-xs uppercase tracking-wider text-wot-dim">
                Tick a module once researched — banked XP drops by its cost
            </p>

            <ul role="list" class="space-y-0.5">
                <li v-for="module in modules" :key="module.module_id">
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

            <div class="mt-2 flex justify-end border-t border-wot-border-soft pt-2">
                <button type="button" class="text-xs uppercase tracking-wider text-wot-dim hover:text-wot-gold" @click="open = false">
                    Close
                </button>
            </div>
        </div>
    </div>
</template>
