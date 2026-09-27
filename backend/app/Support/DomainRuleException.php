<?php

namespace App\Support;

use RuntimeException;

/**
 * A business rule refused the action — not a bug, not a validation failure.
 * e.g. "A case can only be closed after the complainant has been contacted."
 */
class DomainRuleException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode = 'rule_violation',
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
