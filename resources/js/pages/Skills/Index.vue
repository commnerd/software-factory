<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({ skills: Array, steps: Array });

const stepLabel = (v) => props.steps.find((s) => s.value === v)?.label ?? v;
const agents = computed(() => props.skills.filter((s) => s.kind === 'agent'));
const snippets = computed(() => props.skills.filter((s) => s.kind === 'snippet'));
const toggle = (s) => router.patch(`/skills/${s.id}/toggle`, {}, { preserveScroll: true });
</script>

<template>
    <Head title="AI Skills" />
    <PageHeader title="AI Skills" subtitle="System instructions and prompt templates live in the database, versioned. Edits take effect on the next agent call.">
        <Link href="/skills/create" class="btn-primary">+ New skill</Link>
    </PageHeader>

    <div class="card mb-6 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-left text-[11px] font-semibold uppercase tracking-wide text-stone-500">
                <tr><th class="px-5 py-2">Skill</th><th class="px-5 py-2">Bound to step</th><th class="px-5 py-2">Live version</th><th class="px-5 py-2">Updated</th><th class="px-5 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <tr v-for="s in agents" :key="s.id" :class="s.is_active ? '' : 'opacity-50'">
                    <td class="px-5 py-3">
                        <Link :href="`/skills/${s.id}`" class="font-medium text-stone-900 hover:text-amber-700">{{ s.name }}</Link>
                        <div class="text-xs text-stone-500">{{ s.description }}</div>
                    </td>
                    <td class="px-5 py-3 text-stone-600">{{ stepLabel(s.step) }}</td>
                    <td class="px-5 py-3"><span class="rounded bg-stone-100 px-2 py-0.5 font-mono text-xs">v{{ s.version }}</span> <span class="text-xs text-stone-400">of {{ s.versions_count }}</span></td>
                    <td class="px-5 py-3 text-xs text-stone-500">{{ s.updated_at }}</td>
                    <td class="px-5 py-3 text-right">
                        <button class="text-xs text-stone-500 hover:text-stone-900" @click="toggle(s)">{{ s.is_active ? 'Disable' : 'Enable' }}</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-stone-500">Shared snippets</h2>
    <div class="card divide-y divide-stone-100">
        <div v-for="s in snippets" :key="s.id" class="flex items-center justify-between px-5 py-3 text-sm">
            <div>
                <Link :href="`/skills/${s.id}`" class="font-medium text-stone-900 hover:text-amber-700">{{ s.name }}</Link>
                <div class="text-xs text-stone-500">include with <code class="rounded bg-stone-100 px-1">&#123;&#123;> {{ s.slug }} &#125;&#125;</code> — {{ s.description }}</div>
            </div>
            <span class="rounded bg-stone-100 px-2 py-0.5 font-mono text-xs">v{{ s.version }}</span>
        </div>
        <div v-if="!snippets.length" class="px-5 py-4 text-sm text-stone-500">No snippets.</div>
    </div>
</template>
