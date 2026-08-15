<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Updating a profile.
 *
 * The panel owns the *screen*; this owns the write. Keeping the write here
 * means there is exactly one place a profile is updated, whether the request
 * came from the panel's settings page or from a starter kit form that was
 * never migrated.
 *
 * Only validated attributes are assigned, so a field the form never offered —
 * `is_admin`, say — cannot arrive by being added to the request.
 */
final class ProfileController
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique(User::class)->ignore($user->id),
            ],
        ]);

        $user->fill($validated);

        // Changing an address un-verifies it. The old one is no longer proof
        // of anything, and the new one has not been proven yet.
        if ($user->isDirty('email') && $user instanceof MustVerifyEmail) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('success', 'Profile updated.');
    }
}
