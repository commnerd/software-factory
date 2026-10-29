<script setup>
/**
 * stages: [{ value, label, status? }]   current: value of the active stage
 * With `compact`, only the dots + a short label are drawn (dashboard rows).
 */
const props = defineProps({
    stages: { type: Array, required: true },
    current: { type: String, default: null },
    projectStatus: { type: String, default: '' },
    compact: Boolean,
});

function state(stage, index) {
    if (stage.status) {
        return stage.status;
    }
    if (props.projectStatus === 'idle') return 'completed'; // released; waiting for the next cycle
    const currentIndex = props.stages.findIndex((s) => s.value === props.current);
    if (index < currentIndex) return 'completed';
    if (index === currentIndex) return props.projectStatus === 'completed' ? 'completed' : 'running';
    return 'pending';
}

const dot = {
    completed: 'bg-emerald-500 border-emerald-500 text-white',
    running: 'bg-sky-500 border-sky-500 text-white animate-pulse',
    awaiting_approval: 'bg-amber-500 border-amber-500 text-white',
    failed: 'bg-red-500 border-red-500 text-white',
    pending: 'bg-white border-stone-300 text-stone-400',
};
</script>

<template>
    <ol class="flex items-start" :class="compact ? 'gap-1' : 'gap-0'">
        <li v-for="(stage, i) in stages" :key="stage.value" class="flex flex-1 items-start" :class="{ 'flex-none': i === stages.length - 1 && false }">
            <div class="flex flex-1 flex-col items-center text-center">
                <div class="flex w-full items-center">
                    <div class="h-0.5 flex-1" :class="i === 0 ? 'bg-transparent' : state(stages[i - 1], i - 1) === 'completed' ? 'bg-emerald-400' : 'bg-stone-300'"></div>
                    <div
                        class="flex shrink-0 items-center justify-center rounded-full border-2 font-bold"
                        :class="[dot[state(stage, i)] ?? dot.pending, compact ? 'h-4 w-4 text-[9px]' : 'h-8 w-8 text-xs']"
                    >
                        <template v-if="!compact">{{ state(stage, i) === 'completed' ? '✓' : state(stage, i) === 'awaiting_approval' ? '!' : state(stage, i) === 'failed' ? '✕' : i + 1 }}</template>
                    </div>
                    <div class="h-0.5 flex-1" :class="i === stages.length - 1 ? 'bg-transparent' : state(stage, i) === 'completed' ? 'bg-emerald-400' : 'bg-stone-300'"></div>
                </div>
                <div v-if="!compact" class="mt-2 px-1 text-xs font-medium" :class="state(stage, i) === 'pending' ? 'text-stone-400' : 'text-stone-800'">{{ stage.label }}</div>
            </div>
        </li>
    </ol>
</template>
