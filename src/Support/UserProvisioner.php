<?php

namespace ModernMcguire\Overwatch\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Finds or creates the local user for an SSO login, elevating new users to an
 * administrator where possible.
 *
 * Resolution order:
 *   1. A `provision_user` closure in config/mmp.php (always wins when set).
 *   2. The built-in default ladder:
 *        a. spatie/laravel-permission present  -> assign/create an "admin" role
 *        b. an is_admin / role column present  -> set it
 *        c. otherwise                          -> create a plain user
 */
class UserProvisioner
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function provision(array $claims): Authenticatable
    {
        $override = config('mmp.overwatch.provision_user');

        if (is_callable($override)) {
            return $override($claims);
        }

        return $this->default($claims);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function default(array $claims): Authenticatable
    {
        $email = (string) ($claims['sub'] ?? '');
        $name = (string) ($claims['name'] ?? Str::before($email, '@'));

        if ($email === '') {
            throw new RuntimeException('Overwatch token is missing the user email.');
        }

        $model = $this->userModel();
        $existing = $model->newQuery()->where('email', $email)->first();

        if ($existing instanceof Authenticatable) {
            return $existing;
        }

        $attributes = ['name' => $name, 'email' => $email];

        if (Schema::hasColumn($model->getTable(), 'password')) {
            $attributes['password'] = bcrypt(Str::random(40));
        }

        $user = $model->newQuery()->create($attributes);

        $this->elevate($user);

        return $user;
    }

    protected function elevate(Model $user): void
    {
        // (a) Spatie laravel-permission.
        if (method_exists($user, 'assignRole') && class_exists(\Spatie\Permission\Models\Role::class)) {
            \Spatie\Permission\Models\Role::findOrCreate('admin', 'web');
            $user->assignRole('admin');

            return;
        }

        // (b) A boolean flag or a role column on the users table.
        if (Schema::hasColumn($user->getTable(), 'is_admin')) {
            $user->forceFill(['is_admin' => true])->save();

            return;
        }

        if (Schema::hasColumn($user->getTable(), 'role')) {
            $user->forceFill(['role' => 'admin'])->save();

            return;
        }

        // (c) Plain user — nothing further to do.
    }

    protected function userModel(): Model
    {
        $class = config('auth.providers.users.model', \App\Models\User::class);

        return new $class;
    }
}
