<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';
import { home } from '@/routes';
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Privacy Policy" />

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
                    Privacy Policy
                </h1>
            </div>

            <div
                class="prose max-w-none space-y-6 prose-neutral dark:prose-invert"
            >
                <p class="text-sm text-muted-foreground">
                    Last updated: March 17, 2026
                </p>

                <h2>1. Information We Collect</h2>
                <p>
                    FamilyFunds collects information you provide directly when
                    creating an account, including your name and email address.
                    We also collect financial information related to family
                    contributions, payments, and expenses that you record within
                    the application.
                </p>

                <h2>2. How We Use Your Information</h2>
                <p>We use the information we collect to:</p>
                <ul>
                    <li>
                        Provide, maintain, and improve the FamilyFunds service
                    </li>
                    <li>
                        Track and manage family contribution records and
                        payments
                    </li>
                    <li>
                        Generate reports and summaries for your family group
                    </li>
                    <li>Send important notifications about your account</li>
                    <li>Ensure the security of your account</li>
                </ul>

                <h2>3. Information Sharing</h2>
                <p>
                    Your financial data and personal information are shared only
                    with members of your family group within the application. We
                    do not sell, trade, or otherwise transfer your personal
                    information to third parties. We may share information only
                    when required by law or to protect the rights and safety of
                    our users.
                </p>

                <h2>4. Data Security</h2>
                <p>
                    We implement appropriate security measures to protect your
                    personal information, including encrypted connections
                    (HTTPS), secure password hashing, and two-factor
                    authentication. However, no method of transmission over the
                    internet is 100% secure.
                </p>

                <h2>5. Data Retention</h2>
                <p>
                    We retain your personal data for as long as your account is
                    active or as needed to provide you services. You may request
                    deletion of your data at any time by visiting our
                    <Link
                        href="/data-deletion"
                        class="text-primary hover:underline"
                        >Data Deletion</Link
                    >
                    page.
                </p>

                <h2>6. Your Rights</h2>
                <p>You have the right to:</p>
                <ul>
                    <li>Access the personal data we hold about you</li>
                    <li>Request correction of inaccurate data</li>
                    <li>Request deletion of your data</li>
                    <li>Withdraw consent for data processing at any time</li>
                </ul>

                <h2>7. Cookies</h2>
                <p>
                    We use essential cookies to maintain your session and
                    remember your preferences (such as theme selection). We do
                    not use third-party tracking or advertising cookies.
                </p>

                <h2>8. Changes to This Policy</h2>
                <p>
                    We may update this Privacy Policy from time to time. We will
                    notify you of any changes by posting the new policy on this
                    page and updating the "Last updated" date.
                </p>

                <h2>9. Contact Us</h2>
                <p>
                    If you have questions about this Privacy Policy, please
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
