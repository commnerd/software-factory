<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
    project: Object,
    steps: Array,
    models: Array,
    globalRoutes: [Object, Array],
    projectRoutes: [Object, Array],
    effective: Object,
});

const initial = {};
props.steps.forEach((s) => (initial[s.value] = (Array.isArray(props.projectRoutes) ? null : props.projectRoutes[s.value]) ?? ''));
const form = useForm({ routes: initial });

const save = () => form.put(`/projects/${props.project.id}/routes`, { preserveScroll: true });
const globalName = (step) => {
    const id = Array.isArray(props.globalRoutes) ? null : props.globalRoutes[step];
    return props.models.find((m) => m.id === id)?.name ?? 'default';
};
</script>

<template>
    <div class="card">
        <div class="border-b border-stone-200 px-5 py-3">
            <h3 class="font-semibold">Per-project model overrides</h3>
            <p class="text-xs text-stone-500">Pick a model for any step to override the global routing for this project only. Leave “inherit” to use the global mapping.</p>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-left text-[11px] font-semibold uppercase tracking-wide text-stone-500">
                <tr><th class="px-5 py-2">Step</th><th class="px-5 py-2">Override</th><th class="px-5 py-2">Effective model</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <tr v-for="s in steps" :key="s.value">
                    <td class="px-5 py-2 font-medium text-stone-800">{{ s.label }}</td>
                    <td class="px-5 py-2">
                        <select v-model="form.routes[s.value]" class="input max-w-xs">
                            <option value="">inherit ({{ globalName(s.value) }})</option>
                            <option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }} — {{ m.tier }}</option>
                        </select>
                    </td>
                    <td class="px-5 py-2 text-xs text-stone-500">
                        <template v-if="effective[s.value]">{{ effective[s.value].model }} <span class="rounded bg-stone-100 px-1.5 py-0.5 text-[10px] uppercase">{{ effective[s.value].source }}</span></template>
                        <span v-else class="text-red-600">no model available</span>
                    </td>
                </tr>
            </tbody>
        </table>
        <div class="border-t border-stone-200 px-5 py-3"><button class="btn-primary" :disabled="form.processing" @click="save">Save overrides</button></div>
    </div>
</template>
