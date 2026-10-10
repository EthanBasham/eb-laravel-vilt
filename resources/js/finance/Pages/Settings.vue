<script setup>
import { router, useForm, usePage } from '@inertiajs/vue3';
import { IconPencil, IconRefresh, IconSparkles, IconTrash } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import BracketEditor from '../Components/BracketEditor.vue';
import Card from '../Components/Card.vue';
import Field from '../Components/Field.vue';
import FinShell from '../Components/FinShell.vue';
import { money, moneyExact } from '../lib/format';

const props = defineProps({
    profile: Object,
    is_empty: Boolean,
    presets: Object,
    tax: Object,
    irmaa: Object,
});

const statuses = computed(() => usePage().props.lists.filing_statuses);

const form = useForm({
    birth_date: props.profile.birth_date,
    filing_status: props.profile.filing_status,
    retirement_age: props.profile.retirement_age,
    life_expectancy: props.profile.life_expectancy,
    inflation_rate: props.profile.inflation_rate,
    state: props.profile.state,
    state_deduction: props.profile.state_deduction,
    state_brackets: props.profile.state_brackets.map((bracket) => ({ ...bracket })),
    local_name: props.profile.local_name,
    local_deduction: props.profile.local_deduction,
    local_brackets: props.profile.local_brackets.map((bracket) => ({ ...bracket })),
    // Null on either of these two means the built-in figure for the filing status.
    standard_deduction: props.profile.standard_deduction,
    qcd_limit: props.profile.qcd_limit,
    ltcg_brackets: props.profile.ltcg_brackets?.map((bracket) => ({ ...bracket })) ?? null,
    se_tax_rate: props.profile.se_tax_rate,
});

// The server's [rate, ceiling] pairs as the { rate, up_to } rows the editor
// and the profile use.
const asRows = (pairs) => pairs.map(([rate, upTo]) => ({ rate, up_to: upTo }));

const TAX_FIELDS = ['state', 'state_deduction', 'state_brackets', 'local_name', 'local_deduction', 'local_brackets'];

const FEDERAL_FIELDS = ['standard_deduction', 'qcd_limit', 'ltcg_brackets', 'se_tax_rate'];

// The two tax cards on the right read as plain tables until Edit is pressed.
const editingTax = ref(false);
const editingFederal = ref(false);

// One form, two Save buttons: the profile is saved whole whichever is pressed.
const save = () => form.put('/finance/settings', {
    preserveScroll: true,
    onSuccess: () => {
        editingTax.value = false;
        editingFederal.value = false;
    },
    // A refused bracket has to be on show to be corrected.
    onError: (errors) => {
        const refused = (fields) => Object.keys(errors).some((key) => fields.some((field) => key.startsWith(field)));

        if (refused(TAX_FIELDS)) editingTax.value = true;
        if (refused(FEDERAL_FIELDS)) editingFederal.value = true;
    },
});

const cancelFederal = () => {
    form.reset(...FEDERAL_FIELDS);
    form.clearErrors();
    editingFederal.value = false;
};

// An emptied deduction is "use the built-in one", not zero.
const setDeduction = (event) => {
    form.standard_deduction = event.target.value === '' ? null : Number(event.target.value);
};

// And an emptied limit is the built-in one.
const setQcdLimit = (event) => {
    form.qcd_limit = event.target.value === '' ? null : Number(event.target.value);
};

// Ticked, the capital gains brackets follow the filing status; unticked, they
// start from the built-in ones and are the profile's own from then on.
const usesBuiltInGains = computed({
    get: () => form.ltcg_brackets === null,
    set: (builtIn) => {
        form.ltcg_brackets = builtIn ? null : asRows(props.tax.built_in_capital_gains_brackets);
    },
});

const cancelTax = () => {
    form.reset(...TAX_FIELDS);
    form.clearErrors();
    editingTax.value = false;
};

/*
 * The saved state and local tables, as the static card prints them. Read from
 * the profile rather than the form: this is what the tools are taxing with.
 */
const savedTables = computed(() => {
    const { profile } = props;

    if (!profile.state) {
        return [];
    }

    const tables = [{
        key: 'state',
        heading: `${profile.state_label ?? 'State'} · ${money(profile.state_deduction)} standard deduction`,
        brackets: profile.state_brackets,
    }];

    if (profile.local_brackets.length) {
        tables.push({
            key: 'local',
            heading: `${profile.local_name ?? 'Local'} · ${money(profile.local_deduction)} standard deduction`,
            brackets: profile.local_brackets,
        });
    }

    return tables;
});

/*
 * State and local tax.
 *
 * The server sends `presets`: for each state it knows, a deduction and a
 * bracket table per filing status. A preset is only ever a way of filling the
 * fields in. Nothing is looked up from it again — what is saved, and what the
 * tools tax with, is whatever is in the fields when Save is pressed.
 */
const preset = computed(() => props.presets[form.state] ?? null);
const localities = computed(() => preset.value?.localities ?? {});

const fillState = () => {
    form.state_deduction = preset.value.deduction[form.filing_status];
    form.state_brackets = asRows(preset.value.brackets[form.filing_status]);
};

const clearLocal = () => {
    form.local_name = null;
    form.local_deduction = 0;
    form.local_brackets = [];
};

const fillLocal = (key) => {
    const locality = localities.value[key];

    form.local_name = locality.label;
    form.local_deduction = locality.deduction[form.filing_status];
    form.local_brackets = asRows(locality.brackets[form.filing_status]);
};

/*
 * Choosing a state fills its figures in, and clears the local tax, which
 * belonged to the state being left. "Other" and "none" have no preset: other
 * keeps whatever is in the fields to be edited, none empties them.
 */
const onStateChange = () => {
    clearLocal();

    if (preset.value) {
        fillState();
    } else if (!form.state) {
        form.state_deduction = 0;
        form.state_brackets = [];
    }
};

const loadSample = () => router.post('/finance/sample');

const clear = () => {
    if (window.confirm('Remove every holding, income, expense, scenario, goal and snapshot, and reset your profile? This cannot be undone.')) {
        router.delete('/finance/sample');
    }
};
</script>

<template>
    <FinShell title="Profile & settings" subtitle="The few facts about you the tools need: enough to place you on a tax table and a retirement timeline.">
        <div class="grid items-start gap-5 xl:grid-cols-3">
            <div class="flex flex-col gap-5 xl:col-span-2">
                <form class="flex flex-col gap-5" @submit.prevent="save">
                    <Card title="About you">
                        <div class="flex flex-col gap-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <Field label="Date of birth" :hint="`Sets your age (${profile.age}) and when RMDs begin (${profile.rmd_start_age}).`" :error="form.errors.birth_date">
                                    <input v-model="form.birth_date" type="date">
                                </Field>
                                <Field label="Tax filing status" :error="form.errors.filing_status">
                                    <select v-model="form.filing_status">
                                        <option v-for="(label, key) in statuses" :key="key" :value="key">{{ label }}</option>
                                    </select>
                                </Field>
                            </div>

                            <div class="grid gap-4 sm:grid-cols-3">
                                <Field label="Retire at" suffix="years old" hint="Earned income stops here." :error="form.errors.retirement_age">
                                    <input v-model.number="form.retirement_age" type="number" min="30" max="90" required>
                                </Field>
                                <Field label="Plan to" suffix="years old" hint="How long the money has to last." :error="form.errors.life_expectancy">
                                    <input v-model.number="form.life_expectancy" type="number" min="50" max="120" required>
                                </Field>
                                <Field label="Inflation" suffix="% / yr" :error="form.errors.inflation_rate">
                                    <input v-model.number="form.inflation_rate" type="number" min="0" max="15" step="0.1" required>
                                </Field>
                            </div>
                        </div>
                    </Card>

                    <div class="flex items-center justify-end gap-3">
                        <span v-if="form.recentlySuccessful" class="text-sm text-fin-green-600">Saved.</span>
                        <span v-else-if="form.isDirty" class="text-sm text-fin-grey-500">Unsaved changes.</span>
                        <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">Save profile</button>
                    </div>
                </form>

                <Card :title="`${irmaa.year} IRMAA brackets`" :subtitle="`${statuses[profile.filing_status]} · Medicare premiums set by your ${irmaa.income_year} income`" flush>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-5 py-2.5 font-medium">Modified AGI up to</th>
                                <th class="px-5 py-2.5 text-right font-medium">Part B / month</th>
                                <th class="px-5 py-2.5 text-right font-medium">Part D surcharge / month</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="[ceiling, partB, partD] in irmaa.tiers" :key="partB" class="border-b border-fin-grey-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-fin-black">{{ ceiling === null ? 'and above' : money(ceiling) }}</td>
                                <td class="px-5 py-2 text-right text-fin-charcoal">{{ moneyExact(partB) }}</td>
                                <td class="px-5 py-2 text-right text-fin-charcoal">{{ partD ? `+ ${moneyExact(partD)}` : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="border-t border-fin-grey-100 px-5 py-3 text-xs text-fin-grey-500">
                        Built in, and follows your saved filing status. Per person, and a cliff rather than a slope: one dollar over a line
                        costs the whole step. For reference only — the tools do not charge these yet.
                    </p>
                </Card>
            </div>

            <div class="flex flex-col gap-5">
                <Card :title="`${tax.year} federal brackets`" :subtitle="`${statuses[profile.filing_status]} · ${money(tax.deduction)} standard deduction`" flush>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                <th class="px-5 py-2.5 font-medium">Rate</th>
                                <th class="px-5 py-2.5 text-right font-medium">Taxable income up to</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="[rate, ceiling] in tax.brackets" :key="rate" class="border-b border-fin-grey-100 last:border-0">
                                <td class="px-5 py-2 font-medium text-fin-black">{{ rate }}%</td>
                                <td class="px-5 py-2 text-right text-fin-charcoal">{{ ceiling === null ? 'and above' : money(ceiling) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="border-t border-fin-grey-100 px-5 py-3 text-xs text-fin-grey-500">
                        Built in, and the same for everyone. Follows your saved filing status. The deduction, and everything below, is yours to set.
                    </p>
                </Card>

                <Card title="Deduction, capital gains & payroll tax" :subtitle="editingFederal ? 'Leave the deduction blank, or tick the box, to follow the built-in figures for your filing status.' : 'What your incomes are taxed with, beside the brackets above.'" flush>
                    <template v-if="!editingFederal" #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="editingFederal = true"><IconPencil :size="16" /> Edit</button>
                    </template>

                    <form v-if="editingFederal" class="flex flex-col gap-5 border-t border-fin-grey-100 p-5" @submit.prevent="save">
                        <Field label="Standard deduction" prefix="$" :hint="`Blank uses the built-in ${money(tax.built_in_deduction)} for ${statuses[profile.filing_status].toLowerCase()}. Either way, ${money(tax.additional_deduction)} more is added from 65.`" :error="form.errors.standard_deduction">
                            <input :value="form.standard_deduction" type="number" min="0" step="1" :placeholder="String(tax.built_in_deduction)" @input="setDeduction">
                        </Field>

                        <Field label="Qualified charitable distribution limit" prefix="$" :hint="`The most given straight from a traditional IRA to charity in a year, from 70½. Blank uses the built-in ${money(tax.built_in_qcd_limit)} for ${tax.year}. Shown beside each year of the Roth report.`" :error="form.errors.qcd_limit">
                            <input :value="form.qcd_limit" type="number" min="0" step="1" :placeholder="String(tax.built_in_qcd_limit)" @input="setQcdLimit">
                        </Field>

                        <Field label="Self-employment tax" suffix="%" hint="Charged in full on self-employed income. W-2 wages pay half of it, as FICA." :error="form.errors.se_tax_rate">
                            <input v-model.number="form.se_tax_rate" type="number" min="0" max="50" step="0.01" required>
                        </Field>

                        <fieldset>
                            <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Long-term capital gains brackets</legend>
                            <div class="flex flex-col gap-4">
                                <label class="flex items-center gap-2 text-sm text-fin-charcoal">
                                    <input v-model="usesBuiltInGains" type="checkbox">
                                    Use the built-in brackets for my filing status
                                </label>
                                <p v-if="form.errors.ltcg_brackets" class="text-xs text-fin-red-600">{{ form.errors.ltcg_brackets }}</p>
                                <BracketEditor
                                    v-if="!usesBuiltInGains"
                                    v-model="form.ltcg_brackets" field="ltcg_brackets" :errors="form.errors"
                                    empty-text="Add at least one bracket, or tick the box above."
                                />
                            </div>
                        </fieldset>

                        <div class="flex items-center justify-end gap-3">
                            <button type="button" class="fin-btn fin-btn-quiet" @click="cancelFederal">Cancel</button>
                            <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">Save</button>
                        </div>
                    </form>

                    <template v-else>
                        <dl class="border-t border-fin-grey-200 text-sm">
                            <div class="flex items-baseline justify-between gap-4 border-b border-fin-grey-100 px-5 py-2">
                                <dt class="font-medium text-fin-black">Standard deduction</dt>
                                <dd class="text-right text-fin-charcoal">
                                    {{ money(tax.deduction) }} <span class="text-xs text-fin-grey-500">{{ profile.standard_deduction === null ? 'built in' : 'yours' }}</span>
                                    <span class="block text-xs text-fin-grey-500">plus {{ money(tax.additional_deduction) }} from 65</span>
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4 border-b border-fin-grey-100 px-5 py-2">
                                <dt class="font-medium text-fin-black">Qualified charitable distribution limit</dt>
                                <dd class="text-right text-fin-charcoal">
                                    {{ money(tax.qcd_limit) }} <span class="text-xs text-fin-grey-500">{{ profile.qcd_limit === null ? 'built in' : 'yours' }}</span>
                                    <span class="block text-xs text-fin-grey-500">a year, from 70½ (the year you turn {{ tax.qcd_start_age }})</span>
                                </dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4 border-b border-fin-grey-100 px-5 py-2">
                                <dt class="font-medium text-fin-black">Self-employment tax</dt>
                                <dd class="text-right text-fin-charcoal">{{ profile.se_tax_rate }}%</dd>
                            </div>
                            <div class="flex items-baseline justify-between gap-4 px-5 py-2">
                                <dt class="font-medium text-fin-black">FICA on W-2 wages</dt>
                                <dd class="text-right text-fin-charcoal">{{ tax.fica_rate }}% <span class="text-xs text-fin-grey-500">half of it</span></dd>
                            </div>
                        </dl>

                        <h3 class="border-t border-fin-grey-200 px-5 pb-2 pt-3 text-xs font-semibold text-fin-black">
                            Long-term capital gains <span class="font-normal text-fin-grey-500">· {{ profile.ltcg_brackets === null ? 'built in' : 'yours' }}</span>
                        </h3>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                    <th class="px-5 py-2.5 font-medium">Rate</th>
                                    <th class="px-5 py-2.5 text-right font-medium">Taxable income up to</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="([rate, ceiling], index) in tax.capital_gains_brackets" :key="index" class="border-b border-fin-grey-100 last:border-0">
                                    <td class="px-5 py-2 font-medium text-fin-black">{{ rate }}%</td>
                                    <td class="px-5 py-2 text-right text-fin-charcoal">{{ ceiling === null ? 'and above' : money(ceiling) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p class="border-t border-fin-grey-100 px-5 py-3 text-xs text-fin-grey-500">
                            Gains sit on top of your other income, so the thresholds are of the two together. Each income says how it is taxed on Income &amp; expenses.
                        </p>
                    </template>
                </Card>

                <Card title="State & local income tax" :subtitle="editingTax ? 'Pick a state to fill its brackets in, then correct anything that is off. What you save is what the tools use.' : 'Added to the federal tax above.'" flush>
                    <template v-if="!editingTax" #actions>
                        <button type="button" class="fin-btn fin-btn-quiet" @click="editingTax = true"><IconPencil :size="16" /> Edit</button>
                    </template>

                    <form v-if="editingTax" class="flex flex-col gap-5 border-t border-fin-grey-100 p-5" @submit.prevent="save">
                        <Field label="State" :error="form.errors.state">
                            <select v-model="form.state" @change="onStateChange">
                                <option :value="null">None — federal tax only</option>
                                <option v-for="(state, key) in presets" :key="key" :value="key">{{ state.label }}</option>
                                <option value="other">Another state — I'll enter it</option>
                            </select>
                        </Field>

                        <div v-if="preset" class="-mt-2 flex flex-col items-start gap-2">
                            <button type="button" class="fin-btn fin-btn-quiet" @click="fillState">
                                <IconRefresh :size="16" /> Refill {{ preset.label }} for {{ statuses[form.filing_status].toLowerCase() }}
                            </button>
                            <p class="text-xs text-fin-grey-500">
                                {{ preset.note }} The figures depend on filing status — if you change yours, refill.
                            </p>
                        </div>

                        <fieldset v-if="form.state">
                            <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">State brackets</legend>
                            <div class="flex flex-col gap-4">
                                <Field label="State standard deduction" prefix="$" :error="form.errors.state_deduction">
                                    <input v-model.number="form.state_deduction" type="number" min="0" step="1" required>
                                </Field>
                                <BracketEditor
                                    v-model="form.state_brackets" field="state_brackets" :errors="form.errors"
                                    empty-text="No brackets: no state tax is added to this income. Add one if your state does tax it."
                                />
                            </div>
                        </fieldset>

                        <fieldset v-if="form.state">
                            <legend class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-fin-grey-400">Local brackets</legend>
                            <div class="flex flex-col gap-4">
                                <Field label="City or county" hint="Optional. Only if it levies its own income tax." :error="form.errors.local_name">
                                    <input v-model="form.local_name" type="text" maxlength="60" placeholder="e.g. New York City">
                                </Field>
                                <Field label="Local standard deduction" prefix="$" :error="form.errors.local_deduction">
                                    <input v-model.number="form.local_deduction" type="number" min="0" step="1" required>
                                </Field>

                                <div v-if="Object.keys(localities).length || form.local_brackets.length" class="flex flex-wrap gap-2">
                                    <button v-for="(locality, key) in localities" :key="key" type="button" class="fin-btn fin-btn-quiet" @click="fillLocal(key)">
                                        <IconRefresh :size="16" /> Fill in {{ locality.label }}
                                    </button>
                                    <button v-if="form.local_brackets.length" type="button" class="fin-btn fin-btn-quiet" @click="clearLocal">Clear local tax</button>
                                </div>

                                <BracketEditor
                                    v-model="form.local_brackets" field="local_brackets" :errors="form.errors"
                                    empty-text="No local income tax."
                                />
                            </div>
                        </fieldset>

                        <div class="flex items-center justify-end gap-3">
                            <button type="button" class="fin-btn fin-btn-quiet" @click="cancelTax">Cancel</button>
                            <button type="submit" class="fin-btn fin-btn-primary" :disabled="form.processing">Save</button>
                        </div>
                    </form>

                    <p v-else-if="!savedTables.length" class="border-t border-fin-grey-100 px-5 py-3 text-sm text-fin-grey-600">
                        None — federal tax only.
                    </p>

                    <template v-for="table in savedTables" v-else :key="table.key">
                        <h3 class="border-t border-fin-grey-200 px-5 pb-2 pt-3 text-xs font-semibold text-fin-black">{{ table.heading }}</h3>
                        <table v-if="table.brackets.length" class="w-full text-sm">
                            <thead>
                                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs text-fin-grey-500">
                                    <th class="px-5 py-2.5 font-medium">Rate</th>
                                    <th class="px-5 py-2.5 text-right font-medium">Taxable income up to</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(bracket, index) in table.brackets" :key="index" class="border-b border-fin-grey-100 last:border-0">
                                    <td class="px-5 py-2 font-medium text-fin-black">{{ bracket.rate }}%</td>
                                    <td class="px-5 py-2 text-right text-fin-charcoal">{{ bracket.up_to === null ? 'and above' : money(bracket.up_to) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="px-5 pb-3 text-sm text-fin-grey-600">No tax on this income.</p>
                    </template>
                </Card>
            </div>

            <Card class="xl:col-span-3" title="Your data" subtitle="Two blunt instruments.">
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="fin-btn fin-btn-quiet" :disabled="!is_empty" @click="loadSample">
                        <IconSparkles :size="16" /> Load the sample fleet
                    </button>
                    <button type="button" class="fin-btn fin-btn-danger" @click="clear"><IconTrash :size="16" /> Clear everything</button>
                    <p class="text-xs text-fin-grey-500">
                        {{ is_empty ? 'The sample is a made-up household that exercises every tool.' : 'The sample only loads into an empty fleet — clear yours first.' }}
                    </p>
                </div>
            </Card>
        </div>
    </FinShell>
</template>
