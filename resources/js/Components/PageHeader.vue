<script setup>
defineProps({ title: String, subtitle: String });
</script>

<template>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-stone-900">{{ title }}</h1>
            <p v-if="subtitle" class="mt-1 text-sm text-stone-500">{{ subtitle }}</p>
        </div>
        <div class="flex items-center gap-2"><slot /></div>
    </div>
</template>
