<script setup>
import { router, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import BattlePassRow from './BattlePassRow.vue';
import TankPicker from '../TankPicker.vue';

/**
 * The Battle Pass roster: who has been collected, and where they are serving.
 *
 * Edited one field at a time, as each control is left — a row is changed in
 * place rather than opened, saved and closed. That suits what this is: a list
 * kept up to date a field at a time as tankers are earned, moved and retired.
 */
const props = defineProps({
    crews: { type: Array, default: () => [] },
    roles: { type: Object, default: () => ({}) },
    statuses: { type: Object, default: () => ({}) },
    genders: { type: Object, default: () => ({}) },
    // Deferred: a thousand vehicles that only this tab needs.
    vehicles: { type: Array, default: null },
});

const page = usePage();

const BATTLE_PASS_ONLY = { preserveScroll: true, only: ['battle_pass'] };

// Male by default, which is what most of the roster is; the switch is one click
// either way.
const blank = () => ({ name: '', nation: '', season: null, gender: 'male', status: 'uncollected', tank_id: null, crew_role: '' });
const adding = reactive(blank());

const addCrew = () => {
    if (!adding.name.trim()) {
        return;
    }

    router.post('/wot/crews/battle-pass', { ...adding, name: adding.name.trim() }, {
        ...BATTLE_PASS_ONLY,
        onSuccess: () => Object.assign(adding, blank()),
    });
};

const saveCrew = (crew, payload) => router.patch(`/wot/crews/battle-pass/${crew.id}`, payload, BATTLE_PASS_ONLY);

const removeCrew = (crew) => router.delete(`/wot/crews/battle-pass/${crew.id}`, BATTLE_PASS_ONLY);

const vehiclesById = computed(() => new Map((props.vehicles ?? []).map((vehicle) => [vehicle.tank_id, vehicle])));

// What the button in the In tank column says. The vehicle list is deferred, so
// a posting can exist before there is a name to show for it.
const tankLabel = (tankId) => {
    if (tankId === null || tankId === undefined) {
        return 'Choose Tank';
    }

    return vehiclesById.value.get(tankId)?.name ?? 'Loading…';
};

const tankNation = (tankId) => vehiclesById.value.get(tankId)?.nation ?? '';

/*
 * The tank picker, one instance shared by every row and the add row.
 *
 * `picking` says who it is open for and what to do with the answer: a roster
 * row saves straight away, while the add row only fills in `adding`, which is
 * posted with the rest of the new tanker. Null while it is closed.
 */
const picking = ref(null);

const chooseTankFor = (crew) => (picking.value = {
    title: `Choose a tank for ${crew.name}`,
    selectedId: crew.tank_id,
    apply: (tankId) => saveCrew(crew, { tank_id: tankId }),
});

const chooseTankForNew = () => (picking.value = {
    title: 'Choose a tank for the new crew member',
    selectedId: adding.tank_id,
    apply: (tankId) => (adding.tank_id = tankId),
});

const columns = ['Name', 'Nation', 'Season', 'Gender', 'Status', 'In tank', 'Role'];
</script>

<template>
    <section class="mt-4" aria-labelledby="battle-pass-heading">
        <h2 id="battle-pass-heading" class="sr-only">Battle Pass crew</h2>

        <!--
            The add row's form, declared outside the table and joined to its
            controls by id. A <form> cannot wrap a <tr>, and the row has to be a
            real row so its fields sit under the same column widths as the
            roster below. Only the fields that take part in submission carry the
            `form` attribute — addCrew() reads everything from `adding` rather
            than from the form data.
        -->
        <form id="add-battle-pass-crew" @submit.prevent="addCrew"></form>

        <div class="overflow-x-auto border border-wot-border bg-wot-panel">
            <!-- Borders set per section rather than with divide-y on the table.
                 Tables collapse their borders, and a collapsed edge picks solid
                 over dashed — so a divide rule on the table would have quietly
                 overpainted the dashed line under the add row. -->
            <table class="min-w-full text-sm">
                <thead class="border-b border-wot-border bg-wot-sunken">
                    <tr>
                        <th
                            v-for="(column, index) in columns"
                            :key="column"
                            scope="col"
                            class="py-3 text-left text-xs font-bold uppercase tracking-wider text-wot-dim"
                            :class="index === 0 ? 'px-4' : 'px-3'"
                        >
                            {{ column }}
                        </th>
                        <th scope="col" class="px-3 py-3 text-right text-xs font-bold uppercase tracking-wider text-wot-dim">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <!-- The add row, heading the roster rather than trailing it:
                     each field sits in the column it will land in, and a new
                     tanker is entered where the eye already is. The dashed rule
                     marks it as a row not yet written. -->
                <tbody class="border-b border-dashed border-wot-border">
                    <BattlePassRow
                        draft
                        form-id="add-battle-pass-crew"
                        :crew="adding"
                        :roles="roles"
                        :statuses="statuses"
                        :genders="genders"
                        :nations="page.props.nations"
                        :tank-label="tankLabel(adding.tank_id)"
                        :tank-nation="tankNation(adding.tank_id)"
                        :error="page.props.errors?.name"
                        @save="Object.assign(adding, $event)"
                        @choose-tank="chooseTankForNew"
                    />
                </tbody>

                <tbody class="divide-y divide-wot-border-soft">
                    <BattlePassRow
                        v-for="crew in crews"
                        :key="crew.id"
                        :crew="crew"
                        :roles="roles"
                        :statuses="statuses"
                        :genders="genders"
                        :nations="page.props.nations"
                        :tank-label="tankLabel(crew.tank_id)"
                        :tank-nation="tankNation(crew.tank_id)"
                        @save="saveCrew(crew, $event)"
                        @remove="removeCrew(crew)"
                        @choose-tank="chooseTankFor(crew)"
                    />

                    <tr v-if="!crews.length">
                        <td :colspan="columns.length + 1" class="px-4 py-8 text-center text-sm text-wot-dim">
                            No Battle Pass crew recorded yet. Add one above.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <TankPicker
            :open="picking !== null"
            :vehicles="vehicles"
            :selected-id="picking?.selectedId ?? null"
            :title="picking?.title"
            @pick="(tankId) => picking?.apply(tankId)"
            @close="picking = null"
        />
    </section>
</template>
