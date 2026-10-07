<script setup>
import { Link, router } from '@inertiajs/vue3';
import { IconPencil, IconTrash } from '@tabler/icons-vue';
import { asMonth, moneyExact } from '../lib/format';

/**
 * A list of income streams or expenses, with edit and remove at the end of
 * each row. Shared by the income & expenses page and a holding's own page.
 */
defineProps({
    flows: { type: Array, required: true },
    // Hide the "belongs to" column on a holding's page, where it is constant.
    showHolding: { type: Boolean, default: true },
});

const emit = defineEmits(['edit']);

const remove = (flow) => {
    if (window.confirm(flow.items_count ? `Remove ${flow.name} and the ${flow.items_count} items inside it?` : `Remove ${flow.name}?`)) {
        router.delete(`/finance/flows/${flow.id}`, { preserveScroll: true });
    }
};
</script>

<template>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-y border-fin-grey-200 bg-fin-cream-50 text-left text-xs font-medium text-fin-grey-500">
                    <th class="px-5 py-2.5 font-medium">Name</th>
                    <th class="px-3 py-2.5 font-medium">Category</th>
                    <th class="px-3 py-2.5 font-medium">Schedule</th>
                    <th class="px-3 py-2.5 text-right font-medium">Amount</th>
                    <th class="px-3 py-2.5 text-right font-medium">Per month</th>
                    <th class="px-5 py-2.5"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="flow in flows" :key="flow.id" class="border-b border-fin-grey-100 last:border-0">
                    <td class="px-5 py-3">
                        <span class="font-medium text-fin-black">{{ flow.name }}</span>
                        <span v-if="!flow.is_running" class="ml-2 whitespace-nowrap rounded-full bg-fin-gold-100 px-2 py-0.5 text-[11px] text-fin-gold-600">
                            {{ flow.starts_later ? `Starts ${asMonth(flow.starts_on)}` : 'Ended' }}
                        </span>
                        <span v-if="showHolding && flow.holding_name" class="ml-2 whitespace-nowrap rounded-full bg-fin-navy-100 px-2 py-0.5 text-[11px] text-fin-navy-700">{{ flow.holding_name }}</span>
                        <span v-if="flow.account_name" class="block text-xs text-fin-grey-500">{{ flow.direction === 'income' ? 'Paid into' : 'Paid from' }} {{ flow.account_name }}</span>
                    </td>
                    <td class="px-3 py-3 text-fin-grey-600">
                        {{ flow.category_label }}
                        <span v-if="flow.direction === 'income'" class="block text-xs text-fin-grey-500">{{ flow.taxation_label ?? 'Not taxed' }}<template v-if="flow.taxation && flow.taxed_portion < 100"> · {{ flow.taxed_portion }}% of it</template></span>
                    </td>
                    <!-- A flow with items inside has no schedule or amount of its own: it is their sum. -->
                    <td v-if="flow.items_count" class="px-3 py-3 text-fin-grey-600">
                        <Link href="/finance/budget" class="text-fin-green-600 hover:underline">{{ flow.items_count }} {{ flow.items_count === 1 ? 'item' : 'items' }}</Link>
                        <span class="block text-xs text-fin-grey-500">Set up on the budget</span>
                    </td>
                    <td v-else class="px-3 py-3 text-fin-grey-600">
                        {{ flow.frequency_label }}
                        <template v-if="flow.frequency === 'hourly'"> · {{ flow.hours_per_week }} h/wk</template>
                        <Link v-if="flow.is_itemized" href="/finance/budget" class="block text-xs text-fin-green-600 hover:underline">An estimate · itemise it</Link>
                    </td>
                    <td class="px-3 py-3 text-right text-fin-charcoal">{{ flow.items_count ? '—' : moneyExact(flow.amount) }}</td>
                    <td class="px-3 py-3 text-right font-medium text-fin-black">{{ flow.frequency === 'once' ? '—' : moneyExact(flow.monthly_amount) }}</td>
                    <td class="whitespace-nowrap px-5 py-3 text-right">
                        <button type="button" class="fin-icon-btn" :aria-label="`Edit ${flow.name}`" @click="emit('edit', flow)"><IconPencil :size="16" /></button>
                        <button type="button" class="fin-icon-btn" :aria-label="`Remove ${flow.name}`" @click="remove(flow)"><IconTrash :size="16" /></button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
