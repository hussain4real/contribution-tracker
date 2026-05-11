<?php

use App\Channels\WhatsAppMessage;

it('builds text message payloads', function () {
    $message = (new WhatsAppMessage)->text('Hello from the family fund.');

    expect($message->getKind())->toBe('text')
        ->and($message->getTextBody())->toBe('Hello from the family fund.')
        ->and($message->toPayload('2348012345678'))->toBe([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => '2348012345678',
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => 'Hello from the family fund.',
            ],
        ]);
});

it('builds template message payloads with body and button components', function () {
    $message = (new WhatsAppMessage)
        ->template('verify_whatsapp', 'en_GB')
        ->language('en_US')
        ->body(['Amisha', 123456, 45.67])
        ->button('url', '0', '123456');

    $payload = $message->toPayload('2348012345678');

    expect($message->getKind())->toBe('template')
        ->and($message->getTemplateName())->toBe('verify_whatsapp')
        ->and($message->getLanguageCode())->toBe('en_US')
        ->and($message->getBodyParameters())->toBe(['Amisha', '123456', '45.67'])
        ->and($payload['type'])->toBe('template')
        ->and($payload['template']['name'])->toBe('verify_whatsapp')
        ->and($payload['template']['language']['code'])->toBe('en_US')
        ->and($payload['template']['components'])->toHaveCount(2)
        ->and($payload['template']['components'][0])->toBe([
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'Amisha'],
                ['type' => 'text', 'text' => '123456'],
                ['type' => 'text', 'text' => '45.67'],
            ],
        ])
        ->and($payload['template']['components'][1])->toBe([
            'type' => 'button',
            'sub_type' => 'url',
            'index' => '0',
            'parameters' => [
                ['type' => 'text', 'text' => '123456'],
            ],
        ]);
});

it('omits template components when no parameters are provided', function () {
    $payload = (new WhatsAppMessage)
        ->template('contribution_reminder')
        ->toPayload('2348012345678');

    expect($payload['template'])
        ->toHaveKey('name', 'contribution_reminder')
        ->not->toHaveKey('components');
});
