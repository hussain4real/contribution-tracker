<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MemberController extends Controller
{
    /**
     * Display a listing of family members.
     */
    public function index(): Response
    {
        // TODO: Implement with pagination and filters
        return Inertia::render('Members/Index');
    }

    /**
     * Show the form for creating a new family member.
     */
    public function create(): Response
    {
        // TODO: Implement with category options
        return Inertia::render('Members/Create');
    }

    /**
     * Store a newly created family member.
     */
    public function store(Request $request): RedirectResponse
    {
        // TODO: Implement with validation
        return redirect()->route('members.index');
    }

    /**
     * Display the specified family member.
     */
    public function show(User $member): Response
    {
        // TODO: Implement with contribution history
        return Inertia::render('Members/Show', [
            'member' => $member,
        ]);
    }

    /**
     * Show the form for editing the specified family member.
     */
    public function edit(User $member): Response
    {
        // TODO: Implement with category and role options
        return Inertia::render('Members/Edit', [
            'member' => $member,
        ]);
    }

    /**
     * Update the specified family member.
     */
    public function update(Request $request, User $member): RedirectResponse
    {
        // TODO: Implement with validation
        return redirect()->route('members.show', $member);
    }

    /**
     * Archive the specified family member (soft delete).
     */
    public function destroy(User $member): RedirectResponse
    {
        // TODO: Implement soft delete via archived_at
        return redirect()->route('members.index');
    }

    /**
     * Restore an archived family member.
     */
    public function restore(User $member): RedirectResponse
    {
        // TODO: Implement restore
        return redirect()->route('members.show', $member);
    }
}
