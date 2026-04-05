<?php

namespace App\Http\Controllers;

use App\Ai\Agents\FamilyAssistant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AiChatController extends Controller
{
    /**
     * Display the AI chat page with conversation history.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = Auth::user();

        $conversations = DB::table('agent_conversations')
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'updated_at']);

        $messages = [];
        $activeConversationId = $request->query('conversation');

        if ($activeConversationId) {
            $conversation = DB::table('agent_conversations')
                ->where('id', $activeConversationId)
                ->where('user_id', $user->id)
                ->first();

            if ($conversation) {
                $messages = DB::table('agent_conversation_messages')
                    ->where('conversation_id', $activeConversationId)
                    ->orderBy('created_at')
                    ->get(['id', 'role', 'content', 'created_at'])
                    ->map(fn ($m) => [
                        'id' => $m->id,
                        'role' => $m->role,
                        'content' => $m->content,
                        'created_at' => $m->created_at,
                    ])
                    ->toArray();
            }
        }

        return Inertia::render('Ai/Chat', [
            'conversations' => $conversations,
            'messages' => $messages,
            'activeConversationId' => $activeConversationId,
        ]);
    }

    /**
     * Stream an AI response for the given message.
     */
    public function stream(Request $request)
    {
        set_time_limit(120);

        $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'conversation_id' => ['nullable', 'string', 'max:36'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $agent = new FamilyAssistant($user);
        $conversationId = $request->input('conversation_id');

        if ($conversationId) {
            $exists = DB::table('agent_conversations')
                ->where('id', $conversationId)
                ->where('user_id', $user->id)
                ->exists();

            if (! $exists) {
                // Conversation not found or doesn't belong to user — start fresh
                return $agent
                    ->forUser($user)
                    ->stream($request->input('message'));
            }

            return $agent
                ->continue($conversationId, as: $user)
                ->stream($request->input('message'));
        }

        return $agent
            ->forUser($user)
            ->stream($request->input('message'));
    }

    /**
     * Rename a conversation.
     */
    public function rename(Request $request, string $conversation): RedirectResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = Auth::user();

        $updated = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->where('user_id', $user->id)
            ->update(['title' => $request->input('title')]);

        if (! $updated) {
            abort(404);
        }

        return back();
    }

    /**
     * Delete a conversation and its messages.
     */
    public function destroy(string $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $exists = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->where('user_id', $user->id)
            ->exists();

        if (! $exists) {
            abort(404);
        }

        DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation)
            ->delete();

        DB::table('agent_conversations')
            ->where('id', $conversation)
            ->where('user_id', $user->id)
            ->delete();

        return back();
    }
}
