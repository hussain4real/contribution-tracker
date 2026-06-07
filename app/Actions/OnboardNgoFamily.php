<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\MemberCategory;
use App\Enums\Role;
use App\Mail\NgoOnboardingCredentialsMail;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\User;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as LaravelValidator;

/**
 * @phpstan-type ValidatedNgoInput array{
 *     name: string,
 *     due_day: int,
 *     admin_name: string,
 *     admin_email: string,
 *     admin_whatsapp: string,
 *     financial_secretary_name: string,
 *     financial_secretary_email: string,
 *     financial_secretary_whatsapp: string,
 *     send_email: bool,
 *     send_whatsapp: bool
 * }
 * @phpstan-type WhatsappDelivery array{success: bool, wa_message_id: string|null, error: string|null}
 * @phpstan-type OnboardingResult array{
 *     family: Family,
 *     category: FamilyCategory,
 *     users: array{admin: User, financial_secretary: User},
 *     credentials: array{admin: array{email: string, password: string}, financial_secretary: array{email: string, password: string}},
 *     deliveries: array{email: array<string, bool>, whatsapp: array<string, WhatsappDelivery>}
 * }
 */
class OnboardNgoFamily
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return OnboardingResult
     *
     * @throws ValidationException
     */
    public function execute(array $input): array
    {
        $validated = $this->validate($input);
        $passwords = [
            'admin' => $this->temporaryPassword(),
            'financial_secretary' => $this->temporaryPassword(),
        ];

        /** @var array{family: Family, category: FamilyCategory, users: array{admin: User, financial_secretary: User}} $created */
        $created = DB::transaction(fn (): array => $this->createTenant($validated, $passwords));

        $loginUrl = route('login');
        $sendEmail = $validated['send_email'];
        $sendWhatsapp = $validated['send_whatsapp'];

        /** @var array{email: array<string, bool>, whatsapp: array<string, WhatsappDelivery>} $deliveries */
        $deliveries = [
            'email' => [],
            'whatsapp' => [],
        ];

        foreach ($created['users'] as $key => $user) {
            $password = $passwords[$key];

            if ($sendEmail) {
                Mail::to($user->email)->send(new NgoOnboardingCredentialsMail(
                    user: $user,
                    family: $created['family'],
                    temporaryPassword: $password,
                    loginUrl: $loginUrl,
                ));

                $deliveries['email'][$key] = true;
            } else {
                $deliveries['email'][$key] = false;
            }

            if ($sendWhatsapp) {
                $deliveries['whatsapp'][$key] = $this->whatsapp->sendOnboardingNotice(
                    to: (string) $user->whatsapp_phone,
                    name: $user->name,
                    familyName: $created['family']->name,
                    roleLabel: $user->role->label(),
                    email: $user->email,
                    loginUrl: $loginUrl,
                );
            } else {
                $deliveries['whatsapp'][$key] = [
                    'success' => false,
                    'wa_message_id' => null,
                    'error' => 'WhatsApp delivery skipped.',
                ];
            }
        }

        return [
            ...$created,
            'credentials' => [
                'admin' => [
                    'email' => $created['users']['admin']->email,
                    'password' => $passwords['admin'],
                ],
                'financial_secretary' => [
                    'email' => $created['users']['financial_secretary']->email,
                    'password' => $passwords['financial_secretary'],
                ],
            ],
            'deliveries' => $deliveries,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     name: string,
     *     due_day: int,
     *     admin_name: string,
     *     admin_email: string,
     *     admin_whatsapp: string,
     *     financial_secretary_name: string,
     *     financial_secretary_email: string,
     *     financial_secretary_whatsapp: string,
     *     send_email: bool,
     *     send_whatsapp: bool
     * }
     *
     * @phpstan-return ValidatedNgoInput
     *
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'admin_whatsapp' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'financial_secretary_name' => ['required', 'string', 'max:255'],
            'financial_secretary_email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'financial_secretary_whatsapp' => ['required', 'string', 'regex:/^\+[1-9]\d{6,14}$/'],
            'send_email' => ['sometimes', 'boolean'],
            'send_whatsapp' => ['sometimes', 'boolean'],
        ], [
            'admin_whatsapp.regex' => 'Enter the admin WhatsApp number in international format, e.g. +97412345678.',
            'financial_secretary_whatsapp.regex' => 'Enter the financial secretary WhatsApp number in international format, e.g. +97412345678.',
        ]);

        $validator->after(function (LaravelValidator $validator) use ($input): void {
            if (($input['admin_email'] ?? null) === ($input['financial_secretary_email'] ?? null)) {
                $validator->errors()->add('financial_secretary_email', 'The financial secretary must use a different email address.');
            }

            if (($input['admin_whatsapp'] ?? null) === ($input['financial_secretary_whatsapp'] ?? null)) {
                $validator->errors()->add('financial_secretary_whatsapp', 'The financial secretary must use a different WhatsApp number.');
            }
        });

        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return [
            'name' => $this->stringValue($validated['name'] ?? ''),
            'due_day' => $this->integerValue($validated['due_day'] ?? 28, 28),
            'admin_name' => $this->stringValue($validated['admin_name'] ?? ''),
            'admin_email' => $this->stringValue($validated['admin_email'] ?? ''),
            'admin_whatsapp' => $this->stringValue($validated['admin_whatsapp'] ?? ''),
            'financial_secretary_name' => $this->stringValue($validated['financial_secretary_name'] ?? ''),
            'financial_secretary_email' => $this->stringValue($validated['financial_secretary_email'] ?? ''),
            'financial_secretary_whatsapp' => $this->stringValue($validated['financial_secretary_whatsapp'] ?? ''),
            'send_email' => $this->booleanValue($validated['send_email'] ?? true),
            'send_whatsapp' => $this->booleanValue($validated['send_whatsapp'] ?? true),
        ];
    }

    /**
     * @param  ValidatedNgoInput  $input
     * @param  array{admin: string, financial_secretary: string}  $passwords
     * @return array{family: Family, category: FamilyCategory, users: array{admin: User, financial_secretary: User}}
     */
    private function createTenant(array $input, array $passwords): array
    {
        $family = Family::create([
            'name' => $input['name'],
            'slug' => Str::slug($input['name']).'-'.Str::lower(Str::random(5)),
            'currency' => 'QAR',
            'due_day' => $input['due_day'],
        ]);

        $category = FamilyCategory::create([
            'family_id' => $family->id,
            'name' => 'Monthly Dues',
            'slug' => 'monthly-dues',
            'monthly_amount' => 100,
            'sort_order' => 0,
        ]);

        $admin = $this->createUser(
            family: $family,
            role: Role::Admin,
            name: $input['admin_name'],
            email: $input['admin_email'],
            whatsapp: $input['admin_whatsapp'],
            password: $passwords['admin'],
            categoryId: null,
        );

        $family->update(['created_by' => $admin->id]);

        $financialSecretary = $this->createUser(
            family: $family,
            role: Role::FinancialSecretary,
            name: $input['financial_secretary_name'],
            email: $input['financial_secretary_email'],
            whatsapp: $input['financial_secretary_whatsapp'],
            password: $passwords['financial_secretary'],
            categoryId: $category->id,
        );

        return [
            'family' => $family,
            'category' => $category,
            'users' => [
                'admin' => $admin,
                'financial_secretary' => $financialSecretary,
            ],
        ];
    }

    private function createUser(
        Family $family,
        Role $role,
        string $name,
        string $email,
        string $whatsapp,
        string $password,
        ?int $categoryId,
    ): User {
        return User::create([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $password,
            'must_change_password_at' => now(),
            'role' => $role,
            'category' => $categoryId === null ? null : MemberCategory::Employed,
            'family_id' => $family->id,
            'family_category_id' => $categoryId,
            'whatsapp_phone' => $whatsapp,
            'whatsapp_verified_at' => now(),
        ]);
    }

    private function temporaryPassword(): string
    {
        return 'Temp9'.Str::password(length: 12, symbols: false);
    }

    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private function integerValue(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function booleanValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }
}
