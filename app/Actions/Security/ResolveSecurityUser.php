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
            'email' => $this->byEmail($value),
            default => throw new InvalidArgumentException($this->formatMessage()),
        };
    }

    private function byId(string $value): ?User
    {
        $id = ltrim($value, '0');

        if (! ctype_digit($value) || $id === '' || (string) (int) $id !== $id) {
            throw new InvalidArgumentException($this->formatMessage());
        }

        return User::query()->find((int) $id);
    }

    private function byEmail(string $value): ?User
    {
        $users = User::query()->whereRaw('lower(email) = ?', [$value])->limit(2)->get();

        if ($users->count() > 1) {
            throw new InvalidArgumentException('More than one user matches this email address. Use id:123 instead.');
        }

        return $users->first();
    }

    private function formatMessage(): string
    {
        return 'Use id:123 or email:user@example.com.';
    }
}
