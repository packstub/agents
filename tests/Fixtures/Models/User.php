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

    /**
     * A workspace is the team the person owns (what the context asks before entering one, and again on every tool
     * call). Read from the table as it is now, the way a pivot query would, so a change of owner mid-turn shows.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Team && Team::query()->whereKey($tenant->getKey())->where('owner_id', $this->getKey())->exists();
    }
}
