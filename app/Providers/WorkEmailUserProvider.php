<?php

namespace App\Providers;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

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
}