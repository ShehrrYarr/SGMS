<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Owners switch the working branch (or "all branches") for the session.
 * Stored per tenant so the choice never leaks across shops.
 */
class BranchSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user->isOwner(), 403);

        $branch = $request->input('branch');
        $context = tenancy();

        if ($branch !== 'all' && ! $context->branches()->contains('id', (int) $branch)) {
            abort(404);
        }

        $request->session()->put('branch_id.'.$user->tenant_id, $branch === 'all' ? 'all' : (int) $branch);

        $label = $branch === 'all' ? 'All branches' : $context->branches()->firstWhere('id', (int) $branch)->name;

        return back()->with('success', "Now working in: {$label}");
    }
}
