<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { dashboard, login, register } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);

const features = [
    {
        icon: 'wallet',
        title: 'Track Contributions',
        description:
            'Monitor monthly contributions from all family members with real-time status updates.',
    },
    {
        icon: 'users',
        title: 'Member Categories',
        description:
            'Flexible tiers for Employed (₦4,000), Unemployed (₦2,000), and Students (₦1,000).',
    },
    {
        icon: 'chart',
        title: 'Financial Reports',
        description:
            'Generate monthly and yearly reports to keep everyone informed and accountable.',
    },
    {
        icon: 'shield',
        title: 'Role-Based Access',
        description:
            'Secure access control for Super Admins, Financial Secretaries, and Members.',
    },
];

const stats = [
    { value: '₦7,000', label: 'Max Monthly' },
    { value: '28th', label: 'Due Date' },
    { value: '3', label: 'Categories' },
    { value: '100%', label: 'Transparent' },
];
</script>

<template>
    <Head title="Family Contribution Tracker">
        <link rel="preconnect" href="https://rsms.me/" />
        <link rel="stylesheet" href="https://rsms.me/inter/inter.css" />
    </Head>

    <div class="min-h-screen bg-linear-to-b from-slate-50 to-white dark:from-slate-950 dark:to-slate-900">
        <!-- Header -->
        <header class="fixed top-0 z-50 w-full border-b border-slate-200/80 bg-white/80 backdrop-blur-lg dark:border-slate-800 dark:bg-slate-950/80">
            <div class="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-2">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-linear-to-br from-emerald-500 to-teal-600 shadow-lg shadow-emerald-500/25">
                        <svg class="size-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                        </svg>
                    </div>
                    <span class="text-lg font-bold text-slate-900 dark:text-white">FamilyFund</span>
                </div>

                <nav class="flex items-center gap-3">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="dashboard()"
                    >
                        <Button variant="default" size="sm">
                            Dashboard
                        </Button>
                    </Link>
                    <template v-else>
                        <Link :href="login()">
                            <Button variant="ghost" size="sm">
                                Log in
                            </Button>
                        </Link>
                        <Link v-if="canRegister" :href="register()">
                            <Button size="sm">
                                Get Started
                            </Button>
                        </Link>
                    </template>
                </nav>
            </div>
        </header>

        <!-- Hero Section -->
        <section class="relative overflow-hidden pt-32 pb-20 sm:pt-40 sm:pb-32">
            <!-- Background decoration -->
            <div class="absolute inset-0 -z-10 overflow-hidden">
                <div class="absolute -top-40 right-0 h-[500px] w-[500px] rounded-full bg-linear-to-br from-emerald-400/20 to-teal-500/20 blur-3xl dark:from-emerald-400/10 dark:to-teal-500/10" />
                <div class="absolute top-40 -left-20 h-[400px] w-[400px] rounded-full bg-linear-to-br from-blue-400/20 to-indigo-500/20 blur-3xl dark:from-blue-400/10 dark:to-indigo-500/10" />
            </div>

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-3xl text-center">
                    <div class="mb-6 inline-flex items-center gap-2 rounded-full border border-emerald-200 bg-emerald-50 px-4 py-1.5 text-sm font-medium text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                        </span>
                        Now tracking contributions
                    </div>

                    <h1 class="text-4xl font-bold tracking-tight text-slate-900 sm:text-6xl dark:text-white">
                        Keep Your Family
                        <span class="bg-linear-to-r from-emerald-600 to-teal-500 bg-clip-text text-transparent">
                            Financially United
                        </span>
                    </h1>

                    <p class="mt-6 text-lg leading-8 text-slate-600 dark:text-slate-400">
                        A simple, transparent way to manage monthly family contributions.
                        Track payments, view balances, and keep everyone accountable — all in one place.
                    </p>

                    <div class="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Link v-if="!$page.props.auth.user && canRegister" :href="register()">
                            <Button size="lg" class="w-full bg-linear-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-700 hover:to-teal-700 sm:w-auto">
                                Start Tracking Today
                                <svg class="ml-2 size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Button>
                        </Link>
                        <Link v-else-if="$page.props.auth.user" :href="dashboard()">
                            <Button size="lg" class="w-full bg-linear-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-500/25 hover:from-emerald-700 hover:to-teal-700 sm:w-auto">
                                Go to Dashboard
                                <svg class="ml-2 size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Button>
                        </Link>
                        <Link v-if="!$page.props.auth.user" :href="login()">
                            <Button variant="outline" size="lg" class="w-full sm:w-auto">
                                Sign In
                            </Button>
                        </Link>
                    </div>
                </div>

                <!-- Stats -->
                <div class="mx-auto mt-16 max-w-4xl">
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div
                            v-for="stat in stats"
                            :key="stat.label"
                            class="rounded-2xl border border-slate-200 bg-white/60 p-6 text-center backdrop-blur-sm transition-all hover:border-emerald-300 hover:shadow-lg dark:border-slate-800 dark:bg-slate-900/60 dark:hover:border-emerald-700"
                        >
                            <div class="text-2xl font-bold text-emerald-600 sm:text-3xl dark:text-emerald-400">
                                {{ stat.value }}
                            </div>
                            <div class="mt-1 text-sm text-slate-600 dark:text-slate-400">
                                {{ stat.label }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Features Section -->
        <section class="py-20 sm:py-32">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl dark:text-white">
                        Everything you need to manage contributions
                    </h2>
                    <p class="mt-4 text-lg text-slate-600 dark:text-slate-400">
                        Built for Nigerian families who value transparency and accountability.
                    </p>
                </div>

                <div class="mx-auto mt-16 grid max-w-5xl gap-8 sm:grid-cols-2">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="group relative rounded-2xl border border-slate-200 bg-white p-8 transition-all hover:border-emerald-300 hover:shadow-xl dark:border-slate-800 dark:bg-slate-900 dark:hover:border-emerald-700"
                    >
                        <div class="mb-4 inline-flex size-12 items-center justify-center rounded-xl bg-linear-to-br from-emerald-500 to-teal-600 text-white shadow-lg shadow-emerald-500/25 transition-transform group-hover:scale-110">
                            <!-- Wallet Icon -->
                            <svg v-if="feature.icon === 'wallet'" class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                            </svg>
                            <!-- Users Icon -->
                            <svg v-else-if="feature.icon === 'users'" class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
                            </svg>
                            <!-- Chart Icon -->
                            <svg v-else-if="feature.icon === 'chart'" class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                            <!-- Shield Icon -->
                            <svg v-else class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                            </svg>
                        </div>
                        <h3 class="text-xl font-semibold text-slate-900 dark:text-white">
                            {{ feature.title }}
                        </h3>
                        <p class="mt-2 text-slate-600 dark:text-slate-400">
                            {{ feature.description }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- CTA Section -->
        <section class="relative overflow-hidden py-20 sm:py-32">
            <div class="absolute inset-0 -z-10 bg-linear-to-br from-emerald-600 to-teal-700" />
            <div class="absolute inset-0 -z-10 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48ZyBmaWxsPSJub25lIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiPjxnIGZpbGw9IiNmZmZmZmYiIGZpbGwtb3BhY2l0eT0iMC4wNSI+PHBhdGggZD0iTTM2IDM0djItSDI0di0yaDEyek0zNiAyNHYySDI0di0yaDEyeiIvPjwvZz48L2c+PC9zdmc+')] opacity-30" />

            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="mx-auto max-w-2xl text-center">
                    <h2 class="text-3xl font-bold tracking-tight text-white sm:text-4xl">
                        Ready to bring your family together?
                    </h2>
                    <p class="mt-4 text-lg text-emerald-100">
                        Join families across Nigeria who trust FamilyFund to manage their contributions with transparency and ease.
                    </p>
                    <div class="mt-10">
                        <Link v-if="!$page.props.auth.user && canRegister" :href="register()">
                            <Button size="lg" class="bg-white text-emerald-700 shadow-xl hover:bg-emerald-500 hover:text-white cursor-pointer hover:shadow-emerald-300/50 ">
                                Create Your Account
                                <svg class="ml-2 size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Button>
                        </Link>
                        <Link v-else-if="$page.props.auth.user" :href="dashboard()">
                            <Button size="lg" class="bg-white text-emerald-700 shadow-xl hover:bg-slate-50">
                                Go to Dashboard
                                <svg class="ml-2 size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Button>
                        </Link>
                        <Link v-else :href="login()">
                            <Button size="lg" class="bg-white text-emerald-700 shadow-xl hover:bg-slate-50">
                                Sign In to Continue
                                <svg class="ml-2 size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                </svg>
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- Footer -->
        <footer class="border-t border-slate-200 bg-white py-12 dark:border-slate-800 dark:bg-slate-950">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
                    <div class="flex items-center gap-2">
                        <div class="flex size-8 items-center justify-center rounded-lg bg-linear-to-br from-emerald-500 to-teal-600">
                            <svg class="size-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                            </svg>
                        </div>
                        <span class="font-semibold text-slate-900 dark:text-white">FamilyFund</span>
                    </div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        © {{ new Date().getFullYear() }} FamilyFund. Built with ❤️ for Nigerian families.
                    </p>
                </div>
            </div>
        </footer>
    </div>
</template>
