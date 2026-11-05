<?php

declare(strict_types=1);

use App\Models\Expense;
use App\Models\ExpenseDocument;
use App\Models\ExpenseRequest;
use App\Models\PlatformPlan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

it('opens a monthly dashboard total and filters the supporting financial entries', function () {
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    Expense::factory()->recordedBy($admin)->create([
        'description' => 'Accessible transport records',
        'amount' => 1250,
        'spent_at' => now()->toDateString(),
    ]);
    $page = loginBrowserAs($admin);
    $page->assertSee('Money movement')
        ->click('View transactions and activity')
        ->assertSee('Family financial activity')
        ->assertSee('Accessible transport records')
        ->assertSee('Expense Posted')
        ->assertNoJavaScriptErrors();
    $page->fill('Search descriptions or activity', 'absent record')
        ->click('Apply filters')
        ->assertSee('No transactions match these filters.')
        ->assertNoJavaScriptErrors();
});

it('submits an expense request then independently approves and pays it', function () {
    Storage::fake('local');
    $family = createBrowserFamily();
    $owner = createBrowserAdmin($family, ['email' => 'governance-owner@example.test']);
    $requester = createBrowserFinancialSecretary($family, ['email' => 'governance-requester@example.test']);
    $plan = PlatformPlan::query()->firstOrCreate(['slug' => 'organization'], [
        'name' => 'Organization', 'price' => 1000, 'features' => [], 'is_active' => true,
    ]);
    $family->update(['created_by' => $owner->id, 'platform_plan_id' => $plan->id,
        'subscription_status' => 'active',
        'expense_approval_settings' => ['enabled' => true, 'threshold' => 1000, 'approvers' => 1]]);
    $page = loginBrowserAs($requester);
    $page->resize(390, 844)->navigate(route('expense-requests.index'))
        ->assertSee('Submit an expense request')
        ->fill('Amount', '2000')
        ->fill('Description', 'School repair estimate')
        ->fill('Expense date', now()->toDateString())
        ->click('Submit request')
        ->assertSee('School repair estimate')
        ->assertDontSee('Approve request #')
        ->assertDontSee('Record payment for request #');
    expect(ExpenseRequest::count())->toBe(1)->and(Expense::count())->toBe(0);
    $reviewer = loginBrowserAs($owner);
    $reviewer->navigate(route('expense-requests.index'))
        ->assertSee('School repair estimate')
        ->click('button[aria-label="Approve expense request 1"]')
        ->assertSee('1 of 1 independent approvals');
    expect(Expense::count())->toBe(0);
    $reviewer->script('() => { window.confirm = () => true; }');
    $reviewer->click('button[aria-label="Record payment for expense request 1"]')
        ->assertSee('Posted expense #')
        ->assertNoJavaScriptErrors();
    expect(Expense::count())->toBe(1);
    $reviewer->refresh()->assertDontSee('Record payment for request #1');
});

it('saves financial visibility and restricts member navigation after a settings change', function () {
    Cache::put('paystack_banks', [], now()->addDay());
    $family = createBrowserFamily();
    $admin = createBrowserAdmin($family);
    $member = createBrowserMember($family);
    $family->update(['created_by' => $admin->id]);
    $page = loginBrowserAs($admin);
    $page->navigate(route('family.settings'));
    $page->assertSee('Financial visibility and approvals');
    $page->select('#member-financial-visibility', 'personal');
    $page->click('Save financial settings');
    $page->assertSee('Financial visibility and approval settings saved.')
        ->assertNoJavaScriptErrors();
    expect($family->refresh()->member_financial_visibility)->toBe('personal');
    loginBrowserAs($member)->assertDontSee('Family Fund Balance')->assertDontSee('View family transactions and activity');
});

it('sends the selected document and retains its upload key when the network fails', function () {
    Storage::fake('local');
    $family = createBrowserFamily();
    $owner = createBrowserAdmin($family);
    ExpenseRequest::factory()->create(['family_id' => $family->id, 'requested_by' => $owner->id]);
    Storage::disk('local')->put('invoice.pdf', "%PDF-1.4\nSynthetic invoice\n%%EOF");
    $path = Storage::disk('local')->path('invoice.pdf');
    $page = loginBrowserAs($owner);
    $page->navigate(route('expense-requests.index'))->assertSee('Upload document');
    $page->script(<<<'JS'
() => {
    const original = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (body) {
        if (body instanceof FormData && body.has('document')) {
            sessionStorage.setItem('synthetic-upload', JSON.stringify({
                filename: body.get('document')?.name,
                target: body.get('expense_request_id'),
                key: body.get('idempotency_key'),
            }));
            this.abort();
            this.dispatchEvent(new ProgressEvent('error'));
            return;
        }
        return original.call(this, body);
    };
}
JS);
    $page->attach('input[type="file"]', $path)->click('Upload document')
        ->assertSee('The upload may have succeeded.')
        ->assertNoJavaScriptErrors();
    $sent = $page->script('() => JSON.parse(sessionStorage.getItem("synthetic-upload"))');
    expect($sent)->toBeArray()->toHaveKeys(['filename', 'target', 'key']);
    if (! is_array($sent)) {
        throw new RuntimeException('Expected the synthetic multipart request details.');
    }
    expect($sent['filename'])->toBe('invoice.pdf')->and($sent['target'])->toBe('1');
    $page->click('Upload document')->assertSee('The upload may have succeeded.');
    expect($page->script('() => JSON.parse(sessionStorage.getItem("synthetic-upload")).key'))->toBe($sent['key']);
    expect(ExpenseDocument::count())->toBe(0);
});
