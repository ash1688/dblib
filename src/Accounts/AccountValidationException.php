<?php

declare(strict_types=1);

namespace Dblib\Accounts;

/**
 * Raised when an account fails the app-level identity rules
 * (e.g. a student without a student ID, or a teacher without an email).
 */
final class AccountValidationException extends \RuntimeException
{
}
