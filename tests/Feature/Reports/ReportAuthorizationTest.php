<?php

declare(strict_types=1);

use App\Models\Family;
use App\Models\User;

describe('Report Authorization', function () {
    describe('Report Index', function () {
        it('allows super admin to access reports index', function () {
            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->get('/reports')
                ->assertOk();
        });

        it('allows financial secretary to access reports index', function () {
            $fs = User::factory()->financialSecretary()->create();

            $this->actingAs($fs)
                ->get('/reports')
                ->assertOk();
        });

        it('forbids regular members from accessing reports index', function () {
            $member = User::factory()->member()->create();

            $this->actingAs($member)
                ->get('/reports')
                ->assertForbidden();
        });

        it('requires authentication', function () {
            $family = Family::factory()->create();

            $this->get("/{$family->slug}/reports")
                ->assertRedirect();
        });
    });

    describe('Monthly Report', function () {
        it('allows super admin to access monthly report', function () {
            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->get('/reports/monthly')
                ->assertOk();
        });

        it('allows financial secretary to access monthly report', function () {
            $fs = User::factory()->financialSecretary()->create();

            $this->actingAs($fs)
                ->get('/reports/monthly')
                ->assertOk();
        });

        it('forbids regular members from accessing monthly report', function () {
            $member = User::factory()->member()->create();

            $this->actingAs($member)
                ->get('/reports/monthly')
                ->assertForbidden();
        });

        it('requires authentication for monthly report', function () {
            $family = Family::factory()->create();

            $this->get("/{$family->slug}/reports/monthly")
                ->assertRedirect();
        });
    });

    describe('Annual Report', function () {
        it('allows super admin to access annual report', function () {
            $admin = User::factory()->admin()->create();

            $this->actingAs($admin)
                ->get('/reports/annual')
                ->assertOk();
        });

        it('allows financial secretary to access annual report', function () {
            $fs = User::factory()->financialSecretary()->create();

            $this->actingAs($fs)
                ->get('/reports/annual')
                ->assertOk();
        });

        it('forbids regular members from accessing annual report', function () {
            $member = User::factory()->member()->create();

            $this->actingAs($member)
                ->get('/reports/annual')
                ->assertForbidden();
        });

        it('requires authentication for annual report', function () {
            $family = Family::factory()->create();

            $this->get("/{$family->slug}/reports/annual")
                ->assertRedirect();
        });
    });
});
