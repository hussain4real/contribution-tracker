<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('can access the privacy policy page', function () {
    $this->get('/privacy')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Legal/Privacy'));
});

it('can access the terms of service page', function () {
    $this->get('/terms')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Legal/Terms'));
});

it('can access the data deletion page', function () {
    $this->get('/data-deletion')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->component('Legal/DataDeletion'));
});
