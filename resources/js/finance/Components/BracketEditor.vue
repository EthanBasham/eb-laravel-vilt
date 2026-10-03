<script setup>
import { IconPlus, IconX } from '@tabler/icons-vue';

/**
 * An editable tax table: one row per bracket, a rate and the taxable income
 * it runs up to.
 *
 * The model is the list as the server stores it, `[{ rate, up_to }]`, with
 * `up_to: null` on the open top bracket — shown here as an empty field, since
 * "and above" is the absence of a ceiling. An empty list is a real value too:
 * no tax of this kind.
 *
 * Rows are edited in place rather than through a working copy; the form that
 * owns the list is the working copy.
 */
const props = defineProps({
    // Validation errors for the whole form, and the field this list is under,
    // so each message can be printed against the row that earned it
    // ("state_brackets.2.up_to").
    errors: { type: Object, default: () => ({}) },
    field: { type: String, required: true },
    // What an empty list means, in the page's words.
    emptyText: { type: String, default: 'No brackets.' },
});

const brackets = defineModel({ type: Array, required: true });

// A new bracket goes on the end, open-ended, at the rate above it — so the
// one above needs a ceiling now, which the server will ask for if it is blank.
const add = () => {
    const last = brackets.value[brackets.value.length - 1];

    brackets.value.push({ rate: last?.rate ?? 0, up_to: null });
};

const remove = (index) => brackets.value.splice(index, 1);

// An emptied number input is '', which the server would refuse as a ceiling.
const setCeiling = (bracket, event) => {
    bracket.up_to = event.target.value === '' ? null : Number(event.target.value);
};

const errorFor = (index) => props.errors[`${props.field}.${index}.rate`] ?? props.errors[`${props.field}.${index}.up_to`] ?? null;
</script>

<template>
    <div>
        <p v-if="!brackets.length" class="rounded-xl bg-fin-cream-100 px-4 py-3 text-sm text-fin-grey-600">{{ emptyText }}</p>

        <table v-else class="w-full max-w-md text-sm">
            <thead>
                <tr class="text-left text-xs text-fin-grey-500">
                    <th class="pb-1.5 pr-3 font-medium">Rate</th>
                    <th class="pb-1.5 pr-3 font-medium">On taxable income up to</th>
                    <th class="pb-1.5"><span class="sr-only">Remove</span></th>
                </tr>
            </thead>
            <tbody>
                <template v-for="(bracket, index) in brackets" :key="index">
                    <tr>
                        <td class="w-32 py-1 pr-3">
                            <span class="relative block">
                                <input v-model.number="bracket.rate" type="number" min="0" max="100" step="0.001" class="!pr-7" :aria-label="`Rate of bracket ${index + 1}`">
                                <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-fin-grey-400">%</span>
                            </span>
                        </td>
                        <td class="py-1 pr-3">
                            <span class="relative block">
                                <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-fin-grey-400">$</span>
                                <input
                                    :value="bracket.up_to" type="number" min="0" step="1" class="!pl-7"
                                    :placeholder="index === brackets.length - 1 ? 'and above' : ''"
                                    :aria-label="`Ceiling of bracket ${index + 1}`"
                                    @input="setCeiling(bracket, $event)"
                                >
                            </span>
                        </td>
                        <td class="w-8 py-1 text-right">
                            <button type="button" class="fin-icon-btn" :aria-label="`Remove bracket ${index + 1}`" @click="remove(index)"><IconX :size="16" /></button>
                        </td>
                    </tr>
                    <tr v-if="errorFor(index)">
                        <td colspan="3" class="pb-1 text-xs text-fin-red-600">{{ errorFor(index) }}</td>
                    </tr>
                </template>
            </tbody>
        </table>

        <button type="button" class="fin-btn fin-btn-quiet mt-2" @click="add"><IconPlus :size="16" /> Bracket</button>
    </div>
</template>
