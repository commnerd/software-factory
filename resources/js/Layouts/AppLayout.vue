<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const pending = computed(() => page.props.pendingGates ?? 0);
const flash = computed(() => page.props.flash ?? {});

const toast = ref(null);
watch(
    flash,
    (f) => {
        const message = f.error ?? f.success;
        if (!message) return;
        toast.value = { message, error: !!f.error };
        setTimeout(() => (toast.value = null), 5000);
    },
    { immediate: true, deep: true },
);

const nav = [
    { href: '/', label: 'Dashboard', match: (u) => u === '/', icon: 'M3 12l9-9 9 9M5 10v10h14V10' },
    { href: '/projects', label: 'Projects', match: (u) => u.startsWith('/projects'), icon: 'M3 7h18M3 12h18M3 17h18' },
    { href: '/approvals', label: 'Approvals', match: (u) => u.startsWith('/approvals'), icon: 'M9 12l2 2 4-4M21 12a9 9 0 11-18 0 9 9 0 0118 0z', badge: true },
    { href: '/skills', label: 'AI Skills', match: (u) => u.startsWith('/skills'), icon: 'M12 6v12M6 12h12' },
    { href: '/models', label: 'Models & Routing', match: (u) => u.startsWith('/models'), icon: 'M4 6h16M4 12h10M4 18h16' },
    { href: '/self', label: 'Self-Iteration', match: (u) => u.startsWith('/self'), icon: 'M4 4v6h6M20 20v-6h-6M5 14a8 8 0 0014 2M19 10A8 8 0 005 8' },
];
</script>

<template>
    <div class="flex min-h-screen">
        <aside class="fixed inset-y-0 left-0 flex w-60 flex-col bg-stone-900 text-stone-300">
            <div class="flex items-center gap-2 px-5 py-5">
                <span class="flex h-8 w-8 items-center justify-center rounded bg-amber-600 text-lg font-black text-white">⚙</span>
                <div class="leading-tight">
                    <div class="text-sm font-bold text-white">Software Factory</div>
                    <div class="text-[11px] uppercase tracking-widest text-stone-500">autonomous SDLC</div>
                </div>
            </div>
            <nav class="mt-2 flex-1 space-y-0.5 px-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-md px-3 py-2 text-sm transition"
                    :class="item.match(page.url) ? 'bg-stone-800 text-white' : 'hover:bg-stone-800/60 hover:text-white'"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path :d="item.icon" /></svg>
                    <span class="flex-1">{{ item.label }}</span>
                    <span v-if="item.badge && pending" class="rounded-full bg-amber-600 px-2 py-0.5 text-[11px] font-bold text-white">{{ pending }}</span>
                </Link>
            </nav>
            <div class="px-5 py-4 text-[11px] text-stone-600">Laravel · Inertia · Vue</div>
        </aside>

        <main class="ml-60 min-w-0 flex-1 p-8">
            <slot />
        </main>

        <transition enter-active-class="transition" enter-from-class="translate-y-2 opacity-0" leave-active-class="transition" leave-to-class="opacity-0">
            <div
                v-if="toast"
                class="fixed bottom-6 right-6 z-50 max-w-md rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg"
                :class="toast.error ? 'bg-red-600' : 'bg-stone-900'"
            >
                {{ toast.message }}
            </div>
        </transition>
    </div>
</template>
