<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import StatusBadge from '../../Components/StatusBadge.vue';

defineProps({ run: Object });
</script>

<template>
    <Head :title="`Run #${run.id}`" />
    <PageHeader :title="`${run.step.replaceAll('_', ' ')} — run #${run.id}`" :subtitle="run.at">
        <StatusBadge :status="run.status" />
        <Link v-if="run.project" :href="`/projects/${run.project.id}`" class="btn-secondary">← {{ run.project.name }}</Link>
    </PageHeader>

    <div class="card mb-6 grid gap-4 p-5 text-sm sm:grid-cols-2 lg:grid-cols-5">
        <div><div class="label">Model</div>{{ run.model?.name }}<div class="text-xs text-stone-500">{{ run.model?.provider }} · {{ run.model?.model }}</div></div>
        <div><div class="label">Skill</div>{{ run.skill?.name }} <span class="text-xs text-stone-500">v{{ run.skill?.version }}</span></div>
        <div><div class="label">Tokens</div>{{ run.input_tokens.toLocaleString() }} in · {{ run.output_tokens.toLocaleString() }} out</div>
        <div><div class="label">Cost</div>${{ run.cost.toFixed(6) }}</div>
        <div><div class="label">Duration</div>{{ (run.duration_ms / 1000).toFixed(1) }}s</div>
    </div>

    <p v-if="run.error" class="mb-6 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ run.error }}</p>

    <div class="space-y-6">
        <section v-if="run.thoughts">
            <h2 class="label">Agent thoughts</h2>
            <pre class="code max-h-96 whitespace-pre-wrap border-l-4 border-amber-500">{{ run.thoughts }}</pre>
        </section>
        <section>
            <h2 class="label">Response</h2>
            <pre class="code max-h-[28rem] whitespace-pre-wrap">{{ run.response ?? '—' }}</pre>
        </section>
        <section>
            <h2 class="label">Compiled prompt (as sent)</h2>
            <div v-if="run.system_prompt" class="mb-2 text-xs font-semibold text-stone-400">SYSTEM</div>
            <pre v-if="run.system_prompt" class="code max-h-64 whitespace-pre-wrap">{{ run.system_prompt }}</pre>
            <div class="my-2 text-xs font-semibold text-stone-400">USER</div>
            <pre class="code max-h-[28rem] whitespace-pre-wrap">{{ run.prompt }}</pre>
        </section>
    </div>
</template>
