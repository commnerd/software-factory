<script setup>
import { reactive, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    models: Array,
    steps: Array,
    routes: [Object, Array],
    providers: Array,
    tiers: Array,
    providerStatus: Object,
});

// ---- routing matrix --------------------------------------------------------
const routes = reactive({});
const fallbacks = reactive({});
props.steps.forEach((s) => {
    const r = Array.isArray(props.routes) ? null : props.routes[s.value];
    routes[s.value] = r?.model_configuration_id ?? '';
    fallbacks[s.value] = r?.fallback_model_configuration_id ?? '';
});
const savingRoutes = ref(false);
const saveRouting = () => router.put('/models/routing', { routes, fallbacks }, { preserveScroll: true, onStart: () => (savingRoutes.value = true), onFinish: () => (savingRoutes.value = false) });

const tierStyle = { cheap: 'bg-emerald-100 text-emerald-800', standard: 'bg-sky-100 text-sky-800', frontier: 'bg-violet-100 text-violet-800' };

// ---- add / edit ------------------------------------------------------------
const blank = () => ({ name: '', provider: props.providers[0], model: '', tier: 'standard', input_cost_per_mtok: 0, output_cost_per_mtok: 0, parameters: { temperature: 0.2, max_tokens: 8192, thinking_budget: '' }, is_active: true, is_default: false });
const form = useForm(blank());
const editing = ref(null);

function edit(m) {
    editing.value = m.id;
    Object.assign(form, { ...m, parameters: { temperature: '', max_tokens: '', thinking_budget: '', ...m.parameters } });
    form.clearErrors();
    window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
}
function reset() {
    editing.value = null;
    form.defaults(blank()).reset();
}
function submit() {
    const opts = { preserveScroll: true, onSuccess: reset };
    editing.value ? form.put(`/models/${editing.value}`, opts) : form.post('/models', opts);
}
const remove = (m) => confirm(`Remove ${m.name}? Routes pointing at it will be cleared.`) && router.delete(`/models/${m.id}`, { preserveScroll: true });
</script>

<template>
    <Head title="Models & Routing" />
    <PageHeader title="Models & Routing" subtitle="Register providers/models, then choose which one serves each lifecycle step — cheap models for scaffolding, frontier models for reasoning and code." />

    <div class="mb-6 flex flex-wrap gap-2 text-xs">
        <span v-for="(status, name) in providerStatus" :key="name" class="rounded-full border px-3 py-1" :class="status === 'ready' ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-amber-300 bg-amber-50 text-amber-800'">
            {{ name }}: {{ status }}
        </span>
        <span class="self-center text-stone-400">API keys come from .env (ANTHROPIC_API_KEY, OPENAI_API_KEY, OLLAMA_BASE_URL)</span>
    </div>

    <!-- model registry -->
    <div class="card mb-6 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-left text-[11px] font-semibold uppercase tracking-wide text-stone-500">
                <tr><th class="px-5 py-2">Model</th><th class="px-5 py-2">Provider / id</th><th class="px-5 py-2">Tier</th><th class="px-5 py-2 text-right">$ / Mtok in · out</th><th class="px-5 py-2">State</th><th class="px-5 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <tr v-for="m in models" :key="m.id" :class="m.is_active ? '' : 'opacity-50'">
                    <td class="px-5 py-2.5 font-medium">{{ m.name }} <span v-if="m.is_default" class="ml-1 rounded bg-amber-100 px-1.5 text-[10px] font-bold text-amber-800">DEFAULT</span></td>
                    <td class="px-5 py-2.5 text-stone-600"><span class="text-xs uppercase text-stone-400">{{ m.provider }}</span> <code class="text-xs">{{ m.model }}</code></td>
                    <td class="px-5 py-2.5"><span class="rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize" :class="tierStyle[m.tier]">{{ m.tier }}</span></td>
                    <td class="px-5 py-2.5 text-right tabular-nums text-stone-600">{{ m.input_cost_per_mtok }} · {{ m.output_cost_per_mtok }}</td>
                    <td class="px-5 py-2.5 text-xs text-stone-500">{{ m.is_active ? 'active' : 'inactive' }} · {{ m.in_use }} route{{ m.in_use === 1 ? '' : 's' }}</td>
                    <td class="space-x-3 px-5 py-2.5 text-right text-xs font-medium"><button class="text-amber-700 hover:underline" @click="edit(m)">Edit</button><button class="text-stone-400 hover:text-red-600" @click="remove(m)">Remove</button></td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- routing matrix -->
    <div class="card mb-6">
        <div class="border-b border-stone-200 px-5 py-3">
            <h3 class="font-semibold">Global routing — step → model</h3>
            <p class="text-xs text-stone-500">Projects can override any row on their own Models tab. Empty = fall back to a model of the step’s default tier, then the default model. The fallback is tried if the primary errors.</p>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-stone-50 text-left text-[11px] font-semibold uppercase tracking-wide text-stone-500">
                <tr><th class="px-5 py-2">SDLC step</th><th class="px-5 py-2">Primary model</th><th class="px-5 py-2">Fallback</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <tr v-for="s in steps" :key="s.value">
                    <td class="px-5 py-2 font-medium text-stone-800">{{ s.label }}</td>
                    <td class="px-5 py-2"><select v-model="routes[s.value]" class="input max-w-xs"><option value="">— auto (by tier) —</option><option v-for="m in models.filter((x) => x.is_active)" :key="m.id" :value="m.id">{{ m.name }} · {{ m.tier }}</option></select></td>
                    <td class="px-5 py-2"><select v-model="fallbacks[s.value]" class="input max-w-xs"><option value="">none</option><option v-for="m in models.filter((x) => x.is_active)" :key="m.id" :value="m.id">{{ m.name }}</option></select></td>
                </tr>
            </tbody>
        </table>
        <div class="border-t border-stone-200 px-5 py-3"><button class="btn-primary" :disabled="savingRoutes" @click="saveRouting">Save routing</button></div>
    </div>

    <!-- add / edit form -->
    <form class="card max-w-4xl space-y-4 p-6" @submit.prevent="submit">
        <h3 class="font-semibold">{{ editing ? 'Edit model' : 'Add model' }}</h3>
        <div class="grid gap-4 sm:grid-cols-4">
            <div class="sm:col-span-2"><label class="label">Display name</label><input v-model="form.name" class="input" /><p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p></div>
            <div><label class="label">Provider</label><select v-model="form.provider" class="input"><option v-for="p in providers" :key="p" :value="p">{{ p }}</option></select></div>
            <div><label class="label">Tier</label><select v-model="form.tier" class="input"><option v-for="t in tiers" :key="t" :value="t">{{ t }}</option></select></div>
            <div class="sm:col-span-2"><label class="label">Model identifier</label><input v-model="form.model" class="input font-mono" placeholder="claude-sonnet-5-5" /><p v-if="form.errors.model" class="mt-1 text-xs text-red-600">{{ form.errors.model }}</p></div>
            <div><label class="label">$ / Mtok input</label><input v-model="form.input_cost_per_mtok" type="number" step="0.0001" min="0" class="input" /></div>
            <div><label class="label">$ / Mtok output</label><input v-model="form.output_cost_per_mtok" type="number" step="0.0001" min="0" class="input" /></div>
            <div><label class="label">Temperature</label><input v-model="form.parameters.temperature" type="number" step="0.1" min="0" max="2" class="input" /></div>
            <div><label class="label">Max tokens</label><input v-model="form.parameters.max_tokens" type="number" min="1" class="input" /></div>
            <div><label class="label">Thinking budget (Anthropic)</label><input v-model="form.parameters.thinking_budget" type="number" min="0" class="input" placeholder="off" /></div>
            <div class="flex items-end gap-4 pb-2 text-sm">
                <label class="flex items-center gap-2"><input v-model="form.is_active" type="checkbox" /> Active</label>
                <label class="flex items-center gap-2"><input v-model="form.is_default" type="checkbox" /> Default</label>
            </div>
        </div>
        <div class="flex gap-2">
            <button class="btn-primary" :disabled="form.processing">{{ editing ? 'Save changes' : 'Add model' }}</button>
            <button v-if="editing" type="button" class="btn-secondary" @click="reset">Cancel</button>
        </div>
    </form>
</template>
