<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import PipelineStepper from '../../Components/PipelineStepper.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

defineProps({ projects: Array });

const mode = (p) => (p.lifecycle === 'improve' ? `Continuous improvement · cycle ${p.cycle}` : p.status === 'idle' ? 'Released · ready for improvement cycle 1' : 'New build');

function remove(p) {
    if (confirm(`Delete "${p.name}" and all of its history?`)) router.delete(`/projects/${p.id}`);
}
</script>

<template>
    <Head title="Projects" />
    <PageHeader title="Projects" subtitle="Each project moves through the full SDLC with TDD enforced.">
        <Link href="/projects/create" class="btn-primary">+ New project</Link>
    </PageHeader>

    <div v-if="!projects.length" class="card p-12 text-center text-stone-500">No projects yet.</div>

    <div class="grid gap-4">
        <div v-for="p in projects" :key="p.id" class="card p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <Link :href="`/projects/${p.id}`" class="text-lg font-semibold text-stone-900 hover:text-amber-700">{{ p.name }}</Link>
                    <p class="mt-0.5 max-w-3xl truncate text-sm text-stone-500">{{ p.description }}</p>
                    <div class="mt-1 text-xs text-stone-500"><b class="font-semibold text-stone-700">{{ mode(p) }}</b> · {{ p.language }}<span v-if="p.framework"> · {{ p.framework }}</span> · updated {{ p.updated_at }}</div>
                </div>
                <div class="flex items-center gap-4">
                    <div class="text-right text-xs text-stone-500">${{ p.spent_usd.toFixed(2) }} / ${{ p.budget_limit_usd.toFixed(2) }}</div>
                    <StatusBadge :status="p.status" />
                    <button class="text-xs text-stone-400 hover:text-red-600" @click="remove(p)">Delete</button>
                </div>
            </div>
            <div class="mt-4"><PipelineStepper :stages="p.stages" :current="p.current_stage" :project-status="p.status" /></div>
        </div>
    </div>
</template>
