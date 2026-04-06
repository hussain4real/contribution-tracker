<?php

namespace App\Http\Controllers;

use App\Ai\Agents\FamilyAssistant;
use App\Http\Requests\RenameAiConversationRequest;
use App\Http\Requests\StreamAiChatRequest;
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
            $ownsConversation = DB::table('agent_conversations')
                ->where('id', $activeConversationId)
                ->where('user_id', $user->id)
                ->exists();

            if ($ownsConversation) {
                $messages = DB::table('agent_conversation_messages')
                    ->where('conversation_id', $activeConversationId)
                    ->orderBy('created_at')
                    ->get(['id', 'role', 'content', 'created_at'])
                    ->toArray();
            } else {
                $activeConversationId = null;
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
    public function stream(StreamAiChatRequest $request)
    {
        set_time_limit(120);

        $validated = $request->validated();

        /** @var User $user */
        $user = Auth::user();
        $agent = new FamilyAssistant($user);
        $conversationId = $validated['conversation_id'] ?? null;

        if ($conversationId) {
            $ownsConversation = DB::table('agent_conversations')
                ->where('id', $conversationId)
                ->where('user_id', $user->id)
                ->exists();

            if (! $ownsConversation) {
                return $agent
                    ->forUser($user)
                    ->stream($validated['message']);
            }

            return $agent
                ->continue($conversationId, as: $user)
                ->stream($validated['message']);
        }

        return $agent
            ->forUser($user)
            ->stream($validated['message']);
    }

    /**
     * Rename a conversation.
     */
    public function rename(RenameAiConversationRequest $request, string $conversation): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $updated = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->where('user_id', $user->id)
            ->update(['title' => $request->validated('title')]);

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

        $deleted = DB::table('agent_conversations')
            ->where('id', $conversation)
            ->where('user_id', $user->id)
            ->delete();

        if (! $deleted) {
            abort(404);
        }

        DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversation)
            ->delete();

        return back();
    }
}
