<?php

declare(strict_types=1);

namespace FamilyFunds\D1Staging;

use Aws\CommandInterface;
use Aws\ResultInterface;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use LogicException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Throwable;

final class D1StagingStorageAdmission
{
    public function __construct(private readonly string $url, private readonly string $token)
    {
        D1RuntimeProfile::assertTarget('familyfunds-d1-web-staging', $url, $token);
    }

    /** @param callable(CommandInterface, RequestInterface): PromiseInterface $next */
    public function wrapAttempt(callable $next): Closure
    {
        return function (CommandInterface $command, RequestInterface $request) use ($next): PromiseInterface {
            if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
                return $next($command, $request);
            }

            // Presigning serializes the command without the transport signer. The eventual upload runs outside this disk.
            if (! $request->hasHeader('Authorization')) {
                throw new LogicException('Delegated staging writes are unavailable while R2 admission is enabled.');
            }

            $client = Http::withToken($this->token)->withoutRedirecting()->timeout(5);
            $response = $client->post($this->url.'/storage-write/begin');
            $id = $response->json('id');
            $receipt = $response->json('receipt');
            $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';
            if (! $response->ok() || ! is_string($id) || ! is_string($receipt) || ! preg_match($uuid, $id) || ! preg_match($uuid, $receipt)) {
                throw new RuntimeException('Staging R2 write admission unavailable.');
            }

            return $next($command, $request)->then(function (ResultInterface $result) use ($client, $id, $receipt): ResultInterface {
                $metadata = $result['@metadata'];
                $status = is_array($metadata) ? ($metadata['statusCode'] ?? null) : null;
                if (is_int($status) && $status >= 200 && $status < 300) {
                    try {
                        if ($client->withHeaders(['X-Storage-Operation' => $id, 'X-Storage-Receipt' => $receipt])
                            ->post($this->url.'/storage-write/complete')->noContent()) {
                            return $result;
                        }
                    } catch (Throwable) {
                        // R2 already confirmed success. The coordinator outcome must be inspected separately.
                    }
                }
                Log::warning('Staging R2 write acknowledgement unconfirmed.', ['operation_id' => $id]);

                return $result;
            });
        };
    }
}
