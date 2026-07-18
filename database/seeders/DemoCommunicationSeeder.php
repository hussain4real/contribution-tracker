<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\InvitationDeliveryMethod;
use App\Enums\Role;
use App\Features\AiAssistant;
use App\Models\Contribution;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Notifications\ContributionReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

class DemoCommunicationSeeder extends Seeder
{
    public function run(): void
    {
        $family = Family::query()->where('slug', 'demo-family')->firstOrFail();
        $admin = User::query()->where('email', 'admin@family.test')->firstOrFail();
        $finance = User::query()->where('email', 'finance@family.test')->firstOrFail();
        $partial = User::query()->where('email', 'partial@family.test')->firstOrFail();
        $overdue = User::query()->where('email', 'overdue@family.test')->firstOrFail();
        $studentCategory = FamilyCategory::query()
            ->where('family_id', $family->id)
            ->where('slug', 'student')
            ->firstOrFail();

        $this->invitation(
            family: $family,
            inviter: $admin,
            category: $studentCategory,
            tokenSeed: 'pending-email',
            email: 'pending.invite@family.test',
            deliveryMethod: InvitationDeliveryMethod::Email,
            expiresAt: CarbonImmutable::now()->addDays(7),
        );
        $this->invitation(
            family: $family,
            inviter: $admin,
            category: $studentCategory,
            tokenSeed: 'pending-whatsapp',
            whatsAppPhone: '+97455000991',
            deliveryMethod: InvitationDeliveryMethod::WhatsApp,
            expiresAt: CarbonImmutable::now()->addDays(7),
        );
        $this->invitation(
            family: $family,
            inviter: $finance,
            category: $studentCategory,
            tokenSeed: 'accepted-email',
            email: 'accepted.invite@family.test',
            deliveryMethod: InvitationDeliveryMethod::Email,
            expiresAt: CarbonImmutable::now()->addDays(7),
            acceptedAt: CarbonImmutable::now()->subDay(),
        );
        $this->invitation(
            family: $family,
            inviter: $admin,
            category: $studentCategory,
            tokenSeed: 'expired-email',
            email: 'expired.invite@family.test',
            deliveryMethod: InvitationDeliveryMethod::Email,
            expiresAt: CarbonImmutable::now()->subDay(),
        );

        $this->whatsAppMessages($family, $finance, $partial);
        $this->notifications($family, $partial, $overdue);
        $this->aiConversation($admin);

        Feature::for($admin)->activate(AiAssistant::class);
    }

    private function invitation(
        Family $family,
        User $inviter,
        FamilyCategory $category,
        string $tokenSeed,
        InvitationDeliveryMethod $deliveryMethod,
        CarbonImmutable $expiresAt,
        ?string $email = null,
        ?string $whatsAppPhone = null,
        ?CarbonImmutable $acceptedAt = null,
    ): void {
        FamilyInvitation::query()->updateOrCreate(
            ['token' => hash('sha256', "demo-invitation-{$tokenSeed}")],
            [
                'family_id' => $family->id,
                'email' => $email,
                'delivery_method' => $deliveryMethod,
                'whatsapp_phone' => $whatsAppPhone,
                'role' => Role::Member,
                'family_category_id' => $category->id,
                'invited_by' => $inviter->id,
                'accepted_at' => $acceptedAt,
                'expires_at' => $expiresAt,
            ],
        );
    }

    private function whatsAppMessages(Family $family, User $finance, User $member): void
    {
        $definitions = [
            [
                'wa_message_id' => 'wamid.demo.inbound.001',
                'direction' => 'inbound',
                'from' => $member->whatsapp_phone,
                'to' => '+97444000000',
                'type' => 'text',
                'body' => 'I have made a transfer for this month.',
                'status' => 'received',
                'read_at' => null,
            ],
            [
                'wa_message_id' => 'wamid.demo.outbound.delivered',
                'direction' => 'outbound',
                'from' => '+97444000000',
                'to' => $member->whatsapp_phone,
                'type' => 'template',
                'body' => null,
                'template_name' => 'contribution_reminder',
                'status' => 'delivered',
                'read_at' => null,
            ],
            [
                'wa_message_id' => 'wamid.demo.outbound.read',
                'direction' => 'outbound',
                'from' => '+97444000000',
                'to' => $finance->whatsapp_phone,
                'type' => 'text',
                'body' => 'The monthly report is ready.',
                'template_name' => null,
                'status' => 'read',
                'read_at' => now()->subMinutes(5),
            ],
            [
                'wa_message_id' => 'wamid.demo.outbound.failed',
                'direction' => 'outbound',
                'from' => '+97444000000',
                'to' => '+97455000999',
                'type' => 'template',
                'body' => null,
                'template_name' => 'family_invitation',
                'status' => 'failed',
                'error_code' => '131000',
                'error_message' => 'Demo delivery failure.',
                'read_at' => null,
            ],
        ];

        foreach ($definitions as $definition) {
            WhatsAppMessage::query()->updateOrCreate(
                ['wa_message_id' => $definition['wa_message_id']],
                [
                    ...$definition,
                    'family_id' => $family->id,
                    'user_id' => $definition['to'] === $member->whatsapp_phone || $definition['from'] === $member->whatsapp_phone
                        ? $member->id
                        : $finance->id,
                    'payload' => ['demo' => true],
                    'wa_timestamp' => now()->subMinutes(10),
                ],
            );
        }
    }

    private function notifications(Family $family, User $partial, User $overdue): void
    {
        $partialContribution = Contribution::query()
            ->where('family_id', $family->id)
            ->where('user_id', $partial->id)
            ->latest('year')
            ->latest('month')
            ->firstOrFail();
        $overdueContribution = Contribution::query()
            ->where('family_id', $family->id)
            ->where('user_id', $overdue->id)
            ->whereDate('due_date', '<', now())
            ->latest('due_date')
            ->firstOrFail();

        foreach ([
            ['00000000-0000-4000-8000-000000000201', $partial, $partialContribution, 'reminder', null],
            ['00000000-0000-4000-8000-000000000202', $overdue, $overdueContribution, 'follow_up', now()->subHour()],
        ] as [$id, $user, $contribution, $type, $readAt]) {
            DatabaseNotification::query()->updateOrCreate(
                ['id' => $id],
                [
                    'type' => ContributionReminderNotification::class,
                    'notifiable_type' => User::class,
                    'notifiable_id' => $user->id,
                    'data' => [
                        'contribution_id' => $contribution->id,
                        'family_name' => $family->name,
                        'period_label' => $contribution->period_label,
                        'amount_owed' => $contribution->balance,
                        'due_date' => $contribution->due_date->toDateString(),
                        'type' => $type,
                    ],
                    'read_at' => $readAt,
                ],
            );
        }
    }

    private function aiConversation(User $user): void
    {
        $conversationId = '00000000-0000-4000-8000-000000000301';
        DB::table('agent_conversations')->updateOrInsert(
            ['id' => $conversationId],
            [
                'user_id' => $user->id,
                'title' => 'Demo contribution overview',
                'created_at' => now()->subHour(),
                'updated_at' => now()->subMinutes(30),
            ],
        );

        $messages = [
            ['00000000-0000-4000-8000-000000000302', 'user', 'Show me the current contribution overview.'],
            ['00000000-0000-4000-8000-000000000303', 'assistant', 'The demo family includes paid, partial, unpaid, and overdue contributions for testing.'],
        ];

        foreach ($messages as [$id, $role, $content]) {
            DB::table('agent_conversation_messages')->updateOrInsert(
                ['id' => $id],
                [
                    'conversation_id' => $conversationId,
                    'user_id' => $user->id,
                    'agent' => 'family-assistant',
                    'role' => $role,
                    'content' => $content,
                    'attachments' => '[]',
                    'tool_calls' => '[]',
                    'tool_results' => '[]',
                    'usage' => '{}',
                    'meta' => '{"demo":true}',
                    'created_at' => now()->subMinutes($role === 'user' ? 40 : 39),
                    'updated_at' => now()->subMinutes($role === 'user' ? 40 : 39),
                ],
            );
        }
    }
}
