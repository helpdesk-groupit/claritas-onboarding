<?php

namespace App\Providers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;

class WorkEmailUserProvider extends EloquentUserProvider
{
    /**
     * Called by the password broker when looking up a user.
     * Laravel always passes ['email' => ...] as the key.
     * We remap it to ['work_email' => ...] so the DB query uses the right column.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        if (isset($credentials['email'])) {
            $credentials['work_email'] = $credentials['email'];
            unset($credentials['email']);
        }

        return parent::retrieveByCredentials($credentials);
    }

    /**
     * Validate a user against the given credentials with constant-time behaviour.
     * Performs a dummy hash check when the user is null to prevent timing-based
     * user enumeration (OWASP A07).
     */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        $plain = $credentials['password'] ?? '';

        return Hash::check($plain, $user->getAuthPassword());
    }
}