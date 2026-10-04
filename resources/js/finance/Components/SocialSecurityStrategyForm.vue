<script setup>
import { useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import Field from './Field.vue';
import FinDialog from './FinDialog.vue';

/**
 * Build or edit one claiming strategy: an age for each person, and the two
 * rates the comparison is run under. A blank rate is a real answer — "follow
 * inflation", "what my fleet earns" — so each shows its fallback as its
 * placeholder.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    strategy: { type: Object, default: null },
    people: { type: Object, required: true },
    hasSpouse: { type: Boolean, default: false },
    ages: { type: Object, required: true },
    inflationRate: { type: Number, required: true },
    growthRate: { type: Number, required: true },
});

const emit = defineEmits(['close']);

const dialog = ref(null);

const blank = {
    name: '',
    claim_age: 67,
    claim_months: 0,
    spouse_claim_age: null,
    spouse_claim_months: 0,
    cola_rate: null,
    discount_rate: null,
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
        form.claim_age = props.people.self.full_retirement_age.years;
        form.claim_months = props.people.self.full_retirement_age.months;
        form.name = `Claim at ${form.claim_age}`;
    }
}, { immediate: true });

const fra = (person) => `${person.full_retirement_age.years}${person.full_retirement_age.months ? ` and ${person.full_retirement_age.months} months` : ''}`;

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => dialog.value?.close() };

    if (props.strategy) {
        form.patch(`/finance/retirement/social-security/strategies/${props.strategy.id}`, options);
    } else {
        form.post('/finance/retirement/social-security/strategies', options);
    }
};
</script>

<template>
    <FinDialog ref="dialog" :open="open" :title="strategy ? `Edit ${strategy.name}` : 'Build a claiming strategy'" wide @close="emit('close')">
        <form class="flex flex-col gap-5" @submit.prevent="save">
            <Field label="Name" :error="form.errors.name">
                <input v-model="form.name" type="text" required maxlength="80">
            </Field>

            <fieldset>
                <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">You claim at</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field label="Age" :hint="`Between ${ages.earliest} and ${ages.latest}. Your full retirement age is ${fra(people.self)}.`" :error="form.errors.claim_age">
                        <input v-model.number="form.claim_age" type="number" :min="ages.earliest" :max="ages.latest" step="1" required>
                    </Field>
                    <Field label="And months" hint="0 to 11, past that birthday." :error="form.errors.claim_months">
                        <input v-model.number="form.claim_months" type="number" min="0" max="11" step="1" required>
                    </Field>
                </div>
            </fieldset>

            <fieldset v-if="hasSpouse">
                <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Your spouse claims at</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field label="Age" :hint="`Blank is the same age as you. Their full retirement age is ${fra(people.spouse)}.`" :error="form.errors.spouse_claim_age">
                        <input v-model.number="form.spouse_claim_age" type="number" :min="ages.earliest" :max="ages.latest" step="1" :placeholder="String(form.claim_age)">
                    </Field>
                    <Field label="And months" :error="form.errors.spouse_claim_months">
                        <input v-model.number="form.spouse_claim_months" type="number" min="0" max="11" step="1" required :disabled="form.spouse_claim_age === null || form.spouse_claim_age === ''">
                    </Field>
                </div>
            </fieldset>

            <fieldset>
                <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Run it on</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <Field label="Cost-of-living rise" suffix="% / yr" :hint="`Blank follows your inflation rate (${inflationRate}%), which holds a benefit level in today's dollars.`" :error="form.errors.cola_rate">
                        <input v-model.number="form.cola_rate" type="number" min="-5" max="15" step="0.1" :placeholder="String(inflationRate)">
                    </Field>
                    <Field label="What money taken early could earn" suffix="% / yr" :hint="`For the present value. Blank is what your fleet is expected to earn (${growthRate}%).`" :error="form.errors.discount_rate">
                        <input v-model.number="form.discount_rate" type="number" min="-10" max="20" step="0.1" :placeholder="String(growthRate)">
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
