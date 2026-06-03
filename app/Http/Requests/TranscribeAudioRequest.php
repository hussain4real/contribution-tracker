<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class TranscribeAudioRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'audio' => ['required', 'file', 'max:10240', 'mimetypes:audio/webm,video/webm,audio/mp4,video/mp4,application/mp4,audio/mpeg,audio/mp3,audio/ogg,audio/wav,audio/flac,audio/x-m4a,audio/x-flac,application/octet-stream'],
            'duration_seconds' => ['nullable', 'numeric', 'min:0', 'max:60'],
            'audio_level' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'chunk_count' => ['nullable', 'integer', 'min:0'],
            'client_mime_type' => ['nullable', 'string', 'max:100'],
        ];
    }
}
