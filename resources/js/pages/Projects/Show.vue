<script setup>
import { computed, ref, watch } from 'vue';
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PipelineStepper from '../../Components/PipelineStepper.vue';
import StatusBadge from '../../Components/StatusBadge.vue';
import GateCard from '../../Components/GateCard.vue';
import TasksTab from '../../Components/Project/TasksTab.vue';
import TestsTab from '../../Components/Project/TestsTab.vue';
import AgentLogTab from '../../Components/Project/AgentLogTab.vue';
import FilesTab from '../../Components/Project/FilesTab.vue';
import ModelsTab from '../../Components/Project/ModelsTab.vue';
import SettingsTab from '../../Components/Project/SettingsTab.vue';

const props = defineProps({
    project: Object,
    stages: Array,
    sprints: Array,
    testSuites: Array,
    agentRuns: Array,
    gates: Array,
    files: Array,
    steps: Array,
    models: Array,
    globalRoutes: [Object, Array],
    projectRoutes: [Object, Array],
    effectiveRoutes: Object,
});

const tab = ref('pipeline');
const tabs = computed(() => [
    { key: 'pipeline', label: 'Pipeline' },
    { key: 'tasks', label: `Tasks (${props.sprints.reduce((n, s) => n + s.tasks.length, 0)})` },
    { key: 'tests', label: `Tests (${props.testSuites.length})` },
    { key: 'agents', label: `Agent log (${props.agentRuns.length})` },
    { key: 'files', label: `Files (${props.files.length})` },
    { key: 'models', label: 'Models' },
    { key: 'settings', label: 'Settings' },
]);

// Live view: poll while the pipeline is doing something.
const { start, stop } = usePoll(
    2500,
    { only: ['project', 'stages', 'sprints', 'testSuites', 'agentRuns', 'gates', 'files'] },
    { autoStart: false },
);
watch(() => props.project.status, (s) => (s === 'running' ? start() : stop()), { immediate: true });

const pendingGates = computed(() => props.gates.filter((g) => g.status === 'pending'));
const decidedGates = computed(() => props.gates.filter((g) => g.status !== 'pending'));
const canStart = computed(() => !['running', 'awaiting_approval', 'completed'].includes(props.project.status));
const budgetPct = computed(() => Math.min(100, props.project.budget_limit_usd ? (props.project.spent_usd / props.project.budget_limit_usd) * 100 : 0));
const openStage = ref(null);

const action = (path) => router.post(`/projects/${props.project.id}/${path}`, {}, { preserveScroll: true });
</script>

<template>
    <Head :title="project.name" />

    <PageHeader :title="project.name" :subtitle="`${project.language}${project.framework ? ' · ' + project.framework : ''}  ·  ${project.slug}`">
        <StatusBadge :status="project.status" />
        <button v-if="canStart" class="btn-primary" @click="action('start')">{{ project.status === 'draft' ? 'Start pipeline' : 'Resume' }}</button>
        <button v-if="project.status === 'running'" class="btn-secondary" @click="action('pause')">Pause</button>
    </PageHeader>

    <div v-if="project.last_error" class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
        <b>{{ project.status === 'failed' ? 'Pipeline failed:' : 'Note:' }}</b> {{ project.last_error }}
    </div>

    <div class="card mb-6 p-6">
        <PipelineStepper :stages="stages" :current="project.current_stage" :project-status="project.status" />
        <div class="mt-5 flex items-center gap-4 border-t border-stone-100 pt-4 text-xs text-stone-500">
            <span>Budget</span>
            <div class="h-2 w-64 overflow-hidden rounded-full bg-stone-200">
                <div class="h-full rounded-full" :class="budgetPct > 90 ? 'bg-red-500' : budgetPct > 70 ? 'bg-amber-500' : 'bg-emerald-500'" :style="{ width: budgetPct + '%' }"></div>
            </div>
            <span class="font-medium text-stone-700">${{ project.spent_usd.toFixed(4) }} / ${{ project.budget_limit_usd.toFixed(2) }}</span>
        </div>
    </div>

    <div v-if="pendingGates.length" class="mb-6 space-y-4">
        <GateCard v-for="g in pendingGates" :key="g.id" :gate="g" :show-project="false" />
    </div>

    <div class="mb-4 flex gap-1 border-b border-stone-200">
        <button
            v-for="t in tabs"
            :key="t.key"
            class="-mb-px border-b-2 px-4 py-2 text-sm font-medium transition"
            :class="tab === t.key ? 'border-amber-600 text-amber-700' : 'border-transparent text-stone-500 hover:text-stone-800'"
            @click="tab = t.key"
        >
            {{ t.label }}
        </button>
    </div>

    <!-- Pipeline: stage documents -->
    <div v-if="tab === 'pipeline'" class="space-y-3">
        <div v-if="project.brief" class="card p-5">
            <div class="label">Original brief</div>
            <p class="whitespace-pre-wrap text-sm text-stone-700">{{ project.brief }}</p>
        </div>
        <div v-for="s in stages" :key="s.value" class="card">
            <button class="flex w-full items-center justify-between px-5 py-3 text-left" @click="openStage = openStage === s.value ? null : s.value">
                <span class="font-semibold">{{ s.label }}</span>
                <span class="flex items-center gap-3 text-xs text-stone-500">
                    <span v-if="s.runs">{{ s.runs }} run{{ s.runs > 1 ? 's' : '' }}</span>
                    <StatusBadge :status="s.status" />
                </span>
            </button>
            <div v-if="openStage === s.value" class="border-t border-stone-100 p-5">
                <pre v-if="s.output" class="whitespace-pre-wrap font-sans text-sm text-stone-800">{{ s.output }}</pre>
                <p v-else class="text-sm text-stone-400">No output yet.</p>
                <p v-if="s.feedback" class="mt-3 text-xs italic text-stone-500">Reviewer feedback: {{ s.feedback }}</p>
            </div>
        </div>
        <div v-if="decidedGates.length" class="card p-5">
            <div class="label">Decision history</div>
            <ul class="divide-y divide-stone-100 text-sm">
                <li v-for="g in decidedGates" :key="g.id" class="flex items-center justify-between py-2">
                    <span>{{ g.title }} <span v-if="g.decision_note" class="italic text-stone-500">— “{{ g.decision_note }}”</span></span>
                    <StatusBadge :status="g.status" />
                </li>
            </ul>
        </div>
    </div>

    <TasksTab v-else-if="tab === 'tasks'" :sprints="sprints" />
    <TestsTab v-else-if="tab === 'tests'" :suites="testSuites" />
    <AgentLogTab v-else-if="tab === 'agents'" :runs="agentRuns" />
    <FilesTab v-else-if="tab === 'files'" :files="files" />
    <ModelsTab v-else-if="tab === 'models'" :project="project" :steps="steps" :models="models" :global-routes="globalRoutes" :project-routes="projectRoutes" :effective="effectiveRoutes" />
    <SettingsTab v-else-if="tab === 'settings'" :project="project" />
</template>
