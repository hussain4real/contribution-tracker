<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
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
        $user = $this->user($request);

        $notificationFeed = $user
            ->notifications()
            ->latest()
            ->when($filters['status'] === 'unread', fn (Builder $query) => $query->whereNull('read_at'))
            ->when($filters['status'] === 'read', fn (Builder $query) => $query->whereNotNull('read_at'))
            ->when($filters['type'] !== 'all', fn (Builder $query) => $this->whereNotificationDataType($query, $filters['type']))
            ->paginate(12)
            ->withQueryString()
            ->through(fn (DatabaseNotification $notification): array => $this->notificationPayload($notification));

        return Inertia::render('Notifications/Index', [
            'notificationFeed' => $notificationFeed,
            'notificationSummary' => $this->summary($request),
            'notificationFilters' => $filters,
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, DatabaseNotification $notification): RedirectResponse
    {
        $user = $this->user($request);

        abort_unless((int) $notification->notifiable_id === $user->id, 403);

        $notification->markAsRead();

        return back();
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

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
     * @return array{total: int, unread: int, read: int, reminders: int, follow_ups: int}
     */
    private function summary(Request $request): array
    {
        $user = $this->user($request);

        $total = $user->notifications()->count();
        $unread = $user->unreadNotifications()->count();

        return [
            'total' => $total,
            'unread' => $unread,
            'read' => $total - $unread,
            'reminders' => $this->whereNotificationDataType($user->notifications(), 'reminder')->count(),
            'follow_ups' => $this->whereNotificationDataType($user->notifications(), 'follow_up')->count(),
        ];
    }

    /**
     * @param  Builder<DatabaseNotification>|MorphMany<DatabaseNotification, User>  $query
     * @return Builder<DatabaseNotification>|MorphMany<DatabaseNotification, User>
     */
    private function whereNotificationDataType(Builder|MorphMany $query, string $type): Builder|MorphMany
    {
        $connection = $query instanceof MorphMany
            ? $query->getRelated()->getConnection()
            : $query->getModel()->getConnection();

        return match ($connection->getDriverName()) {
            'pgsql' => $query->whereRaw("(data)::jsonb ->> 'type' = ?", [$type]),
            'sqlite' => $query->whereRaw("json_extract(data, '$.type') = ?", [$type]),
            'sqlsrv' => $query->whereRaw("json_value(data, '$.type') = ?", [$type]),
            default => $query->whereRaw("json_unquote(json_extract(data, '$.type')) = ?", [$type]),
        };
    }

    /**
     * @return array{id: string, type: string, data: array<mixed>, read_at: string|null, created_at: string|null}
     */
    private function notificationPayload(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'data' => $notification->data,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
