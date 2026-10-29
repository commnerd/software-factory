<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({
    skill: Object,
    versions: Array,
    steps: Array,
    models: Array,
    snippets: { type: Array, default: () => [] },
});

const isNew = !props.skill;
const current = props.skill?.current;

const form = useForm({
    slug: '',
    name: props.skill?.name ?? '',
    description: props.skill?.description ?? '',
    kind: props.skill?.kind ?? 'agent',
    step: props.skill?.step ?? props.steps[0]?.value,
    system_prompt: current?.system_prompt ?? '',
    user_template: current?.user_template ?? '',
    output_format: current?.output_format ?? 'text',
    change_note: '',
});

const detected = computed(() => {
    const found = new Set();
    for (const text of [form.system_prompt, form.user_template]) {
        for (const m of (text ?? '').matchAll(/\{\{\s*([\w.\-]+)\s*\}\}/g)) found.add(m[1]);
    }
    return [...found];
});

function save() {
    if (isNew) form.post('/skills');
    else form.put(`/skills/${props.skill.id}`, { preserveScroll: true, onSuccess: () => (form.change_note = '') });
}

// ---- playground ------------------------------------------------------------
const tab = ref('playground');
const page = usePage();
const result = computed(() => page.props.flash?.skill_test);

const sample = ref(JSON.stringify(Object.fromEntries(detected.value.map((v) => [v.split('.')[0], v.includes('.') ? {} : `<${v}>`])), null, 2));
const modelId = ref(props.models[0]?.id ?? '');
const testing = ref(false);

function runTest(dry) {
    testing.value = true;
    router.post(
        `/skills/${props.skill.id}/test`,
        {
            system_prompt: form.system_prompt,
            user_template: form.user_template,
            output_format: form.output_format,
            model_id: modelId.value,
            variables: sample.value,
            dry_run: dry,
        },
        { preserveScroll: true, onFinish: () => (testing.value = false) },
    );
}

function loadVersion(v) {
    form.system_prompt = v.system_prompt ?? '';
    form.user_template = v.user_template;
    form.output_format = v.output_format;
    form.change_note = `Restored from v${v.version}`;
    tab.value = 'playground';
}

const activate = (v) => router.post(`/skills/${props.skill.id}/versions/${v.id}/activate`, {}, { preserveScroll: true });
const dirty = computed(() => !isNew && (form.system_prompt !== (current?.system_prompt ?? '') || form.user_template !== current?.user_template || form.output_format !== current?.output_format));
</script>

<template>
    <Head :title="isNew ? 'New skill' : skill.name" />
    <PageHeader :title="isNew ? 'New skill' : skill.name" :subtitle="isNew ? 'Define an agent behaviour or a reusable snippet.' : `${skill.slug} · live version v${current?.version}`">
        <Link href="/skills" class="btn-secondary">← All skills</Link>
    </PageHeader>

    <div class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
        <!-- editor -->
        <form class="card space-y-4 p-6" @submit.prevent="save">
            <div class="grid gap-4 sm:grid-cols-2">
                <div v-if="isNew">
                    <label class="label">Slug</label>
                    <input v-model="form.slug" class="input font-mono" placeholder="my-skill" />
                    <p v-if="form.errors.slug" class="mt-1 text-xs text-red-600">{{ form.errors.slug }}</p>
                </div>
                <div :class="isNew ? '' : 'sm:col-span-2'">
                    <label class="label">Name</label>
                    <input v-model="form.name" class="input" />
                </div>
                <template v-if="isNew">
                    <div>
                        <label class="label">Kind</label>
                        <select v-model="form.kind" class="input"><option value="agent">Agent (bound to a step)</option><option value="snippet">Snippet (include in others)</option></select>
                    </div>
                    <div v-if="form.kind === 'agent'">
                        <label class="label">SDLC step</label>
                        <select v-model="form.step" class="input"><option v-for="s in steps" :key="s.value" :value="s.value">{{ s.label }}</option></select>
                        <p v-if="form.errors.step" class="mt-1 text-xs text-red-600">{{ form.errors.step }}</p>
                    </div>
                </template>
                <div class="sm:col-span-2">
                    <label class="label">Description</label>
                    <input v-model="form.description" class="input" />
                </div>
            </div>

            <div>
                <label class="label">System instructions</label>
                <textarea v-model="form.system_prompt" rows="6" class="input font-mono text-xs" placeholder="Who the agent is, rules it must follow…  {{> house-rules}} includes a shared snippet"></textarea>
            </div>

            <div>
                <label class="label">Prompt template</label>
                <textarea v-model="form.user_template" rows="16" class="input font-mono text-xs"></textarea>
                <p v-if="form.errors.user_template" class="mt-1 text-xs text-red-600">{{ form.errors.user_template }}</p>
            </div>

            <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
                <div>
                    <label class="label">Expected output</label>
                    <select v-model="form.output_format" class="input w-40"><option value="text">Text / Markdown</option><option value="json">JSON</option></select>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="label">Variables detected</div>
                    <div class="flex flex-wrap gap-1">
                        <code v-for="v in detected" :key="v" class="rounded bg-amber-50 px-1.5 py-0.5 text-[11px] text-amber-800">{{ v }}</code>
                        <span v-if="!detected.length" class="text-xs text-stone-400">none</span>
                    </div>
                </div>
            </div>

            <div v-if="!isNew" class="border-t border-stone-100 pt-4">
                <label class="label">Change note</label>
                <input v-model="form.change_note" class="input" placeholder="What changed and why? (optional)" />
            </div>

            <div class="flex items-center gap-3">
                <button class="btn-primary" :disabled="form.processing">{{ isNew ? 'Create skill' : 'Publish new version' }}</button>
                <span v-if="dirty" class="text-xs text-amber-700">Unpublished edits — saving creates v{{ (versions[0]?.version ?? 0) + 1 }} and makes it live.</span>
            </div>
        </form>

        <!-- playground + history -->
        <div v-if="!isNew" class="space-y-4">
            <div class="flex gap-1 border-b border-stone-200">
                <button v-for="t in [['playground', 'Playground'], ['versions', `Versions (${versions.length})`]]" :key="t[0]" class="-mb-px border-b-2 px-4 py-2 text-sm font-medium" :class="tab === t[0] ? 'border-amber-600 text-amber-700' : 'border-transparent text-stone-500'" @click="tab = t[0]">{{ t[1] }}</button>
            </div>

            <div v-if="tab === 'playground'" class="card space-y-4 p-5">
                <p class="text-xs text-stone-500">Tests the <b>editor contents</b> (even unpublished) against any model. Playground runs are logged but don’t count toward project budgets.</p>
                <div>
                    <label class="label">Model</label>
                    <select v-model="modelId" class="input"><option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }} ({{ m.provider }} · {{ m.tier }})</option></select>
                </div>
                <div>
                    <label class="label">Sample variables (JSON)</label>
                    <textarea v-model="sample" rows="7" class="input font-mono text-xs"></textarea>
                </div>
                <div class="flex gap-2">
                    <button class="btn-primary" :disabled="testing" @click="runTest(false)">{{ testing ? 'Running…' : 'Run on model' }}</button>
                    <button class="btn-secondary" :disabled="testing" @click="runTest(true)">Compile only</button>
                </div>

                <div v-if="result" class="space-y-3 border-t border-stone-100 pt-4">
                    <p v-if="result.missing?.length" class="rounded bg-amber-50 px-3 py-2 text-xs text-amber-800">Missing variables: <code v-for="m in result.missing" :key="m" class="mr-1">{{ m }}</code></p>
                    <p v-if="result.error" class="rounded bg-red-50 px-3 py-2 text-xs text-red-700">{{ result.error }}</p>
                    <div v-if="result.response !== undefined">
                        <div class="mb-1 flex items-center justify-between text-xs text-stone-500">
                            <span class="label !mb-0">Response</span>
                            <span>{{ result.tokens }} tokens · ${{ Number(result.cost).toFixed(5) }} · {{ result.duration_ms }}ms<span v-if="result.json_valid !== null"> · JSON {{ result.json_valid ? 'valid ✓' : 'INVALID ✕' }}</span></span>
                        </div>
                        <pre class="code max-h-72 whitespace-pre-wrap">{{ result.response }}</pre>
                    </div>
                    <details v-if="result.thoughts"><summary class="cursor-pointer text-xs font-semibold text-stone-500">Agent thoughts</summary><pre class="code mt-1 max-h-48 whitespace-pre-wrap">{{ result.thoughts }}</pre></details>
                    <details><summary class="cursor-pointer text-xs font-semibold text-stone-500">Compiled prompt</summary>
                        <div v-if="result.system" class="label mt-2">System</div><pre v-if="result.system" class="code max-h-48 whitespace-pre-wrap">{{ result.system }}</pre>
                        <div class="label mt-2">User</div><pre class="code max-h-64 whitespace-pre-wrap">{{ result.prompt }}</pre>
                    </details>
                </div>
            </div>

            <div v-else class="card divide-y divide-stone-100">
                <div v-for="v in versions" :key="v.id" class="px-5 py-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-mono text-sm font-semibold">v{{ v.version }}</span>
                            <span v-if="v.id === skill.current_version_id" class="ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800">live</span>
                            <div class="text-xs text-stone-500">{{ v.created_at }} · {{ v.created_by ?? 'unknown' }}<span v-if="v.change_note"> · {{ v.change_note }}</span></div>
                        </div>
                        <div class="flex gap-3 text-xs font-medium">
                            <button class="text-stone-600 hover:text-stone-900" @click="loadVersion(v)">Load in editor</button>
                            <button v-if="v.id !== skill.current_version_id" class="text-amber-700 hover:underline" @click="activate(v)">Make live</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
