<script setup>
import { computed, ref } from 'vue';

const props = defineProps({ files: Array });
const selectedId = ref(null);
const selected = computed(() => props.files.find((f) => f.id === selectedId.value) ?? props.files[0]);

const badge = { test: 'bg-red-100 text-red-700', source: 'bg-emerald-100 text-emerald-700', workflow: 'bg-violet-100 text-violet-700', doc: 'bg-stone-200 text-stone-700' };
</script>

<template>
    <div v-if="!files.length" class="card p-10 text-center text-sm text-stone-500">Generated files appear here (tests first, then implementation).</div>

    <div v-else class="grid gap-4 lg:grid-cols-[280px_1fr]">
        <ul class="card max-h-[600px] divide-y divide-stone-100 overflow-auto text-sm">
            <li v-for="f in files" :key="f.id">
                <button class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-stone-50" :class="selected?.id === f.id ? 'bg-amber-50' : ''" @click="selectedId = f.id">
                    <span class="truncate font-mono text-xs">{{ f.path }}</span>
                    <span class="rounded px-1.5 text-[10px] font-semibold" :class="badge[f.kind]">{{ f.kind }}</span>
                </button>
            </li>
        </ul>
        <div v-if="selected" class="card min-w-0 p-4">
            <div class="mb-2 flex items-center justify-between text-xs text-stone-500">
                <span class="font-mono text-stone-700">{{ selected.path }}</span><span>revision {{ selected.revision }}</span>
            </div>
            <pre class="code max-h-[560px]">{{ selected.content }}</pre>
        </div>
    </div>
</template>
