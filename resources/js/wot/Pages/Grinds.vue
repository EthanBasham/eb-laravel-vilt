<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppShell from '../Components/AppShell.vue';

const props = defineProps({
    grinds: { type: Array, default: () => [] },
    options: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const form = useForm({ tank_id: '', target_type: '', target_id: '' });
const showComplete = ref(false);

const selectedTank = computed(() =>
    props.options.find((option) => option.tank_id === Number(form.tank_id)),
);

// Targets are keyed "type:id" in the select, because a module id and a tank id
// can collide — they're separate id spaces upstream.
const targetKey = ref('');

const submit = () => {
    const [type, id] = targetKey.value.split(':');

    form.target_type = type;
    form.target_id = id;
    form.post('/wot/grinds', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            targetKey.value = '';
        },
    });
};

const remove = (grind) => {
    if (window.confirm(`Stop tracking ${grind.tank_name} → ${grind.target_name}?`)) {
        router.delete(`/wot/grinds/${grind.id}`, { preserveScroll: true });
    }
};

const active = computed(() => props.grinds.filter((g) => !g.is_complete));
const complete = computed(() => props.grinds.filter((g) => g.is_complete));

const number = (v) => (v === null || v === undefined ? '—' : new Intl.NumberFormat().format(v));

const eta = (grind) => {
    if (grind.is_complete) return 'Done';
    if (grind.days_remaining === null) return null;
    if (grind.days_remaining < 1) return 'Less than a day at your recent rate';

    return `About ${Math.round(grind.days_remaining)} day${Math.round(grind.days_remaining) === 1 ? '' : 's'} at your recent rate`;
};
</script>

<template>
    <Head title="Grinds" />

    <AppShell>
        <div class="border-b border-wot-border pb-5">
            <h1 class="text-3xl">Grinds</h1>
            <p class="mt-1 text-sm text-wot-dim">
                Track XP towards a vehicle or module unlock.
            </p>
        </div>

        <p v-if="error" class="mt-6 border-l-2 border-wot-bad bg-wot-panel px-4 py-3 text-sm text-wot-bad" role="alert">
            {{ error }}
        </p>

        <!--
            Stated plainly rather than buried in a tooltip: Wargaming exposes no
            per-vehicle unspent XP, so a grind can only measure from the moment
            it is declared. A reader who doesn't know that would think the
            numbers were simply wrong.
        -->
        <p class="mt-6 border-l-2 border-wot-blue-deep bg-wot-panel px-4 py-3 text-sm text-wot-muted">
            Progress is measured from the moment you start tracking. The Wargaming API doesn't
            expose XP already banked on a vehicle, so anything earned before you added the grind
            isn't counted — a new grind reads pessimistically until its first unlock.
        </p>

        <section class="mt-8" aria-labelledby="add-heading">
            <h2 id="add-heading" class="text-xl">Start tracking</h2>

            <form class="mt-4 flex flex-wrap items-end gap-3 border border-wot-border bg-wot-panel p-4" @submit.prevent="submit">
                <div class="min-w-64 flex-1">
                    <label for="tank" class="block text-xs font-medium uppercase tracking-wider text-wot-dim">Vehicle</label>
                    <select id="tank" v-model="form.tank_id" class="mt-1 w-full border px-2 py-1.5 text-sm" @change="targetKey = ''">
                        <option value="">Choose a vehicle you own…</option>
                        <option v-for="option in options" :key="option.tank_id" :value="option.tank_id">
                            {{ option.name }} (tier {{ option.tier }})
                        </option>
                    </select>
                </div>

                <div class="min-w-64 flex-1">
                    <label for="target" class="block text-xs font-medium uppercase tracking-wider text-wot-dim">Target</label>
                    <select id="target" v-model="targetKey" class="mt-1 w-full border px-2 py-1.5 text-sm" :disabled="!selectedTank">
                        <option value="">{{ selectedTank ? 'Choose a target…' : 'Pick a vehicle first' }}</option>
                        <option
                            v-for="target in selectedTank?.targets ?? []"
                            :key="`${target.type}:${target.id}`"
                            :value="`${target.type}:${target.id}`"
                        >
                            {{ target.type === 'tank' ? '🏁' : '🔧' }} {{ target.name }} — {{ number(target.xp) }} XP
                        </option>
                    </select>
                </div>

                <button
                    type="submit"
                    class="border border-wot-gold bg-wot-gold px-5 py-2 text-sm font-bold uppercase tracking-wider text-wot-abyss transition-colors hover:border-wot-gold-bright hover:bg-wot-gold-bright disabled:cursor-not-allowed disabled:opacity-40"
                    :disabled="!targetKey || form.processing"
                >
                    Track
                </button>
            </form>

            <p v-if="!options.length" class="mt-2 text-xs text-wot-dim">
                No research targets found. Run <code>php artisan wot:sync-vehicles</code> to load the tech tree.
            </p>
        </section>

        <section class="mt-12" aria-labelledby="active-heading">
            <h2 id="active-heading" class="text-xl">In progress</h2>

            <p v-if="!active.length" class="mt-4 border border-dashed border-wot-border p-8 text-center text-sm text-wot-dim">
                Nothing being tracked yet.
            </p>

            <ul v-else role="list" class="mt-4 space-y-3">
                <li v-for="grind in active" :key="grind.id" class="border border-wot-border bg-wot-panel p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <span class="font-medium text-wot-heading">{{ grind.tank_name }}</span>
                            <span class="mx-2 text-wot-dim" aria-hidden="true">→</span>
                            <span class="text-wot-gold">{{ grind.target_name }}</span>
                            <span class="ms-2 text-xs uppercase tracking-wider text-wot-dim">{{ grind.target_type }}</span>
                        </div>

                        <button
                            type="button"
                            class="text-xs uppercase tracking-wider text-wot-dim transition-colors hover:text-wot-bad"
                            @click="remove(grind)"
                        >
                            Remove
                        </button>
                    </div>

                    <div
                        class="mt-3 h-2 w-full bg-wot-sunken"
                        role="progressbar"
                        :aria-valuenow="grind.progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="`${grind.tank_name} towards ${grind.target_name}`"
                    >
                        <div class="h-full bg-wot-gold transition-all" :style="{ width: `${grind.progress}%` }" />
                    </div>

                    <dl class="mt-3 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-wot-dim">Progress</dt>
                            <dd class="tabular-nums text-wot-heading">
                                {{ number(grind.earned_xp) }} / {{ number(grind.target_xp) }} XP
                                <span class="text-wot-dim">({{ grind.progress }}%)</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-wot-dim">Remaining</dt>
                            <dd class="tabular-nums text-wot-muted">{{ number(grind.remaining_xp) }} XP</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-wot-dim">Battles left</dt>
                            <dd class="tabular-nums text-wot-muted">
                                {{ grind.battles_remaining === null ? '—' : `~${number(grind.battles_remaining)}` }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wider text-wot-dim">XP / battle</dt>
                            <dd class="tabular-nums text-wot-muted">
                                {{ number(grind.xp_per_battle) }}
                                <!-- Labelled, because a lifetime average and a
                                     two-week average are very different claims. -->
                                <span v-if="grind.rate_source" class="text-xs text-wot-dim">({{ grind.rate_source }})</span>
                            </dd>
                        </div>
                    </dl>

                    <p v-if="eta(grind)" class="mt-2 text-xs text-wot-dim">{{ eta(grind) }}</p>
                    <p v-else class="mt-2 text-xs text-wot-dim">
                        No recent play recorded for this vehicle yet — an estimate needs a few captures.
                    </p>
                </li>
            </ul>
        </section>

        <section v-if="complete.length" class="mt-12" aria-labelledby="done-heading">
            <div class="flex items-baseline justify-between gap-2">
                <h2 id="done-heading" class="text-xl">Completed</h2>
                <button
                    type="button"
                    class="text-sm font-medium text-wot-gold hover:text-wot-gold-bright"
                    @click="showComplete = !showComplete"
                >
                    {{ showComplete ? 'Hide' : `Show ${complete.length}` }}
                </button>
            </div>

            <ul v-if="showComplete" role="list" class="mt-4 space-y-2">
                <li
                    v-for="grind in complete"
                    :key="grind.id"
                    class="flex flex-wrap items-center justify-between gap-2 border border-wot-border-soft bg-wot-panel px-4 py-3 text-sm"
                >
                    <span>
                        <span class="text-wot-good">✓</span>
                        <span class="ms-2 text-wot-muted">{{ grind.tank_name }}</span>
                        <span class="mx-2 text-wot-dim" aria-hidden="true">→</span>
                        <span class="text-wot-muted">{{ grind.target_name }}</span>
                    </span>

                    <button
                        type="button"
                        class="text-xs uppercase tracking-wider text-wot-dim transition-colors hover:text-wot-bad"
                        @click="remove(grind)"
                    >
                        Remove
                    </button>
                </li>
            </ul>
        </section>
    </AppShell>
</template>
