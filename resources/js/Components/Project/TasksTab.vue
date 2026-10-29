<script setup>
import { ref } from 'vue';
import StatusBadge from '../StatusBadge.vue';

defineProps({ sprints: Array });
const open = ref(null);

const phases = ['red', 'green', 'refactor', 'done'];
</script>

<template>
    <div v-if="!sprints.length" class="card p-10 text-center text-sm text-stone-500">Tasks appear after the Task Breakdown stage.</div>

    <div class="space-y-6">
        <section v-for="s in sprints" :key="s.id">
            <div class="mb-2 flex items-baseline gap-3">
                <h3 class="font-semibold">{{ s.name }}</h3>
                <span class="text-sm text-stone-500">{{ s.goal }}</span>
            </div>
            <div class="card divide-y divide-stone-100">
                <div v-for="t in s.tasks" :key="t.id" class="px-5 py-3">
                    <button class="flex w-full items-center justify-between gap-4 text-left" @click="open = open === t.id ? null : t.id">
                        <span class="font-medium text-stone-900">
                            {{ t.title }}
                            <span v-if="t.type === 'characterization'" class="ml-2 rounded bg-teal-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-teal-800" title="Documents existing behaviour; tests must pass against current code">existing code</span>
                        </span>
                        <span class="flex items-center gap-4">
                            <!-- red → green → refactor → done -->
                            <span class="flex items-center gap-1" title="TDD phase">
                                <span
                                    v-for="p in phases"
                                    :key="p"
                                    class="h-2.5 w-7 rounded-full"
                                    :class="[
                                        t.tdd_phase === 'blocked' ? 'bg-red-200' :
                                        phases.indexOf(p) <= phases.indexOf(t.tdd_phase) ? (p === 'red' ? 'bg-red-500' : p === 'green' ? 'bg-emerald-500' : p === 'refactor' ? 'bg-violet-500' : 'bg-emerald-600') : 'bg-stone-200',
                                    ]"
                                ></span>
                            </span>
                            <span class="text-xs text-stone-500">{{ t.attempts }} attempt{{ t.attempts === 1 ? '' : 's' }}</span>
                            <StatusBadge :status="t.tdd_phase === 'pending' ? 'pending' : t.status" />
                        </span>
                    </button>
                    <div v-if="open === t.id" class="mt-3 text-sm text-stone-700">
                        <p>{{ t.description }}</p>
                        <ul v-if="t.acceptance_criteria.length" class="mt-2 list-inside list-disc text-stone-600">
                            <li v-for="c in t.acceptance_criteria" :key="c">{{ c }}</li>
                        </ul>
                        <p v-if="t.notes" class="mt-2 whitespace-pre-line text-xs italic text-stone-500">{{ t.notes }}</p>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
