<?php

namespace App\Http\Controllers;

use App\Ai\Agents\FamilyAssistant;
use App\Http\Requests\RenameAiConversationRequest;
use App\Http\Requests\StreamAiChatRequest;
use App\Http\Requests\TranscribeAudioRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Transcription;

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

        $memberNames = $user->family_id
            ? User::query()
                ->where('family_id', $user->family_id)
                ->whereNull('archived_at')
                ->pluck('name')
                ->toArray()
            : [];

        $transcriptionAvailable = ! empty(config('ai.providers.openai.key'))
            || ! empty(config('ai.providers.mistral.key'));

        return Inertia::render('Ai/Chat', [
            'conversations' => $conversations,
            'messages' => $messages,
            'activeConversationId' => $activeConversationId,
            'memberNames' => $memberNames,
            'transcriptionAvailable' => $transcriptionAvailable,
        ]);
    }

    /**
     * Transcribe uploaded audio to text using AI provider.
     */
    public function transcribe(TranscribeAudioRequest $request): JsonResponse
    {
        $file = $request->file('audio');

        $providers = array_filter([
            ! empty(config('ai.providers.openai.key')) ? Lab::OpenAI : null,
            ! empty(config('ai.providers.mistral.key')) ? Lab::Mistral : null,
        ]);

        $response = Transcription::of($file)
            ->language('en')
            ->timeout(30)
            ->generate($providers ?: null);

        Log::info('AI Transcription completed', [
            'provider' => $response->meta->provider ?? null,
            'model' => $response->meta->model ?? null,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'text_length' => mb_strlen($response->text),
            'usage' => $response->usage ?? null,
        ]);

        return response()->json(['text' => $response->text]);
    }

    /**
     * Stream an AI response for the given message.
     */
    public function stream(StreamAiChatRequest $request): StreamableAgentResponse
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

        DB::transaction(function () use ($conversation, $user) {
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
        });

        return back();
    }
}
