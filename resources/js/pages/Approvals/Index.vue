<script setup>
import { Head, usePoll } from '@inertiajs/vue3';
import PageHeader from '../../Components/PageHeader.vue';
import GateCard from '../../Components/GateCard.vue';

defineProps({ pending: Array, history: Array });

usePoll(5000);
</script>

<template>
    <Head title="Approvals" />
    <PageHeader title="Decision gates" subtitle="Execution is paused on each of these until you decide." />

    <div v-if="!pending.length" class="card mb-8 p-10 text-center text-stone-500">Nothing is waiting on you.</div>
    <div class="mb-10 space-y-4"><GateCard v-for="g in pending" :key="g.id" :gate="g" /></div>

    <h2 v-if="history.length" class="mb-3 text-sm font-semibold uppercase tracking-wide text-stone-500">Recent decisions</h2>
    <div class="space-y-3"><GateCard v-for="g in history" :key="g.id" :gate="g" /></div>
</template>
