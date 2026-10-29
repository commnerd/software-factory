<script setup>
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';

const props = defineProps({ languages: Array, defaultBudget: Number });

const form = useForm({
    name: '',
    brief: '',
    language: props.languages[0]?.value ?? 'php',
    framework: '',
    repository_url: '',
    budget_limit_usd: props.defaultBudget,
    start: false,
});

const selected = computed(() => props.languages.find((l) => l.value === form.language));

const submit = (start) => form.transform((d) => ({ ...d, start })).post('/projects');
</script>

<template>
    <Head title="New project" />
    <PageHeader title="New project" subtitle="Describe what to build. The factory handles the rest — you approve the key decisions." />

    <form class="card max-w-3xl space-y-5 p-6" @submit.prevent="submit(false)">
        <div>
            <label class="label">Name</label>
            <input v-model="form.name" class="input" placeholder="Invoice reminder service" />
            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
        </div>

        <div>
            <label class="label">Brief — what should it do?</label>
            <textarea v-model="form.brief" rows="8" class="input" placeholder="Users upload invoices; the system emails reminders 3 days before and 1 day after the due date…"></textarea>
            <p v-if="form.errors.brief" class="mt-1 text-xs text-red-600">{{ form.errors.brief }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label">Language / stack</label>
                <select v-model="form.language" class="input">
                    <option v-for="l in languages" :key="l.value" :value="l.value">{{ l.label }}</option>
                </select>
                <p v-if="selected" class="mt-1 text-xs text-stone-500">Tests run in <code>{{ selected.image }}</code> with <code>{{ selected.test }}</code></p>
            </div>
            <div>
                <label class="label">Framework (optional)</label>
                <input v-model="form.framework" class="input" placeholder="Laravel, Express, FastAPI, Gin…" />
            </div>
            <div>
                <label class="label">GitHub repository (optional)</label>
                <input v-model="form.repository_url" class="input" placeholder="https://github.com/org/repo" />
                <p v-if="form.errors.repository_url" class="mt-1 text-xs text-red-600">{{ form.errors.repository_url }}</p>
            </div>
            <div>
                <label class="label">Budget limit (USD)</label>
                <input v-model="form.budget_limit_usd" type="number" step="0.01" min="0" class="input" />
                <p class="mt-1 text-xs text-stone-500">Reaching it pauses the pipeline behind a cost-override decision.</p>
            </div>
        </div>

        <div class="flex items-center gap-2 border-t border-stone-100 pt-5">
            <button type="button" class="btn-primary" :disabled="form.processing" @click="submit(true)">Create & start pipeline</button>
            <button type="submit" class="btn-secondary" :disabled="form.processing">Create only</button>
            <Link href="/projects" class="ml-auto text-sm text-stone-500 hover:underline">Cancel</Link>
        </div>
    </form>
</template>
