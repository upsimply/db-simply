<?php

declare(strict_types=1);

namespace DbSimply\Sql;

/**
 * Splits PostgreSQL text into statements, the way psql does.
 *
 * A semicolon only ends a statement outside string literals ('…', with ''
 * for a quote, and E'…', where a backslash escapes too), quoted identifiers
 * ("…"), dollar-quoted strings ($$…$$, $tag$…$tag$, as function bodies are
 * written) and comments (-- … and /* … *\/, which nest in PostgreSQL). There
 * is no DELIMITER: a function body is dollar-quoted instead.
 *
 * Unlike {@see Splitter}, which reads MariaDB and MySQL dumps in chunks for
 * imports, this splits text the console was given whole.
 */
final class PgsqlSplitter
{
    /**
     * @return list<Statement>
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $length = strlen($sql);
        $position = 0;
        $start = 0;
        $line = 1;
        $startLine = null;

        $emit = static function (int $end, int $next) use ($sql, &$statements, &$start, &$startLine, &$line): void {
            $text = trim(substr($sql, $start, $end - $start));

            if ($startLine !== null && $text !== '') {
                $statements[] = new Statement($text, $startLine, $next, $line, ';');
            }

            $start = $next;
            $startLine = null;
        };

        while ($position < $length) {
            $char = $sql[$position];
            $next = $sql[$position + 1] ?? '';

            if ($char === "\n") {
                $line++;
                $position++;

                continue;
            }

            if (ctype_space($char)) {
                $position++;

                continue;
            }

            // Comments are not statement text on their own.
            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $position);
                $position = $end === false ? $length : $end;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $position = self::skipBlockComment($sql, $position, $line);

                continue;
            }

            $startLine ??= $line;

            if ($char === ';') {
                $emit($position, $position + 1);
                $position++;

                continue;
            }

            if ($char === "'") {
                $escapes = $position > 0 && in_array($sql[$position - 1], ['E', 'e'], true)
                    && ($position < 2 || ! self::isWordCharacter($sql[$position - 2]));
                $position = self::skipQuoted($sql, $position, "'", $escapes, $line);

                continue;
            }

            if ($char === '"') {
                $position = self::skipQuoted($sql, $position, '"', false, $line);

                continue;
            }

            if ($char === '$' && ($tag = self::dollarTag($sql, $position)) !== null) {
                $close = strpos($sql, $tag, $position + strlen($tag));
                $end = $close === false ? $length : $close + strlen($tag);
                $line += substr_count($sql, "\n", $position, $end - $position);
                $position = $end;

                continue;
            }

            // A word is skipped whole, so a $ inside an identifier such as
            // a$b never starts a dollar quote.
            if (self::isWordCharacter($char)) {
                while ($position < $length && (self::isWordCharacter($sql[$position]) || $sql[$position] === '$')) {
                    $position++;
                }

                continue;
            }

            $position++;
        }

        $emit($length, $length);

        return $statements;
    }

    /**
     * The opening tag of a dollar-quoted string starting at $position
     * ("$$" or "$tag$"), or null when the $ starts no such string.
     */
    private static function dollarTag(string $sql, int $position): ?string
    {
        if (preg_match('/\G\$([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $sql, $matches, 0, $position) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * Skip a quoted string or identifier, returning the offset after it. A
     * doubled quote is a quote; a backslash escapes in an E'' string.
     */
    private static function skipQuoted(string $sql, int $position, string $quote, bool $backslashEscapes, int &$line): int
    {
        $length = strlen($sql);
        $position++;

        while ($position < $length) {
            $char = $sql[$position];

            if ($char === "\n") {
                $line++;
            }

            if ($backslashEscapes && $char === '\\') {
                $position += 2;

                continue;
            }

            if ($char === $quote) {
                if (($sql[$position + 1] ?? '') === $quote) {
                    $position += 2;

                    continue;
                }

                return $position + 1;
            }

            $position++;
        }

        return $length;
    }

    /**
     * Skip a block comment, which nests in PostgreSQL.
     */
    private static function skipBlockComment(string $sql, int $position, int &$line): int
    {
        $length = strlen($sql);
        $depth = 0;

        while ($position < $length) {
            $pair = substr($sql, $position, 2);

            if ($pair === '/*') {
                $depth++;
                $position += 2;

                continue;
            }

            if ($pair === '*/') {
                $depth--;
                $position += 2;

                if ($depth === 0) {
                    return $position;
                }

                continue;
            }

            if ($sql[$position] === "\n") {
                $line++;
            }

            $position++;
        }

        return $length;
    }

    private static function isWordCharacter(string $char): bool
    {
        return ctype_alnum($char) || $char === '_' || ord($char) >= 0x80;
    }
}
