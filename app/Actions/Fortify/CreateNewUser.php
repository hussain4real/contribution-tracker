<?php

namespace App\Actions\Fortify;

use App\Enums\Role;
use App\Models\Family;
use App\Models\FamilyCategory;
use App\Models\FamilyInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
            ],
            'password' => $this->passwordRules(),
            'family_name' => ['required_without:invitation_token', 'nullable', 'string', 'max:255'],
            'invitation_token' => ['nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($input) {
            // If joining via invitation
            if (! empty($input['invitation_token'])) {
                return $this->createViaInvitation($input);
            }

            // Otherwise, create a new family
            return $this->createWithNewFamily($input);
        });
    }

    private function createWithNewFamily(array $input): User
    {
        $familyName = $input['family_name'];

        $family = Family::create([
            'name' => $familyName,
            'slug' => Str::slug($familyName).'-'.Str::random(5),
            'currency' => '₦',
            'due_day' => 28,
        ]);

        // Create default categories
        $defaultCategories = [
            ['name' => 'Employed', 'slug' => 'employed', 'monthly_amount' => 4000, 'sort_order' => 0],
            ['name' => 'Unemployed', 'slug' => 'unemployed', 'monthly_amount' => 2000, 'sort_order' => 1],
            ['name' => 'Student', 'slug' => 'student', 'monthly_amount' => 1000, 'sort_order' => 2],
        ];

        foreach ($defaultCategories as $category) {
            FamilyCategory::create([...$category, 'family_id' => $family->id]);
        }

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => Role::Admin,
            'family_id' => $family->id,
        ]);

        $family->update(['created_by' => $user->id]);

        return $user;
    }

    private function createViaInvitation(array $input): User
    {
        $invitation = FamilyInvitation::query()
            ->where('token', $input['invitation_token'])
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => $invitation->role,
            'family_id' => $invitation->family_id,
        ]);

        $invitation->update(['accepted_at' => now()]);

        return $user;
    }
}
