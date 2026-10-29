<script setup>
import { useForm } from '@inertiajs/vue3';

const props = defineProps({ project: Object });

const form = useForm({
    budget_limit_usd: props.project.budget_limit_usd,
    repository_url: props.project.repository_url ?? '',
    config: {
        image: props.project.config.image ?? '',
        install: props.project.config.install ?? '',
        test: props.project.config.test ?? '',
        test_dir: props.project.config.test_dir ?? '',
    },
});

const save = () => form.put(`/projects/${props.project.id}/settings`, { preserveScroll: true });
</script>

<template>
    <form class="card max-w-3xl space-y-5 p-6" @submit.prevent="save">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label">Budget limit (USD)</label>
                <input v-model="form.budget_limit_usd" type="number" step="0.01" min="0" class="input" />
            </div>
            <div>
                <label class="label">GitHub repository</label>
                <input v-model="form.repository_url" class="input" />
                <p v-if="form.errors.repository_url" class="mt-1 text-xs text-red-600">{{ form.errors.repository_url }}</p>
            </div>
        </div>

        <div>
            <div class="label">Container execution overrides</div>
            <p class="mb-3 text-xs text-stone-500">Leave blank to use the {{ project.language }} profile: image <code>{{ project.profile.image }}</code>, tests <code>{{ project.profile.test }}</code>.</p>
            <div class="grid gap-4 sm:grid-cols-2">
                <div><label class="label">Image</label><input v-model="form.config.image" class="input font-mono" :placeholder="project.profile.image" /></div>
                <div><label class="label">Test directory</label><input v-model="form.config.test_dir" class="input font-mono" :placeholder="project.profile.test_dir" /></div>
                <div><label class="label">Install command</label><input v-model="form.config.install" class="input font-mono" :placeholder="project.profile.install" /></div>
                <div><label class="label">Test command</label><input v-model="form.config.test" class="input font-mono" :placeholder="project.profile.test" /></div>
            </div>
        </div>

        <button class="btn-primary" :disabled="form.processing">Save settings</button>
    </form>
</template>
