<script setup>
import { Head, Link, usePoll } from '@inertiajs/vue3';
import PageHeader from '../Components/PageHeader.vue';
import PipelineStepper from '../Components/PipelineStepper.vue';
import StatusBadge from '../Components/StatusBadge.vue';

defineProps({ stats: Object, stages: Array, projects: Array, gates: Array, activity: Array });

usePoll(5000);

const tiles = (s) => [
    { label: 'Projects', value: s.projects },
    { label: 'Running pipelines', value: s.running, accent: s.running > 0 },
    { label: 'Awaiting you', value: s.awaiting, warn: s.awaiting > 0 },
    { label: 'AI spend', value: `$${s.spend.toFixed(2)}`, sub: `${s.runs} agent runs` },
];
</script>

<template>
    <Head title="Dashboard" />
    <PageHeader title="Factory floor" subtitle="Live view of every pipeline, decision and agent call.">
        <Link href="/projects/create" class="btn-primary">+ New project</Link>
    </PageHeader>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div v-for="t in tiles(stats)" :key="t.label" class="card p-5" :class="t.warn ? 'border-amber-400 bg-amber-50' : ''">
            <div class="text-xs font-semibold uppercase tracking-wide text-stone-500">{{ t.label }}</div>
            <div class="mt-1 text-3xl font-bold" :class="t.accent ? 'text-sky-600' : t.warn ? 'text-amber-700' : 'text-stone-900'">{{ t.value }}</div>
            <div v-if="t.sub" class="text-xs text-stone-500">{{ t.sub }}</div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <section class="card xl:col-span-2">
            <header class="flex items-center justify-between border-b border-stone-200 px-5 py-3">
                <h2 class="font-semibold">Pipelines</h2>
                <Link href="/projects" class="text-xs font-medium text-amber-700 hover:underline">All projects →</Link>
            </header>
            <div v-if="!projects.length" class="p-8 text-center text-sm text-stone-500">No projects yet. Create one to start the factory.</div>
            <ul class="divide-y divide-stone-100">
                <li v-for="p in projects" :key="p.id" class="px-5 py-4">
                    <div class="mb-3 flex items-center justify-between">
                        <Link :href="`/projects/${p.id}`" class="font-medium text-stone-900 hover:text-amber-700">{{ p.name }}</Link>
                        <div class="flex items-center gap-3 text-xs text-stone-500">
                            <span>{{ p.language }}</span><span>${{ p.spent_usd.toFixed(2) }}</span><StatusBadge :status="p.status" />
                        </div>
                    </div>
                    <PipelineStepper :stages="stages" :current="p.current_stage" :project-status="p.status" compact />
                </li>
            </ul>
        </section>

        <div class="space-y-6">
            <section class="card">
                <header class="flex items-center justify-between border-b border-stone-200 px-5 py-3">
                    <h2 class="font-semibold">Needs a decision</h2>
                    <Link href="/approvals" class="text-xs font-medium text-amber-700 hover:underline">Open →</Link>
                </header>
                <div v-if="!gates.length" class="p-5 text-sm text-stone-500">Nothing waiting. 🎉</div>
                <ul class="divide-y divide-stone-100">
                    <li v-for="g in gates" :key="g.id" class="px-5 py-3 text-sm">
                        <Link href="/approvals" class="font-medium text-stone-900 hover:text-amber-700">{{ g.title }}</Link>
                        <div class="text-xs text-stone-500">{{ g.project ?? 'Factory' }} · {{ g.created_at }}</div>
                    </li>
                </ul>
            </section>

            <section class="card">
                <header class="border-b border-stone-200 px-5 py-3"><h2 class="font-semibold">Agent activity</h2></header>
                <div v-if="!activity.length" class="p-5 text-sm text-stone-500">No agent calls yet.</div>
                <ul class="divide-y divide-stone-100">
                    <li v-for="r in activity" :key="r.id" class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <div class="min-w-0">
                            <Link :href="`/runs/${r.id}`" class="block truncate font-medium text-stone-800 hover:text-amber-700">{{ r.step.replaceAll('_', ' ') }}</Link>
                            <div class="truncate text-xs text-stone-500">{{ r.project ?? '—' }} · {{ r.model }} · {{ r.at }}</div>
                        </div>
                        <div class="text-right text-xs"><StatusBadge :status="r.status" /><div class="mt-0.5 text-stone-500">${{ r.cost.toFixed(4) }}</div></div>
                    </li>
                </ul>
            </section>
        </div>
    </div>
</template>
