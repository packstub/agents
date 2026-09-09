<?php

namespace Packstub\Agents\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    protected $guarded = [];

    protected $casts = ['is_admin' => 'bool'];

    /** A workspace is the team the person owns (what the context asks before entering one). */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Team && (int) $tenant->owner_id === (int) $this->getKey();
    }
}
