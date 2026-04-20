<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Channels\WhatsAppMessage as WhatsAppMessageBuilder;
use App\Http\Requests\ReplyWhatsAppMessageRequest;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin inbox for WhatsApp conversations within the current family.
 *
 * - Only Admin and Financial Secretary roles may access.
 * - Threads are grouped by the inbound phone number.
 * - Replies are sent as plain text and are only valid inside the WhatsApp
 *   24-hour customer service window (i.e. within 24h of the last inbound
 *   message). Outside that window, only template messages would work.
 */
class WhatsAppInboxController extends Controller
{
    /**
     * Window during which a free-form text reply is allowed (24 hours).
     */
    protected const REPLY_WINDOW_HOURS = 24;

    public function __construct(
        protected WhatsAppService $whatsapp,
    ) {}

    /**
     * List inbound conversation threads for the current family.
     */
    public function index(): Response
    {
        $this->authorizeInboxAccess();

        /** @var User $user */
        $user = Auth::user();

        // Subquery to fetch the latest message body per phone number for the family.
        $latestBodySub = WhatsAppMessage::query()
            ->select('body')
            ->whereColumn('from', 'wam.from')
            ->where('family_id', $user->family_id)
            ->orderByDesc('created_at')
            ->limit(1);

        $rows = WhatsAppMessage::query()
            ->from('whatsapp_messages as wam')
            ->where('family_id', $user->family_id)
            ->selectRaw('"from" as phone, MAX(created_at) as last_at, COUNT(*) as message_count, MAX(user_id) as user_id')
            ->selectSub($latestBodySub, 'last_body')
            ->groupBy('from')
            ->orderByDesc('last_at')
            ->get();

        // Batch-load matched users to avoid N+1.
        $userIds = $rows->pluck('user_id')->filter()->unique()->values();
        $users = $userIds->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        $threads = $rows->map(function ($row) use ($users): array {
            $member = $row->user_id ? $users->get($row->user_id) : null;

            return [
                'phone' => $row->phone,
                'member_id' => $member?->id,
                'member_name' => $member?->name,
                'last_at' => $row->last_at,
                'last_body' => $row->last_body,
                'message_count' => (int) $row->message_count,
            ];
        });

        return Inertia::render('Inbox/Index', [
            'threads' => $threads,
        ]);
    }

    /**
     * Show a single conversation with the supplied phone number.
     */
    public function show(string $phone): Response
    {
        $this->authorizeInboxAccess();

        /** @var User $user */
        $user = Auth::user();

        $messages = WhatsAppMessage::query()
            ->where('family_id', $user->family_id)
            ->where(function ($query) use ($phone): void {
                $query->where('from', $phone)->orWhere('to', $phone);
            })
            ->orderBy('created_at')
            ->get()
            ->map(fn (WhatsAppMessage $message) => [
                'id' => $message->id,
                'direction' => $message->direction,
                'body' => $message->body,
                'template_name' => $message->template_name,
                'status' => $message->status,
                'created_at' => $message->created_at?->toIso8601String(),
            ]);

        $lastInbound = WhatsAppMessage::query()
            ->where('family_id', $user->family_id)
            ->where('from', $phone)
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->first();

        $canReply = $lastInbound !== null
            && $lastInbound->created_at?->diffInHours(now()) < self::REPLY_WINDOW_HOURS;

        $member = WhatsAppMessage::query()
            ->where('family_id', $user->family_id)
            ->where('from', $phone)
            ->whereNotNull('user_id')
            ->latest('created_at')
            ->first()?->user;

        return Inertia::render('Inbox/Thread', [
            'phone' => $phone,
            'messages' => $messages,
            'canReply' => $canReply,
            'replyWindowHours' => self::REPLY_WINDOW_HOURS,
            'member' => $member ? [
                'id' => $member->id,
                'name' => $member->name,
            ] : null,
        ]);
    }

    /**
     * Send a free-form text reply to the supplied phone number.
     */
    public function reply(ReplyWhatsAppMessageRequest $request, string $phone): RedirectResponse
    {
        $this->authorizeInboxAccess();

        $validated = $request->validated();

        /** @var User $user */
        $user = Auth::user();

        $lastInbound = WhatsAppMessage::query()
            ->where('family_id', $user->family_id)
            ->where('from', $phone)
            ->where('direction', 'inbound')
            ->latest('created_at')
            ->first();

        if (! $lastInbound || $lastInbound->created_at?->diffInHours(now()) >= self::REPLY_WINDOW_HOURS) {
            return back()->withErrors([
                'body' => 'You can only reply within '.self::REPLY_WINDOW_HOURS.' hours of the last inbound message.',
            ]);
        }

        $message = (new WhatsAppMessageBuilder)->text($validated['body']);

        $result = $this->whatsapp->send($this->whatsapp->normalisePhone($phone), $message);

        if (! $result['success']) {
            return back()->withErrors([
                'body' => 'Could not send message: '.($result['error'] ?? 'unknown error'),
            ]);
        }

        return back()->with('success', 'Reply sent.');
    }

    /**
     * Ensure the current user can view the WhatsApp inbox.
     */
    protected function authorizeInboxAccess(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user && $user->role->canViewAllMembers(), 403);
    }
}
