<?php

declare(strict_types=1);

namespace Dblib\Sql;

/**
 * Tokenizer-lite for the execution guards.
 *
 * Walks the raw SQL once and produces a "masked" copy where the *contents* of
 * string literals, quoted identifiers, and comments are replaced with spaces.
 * Guards then inspect the mask, so a table called `database` or the text
 * 'drop database' inside a string can never trip a keyword check, and a block
 * comment wedged between DROP and DATABASE can never slip past one.
 *
 * It also splits the input into top-level statements (on `;` outside any
 * string/comment). statements() returns the masked form the guards use;
 * rawStatements() returns the original text of each, which the seed runner
 * executes one at a time.
 */
final class SqlInspector
{
    private string $masked = '';
    /** @var list<string> non-empty, trimmed, masked statements */
    private array $statements = [];
    /** @var list<string> the raw text of each non-empty statement */
    private array $rawStatements = [];

    public function __construct(public readonly string $raw)
    {
        $this->scan();
    }

    public function masked(): string
    {
        return $this->masked;
    }

    /** @return list<string> masked statements (for the guards) */
    public function statements(): array
    {
        return $this->statements;
    }

    /** @return list<string> raw, runnable statements (for the seed runner) */
    public function rawStatements(): array
    {
        return $this->rawStatements;
    }

    public function statementCount(): int
    {
        return count($this->statements);
    }

    private function scan(): void
    {
        $s = $this->raw;
        $n = strlen($s);
        $masked = '';
        $current = '';   // masked text of the current statement
        $stmtStart = 0;  // raw index where the current statement begins
        $i = 0;

        while ($i < $n) {
            $c  = $s[$i];
            $c2 = $i + 1 < $n ? $s[$i + 1] : '';

            // ---- line comments: -- ... \n  and  # ... \n ----
            if (($c === '-' && $c2 === '-') || $c === '#') {
                while ($i < $n && $s[$i] !== "\n") {
                    $masked  .= ' ';
                    $current .= ' ';
                    $i++;
                }
                continue;
            }

            // ---- block comment: /* ... */ ----
            if ($c === '/' && $c2 === '*') {
                while ($i < $n && !($s[$i] === '*' && ($s[$i + 1] ?? '') === '/')) {
                    $masked  .= ' ';
                    $current .= ' ';
                    $i++;
                }
                $skip = min(2, $n - $i);
                $masked  .= str_repeat(' ', $skip);
                $current .= str_repeat(' ', $skip);
                $i += $skip;
                continue;
            }

            // ---- quoted regions: ' " ` ----
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote     = $c;
                $backslash = ($quote !== '`');
                $masked  .= $c;
                $current .= $c;
                $i++;
                while ($i < $n) {
                    $ch = $s[$i];
                    if ($backslash && $ch === '\\') {
                        $masked  .= '  ';
                        $current .= '  ';
                        $i += 2;
                        continue;
                    }
                    if ($ch === $quote) {
                        if (($s[$i + 1] ?? '') === $quote) {
                            $masked  .= '  ';
                            $current .= '  ';
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    $masked  .= ' ';
                    $current .= ' ';
                    $i++;
                }
                if ($i < $n) {
                    $masked  .= $s[$i];
                    $current .= $s[$i];
                    $i++;
                }
                continue;
            }

            // ---- statement terminator ----
            if ($c === ';') {
                $this->push($current, substr($s, $stmtStart, $i - $stmtStart));
                $current = '';
                $masked .= ' ';
                $i++;
                $stmtStart = $i;
                continue;
            }

            $masked  .= $c;
            $current .= $c;
            $i++;
        }

        $this->push($current, substr($s, $stmtStart));
        $this->masked = $masked;
    }

    /** Record a statement only when its masked form has real content. */
    private function push(string $masked, string $raw): void
    {
        $trimmed = trim($masked);
        if ($trimmed !== '') {
            $this->statements[]    = $trimmed;
            $this->rawStatements[] = trim($raw);
        }
    }
}
