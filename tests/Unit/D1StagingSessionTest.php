<?php

declare(strict_types=1);

use FamilyFunds\D1Staging\D1StagingSessionHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Client\Request;
use Illuminate\Session\EncryptedStore;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class);

it('keeps encrypted login state across independent runtime instances and destroys it on logout', function (): void {
    require_once __DIR__.'/../../deployment/d1-proof/autoload.php';
    config(['d1-staging.enabled' => true]);
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('create table sessions(id text primary key,payload text,last_activity integer)');
    Http::fake(function (Request $request) use ($pdo): PromiseInterface {
        $sql = $request['sql'];
        $params = $request['params'];
        assert(is_string($sql) && is_array($params));
        $statement = $pdo->prepare($sql);
        $statement->execute($params);

        return Http::response(['database' => 'familyfunds-d1-web-staging', 'result' => ['results' => $statement->fetchAll(PDO::FETCH_ASSOC)]]);
    });
    $key = new Encrypter(random_bytes(32), 'AES-256-CBC');
    $handler = fn (): D1StagingSessionHandler => new D1StagingSessionHandler('https://familyfunds-d1-staging-sql.familyfunds-lab.workers.dev', 'synthetic-token', 'familyfunds-d1-web-staging', 120);
    $first = new EncryptedStore('d1-staging', $handler(), $key);
    $id = str_repeat('a', 40);
    $first->setId($id);
    $first->start();
    $first->put('login_user', 'synthetic-private-session');
    $first->save();
    $statement = $pdo->query('select payload from sessions');
    assert($statement !== false);
    $payload = $statement->fetchColumn();
    assert(is_string($payload));
    $encrypted = base64_decode($payload, true);
    assert(is_string($encrypted));
    expect($key->decrypt($encrypted))->toContain('synthetic-private-session');
    $second = new EncryptedStore('d1-staging', $handler(), $key);
    $second->setId($id);
    $second->start();
    expect($second->get('login_user'))->toBe('synthetic-private-session');
    $handler()->destroy($id);
    expect($handler()->read($id))->toBe('');
});
