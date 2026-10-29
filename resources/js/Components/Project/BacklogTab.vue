<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import StatusBadge from '../StatusBadge.vue';

const props = defineProps({ project: Object, proposals: Array, cycles: Array });

const filter = ref('open');
const filters = {
    open: (p) => ['proposed', 'deferred'].includes(p.status),
    building: (p) => ['approved', 'in_progress'].includes(p.status),
    done: (p) => p.status === 'done',
    other: (p) => ['failed', 'rejected'].includes(p.status),
    all: () => true,
};
const counts = computed(() => Object.fromEntries(Object.entries(filters).map(([k, fn]) => [k, props.proposals.filter(fn).length])));
const shown = computed(() => props.proposals.filter(filters[filter.value]));

const categories = ['feature', 'enhancement', 'bugfix', 'tests', 'refactor', 'performance', 'security', 'docs', 'devex', 'dependency'];
const idea = useForm({ title: '', category: 'feature', summary: '', impact: 3, effort: 3 });
const addIdea = () => idea.post(`/projects/${props.project.id}/ideas`, { preserveScroll: true, onSuccess: () => idea.reset() });
const act = (p, action) => router.patch(`/proposals/${p.id}`, { action }, { preserveScroll: true });

const open = ref(null);
const cycleOpen = ref(null);
</script>

<template>
    <div class="space-y-6">
        <!-- cycle history -->
        <section v-if="cycles.length" class="card">
            <header class="border-b border-stone-200 px-5 py-3"><h3 class="font-semibold">Improvement cycles</h3></header>
            <div v-for="c in cycles" :key="c.cycle" class="border-b border-stone-100 px-5 py-3 last:border-0">
                <button class="flex w-full items-center justify-between text-left" @click="cycleOpen = cycleOpen === c.cycle ? null : c.cycle">
                    <span class="font-medium">Cycle {{ c.cycle }} <span class="ml-2 text-sm font-normal text-stone-500">{{ c.shipped.length ? c.shipped.join(' · ') : 'nothing shipped' }}</span></span>
                    <StatusBadge :status="c.status" />
                </button>
                <pre v-if="cycleOpen === c.cycle && c.retrospective" class="mt-3 whitespace-pre-wrap font-sans text-sm text-stone-700">{{ c.retrospective }}</pre>
            </div>
        </section>

        <!-- backlog -->
        <section class="card">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-5 py-3">
                <h3 class="font-semibold">Backlog</h3>
                <div class="flex gap-1 text-xs">
                    <button v-for="(n, key) in counts" :key="key" class="rounded-full px-3 py-1 font-medium capitalize" :class="filter === key ? 'bg-stone-900 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200'" @click="filter = key">{{ key }} {{ n }}</button>
                </div>
            </header>

            <div v-if="!shown.length" class="p-8 text-center text-sm text-stone-500">Nothing here. Start an improvement cycle to have the factory survey the code, or add your own idea below.</div>

            <ul class="divide-y divide-stone-100">
                <li v-for="p in shown" :key="p.id" class="px-5 py-3">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <button class="text-left font-medium text-stone-900 hover:text-amber-700" @click="open = open === p.id ? null : p.id">{{ p.title }}</button>
                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-xs text-stone-500">
                                <span class="rounded bg-stone-100 px-1.5 py-0.5 font-semibold uppercase">{{ p.category }}</span>
                                <span>impact {{ p.impact }} · effort {{ p.effort }} · score {{ p.score.toFixed(1) }}</span>
                                <span>{{ p.source }} · cycle {{ p.cycle }}<template v-if="p.built_in_cycle"> · shipped in cycle {{ p.built_in_cycle }}</template></span>
                            </div>
                            <div v-if="open === p.id" class="mt-2 text-sm text-stone-700">
                                <p>{{ p.summary }}</p>
                                <p v-if="p.rationale" class="mt-1 italic text-stone-500">{{ p.rationale }}</p>
                                <ul v-if="p.acceptance_criteria.length" class="mt-1 list-inside list-disc text-stone-600"><li v-for="c in p.acceptance_criteria" :key="c">{{ c }}</li></ul>
                                <p v-if="p.outcome" class="mt-1 text-xs text-stone-500">Outcome: {{ p.outcome }}</p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <StatusBadge :status="p.status" />
                            <template v-if="['proposed', 'deferred'].includes(p.status)">
                                <button class="text-xs text-stone-400 hover:text-red-600" title="Never propose this again" @click="act(p, 'dismiss')">Dismiss</button>
                            </template>
                            <button v-if="['rejected', 'failed'].includes(p.status)" class="text-xs font-medium text-amber-700 hover:underline" @click="act(p, 'restore')">Restore</button>
                        </div>
                    </div>
                </li>
            </ul>
        </section>

        <!-- add idea -->
        <form class="card max-w-3xl space-y-3 p-5" @submit.prevent="addIdea">
            <h3 class="font-semibold">Add your own idea</h3>
            <p class="text-xs text-stone-500">It joins the backlog and competes with the AI’s proposals at the next Backlog Review.</p>
            <div class="grid gap-3 sm:grid-cols-[1fr_160px]">
                <div><label class="label">Title</label><input v-model="idea.title" class="input" /><p v-if="idea.errors.title" class="mt-1 text-xs text-red-600">{{ idea.errors.title }}</p></div>
                <div><label class="label">Category</label><select v-model="idea.category" class="input"><option v-for="c in categories" :key="c" :value="c">{{ c }}</option></select></div>
            </div>
            <div><label class="label">What and why</label><textarea v-model="idea.summary" rows="3" class="input"></textarea></div>
            <div class="flex items-end gap-4">
                <div><label class="label">Impact (1-5)</label><input v-model="idea.impact" type="number" min="1" max="5" class="input w-24" /></div>
                <div><label class="label">Effort (1-5)</label><input v-model="idea.effort" type="number" min="1" max="5" class="input w-24" /></div>
                <button class="btn-primary" :disabled="idea.processing">Add to backlog</button>
            </div>
        </form>
    </div>
</template>
