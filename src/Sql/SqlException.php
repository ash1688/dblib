<?php

declare(strict_types=1);

namespace Dblib\Sql;

/**
 * Raised when a guard rejects a statement, or the database returns an error.
 * Carries a student-friendly message safe to render in the result pane.
 */
final class SqlException extends \RuntimeException
{
}
