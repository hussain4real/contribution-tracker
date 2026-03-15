<?php

use App\Enums\MemberCategory;

describe('MemberCategory Monthly Amounts', function () {
    it('employed category returns ₦4,000', function () {
        expect(MemberCategory::Employed->monthlyAmount())->toBe(4000);
    });

    it('unemployed category returns ₦2,000', function () {
        expect(MemberCategory::Unemployed->monthlyAmount())->toBe(2000);
    });

    it('student category returns ₦1,000', function () {
        expect(MemberCategory::Student->monthlyAmount())->toBe(1000);
    });

    it('formattedAmount returns correctly formatted currency string', function () {
        expect(MemberCategory::Employed->formattedAmount())->toBe('₦4,000');
        expect(MemberCategory::Unemployed->formattedAmount())->toBe('₦2,000');
        expect(MemberCategory::Student->formattedAmount())->toBe('₦1,000');
    });

    it('labelWithAmount combines label and amount', function () {
        expect(MemberCategory::Employed->labelWithAmount())->toBe('Employed (₦4,000/month)');
        expect(MemberCategory::Unemployed->labelWithAmount())->toBe('Unemployed (₦2,000/month)');
        expect(MemberCategory::Student->labelWithAmount())->toBe('Student (₦1,000/month)');
    });

    it('label returns human-readable name', function () {
        expect(MemberCategory::Employed->label())->toBe('Employed');
        expect(MemberCategory::Unemployed->label())->toBe('Unemployed');
        expect(MemberCategory::Student->label())->toBe('Student');
    });
});
