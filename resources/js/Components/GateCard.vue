<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import StatusBadge from './StatusBadge.vue';

/** An interactive human-in-the-loop decision card. */
const props = defineProps({ gate: { type: Object, required: true }, showProject: { type: Boolean, default: true } });

const note = ref('');
const newBudget = ref(props.gate.payload?.suggested_new_limit ?? '');
const expanded = ref(props.gate.type === 'architecture_approval');
const busy = ref(false);
const startStage = ref(props.gate.payload?.recommended_stage ?? '');

const accent = {
    assessment_review: 'border-l-teal-500',
    architecture_approval: 'border-l-sky-500',
    cost_override: 'border-l-orange-500',
    deployment: 'border-l-emerald-500',
    self_modification: 'border-l-violet-500',
    intervention: 'border-l-red-500',
};

const labels = {
    assessment_review: ['Confirm & continue from here', 'Re-assess with my note'],
    architecture_approval: ['Approve architecture', 'Reject & regenerate'],
    cost_override: ['Raise limit & continue', 'Keep paused'],
    deployment: ['Kick off deployment', 'Send back'],
    self_modification: ['Stage this change', 'Reject'],
    intervention: ['Retry / send back', 'Skip / pause'],
};

function decide(decision) {
    busy.value = true;
    router.post(
        `/approvals/${props.gate.id}/decide`,
        {
            decision,
            note: note.value || null,
            new_budget: props.gate.type === 'cost_override' ? newBudget.value || null : null,
            start_stage: props.gate.type === 'assessment_review' ? startStage.value || null : null,
        },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}
</script>

<template>
    <div class="card border-l-4 p-5" :class="accent[gate.type]">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-[11px] font-bold uppercase tracking-wider text-stone-500">{{ gate.type_label }}</div>
                <h3 class="mt-0.5 text-base font-semibold text-stone-900">{{ gate.title }}</h3>
                <div class="mt-1 flex flex-wrap items-center gap-x-3 text-xs text-stone-500">
                    <Link v-if="showProject && gate.project" :href="`/projects/${gate.project.id}`" class="font-medium text-amber-700 hover:underline">{{ gate.project.name }}</Link>
                    <span v-if="gate.task">task: {{ gate.task }}</span>
                    <span>{{ gate.at }}</span>
                </div>
            </div>
            <StatusBadge :status="gate.status" />
        </div>

        <p v-if="gate.summary" class="mt-3 text-sm text-stone-700">{{ gate.summary }}</p>

        <div v-if="gate.type === 'cost_override' && gate.payload" class="mt-3 flex gap-6 text-sm">
            <div><span class="text-stone-500">Spent</span> <b>${{ Number(gate.payload.spent).toFixed(2) }}</b></div>
            <div><span class="text-stone-500">Limit</span> <b>${{ Number(gate.payload.limit).toFixed(2) }}</b></div>
        </div>

        <template v-if="gate.detail">
            <button class="mt-3 text-xs font-medium text-amber-700 hover:underline" @click="expanded = !expanded">{{ expanded ? 'Hide' : 'Show' }} details</button>
            <pre v-if="expanded" class="code mt-2 max-h-96 whitespace-pre-wrap">{{ gate.detail }}</pre>
        </template>

        <template v-if="gate.status === 'pending'">
            <div v-if="gate.type === 'assessment_review'" class="mt-4">
                <label class="label">Resume the pipeline at</label>
                <select v-model="startStage" class="input max-w-sm">
                    <option v-for="s in gate.payload.stages" :key="s.value" :value="s.value">
                        {{ s.label }}{{ s.value === gate.payload.recommended_stage ? ' (recommended)' : '' }}
                    </option>
                </select>
                <p class="mt-1 text-xs text-stone-500">Stages before this one are marked satisfied by the existing repository; nothing in them is rebuilt.</p>
            </div>
            <div class="mt-4 grid gap-3" :class="gate.type === 'cost_override' ? 'sm:grid-cols-[1fr_160px]' : ''">
                <div>
                    <label class="label">Note (used as feedback when rejecting)</label>
                    <input v-model="note" class="input" placeholder="Optional" />
                </div>
                <div v-if="gate.type === 'cost_override'">
                    <label class="label">New limit (USD)</label>
                    <input v-model="newBudget" type="number" step="0.01" min="0" class="input" />
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <button class="btn-success" :disabled="busy" @click="decide('approve')">{{ labels[gate.type][0] }}</button>
                <button class="btn-secondary" :disabled="busy" @click="decide('reject')">{{ labels[gate.type][1] }}</button>
            </div>
        </template>
        <p v-else-if="gate.decision_note" class="mt-3 text-sm italic text-stone-500">“{{ gate.decision_note }}”<span v-if="gate.decided_at"> — {{ gate.decided_at }}</span></p>
    </div>
</template>
