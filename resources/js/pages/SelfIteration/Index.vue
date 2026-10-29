<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm, usePoll } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

const props = defineProps({ proposals: Array });

usePoll(4000);

const form = useForm({ focus: '' });
const analyze = () => form.post('/self/analyze', { preserveScroll: true, onSuccess: () => form.reset() });
const run = (p) => router.post(`/self/proposals/${p.id}/process`, {}, { preserveScroll: true });
const dismiss = (p) => confirm('Dismiss this proposal?') && router.delete(`/self/proposals/${p.id}`, { preserveScroll: true });
const open = ref(null);
</script>

<template>
    <Head title="Self-Iteration" />
    <PageHeader title="Self-iteration" subtitle="The factory reads its own code, proposes improvements, implements them test-first in an isolated copy, runs the suite in a container, then waits for you." />

    <form class="card mb-6 flex flex-wrap items-end gap-3 p-5" @submit.prevent="analyze">
        <div class="min-w-64 flex-1">
            <label class="label">Focus (optional)</label>
            <input v-model="form.focus" class="input" placeholder="e.g. improve error handling in the pipeline, or add tests for the model router" />
        </div>
        <button class="btn-primary" :disabled="form.processing">{{ form.processing ? 'Analysing…' : 'Analyse my own codebase' }}</button>
    </form>

    <div v-if="!proposals.length" class="card p-10 text-center text-stone-500">No proposals yet.</div>

    <div class="space-y-4">
        <div v-for="p in proposals" :key="p.id" class="card p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h3 class="font-semibold">{{ p.title }}</h3>
                    <p class="mt-1 text-sm text-stone-600">{{ p.rationale }}</p>
                </div>
                <div class="flex flex-col items-end gap-1"><StatusBadge :status="p.status" /><span v-if="p.test_status" class="text-xs text-stone-500">tests: {{ p.test_status }}</span></div>
            </div>
            <p v-if="p.plan" class="mt-2 text-xs text-stone-500"><b>Plan:</b> {{ p.plan }}</p>
            <div v-if="p.files.length" class="mt-2 flex flex-wrap gap-1"><code v-for="f in p.files" :key="f" class="rounded bg-stone-100 px-1.5 py-0.5 text-[11px]">{{ f }}</code></div>
            <p v-if="p.last_error" class="mt-3 rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ p.last_error }}</p>

            <div v-if="p.status === 'staged'" class="mt-3 rounded bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                Staged at <code>{{ p.staged_path }}</code>. Apply it yourself: <code>sail artisan factory:apply-staged {{ p.id }}</code>
            </div>
            <p v-if="p.has_pending_gate" class="mt-3 text-sm text-amber-700">Awaiting your decision — <Link href="/approvals" class="font-medium underline">open approvals</Link>.</p>

            <div class="mt-4 flex items-center gap-3">
                <button v-if="['proposed', 'failed'].includes(p.status)" class="btn-primary" @click="run(p)">{{ p.status === 'failed' ? 'Retry' : 'Implement & test' }}</button>
                <button v-if="p.test_output" class="text-xs font-medium text-amber-700 hover:underline" @click="open = open === p.id ? null : p.id">{{ open === p.id ? 'Hide' : 'Show' }} test output</button>
                <button class="ml-auto text-xs text-stone-400 hover:text-red-600" @click="dismiss(p)">Dismiss</button>
            </div>
            <pre v-if="open === p.id" class="code mt-3 max-h-80">{{ p.test_output }}</pre>
        </div>
    </div>
</template>
