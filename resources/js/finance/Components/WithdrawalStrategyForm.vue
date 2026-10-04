<script setup>
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';
import { money } from '../lib/format';

/**
 * Build or edit one withdrawal strategy: the order the buckets are drawn in,
 * how much a retired year takes, and what it is run on. Blanks fall back as
 * a conversion strategy's do, and show what they fall back to.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    strategy: { type: Object, default: null },
    kinds: { type: Object, required: true },
    spendingRules: { type: Object, required: true },
    fillRates: { type: Array, required: true },
    scenarios: { type: Array, required: true },
    profile: { type: Object, required: true },
    growthRate: { type: Number, required: true },
    // What the three buckets hold today, for the "4% of it" suggestion.
    balance: { type: Number, required: true },
    defaultPercent: { type: Number, required: true },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

const blank = {
    name: '',
    kind: 'conventional',
    scenario_id: null,
    fill_rate: null,
    spending_rule: 'projection',
    spending_amount: null,
    spending_percent: null,
    inflation_rate: null,
    growth_rate: null,
};

const form = useForm({ ...blank });

// An emptied number field is '', which is this form's way of saying "blank".
form.transform((data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])));

watch(() => props.open, (open) => {
    if (!open) return;

    form.clearErrors();

    Object.keys(blank).forEach((key) => {
        form[key] = props.strategy ? props.strategy[key] : blank[key];
    });

    if (!props.strategy) {
        form.name = props.kinds[form.kind].label;
    }
}, { immediate: true });

const kind = computed(() => props.kinds[form.kind]);
const rule = computed(() => props.spendingRules[form.spending_rule]);

// A new strategy is named after its order until it is given a name of its own.
const onKindChange = () => {
    if (!props.strategy && Object.values(props.kinds).some((candidate) => candidate.label === form.name)) {
        form.name = kind.value.label;
    }
};

// Each rule starts on the classic figure: 4% of today's balance.
const onRuleChange = () => {
    if (rule.value.reads === 'amount' && !form.spending_amount) {
        form.spending_amount = Math.round(props.balance * props.defaultPercent / 100 / 100) * 100;
    }

    if (rule.value.reads === 'percent' && !form.spending_percent) {
        form.spending_percent = props.defaultPercent;
    }
};

const projectionRate = computed(() => props.scenarios.find((scenario) => scenario.id === form.scenario_id)?.bracket_inflation_rate ?? props.profile.inflation_rate);

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (props.strategy) {
        form.patch(`/finance/retirement/withdrawals/strategies/${props.strategy.id}`, options);
    } else {
        form.post('/finance/retirement/withdrawals/strategies', options);
    }
};
</script>

<template>
    <FinDialog ref="dialog" :open="open" :title="strategy ? `Edit ${strategy.name}` : 'Build a withdrawal strategy'" wide @close="emit('close')">
        <form class="flex flex-col gap-5" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <Field label="Draw in this order" :hint="kind.description" :error="form.errors.kind">
                    <select v-model="form.kind" @change="onKindChange">
                        <option v-for="(option, key) in kinds" :key="key" :value="key">{{ option.label }}</option>
                    </select>
                </Field>
                <Field label="Name" :error="form.errors.name">
                    <input v-model="form.name" type="text" required maxlength="80">
                </Field>
            </div>

            <Field v-if="kind.fills" label="Take traditional money to the top of" :error="form.errors.fill_rate">
                <select v-model="form.fill_rate">
                    <option :value="null">Whichever bracket I am in</option>
                    <option v-for="rate in fillRates" :key="rate" :value="rate">The {{ rate }}% bracket</option>
                </select>
            </Field>

            <fieldset>
                <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Each retired year takes</legend>
                <div class="grid items-start gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-2">
                        <Field label="How much" :hint="rule.description" :error="form.errors.spending_rule">
                            <select v-model="form.spending_rule" @change="onRuleChange">
                                <option v-for="(option, key) in spendingRules" :key="key" :value="key">{{ option.label }}</option>
                            </select>
                        </Field>
                    </div>
                    <Field v-if="rule.reads === 'amount'" label="A year" prefix="$" :hint="`In today's dollars. ${defaultPercent}% of today's ${money(balance)} is ${money(balance * defaultPercent / 100)}.`" :error="form.errors.spending_amount">
                        <input v-model.number="form.spending_amount" type="number" min="0" step="100" required>
                    </Field>
                    <Field v-if="rule.reads === 'percent'" label="Of the balance" suffix="% / yr" hint="Of everything in the three buckets as the year opens." :error="form.errors.spending_percent">
                        <input v-model.number="form.spending_percent" type="number" min="0" max="100" step="0.1" required>
                    </Field>
                </div>
            </fieldset>

            <fieldset>
                <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Run it on</legend>
                <div class="grid gap-4 sm:grid-cols-3">
                    <Field label="Projection" hint="From Projections & scenarios." :error="form.errors.scenario_id">
                        <select v-model="form.scenario_id">
                            <option :value="null">Income &amp; expenses as entered</option>
                            <option v-for="scenario in scenarios" :key="scenario.id" :value="scenario.id">{{ scenario.name }}</option>
                        </select>
                    </Field>
                    <Field label="Inflation" suffix="% / yr" :hint="`Prices, tax brackets and IRMAA tiers. Blank follows the projection (${projectionRate}%).`" :error="form.errors.inflation_rate">
                        <input v-model.number="form.inflation_rate" type="number" min="-5" max="15" step="0.1" :placeholder="String(projectionRate)">
                    </Field>
                    <Field label="Growth" suffix="% / yr" :hint="`Every account, before inflation. Blank lets each grow at its own holdings' rate (${growthRate}% overall).`" :error="form.errors.growth_rate">
                        <input v-model.number="form.growth_rate" type="number" min="-10" max="20" step="0.1" :placeholder="String(growthRate)">
                    </Field>
                </div>
            </fieldset>

            <div class="mt-1 flex justify-end gap-2">
                <button type="button" class="fin-btn fin-btn-quiet" @click="dialog?.close()">Cancel</button>
                <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">{{ strategy ? 'Save changes' : 'Add strategy' }}</button>
            </div>
        </form>
    </FinDialog>
</template>
