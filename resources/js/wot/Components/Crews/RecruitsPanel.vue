<script setup>
import EditableNumber from '../EditableNumber.vue';
import { n } from '../../lib/format';

/**
 * Recruits held, by where they came from.
 *
 * Steppers rather than fields: these move by one or two at a time as they are
 * earned and spent, so the useful control is a nudge rather than a retype.
 */
defineProps({
    recruits: { type: Object, required: true },
});
</script>

<template>
    <div class="border border-wot-border bg-wot-panel">
        <div class="flex items-baseline justify-between border-b border-wot-border px-4 py-3">
            <h3 class="text-sm">Recruits</h3>
            <span class="text-xs uppercase tracking-wider text-wot-dim">{{ n(recruits.total) }} held</span>
        </div>

        <table class="min-w-full divide-y divide-wot-border-soft text-sm">
            <tbody class="divide-y divide-wot-border-soft">
                <tr v-for="row in recruits.rows" :key="row.key" class="hover:bg-wot-sunken">
                    <th scope="row" class="px-4 py-2 text-left font-normal text-wot-text">{{ row.label }}</th>
                    <td class="px-4 py-2 text-right">
                        <EditableNumber
                            field="quantity"
                            stepper
                            :model-value="row.quantity"
                            :url="`/wot/crews/recruits/${row.key}`"
                            :only="['recruits']"
                        />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
