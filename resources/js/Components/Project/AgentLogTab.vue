<script setup>
import { Link } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

defineProps({ runs: Array });
</script>

<template>
    <div v-if="!runs.length" class="card p-10 text-center text-sm text-stone-500">No agent calls yet.</div>

    <div class="card overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-left text-[11px] font-semibold uppercase tracking-wide text-stone-500">
                <tr><th class="px-4 py-2">Step</th><th class="px-4 py-2">Skill</th><th class="px-4 py-2">Model</th><th class="px-4 py-2 text-right">Tokens</th><th class="px-4 py-2 text-right">Cost</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <tr v-for="r in runs" :key="r.id" class="hover:bg-stone-50">
                    <td class="px-4 py-2">
                        <div class="font-medium text-stone-800">{{ r.step.replaceAll('_', ' ') }}</div>
                        <div class="text-xs text-stone-500">{{ r.task ?? '' }} {{ r.at }}</div>
                    </td>
                    <td class="px-4 py-2 text-stone-600">{{ r.skill }} <span class="text-xs text-stone-400">v{{ r.skill_version }}</span></td>
                    <td class="px-4 py-2 text-stone-600">{{ r.model }}</td>
                    <td class="px-4 py-2 text-right tabular-nums text-stone-600">{{ r.tokens.toLocaleString() }}</td>
                    <td class="px-4 py-2 text-right tabular-nums text-stone-600">${{ r.cost.toFixed(4) }}</td>
                    <td class="px-4 py-2"><StatusBadge :status="r.status" /></td>
                    <td class="px-4 py-2 text-right"><Link :href="`/runs/${r.id}`" class="text-xs font-medium text-amber-700 hover:underline">{{ r.has_thoughts ? 'thoughts →' : 'inspect →' }}</Link></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
