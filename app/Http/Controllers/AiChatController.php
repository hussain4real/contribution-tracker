<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Agents\FamilyAssistant;
use App\Http\Requests\RenameAiConversationRequest;
use App\Http\Requests\StreamAiChatRequest;
use App\Http\Requests\TranscribeAudioRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Files\Base64Audio;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Transcription;

class AiChatController extends Controller
{
    private const int MIN_TRANSCRIPTION_AUDIO_BYTES = 4096;

    private const float MIN_TRANSCRIPTION_AUDIO_LEVEL = 0.01;

    /**
     * Normalize stored JSON activity payloads to indexed arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function normalizeActivityPayload(?string $payload): array
    {
        $decoded = json_decode($payload ?? '[]', true);

        if (! is_array($decoded)) {
            return [];
        }

        $activities = [];

        foreach ($decoded as $activity) {
            $activity = $this->stringKeyedArray($activity);

            if ($activity !== null) {
                $activities[] = $activity;
            }
        }

        return $activities;
    }

    /**
     * Display the AI chat page with conversation history.
     */
    public function index(Request $request): Response
    {
        $user = $this->user($request);

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
                    ->get(['id', 'role', 'content', 'created_at', 'tool_calls', 'tool_results'])
                    ->map(fn (object $message) => [
                        'id' => $message->id,
                        'role' => $message->role,
                        'content' => $message->content,
                        'created_at' => $message->created_at,
                        'tool_calls' => $this->normalizeActivityPayload($this->nullableString($message->tool_calls ?? null)),
                        'tool_results' => $this->normalizeActivityPayload($this->nullableString($message->tool_results ?? null)),
                    ])
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

        return Inertia::render('Ai/Chat', [
            'conversations' => $conversations,
            'messages' => $messages,
            'activeConversationId' => $activeConversationId,
            'memberNames' => $memberNames,
            'transcriptionAvailable' => $this->transcriptionProviders() !== [],
        ]);
    }

    /**
     * Transcribe uploaded audio to text using AI provider.
     */
    public function transcribe(TranscribeAudioRequest $request): JsonResponse
    {
        $file = $request->file('audio');
        $audio = Base64Audio::fromUpload($file, $this->transcriptionAudioMimeType($file));
        $recordingMetadata = $this->transcriptionRecordingMetadata($request);

        $providers = $this->transcriptionProviders();

        if (
            $file->getSize() < self::MIN_TRANSCRIPTION_AUDIO_BYTES
            && (float) ($recordingMetadata['audio_level'] ?? 0.0) < self::MIN_TRANSCRIPTION_AUDIO_LEVEL
        ) {
            Log::warning('AI Transcription skipped because uploaded audio was too small', [
                ...$this->transcriptionLogContext($file, $audio, $recordingMetadata),
                'minimum_file_size' => self::MIN_TRANSCRIPTION_AUDIO_BYTES,
                'minimum_audio_level' => self::MIN_TRANSCRIPTION_AUDIO_LEVEL,
            ]);

            return response()->json([
                'message' => 'No microphone audio was captured. Check your microphone and try again.',
            ], 422);
        }

        $response = Transcription::of($audio)
            ->language('en')
            ->timeout(30)
            ->generate($providers ?: null);
        $text = trim($response->text);

        Log::info('AI Transcription completed', [
            'provider' => $response->meta->provider ?? null,
            'model' => $response->meta->model ?? null,
            ...$this->transcriptionLogContext($file, $audio, $recordingMetadata),
            'text_length' => mb_strlen($response->text),
            'usage' => $response->usage ?? null,
        ]);

        if ($text === '') {
            Log::warning('AI Transcription completed without recognized speech', [
                'provider' => $response->meta->provider ?? null,
                'model' => $response->meta->model ?? null,
                ...$this->transcriptionLogContext($file, $audio, $recordingMetadata),
                'usage' => $response->usage ?? null,
            ]);

            return response()->json([
                'message' => 'No speech was recognized. Check your microphone and try again.',
            ], 422);
        }

        return response()->json(['text' => $text]);
    }

    /**
     * Get the configured transcription providers in failover order.
     *
     * @return array<int, Lab>
     */
    private function transcriptionProviders(): array
    {
        $providers = [
            Lab::OpenAI->value => Lab::OpenAI,
            Lab::Gemini->value => Lab::Gemini,
            Lab::Mistral->value => Lab::Mistral,
        ];

        $configuredProviders = array_filter(
            $providers,
            fn (Lab $provider): bool => ! empty(config("ai.providers.{$provider->value}.key"))
        );

        $defaultProviderValue = config('ai.default_for_transcription');
        $defaultProvider = is_string($defaultProviderValue) ? Lab::tryFrom($defaultProviderValue) : null;

        if ($defaultProvider instanceof Lab && array_key_exists($defaultProvider->value, $configuredProviders)) {
            $configuredProviders = [
                $defaultProvider->value => $defaultProvider,
            ] + array_diff_key($configuredProviders, [$defaultProvider->value => true]);
        }

        return array_values($configuredProviders);
    }

    private function transcriptionAudioMimeType(UploadedFile $file): string
    {
        $mimeType = Str::of($file->getClientMimeType() ?: $file->getMimeType() ?: '')
            ->before(';')
            ->trim()
            ->lower()
            ->toString();

        return match ($mimeType) {
            'audio/webm', 'video/webm' => 'audio/webm',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a', 'video/mp4', 'application/mp4' => 'audio/mp4',
            'audio/mp3', 'audio/mpeg', 'audio/mpga' => 'audio/mpeg',
            'application/octet-stream', '' => $this->transcriptionAudioMimeTypeFromExtension($file),
            default => $mimeType,
        };
    }

    private function transcriptionAudioMimeTypeFromExtension(UploadedFile $file): string
    {
        $mimeTypes = [
            'webm' => 'audio/webm',
            'mp4' => 'audio/mp4',
            'm4a' => 'audio/mp4',
            'mp3' => 'audio/mpeg',
            'mpeg' => 'audio/mpeg',
            'mpga' => 'audio/mpeg',
            'ogg' => 'audio/ogg',
            'oga' => 'audio/ogg',
            'wav' => 'audio/wav',
            'wave' => 'audio/wav',
            'flac' => 'audio/flac',
        ];

        return $mimeTypes[Str::lower($file->getClientOriginalExtension())] ?? 'audio/webm';
    }

    /**
     * @return array{duration_seconds: float|null, audio_level: float|null, chunk_count: int|null, client_mime_type: string|null}
     */
    private function transcriptionRecordingMetadata(TranscribeAudioRequest $request): array
    {
        return [
            'duration_seconds' => $request->float('duration_seconds') ?: null,
            'audio_level' => $request->float('audio_level') ?: null,
            'chunk_count' => $request->integer('chunk_count') ?: null,
            'client_mime_type' => $request->string('client_mime_type')->toString() ?: null,
        ];
    }

    /**
     * @param  array{duration_seconds: float|null, audio_level: float|null, chunk_count: int|null, client_mime_type: string|null}  $recordingMetadata
     * @return array<string, mixed>
     */
    private function transcriptionLogContext(UploadedFile $file, Base64Audio $audio, array $recordingMetadata): array
    {
        return [
            'file_size' => $file->getSize(),
            'client_mime_type' => $file->getClientMimeType(),
            'detected_mime_type' => $file->getMimeType(),
            'provider_mime_type' => $audio->mimeType(),
            'recording' => $recordingMetadata,
        ];
    }

    /**
     * Stream an AI response for the given message.
     */
    public function stream(StreamAiChatRequest $request): StreamableAgentResponse
    {
        set_time_limit(120);

        $validated = $request->validated();

        $user = $this->authUser();
        $agent = new FamilyAssistant($user);
        $message = $this->stringValue($validated['message'] ?? null);
        $conversationId = $this->nullableString($validated['conversation_id'] ?? null);

        if ($conversationId) {
            $ownsConversation = DB::table('agent_conversations')
                ->where('id', $conversationId)
                ->where('user_id', $user->id)
                ->exists();

            if (! $ownsConversation) {
                return $agent
                    ->forUser($user)
                    ->stream($message);
            }

            return $agent
                ->continue($conversationId, as: $user)
                ->stream($message);
        }

        return $agent
            ->forUser($user)
            ->stream($message);
    }

    /**
     * Rename a conversation.
     */
    public function rename(RenameAiConversationRequest $request, string $conversation): RedirectResponse
    {
        $user = $this->authUser();

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
        $user = $this->authUser();

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

    /**
     * @return array<string, mixed>|null
     */
    private function stringKeyedArray(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $items[$key] = $item;
            }
        }

        return $items;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
