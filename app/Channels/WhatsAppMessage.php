<?php

namespace App\Channels;

/**
 * Fluent builder for a WhatsApp Cloud API outbound message payload.
 *
 * Supports both template messages (required for proactive notifications outside
 * the 24-hour customer service window) and plain text messages (only valid
 * within the 24-hour session window — typically used for replies).
 */
class WhatsAppMessage
{
    /**
     * Message kind: 'template' or 'text'.
     */
    protected string $kind = 'template';

    /**
     * Template name (when kind is 'template').
     */
    protected ?string $templateName = null;

    /**
     * Template language code.
     */
    protected string $languageCode = 'en';

    /**
     * Template body parameters in declaration order.
     *
     * @var array<int, string>
     */
    protected array $bodyParameters = [];

    /**
     * Template button components (e.g. for authentication copy-code buttons).
     *
     * @var array<int, array{sub_type: string, index: string, parameters: array<int, array{type: string, text: string}>}>
     */
    protected array $buttonComponents = [];

    /**
     * Plain text message body (when kind is 'text').
     */
    protected ?string $textBody = null;

    /**
     * Use a pre-approved WhatsApp template message.
     */
    public function template(string $name, string $languageCode = 'en'): self
    {
        $this->kind = 'template';
        $this->templateName = $name;
        $this->languageCode = $languageCode;

        return $this;
    }

    /**
     * Set the template language code.
     */
    public function language(string $code): self
    {
        $this->languageCode = $code;

        return $this;
    }

    /**
     * Set the template body parameters in declaration order.
     *
     * @param  array<int, string|int|float>  $parameters
     */
    public function body(array $parameters): self
    {
        $this->bodyParameters = array_map(fn ($value) => (string) $value, array_values($parameters));

        return $this;
    }

    /**
     * Add a button component for authentication templates (copy-code / URL).
     *
     * Meta's authentication templates require the OTP to be passed as both a
     * body parameter AND a button parameter with sub_type "url" at index 0.
     */
    public function button(string $subType, string $index, string $text): self
    {
        $this->buttonComponents[] = [
            'type' => 'button',
            'sub_type' => $subType,
            'index' => $index,
            'parameters' => [
                ['type' => 'text', 'text' => $text],
            ],
        ];

        return $this;
    }

    /**
     * Send a plain text message (only valid inside the 24h session window).
     */
    public function text(string $body): self
    {
        $this->kind = 'text';
        $this->textBody = $body;

        return $this;
    }

    /**
     * Build the JSON payload for the Graph API messages endpoint.
     *
     * @return array<string, mixed>
     */
    public function toPayload(string $to): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ];

        if ($this->kind === 'text') {
            return $payload + [
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $this->textBody ?? '',
                ],
            ];
        }

        $template = [
            'name' => $this->templateName,
            'language' => ['code' => $this->languageCode],
        ];

        $components = [];

        if ($this->bodyParameters !== []) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value) => ['type' => 'text', 'text' => $value],
                    $this->bodyParameters,
                ),
            ];
        }

        foreach ($this->buttonComponents as $button) {
            $components[] = $button;
        }

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $payload + [
            'type' => 'template',
            'template' => $template,
        ];
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTemplateName(): ?string
    {
        return $this->templateName;
    }

    public function getLanguageCode(): string
    {
        return $this->languageCode;
    }

    /**
     * @return array<int, string>
     */
    public function getBodyParameters(): array
    {
        return $this->bodyParameters;
    }

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }
}
