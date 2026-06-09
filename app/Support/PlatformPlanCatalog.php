<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PlatformPlan;

final class PlatformPlanCatalog
{
    public const Free = 'free';

    public const Family = 'family';

    public const Growth = 'growth';

    public const Organization = 'organization';

    public const BasicContributions = 'basic_contributions';

    public const ManualPayments = 'manual_payments';

    public const OnlinePayments = 'online_payments';

    public const Reports = 'reports';

    public const Exports = 'exports';

    public const AiAssistant = 'ai_assistant';

    public const WhatsappMessaging = 'whatsapp_messaging';

    public const PrioritySupport = 'priority_support';

    /**
     * @return array<string, string>
     */
    public static function featureLabels(): array
    {
        return [
            self::BasicContributions => 'Monthly Contributions',
            self::ManualPayments => 'Manual Payment Recording',
            self::OnlinePayments => 'Online Payments (Paystack)',
            self::Reports => 'Financial Reports',
            self::Exports => 'CSV Exports',
            self::AiAssistant => 'AI Assistant & Report Summaries',
            self::WhatsappMessaging => 'WhatsApp Reminders & Inbox',
            self::PrioritySupport => 'Priority Support',
        ];
    }

    /**
     * @return array<string, array{audience: string, summary: string, is_recommended: bool}>
     */
    public static function subscriptionCardMetadata(): array
    {
        return [
            self::Free => [
                'audience' => 'Tiny families testing the platform',
                'summary' => 'Track obligations, categories, manual payments, and member balances.',
                'is_recommended' => false,
            ],
            self::Family => [
                'audience' => 'Active family funds',
                'summary' => 'Add online self-pay, reminders, and monthly or annual reports.',
                'is_recommended' => true,
            ],
            self::Growth => [
                'audience' => 'Large families and small groups',
                'summary' => 'Unlock exports and AI-assisted answers when the AI feature flag is active.',
                'is_recommended' => false,
            ],
            self::Organization => [
                'audience' => 'Associations, NGOs, churches, alumni groups, and workplace funds',
                'summary' => 'Add WhatsApp workflows, priority support, and assisted onboarding.',
                'is_recommended' => false,
            ],
        ];
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     slug: string,
     *     price: int,
     *     formatted_price: string,
     *     max_members: int|null,
     *     features: list<string>,
     *     is_current: bool,
     *     audience: string,
     *     summary: string,
     *     is_recommended: bool
     * }
     */
    public static function subscriptionCard(PlatformPlan $plan, ?PlatformPlan $currentPlan = null): array
    {
        $metadata = self::subscriptionCardMetadata()[$plan->slug] ?? [
            'audience' => 'FamilyFunds workspace',
            'summary' => 'A custom package configured by the platform team.',
            'is_recommended' => false,
        ];

        return [
            'id' => $plan->id,
            'name' => $plan->name,
            'slug' => $plan->slug,
            'price' => $plan->price,
            'formatted_price' => $plan->formattedPrice(),
            'max_members' => $plan->max_members,
            'features' => $plan->features ?? [],
            'is_current' => $currentPlan?->id === $plan->id,
            ...$metadata,
        ];
    }
}
