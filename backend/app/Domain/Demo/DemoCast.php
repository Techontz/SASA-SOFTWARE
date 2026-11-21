<?php

namespace App\Domain\Demo;

use App\Models\User;
use App\Support\DomainRuleException;

/**
 * Who "does" each part of the sample story: the community relations officer
 * logs engagements, the grievance officer takes in cases, HSE raises ESHS
 * anomalies, and so on.
 *
 * The showcase seed casts one person per role. A demo workspace has only the
 * person who asked for it, so every part is played by them — the records are
 * theirs to open, assign and close.
 */
final class DemoCast
{
    public const PARTS = [
        'admin', 'project_admin', 'executive', 'pm', 'grievance', 'cro',
        'hr', 'hse', 'security', 'field', 'field2',
    ];

    /** @param array<string,User> $users */
    private function __construct(private readonly array $users, private readonly ?User $fallback) {}

    /** @param array<string,User> $users keyed by part */
    public static function of(array $users, ?User $fallback = null): self
    {
        return new self($users, $fallback);
    }

    public static function single(User $user): self
    {
        return new self(array_fill_keys(self::PARTS, $user), $user);
    }

    public function get(string $part): User
    {
        return $this->users[$part] ?? $this->fallback ?? throw new DomainRuleException(
            "Nobody is cast as {$part} for the sample data.", 'demo_cast_missing', 500
        );
    }

    public function has(string $part): bool
    {
        return isset($this->users[$part]);
    }
}
