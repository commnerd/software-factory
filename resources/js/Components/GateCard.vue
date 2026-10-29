<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import StatusBadge from './StatusBadge.vue';

/** An interactive human-in-the-loop decision card. */
const props = defineProps({ gate: { type: Object, required: true }, showProject: { type: Boolean, default: true } });

const note = ref('');
const newBudget = ref(props.gate.payload?.suggested_new_limit ?? '');
const expanded = ref(props.gate.type === 'architecture_approval');
const busy = ref(false);

// backlog selection
const isBacklog = props.gate.type === 'backlog_selection';
const proposals = props.gate.payload?.proposals ?? [];
const selected = ref(new Set(props.gate.payload?.recommended_ids ?? []));
const open = ref(null);
const toggle = (id) => {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
};
const selectedCount = computed(() => selected.value.size);

const categoryStyle = {
    feature: 'bg-violet-100 text-violet-800', enhancement: 'bg-sky-100 text-sky-800', bugfix: 'bg-red-100 text-red-800',
    tests: 'bg-teal-100 text-teal-800', refactor: 'bg-stone-200 text-stone-700', performance: 'bg-amber-100 text-amber-800',
    security: 'bg-rose-100 text-rose-800', docs: 'bg-lime-100 text-lime-800', devex: 'bg-indigo-100 text-indigo-800', dependency: 'bg-orange-100 text-orange-800',
};

const accent = {
    assessment_review: 'border-l-teal-500',
    backlog_selection: 'border-l-fuchsia-500',
    architecture_approval: 'border-l-sky-500',
    cost_override: 'border-l-orange-500',
    deployment: 'border-l-emerald-500',
    self_modification: 'border-l-violet-500',
    intervention: 'border-l-red-500',
};

const labels = {
    assessment_review: ['Confirm & start improving', 'Re-assess with my note'],
    backlog_selection: ['Build selected', 'Re-survey with my note'],
    architecture_approval: ['Approve architecture', 'Reject & regenerate'],
    cost_override: ['Raise limit & continue', 'Keep paused'],
    deployment: ['Kick off deployment', 'Send back'],
    self_modification: ['Stage this change', 'Reject'],
    intervention: ['Retry / send back', 'Skip / pause'],
};

const approveLabel = computed(() => (isBacklog ? (selectedCount.value ? `Build ${selectedCount.value} selected` : 'Build nothing — close the cycle') : labels[props.gate.type][0]));

function decide(decision) {
    busy.value = true;
    router.post(
        `/approvals/${props.gate.id}/decide`,
        {
            decision,
            note: note.value || null,
            new_budget: props.gate.type === 'cost_override' ? newBudget.value || null : null,
            selected_ids: isBacklog ? [...selected.value] : null,
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

        <!-- backlog: tick what this cycle builds -->
        <ul v-if="isBacklog && gate.status === 'pending'" class="mt-4 divide-y divide-stone-100 rounded-md border border-stone-200">
            <li v-for="p in proposals" :key="p.id" class="px-4 py-3" :class="selected.has(p.id) ? 'bg-fuchsia-50/50' : ''">
                <div class="flex items-start gap-3">
                    <input type="checkbox" class="mt-1 h-4 w-4 accent-fuchsia-600" :checked="selected.has(p.id)" @change="toggle(p.id)" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-stone-900">{{ p.title }}</span>
                            <span class="rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase" :class="categoryStyle[p.category] ?? 'bg-stone-200'">{{ p.category }}</span>
                            <span v-if="p.source !== 'survey'" class="rounded bg-stone-100 px-1.5 py-0.5 text-[10px] uppercase text-stone-500">{{ p.source }}</span>
                            <span v-if="p.status === 'deferred'" class="rounded bg-stone-100 px-1.5 py-0.5 text-[10px] uppercase text-stone-500">carried over</span>
                        </div>
                        <p class="mt-0.5 text-sm text-stone-600">{{ p.summary }}</p>
                        <button class="mt-1 text-xs font-medium text-amber-700 hover:underline" @click="open = open === p.id ? null : p.id">{{ open === p.id ? 'Hide' : 'Why & acceptance criteria' }}</button>
                        <div v-if="open === p.id" class="mt-2 text-sm text-stone-700">
                            <p v-if="p.rationale">{{ p.rationale }}</p>
                            <ul v-if="p.acceptance_criteria.length" class="mt-1 list-inside list-disc text-stone-600"><li v-for="c in p.acceptance_criteria" :key="c">{{ c }}</li></ul>
                        </div>
                    </div>
                    <div class="shrink-0 text-right text-xs text-stone-500">
                        <div title="value for effort"><b class="text-sm text-stone-800">{{ p.score.toFixed(1) }}</b> score</div>
                        <div>impact {{ p.impact }} · effort {{ p.effort }}</div>
                    </div>
                </div>
            </li>
        </ul>

        <template v-if="gate.detail">
            <button class="mt-3 text-xs font-medium text-amber-700 hover:underline" @click="expanded = !expanded">{{ expanded ? 'Hide' : 'Show' }} details</button>
            <pre v-if="expanded" class="code mt-2 max-h-96 whitespace-pre-wrap">{{ gate.detail }}</pre>
        </template>

        <template v-if="gate.status === 'pending'">
            <div class="mt-4 grid gap-3" :class="gate.type === 'cost_override' ? 'sm:grid-cols-[1fr_160px]' : ''">
                <div>
                    <label class="label">{{ isBacklog ? 'Note (focus for a re-survey, or context for what you chose)' : 'Note (used as feedback when rejecting)' }}</label>
                    <input v-model="note" class="input" placeholder="Optional" />
                </div>
                <div v-if="gate.type === 'cost_override'">
                    <label class="label">New limit (USD)</label>
                    <input v-model="newBudget" type="number" step="0.01" min="0" class="input" />
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <button class="btn-success" :disabled="busy" @click="decide('approve')">{{ approveLabel }}</button>
                <button class="btn-secondary" :disabled="busy" @click="decide('reject')">{{ labels[gate.type][1] }}</button>
            </div>
        </template>
        <p v-else-if="gate.decision_note" class="mt-3 text-sm italic text-stone-500">“{{ gate.decision_note }}”<span v-if="gate.decided_at"> — {{ gate.decided_at }}</span></p>
    </div>
</template>
