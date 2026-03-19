<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename super_admin role to admin in the users table
        DB::table('users')
            ->where('role', 'super_admin')
            ->update(['role' => 'admin']);

        // Create a default family for existing data if users exist
        if (DB::table('users')->exists()) {
            $familyId = DB::table('families')->insertGetId([
                'name' => 'Hussain Family',
                'slug' => 'hussain-family',
                'currency' => 'NGN',
                'due_day' => 28,
                'created_by' => DB::table('users')->where('role', 'admin')->value('id'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Create default categories matching the old MemberCategory enum
            $categories = [
                ['family_id' => $familyId, 'name' => 'Employed', 'slug' => 'employed', 'monthly_amount' => 4000, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['family_id' => $familyId, 'name' => 'Unemployed', 'slug' => 'unemployed', 'monthly_amount' => 2000, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
                ['family_id' => $familyId, 'name' => 'Student', 'slug' => 'student', 'monthly_amount' => 1000, 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
            ];

            DB::table('family_categories')->insert($categories);

            // Map users to default family and assign family_category_id
            DB::table('users')->update(['family_id' => $familyId]);

            $categoryMap = DB::table('family_categories')
                ->where('family_id', $familyId)
                ->pluck('id', 'slug');

            foreach ($categoryMap as $slug => $categoryId) {
                DB::table('users')
                    ->where('category', $slug)
                    ->update(['family_category_id' => $categoryId]);
            }

            // Assign family_id to contributions
            DB::table('contributions')->update(['family_id' => $familyId]);

            // Backfill due_date on existing contributions
            $contributions = DB::table('contributions')->get(['id', 'year', 'month']);
            foreach ($contributions as $contribution) {
                $dueDate = sprintf('%04d-%02d-%02d', $contribution->year, $contribution->month, 28);
                DB::table('contributions')->where('id', $contribution->id)->update(['due_date' => $dueDate]);
            }

            // Assign family_id to expenses and fund_adjustments
            DB::table('expenses')->update(['family_id' => $familyId]);
            DB::table('fund_adjustments')->update(['family_id' => $familyId]);
        }
    }

    public function down(): void
    {
        // Rename admin role back to super_admin
        DB::table('users')
            ->where('role', 'admin')
            ->update(['role' => 'super_admin']);

        // Clear family associations
        DB::table('users')->update(['family_id' => null, 'family_category_id' => null]);
        DB::table('contributions')->update(['family_id' => null, 'due_date' => null]);
        DB::table('expenses')->update(['family_id' => null]);
        DB::table('fund_adjustments')->update(['family_id' => null]);

        // Remove default family and categories
        DB::table('families')->where('slug', 'hussain-family')->delete();
    }
};
