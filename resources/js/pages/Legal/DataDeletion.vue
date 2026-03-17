<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { home } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Data Deletion" />

    <div class="min-h-svh bg-background">
        <div class="absolute top-4 right-4">
            <ThemeToggle />
        </div>

        <div class="mx-auto max-w-3xl px-4 py-16 sm:px-6 lg:px-8">
            <div class="mb-8 flex items-center gap-3">
                <Link :href="home()">
                    <AppLogoIcon class="h-8 w-8" />
                </Link>
                <h1
                    class="text-3xl font-bold text-neutral-900 dark:text-neutral-100"
                >
                    Data Deletion
                </h1>
            </div>

            <div
                class="prose prose-neutral dark:prose-invert max-w-none space-y-6"
            >
                <p class="text-sm text-muted-foreground">
                    Last updated: March 17, 2026
                </p>

                <h2>How to Delete Your Data</h2>
                <p>
                    At FamilyFunds, we respect your right to control your
                    personal data. You can request deletion of your account and
                    all associated data at any time.
                </p>

                <h2>What Gets Deleted</h2>
                <p>
                    When you request data deletion, the following will be
                    permanently removed:
                </p>
                <ul>
                    <li>Your account information (name, email, password)</li>
                    <li>Your payment records</li>
                    <li>Your contribution history</li>
                    <li>Any expenses you recorded</li>
                    <li>Your session data and preferences</li>
                </ul>

                <h2>How to Request Deletion</h2>
                <p>To request deletion of your data, you can either:</p>

                <div
                    class="not-prose rounded-lg border border-neutral-200 bg-neutral-50 p-6 dark:border-neutral-700 dark:bg-neutral-800/50"
                >
                    <h3
                        class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        Option 1: Email Request
                    </h3>
                    <p class="mb-2 text-neutral-600 dark:text-neutral-400">
                        Send an email to
                        <a
                            href="mailto:aminuhussain22@gmail.com"
                            class="text-primary hover:underline"
                            >aminuhussain22@gmail.com</a
                        >
                        with the subject line "Data Deletion Request" and
                        include:
                    </p>
                    <ul
                        class="list-disc space-y-1 pl-6 text-neutral-600 dark:text-neutral-400"
                    >
                        <li>Your full name</li>
                        <li>The email address associated with your account</li>
                    </ul>
                </div>

                <div
                    class="not-prose mt-4 rounded-lg border border-neutral-200 bg-neutral-50 p-6 dark:border-neutral-700 dark:bg-neutral-800/50"
                >
                    <h3
                        class="mb-4 text-lg font-semibold text-neutral-900 dark:text-neutral-100"
                    >
                        Option 2: Contact Your Family Admin
                    </h3>
                    <p class="text-neutral-600 dark:text-neutral-400">
                        Contact the super admin of your family group and request
                        that your account be archived or removed from the
                        system.
                    </p>
                </div>

                <h2>Processing Time</h2>
                <p>
                    Data deletion requests are processed within 30 days. You
                    will receive a confirmation email once your data has been
                    permanently deleted.
                </p>

                <h2>Important Notes</h2>
                <ul>
                    <li>
                        Data deletion is <strong>permanent</strong> and cannot
                        be undone.
                    </li>
                    <li>
                        Aggregated, anonymized data used in family reports may
                        be retained as it cannot be linked back to you.
                    </li>
                    <li>
                        We may retain certain data if required by law or for
                        legitimate business purposes (e.g., fraud prevention).
                    </li>
                </ul>

                <h2>Contact Us</h2>
                <p>
                    If you have questions about data deletion, please contact us
                    at
                    <a
                        href="mailto:aminuhussain22@gmail.com"
                        class="text-primary hover:underline"
                        >aminuhussain22@gmail.com</a
                    >.
                </p>
            </div>

            <div
                class="mt-12 border-t border-neutral-200 pt-6 dark:border-neutral-700"
            >
                <Link
                    :href="home()"
                    class="text-sm text-muted-foreground hover:text-primary"
                >
                    &larr; Back to home
                </Link>
            </div>
        </div>
    </div>
</template>
