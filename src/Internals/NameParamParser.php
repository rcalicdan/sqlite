<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Internals;

use Hibla\Sql\Exceptions\PreparedException;

/**
 * Parses SQL containing named parameters (:name) into positional placeholders (?).
 *
 * @internal
 */
final class NameParamParser
{
    /**
     * @return array{0: string, 1: array<int, string>} Returns [processedSql, paramMap]
     */
    public static function parse(string $sql): array
    {
        if (! str_contains($sql, ':') && ! str_contains($sql, '?')) {
            return [$sql, []];
        }

        $length = \strlen($sql);
        $result = '';
        $paramMap = [];
        $paramIndex = 0;
        $state = 'NORMAL';
        $hasNamed = false;
        $hasPositional = false;

        for ($position = 0; $position < $length; $position++) {
            $currentChar = $sql[$position];
            $nextChar = $sql[$position + 1] ?? '';

            if ($state === 'NORMAL') {
                if ($currentChar === "'" || $currentChar === '"' || $currentChar === '`') {
                    $state = $currentChar;
                    $result .= $currentChar;

                    continue;
                }

                if ($currentChar === '-' && $nextChar === '-') {
                    $state = '--';
                    $result .= $currentChar;

                    continue;
                }

                if ($currentChar === '#') {
                    $state = '#';
                    $result .= $currentChar;

                    continue;
                }

                if ($currentChar === '/' && $nextChar === '*') {
                    $state = '/*';
                    $result .= $currentChar;

                    continue;
                }

                if ($currentChar === '?') {
                    $hasPositional = true;
                    if ($hasNamed) {
                        throw new PreparedException('Cannot mix named and positional parameters in the same query.');
                    }
                    $result .= '?';
                    $paramIndex++;

                    continue;
                }

                if ($currentChar === ':' && $nextChar === ':') {
                    $result .= '::';
                    $position++;

                    continue;
                }

                if ($currentChar === ':' && $nextChar === '=') {
                    $result .= $currentChar;

                    continue;
                }

                if ($currentChar === ':') {
                    $nameStartPosition = $position + 1;
                    $paramName = '';
                    $scanPosition = $nameStartPosition;

                    if ($nameStartPosition < $length) {
                        $firstCharCode = \ord($sql[$nameStartPosition]);
                        $isValidFirstChar = ($firstCharCode >= 97 && $firstCharCode <= 122)
                                         || ($firstCharCode >= 65 && $firstCharCode <= 90)
                                         || $firstCharCode === 95;

                        if ($isValidFirstChar) {
                            while ($scanPosition < $length) {
                                $nameChar = $sql[$scanPosition];
                                $nameCharCode = \ord($nameChar);

                                if (
                                    ($nameCharCode >= 97 && $nameCharCode <= 122)
                                    || ($nameCharCode >= 65 && $nameCharCode <= 90)
                                    || ($nameCharCode >= 48 && $nameCharCode <= 57)
                                    || $nameCharCode === 95
                                ) {
                                    $paramName .= $nameChar;
                                    $scanPosition++;
                                } else {
                                    break;
                                }
                            }
                        }
                    }

                    if ($paramName !== '') {
                        $hasNamed = true;
                        if ($hasPositional) {
                            throw new PreparedException('Cannot mix named and positional parameters in the same query.');
                        }
                        $result .= '?';
                        $paramMap[$paramIndex++] = $paramName;
                        $position = $scanPosition - 1;

                        continue;
                    }
                }

                $result .= $currentChar;

            } elseif ($state === "'" || $state === '"' || $state === '`') {
                $result .= $currentChar;
                if ($currentChar === '\\' && $nextChar !== '') {
                    $result .= $nextChar;
                    $position++;
                } elseif ($currentChar === $state) {
                    if ($state !== '`' && $nextChar === $state) {
                        $result .= $nextChar;
                        $position++;
                    } else {
                        $state = 'NORMAL';
                    }
                }
            } elseif ($state === '--' || $state === '#') {
                $result .= $currentChar;
                if ($currentChar === "\n") {
                    $state = 'NORMAL';
                }
            } elseif ($state === '/*') {
                $result .= $currentChar;
                if ($currentChar === '*' && $nextChar === '/') {
                    $result .= $nextChar;
                    $position++;
                    $state = 'NORMAL';
                }
            }
        }

        return [$result, $paramMap];
    }
}
