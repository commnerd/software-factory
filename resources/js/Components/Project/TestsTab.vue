<script setup>
import { ref } from 'vue';
import StatusBadge from '../StatusBadge.vue';

defineProps({ suites: Array });
const open = ref(null);
</script>

<template>
    <div v-if="!suites.length" class="card p-10 text-center text-sm text-stone-500">No test runs yet.</div>

    <div class="card divide-y divide-stone-100">
        <div v-for="t in suites" :key="t.id" class="px-5 py-3">
            <button class="flex w-full items-center justify-between gap-4 text-left" @click="open = open === t.id ? null : t.id">
                <span class="flex items-center gap-3">
                    <StatusBadge v-if="['red', 'green', 'refactor'].includes(t.phase)" :status="t.phase" />
                    <span v-else class="rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold uppercase text-sky-800">{{ t.phase }}</span>
                    <span class="text-sm font-medium text-stone-800">{{ t.task ?? 'Full suite' }}</span>
                </span>
                <span class="flex items-center gap-4 text-xs text-stone-500">
                    <span>{{ t.passed }}/{{ t.total }} passed<span v-if="t.failed" class="text-red-600"> · {{ t.failed }} failed</span></span>
                    <span>{{ (t.duration_ms / 1000).toFixed(1) }}s</span>
                    <span>{{ t.at }}</span>
                    <StatusBadge :status="t.status" />
                </span>
            </button>
            <div v-if="open === t.id" class="mt-3">
                <div class="mb-2 text-xs text-stone-500">container <code>{{ t.image }}</code> · <code>{{ t.command }}</code></div>
                <pre class="code max-h-96">{{ t.output }}</pre>
            </div>
        </div>
    </div>
</template>
