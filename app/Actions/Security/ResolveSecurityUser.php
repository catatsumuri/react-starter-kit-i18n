<?php

namespace App\Actions\Security;

use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ResolveSecurityUser
{
    public function handle(string $selector): ?User
    {
        [$type, $value] = array_pad(explode(':', trim($selector), 2), 2, null);

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException($this->formatMessage());
        }

        $value = Str::lower(trim($value));

        return match ($type) {
            'id' => $this->byId($value),
            'email' => User::query()->where('email', $value)->first(),
            default => throw new InvalidArgumentException($this->formatMessage()),
        };
    }

    private function byId(string $value): ?User
    {
        if (! ctype_digit($value) || (int) $value < 1) {
            throw new InvalidArgumentException($this->formatMessage());
        }

        return User::query()->find((int) $value);
    }

    private function formatMessage(): string
    {
        return 'Use id:123 or email:user@example.com.';
    }
}
