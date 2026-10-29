<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, default: '' } });

const styles = {
    // pipeline / project
    draft: 'bg-stone-200 text-stone-700',
    pending: 'bg-stone-200 text-stone-700',
    running: 'bg-sky-100 text-sky-800',
    in_progress: 'bg-sky-100 text-sky-800',
    implementing: 'bg-sky-100 text-sky-800',
    testing: 'bg-sky-100 text-sky-800',
    awaiting_approval: 'bg-amber-100 text-amber-800',
    paused: 'bg-orange-100 text-orange-800',
    completed: 'bg-emerald-100 text-emerald-800',
    done: 'bg-emerald-100 text-emerald-800',
    passed: 'bg-emerald-100 text-emerald-800',
    approved: 'bg-emerald-100 text-emerald-800',
    staged: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
    error: 'bg-red-100 text-red-800',
    blocked: 'bg-red-100 text-red-800',
    rejected: 'bg-red-100 text-red-800',
    // tdd
    red: 'bg-red-100 text-red-800',
    green: 'bg-emerald-100 text-emerald-800',
    refactor: 'bg-violet-100 text-violet-800',
    // misc
    proposed: 'bg-stone-200 text-stone-700',
    tested: 'bg-emerald-100 text-emerald-800',
};

const cls = computed(() => styles[props.status] ?? 'bg-stone-200 text-stone-700');
const label = computed(() => props.status.replaceAll('_', ' '));
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="cls">
        <span v-if="status === 'running'" class="mr-1 h-1.5 w-1.5 animate-pulse rounded-full bg-sky-600"></span>
        {{ label }}
    </span>
</template>
