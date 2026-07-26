<?php

declare(strict_types=1);

use App\Support\PlatformPlanCatalog;
use Database\Seeders\PlatformPlanSeeder;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

it('shows active pricing plans to guests', function () {
    withoutVite();
    $this->seed(PlatformPlanSeeder::class);

    $this->get(route('pricing'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->has('plans', 4)
            ->where('plans.0.slug', PlatformPlanCatalog::Free)
            ->where('plans.0.formatted_price', 'Free')
            ->where('plans.0.max_members', 5)
            ->where('plans.1.slug', PlatformPlanCatalog::Family)
            ->where('plans.1.formatted_price', '₦3,000')
            ->where('plans.1.max_members', 25)
            ->where('plans.1.is_recommended', true)
            ->where('plans.1.features.3', PlatformPlanCatalog::EmailReminders)
            ->where('plans.1.features.4', PlatformPlanCatalog::WebPushReminders)
            ->where('plans.1.features.5', PlatformPlanCatalog::WhatsappReminders)
            ->where('plans.2.slug', PlatformPlanCatalog::Growth)
            ->where('plans.2.formatted_price', '₦7,500')
            ->where('plans.2.max_members', 75)
            ->where('plans.2.features.5', PlatformPlanCatalog::WhatsappReminders)
            ->where('plans.2.features.8', PlatformPlanCatalog::AiAssistant)
            ->where('plans.3.slug', PlatformPlanCatalog::Organization)
            ->where('plans.3.formatted_price', '₦20,000')
            ->where('plans.3.max_members', 250)
            ->where('plans.3.features.5', PlatformPlanCatalog::WhatsappReminders)
            ->where('plans.3.features.8', PlatformPlanCatalog::AiAssistant)
            ->where('plans.3.features.9', PlatformPlanCatalog::WhatsappMessaging)
            ->where('available_features.'.PlatformPlanCatalog::OnlinePayments, 'Online Payments (Paystack)')
            ->where('available_features.'.PlatformPlanCatalog::EmailReminders, 'Email Reminders')
            ->where('available_features.'.PlatformPlanCatalog::WebPushReminders, 'Browser Push Reminders')
            ->where('available_features.'.PlatformPlanCatalog::AiAssistant, 'AI Agent & Report Summaries')
            ->where('available_features.'.PlatformPlanCatalog::WhatsappReminders, 'WhatsApp Reminders')
            ->where('available_features.'.PlatformPlanCatalog::WhatsappMessaging, 'WhatsApp Inbox & Replies')
        );
});

it('adds compact pricing preview data to the welcome page', function () {
    $this->seed(PlatformPlanSeeder::class);

    $this->get(route('home'))
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('pricingPreviewPlans', 4)
            ->where('pricingPreviewPlans.0.slug', PlatformPlanCatalog::Free)
            ->where('pricingPreviewPlans.1.slug', PlatformPlanCatalog::Family)
            ->where('pricingPreviewPlans.1.is_recommended', true)
            ->where('availableFeatures.'.PlatformPlanCatalog::Reports, 'Financial Reports')
        );
});
