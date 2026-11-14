<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Persistence\Eloquent;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';
}
