<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * Display the user's notifications.
     */
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $notifications = $request->user()
            ->notifications()
            ->latest()
            ->get();

        $filteredNotifications = $notifications
            ->filter(fn (DatabaseNotification $notification): bool => $this->matchesStatusFilter($notification, $filters['status']))
            ->filter(fn (DatabaseNotification $notification): bool => $this->matchesTypeFilter($notification, $filters['type']))
            ->values();

        return Inertia::render('Notifications/Index', [
            'notificationFeed' => $this->paginateNotifications($request, $filteredNotifications),
            'notificationSummary' => $this->summary($notifications),
            'notificationFilters' => $filters,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        abort_unless((int) $notification->notifiable_id === $request->user()->id, 403);

        $notification->markAsRead();

        return back();
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    /**
     * @return array{status: string, type: string}
     */
    private function filters(Request $request): array
    {
        $status = $request->string('status')->toString();
        $type = $request->string('type')->toString();

        return [
            'status' => in_array($status, ['unread', 'read'], true) ? $status : 'all',
            'type' => in_array($type, ['reminder', 'follow_up'], true) ? $type : 'all',
        ];
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     * @return array{total: int, unread: int, read: int, reminders: int, follow_ups: int}
     */
    private function summary(Collection $notifications): array
    {
        $total = $notifications->count();
        $unread = $notifications->whereNull('read_at')->count();

        return [
            'total' => $total,
            'unread' => $unread,
            'read' => $total - $unread,
            'reminders' => $notifications->filter(fn (DatabaseNotification $notification): bool => $this->notificationDataType($notification) === 'reminder')->count(),
            'follow_ups' => $notifications->filter(fn (DatabaseNotification $notification): bool => $this->notificationDataType($notification) === 'follow_up')->count(),
        ];
    }

    private function matchesStatusFilter(DatabaseNotification $notification, string $status): bool
    {
        return match ($status) {
            'unread' => $notification->read_at === null,
            'read' => $notification->read_at !== null,
            default => true,
        };
    }

    private function matchesTypeFilter(DatabaseNotification $notification, string $type): bool
    {
        return $type === 'all' || $this->notificationDataType($notification) === $type;
    }

    private function notificationDataType(DatabaseNotification $notification): ?string
    {
        $data = $notification->data;

        return is_array($data) ? ($data['type'] ?? null) : null;
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $notifications
     */
    private function paginateNotifications(Request $request, Collection $notifications): LengthAwarePaginator
    {
        $perPage = 12;
        $page = max(1, $request->integer('page', 1));

        return new LengthAwarePaginator(
            $notifications
                ->forPage($page, $perPage)
                ->values()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'data' => $notification->data,
                    'read_at' => $notification->read_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ]),
            $notifications->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }
}
