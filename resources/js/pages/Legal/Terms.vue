<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { home } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Terms of Service" />

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
                    Terms of Service
                </h1>
            </div>

            <div
                class="prose prose-neutral dark:prose-invert max-w-none space-y-6"
            >
                <p class="text-sm text-muted-foreground">
                    Last updated: March 17, 2026
                </p>

                <h2>1. Acceptance of Terms</h2>
                <p>
                    By accessing or using FamilyFunds, you agree to be bound by
                    these Terms of Service. If you do not agree to these terms,
                    please do not use the service.
                </p>

                <h2>2. Description of Service</h2>
                <p>
                    FamilyFunds is a family contribution tracking application
                    that allows families to manage monthly contributions, record
                    payments, track expenses, and monitor their family fund. The
                    service is provided for personal, non-commercial use within
                    family groups.
                </p>

                <h2>3. User Accounts</h2>
                <p>
                    To use FamilyFunds, you must create an account with accurate
                    and complete information. You are responsible for:
                </p>
                <ul>
                    <li>
                        Maintaining the security of your account credentials
                    </li>
                    <li>All activities that occur under your account</li>
                    <li>Notifying us immediately of any unauthorized use</li>
                </ul>

                <h2>4. Acceptable Use</h2>
                <p>You agree not to:</p>
                <ul>
                    <li>
                        Use the service for any illegal or unauthorized purpose
                    </li>
                    <li>
                        Attempt to gain unauthorized access to any part of the
                        service
                    </li>
                    <li>
                        Interfere with or disrupt the service or its
                        infrastructure
                    </li>
                    <li>
                        Upload malicious code or attempt to compromise security
                    </li>
                </ul>

                <h2>5. Data Accuracy</h2>
                <p>
                    You are responsible for the accuracy of the financial data
                    you enter into FamilyFunds, including contribution amounts,
                    payment records, and expense entries. FamilyFunds is a
                    tracking tool and does not provide financial advice.
                </p>

                <h2>6. Intellectual Property</h2>
                <p>
                    The FamilyFunds service, including its design, features, and
                    content, is owned by us and protected by applicable
                    intellectual property laws. Your data remains yours.
                </p>

                <h2>7. Service Availability</h2>
                <p>
                    We strive to maintain high availability of the service but
                    do not guarantee uninterrupted access. We may perform
                    maintenance or updates that temporarily affect availability.
                </p>

                <h2>8. Limitation of Liability</h2>
                <p>
                    FamilyFunds is provided "as is" without warranties of any
                    kind. We are not liable for any indirect, incidental, or
                    consequential damages arising from your use of the service,
                    including loss of data or financial discrepancies.
                </p>

                <h2>9. Termination</h2>
                <p>
                    We may suspend or terminate your access to the service at
                    any time for violation of these terms. You may also delete
                    your account at any time through the
                    <Link
                        href="/data-deletion"
                        class="text-primary hover:underline"
                        >Data Deletion</Link
                    >
                    process.
                </p>

                <h2>10. Changes to Terms</h2>
                <p>
                    We reserve the right to modify these terms at any time.
                    Continued use of the service after changes constitutes
                    acceptance of the updated terms.
                </p>

                <h2>11. Contact Us</h2>
                <p>
                    If you have questions about these Terms of Service, please
                    contact us at
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
