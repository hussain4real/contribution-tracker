<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\ExpenseDocument;
use App\Models\ExpenseRequest;
use App\Models\Family;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    $this->family = Family::factory()->create();
    $this->officer = User::factory()->admin()->create(['family_id' => $this->family->id]);
    $this->expense = Expense::factory()->recordedBy($this->officer)->create();
    $this->key = (string) Str::uuid();
});

it('stores private supporting files and streams them only to officers in the family', function () {
    $file = UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\nSynthetic document\n%%EOF");
    $this->actingAs($this->officer)->postJson(route('expense-documents.store'), [
        'expense_id' => $this->expense->id, 'idempotency_key' => $this->key, 'document' => $file,
    ])->assertRedirect();
    $document = ExpenseDocument::sole();
    expect($document->ready)->toBeTrue()->and(Storage::disk('local')->getVisibility($document->path))->toBe('private');
    $response = $this->get(route('expense-documents.download', $document));
    $response->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertDownload('invoice.pdf');
    expect($response->streamedContent())->toBe(file_get_contents($file->getPathname()));
    $this->postJson(route('expense-documents.store'), [
        'expense_id' => $this->expense->id, 'idempotency_key' => $this->key, 'document' => $file,
    ])->assertRedirect();
    expect(ExpenseDocument::count())->toBe(1)->and(AuditEvent::query()->where('action', 'expense_document.added')->count())->toBe(1);
    $member = User::factory()->member()->create(['family_id' => $this->family->id]);
    $this->actingAs($member)->get(route('expense-documents.download', $document))->assertForbidden();
    $other = User::factory()->admin()->create();
    $this->actingAs($other)->get(route('expense-documents.download', $document))->assertForbidden();
    $this->postJson(route('expense-documents.store'), ['expense_id' => $this->expense->id,
        'idempotency_key' => (string) Str::uuid(), 'document' => $file])->assertNotFound();
});

it('validates file contents extension size and exactly one target', function () {
    $this->actingAs($this->officer);
    foreach ([
        UploadedFile::fake()->createWithContent('bad.pdf', '<script>bad</script>'),
        UploadedFile::fake()->create('large.pdf', 10241, 'application/pdf'),
        UploadedFile::fake()->create('bad.exe', 1, 'application/pdf'),
    ] as $file) {
        $response = $this->postJson(route('expense-documents.store'), ['expense_id' => $this->expense->id,
            'idempotency_key' => $this->key, 'document' => $file]);
        $response->assertUnprocessable();
    }
    $file = UploadedFile::fake()->image('receipt.png');
    $this->postJson(route('expense-documents.store'), ['document' => $file, 'idempotency_key' => $this->key])->assertUnprocessable();
    $this->postJson(route('expense-documents.store'), ['document' => $file, 'idempotency_key' => $this->key,
        'expense_id' => $this->expense->id, 'expense_request_id' => 1])->assertUnprocessable();
    expect(ExpenseDocument::count())->toBe(0);
});

it('attaches to pending requests without posting money and rejects changed replay content', function () {
    $request = ExpenseRequest::factory()->create(['family_id' => $this->family->id, 'requested_by' => $this->officer->id]);
    $this->actingAs($this->officer)->postJson(route('expense-documents.store'), [
        'expense_request_id' => $request->id, 'idempotency_key' => $this->key, 'document' => UploadedFile::fake()->image('receipt.png'),
    ])->assertRedirect();
    expect(ExpenseDocument::sole()->expense_request_id)->toBe($request->id)->and(Expense::count())->toBe(1);
    $this->postJson(route('expense-documents.store'), [
        'expense_id' => $this->expense->id, 'idempotency_key' => $this->key, 'document' => UploadedFile::fake()->image('other.png'),
    ])->assertUnprocessable();
    $document = ExpenseDocument::sole();
    $document->update(['ready' => false]);
    $this->get(route('expense-documents.download', $document))->assertNotFound();
});

it('resumes an interrupted storage write using the same document record', function () {
    $file = UploadedFile::fake()->image('receipt.png');
    $manager = Storage::getFacadeRoot();
    $adapter = Storage::disk('local');
    $failing = Mockery::mock($adapter);
    $failing->shouldReceive('put')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('local')->andReturn($failing);
    $payload = ['expense_id' => $this->expense->id, 'idempotency_key' => $this->key, 'document' => $file];
    $this->actingAs($this->officer)->postJson(route('expense-documents.store'), $payload)->assertUnprocessable();
    $document = ExpenseDocument::sole();
    expect($document->ready)->toBeFalse();
    $this->get(route('expense-documents.download', $document))->assertNotFound();
    Storage::swap($manager);
    $this->postJson(route('expense-documents.store'), $payload)->assertRedirect();
    expect(ExpenseDocument::count())->toBe(1)->and($document->refresh()->ready)->toBeTrue();
});

it('rejects a permitted extension that disagrees with the actual MIME type', function () {
    $png = UploadedFile::fake()->image('source.png');
    $mismatched = UploadedFile::fake()->createWithContent('receipt.pdf', $png->getContent());
    $this->actingAs($this->officer)->postJson(route('expense-documents.store'), [
        'expense_id' => $this->expense->id, 'idempotency_key' => $this->key, 'document' => $mismatched,
    ])->assertUnprocessable()->assertJsonValidationErrors('document');
    expect(ExpenseDocument::count())->toBe(0);
});

it('cleans up an in-flight upload if its family was purged before finalization', function () {
    $file = UploadedFile::fake()->image('receipt.png');
    $adapter = Storage::disk('local');
    $proxy = Mockery::mock($adapter);
    $proxy->shouldReceive('put')->with(Mockery::on(function (string $path) use ($adapter, $file): bool {
        $adapter->put($path, $file->getContent(), ['visibility' => 'private']);
        DB::table('users')->where('family_id', $this->family->id)->update(['family_id' => null, 'current_family_id' => null]);
        Family::withoutEvents(fn () => $this->family->delete());

        return true;
    }), Mockery::any(), Mockery::any())->once()->andReturn(true);
    Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
    $this->actingAs($this->officer)->postJson(route('expense-documents.store'), [
        'expense_id' => $this->expense->id, 'idempotency_key' => $this->key, 'document' => $file,
    ])->assertNotFound();
    expect(ExpenseDocument::count())->toBe(0)->and($adapter->allFiles())->toBe([]);
});
