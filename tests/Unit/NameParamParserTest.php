<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\PreparedException;
use Hibla\Sqlite\Internals\NameParamParser;

describe('NameParamParser', function (): void {

    describe('Basic Parsing', function (): void {
        it('leaves a plain query untouched', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM users');
            expect($sql)->toBe('SELECT * FROM users')
                ->and($map)->toBe([])
            ;
        });

        it('handles an empty string', function (): void {
            [$sql, $map] = NameParamParser::parse('');
            expect($sql)->toBe('')
                ->and($map)->toBe([])
            ;
        });

        it('handles a whitespace-only query', function (): void {
            [$sql, $map] = NameParamParser::parse('   ');
            expect($sql)->toBe('   ')
                ->and($map)->toBe([])
            ;
        });
    });

    describe('Positional Parameters (?)', function (): void {
        it('passes through a single ? parameter', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE id = ?');
            expect($sql)->toBe('SELECT * FROM users WHERE id = ?')
                ->and($map)->toBe([])
            ;
        });

        it('passes through multiple ? parameters in order', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE a = ? AND b = ?');
            expect($sql)->toBe('SELECT * FROM t WHERE a = ? AND b = ?')
                ->and($map)->toBe([])
            ;
        });

        it('handles a ? at the very end of the string', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT ?');
            expect($sql)->toBe('SELECT ?')
                ->and($map)->toBe([])
            ;
        });

        it('handles a query that is exactly one ?', function (): void {
            [$sql, $map] = NameParamParser::parse('?');
            expect($sql)->toBe('?')
                ->and($map)->toBe([])
            ;
        });
    });

    describe('Named Parameters (:name)', function (): void {
        it('converts a single :name to ? and maps it', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE id = :id');
            expect($sql)->toBe('SELECT * FROM users WHERE id = ?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('converts multiple distinct :name parameters in order', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE id = :id AND status = :status');
            expect($sql)->toBe('SELECT * FROM users WHERE id = ? AND status = ?')
                ->and($map)->toBe([0 => 'id', 1 => 'status'])
            ;
        });

        it('does not deduplicate repeated :name parameters at parse time (maps each to a new ?)', function (): void {
            // Deduplication happens in PreparedStatement::mapAndNormalizeParams
            [$sql, $map] = NameParamParser::parse('SELECT * FROM users WHERE (first = :name OR last = :name)');
            expect($sql)->toBe('SELECT * FROM users WHERE (first = ? OR last = ?)')
                ->and($map)->toBe([0 => 'name', 1 => 'name'])
            ;
        });

        it('parses named parameters with underscores', function (): void {
            [$sql, $map] = NameParamParser::parse('INSERT INTO t VALUES (:first_name, :_private_id)');
            expect($sql)->toBe('INSERT INTO t VALUES (?, ?)')
                ->and($map)->toBe([0 => 'first_name', 1 => '_private_id'])
            ;
        });

        it('parses named parameters with digits in the name', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE col = :val123');
            expect($sql)->toBe('SELECT * FROM t WHERE col = ?')
                ->and($map)->toBe([0 => 'val123'])
            ;
        });

        it('parses a single-character parameter name', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :i');
            expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
                ->and($map)->toBe([0 => 'i'])
            ;
        });

        it('parses a parameter name that is only underscores', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :___');
            expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
                ->and($map)->toBe([0 => '___'])
            ;
        });

        it('handles a query that is exactly one named parameter', function (): void {
            [$sql, $map] = NameParamParser::parse(':id');
            expect($sql)->toBe('?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('parses a named parameter immediately followed by a comma, parenthesis, or newline', function (): void {
            [$sql, $map] = NameParamParser::parse("INSERT INTO t (a, b) VALUES (:a, :b)\nWHERE (id = :id)");
            expect($sql)->toBe("INSERT INTO t (a, b) VALUES (?, ?)\nWHERE (id = ?)")
                ->and($map)->toBe([0 => 'a', 1 => 'b', 2 => 'id'])
            ;
        });

        it('parses two named parameters with no space between them', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT :a:b');
            expect($sql)->toBe('SELECT ??')
                ->and($map)->toBe([0 => 'a', 1 => 'b'])
            ;
        });

        it('handles tab characters between tokens', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT\t*\tFROM\tt\tWHERE\tid\t=\t:id");
            expect($sql)->toBe("SELECT\t*\tFROM\tt\tWHERE\tid\t=\t?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('stops a parameter name at a dash', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :user-id');
            expect($sql)->toBe('SELECT * FROM t WHERE id = ?-id')
                ->and($map)->toBe([0 => 'user'])
            ;
        });

        it('stops a parameter name at a dot', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :table.column');
            expect($sql)->toBe('SELECT * FROM t WHERE id = ?.column')
                ->and($map)->toBe([0 => 'table'])
            ;
        });

        it('stops a parameter name at a quote character', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE id = :user'injection");
            expect($sql)->toBe("SELECT * FROM t WHERE id = ?'injection")
                ->and($map)->toBe([0 => 'user'])
            ;
        });

        it('stops a parameter name at a null byte', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE id = :user\x00injection");
            expect($sql)->toBe("SELECT * FROM t WHERE id = ?\x00injection")
                ->and($map)->toBe([0 => 'user'])
            ;
        });

        it('does not treat SQL keywords after a colon as anything other than a safe placeholder', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :SELECT');
            expect($sql)->toBe('SELECT * FROM t WHERE id = ?')
                ->and($map)->toBe([0 => 'SELECT'])
            ;
        });
    });

    describe('Mixing Placeholders (Throws)', function (): void {
        it('throws when mixing ? and then :name', function (): void {
            expect(fn () => NameParamParser::parse('WHERE a = ? AND b = :name'))
                ->toThrow(PreparedException::class, 'Cannot mix named and positional parameters')
            ;
        });

        it('throws when mixing :name and then ?', function (): void {
            expect(fn () => NameParamParser::parse('WHERE a = :name AND b = ?'))
                ->toThrow(PreparedException::class, 'Cannot mix named and positional parameters')
            ;
        });
    });

    describe('Operators & Edge Cases (Ignored)', function (): void {
        it('passes through the :: cast operator untouched', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT col::text FROM t WHERE id = :id');
            expect($sql)->toBe('SELECT col::text FROM t WHERE id = ?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('passes through the := assignment operator untouched', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT @row := @row + 1, :param');
            expect($sql)->toBe('SELECT @row := @row + 1, ?')
                ->and($map)->toBe([0 => 'param'])
            ;
        });

        it('treats a lone colon with no valid identifier as literal', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT : FROM t WHERE id = :id');
            expect($sql)->toBe('SELECT : FROM t WHERE id = ?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('does not treat a colon followed by a space as a named parameter', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT : param FROM t WHERE id = :id');
            expect($sql)->toBe('SELECT : param FROM t WHERE id = ?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('does not treat a colon followed by a digit as a named parameter', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t WHERE id = :id LIMIT :1');
            expect($sql)->toBe('SELECT * FROM t WHERE id = ? LIMIT :1')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('does not treat a high-byte (Unicode) character after a colon as a parameter', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE id = :\xc3\xa9dition AND name = :name");
            expect($map)->toBe([0 => 'name']); // Only captures :name
        });
    });

    describe('Strings & Identifiers (Ignored)', function (): void {
        it('ignores placeholders inside single-quoted strings', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM logs WHERE msg = 'Error: :not_param' AND id = :id");
            expect($sql)->toBe("SELECT * FROM logs WHERE msg = 'Error: :not_param' AND id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('ignores placeholders inside double-quoted identifiers', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM "table:name" WHERE id = :id');
            expect($sql)->toBe('SELECT * FROM "table:name" WHERE id = ?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('ignores placeholders inside backtick identifiers (SQLite specific)', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM `table:name` WHERE id = :id');
            expect($sql)->toBe('SELECT * FROM `table:name` WHERE id = ?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('handles doubled single-quote escapes (O\'\'Reilly)', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM logs WHERE msg = 'O''Reilly :trap' AND id = :id");
            expect($sql)->toBe("SELECT * FROM logs WHERE msg = 'O''Reilly :trap' AND id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('handles doubled double-quote escapes', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM logs WHERE msg = "My "" :trap" AND id = :id');
            expect($sql)->toBe('SELECT * FROM logs WHERE msg = "My "" :trap" AND id = ?')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('handles backslash-escaped quotes inside strings', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE name = 'it\\'s :trap' AND id = :id");
            expect($sql)->toBe("SELECT * FROM t WHERE name = 'it\\'s :trap' AND id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('handles adjacent string literals', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE a = ':trap' AND b = ':trap' AND id = :id");
            expect($sql)->toBe("SELECT * FROM t WHERE a = ':trap' AND b = ':trap' AND id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('handles an empty string literal', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE name = '' AND id = :id");
            expect($sql)->toBe("SELECT * FROM t WHERE name = '' AND id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('handles an unterminated single-quoted string gracefully', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE msg = 'unterminated :trap");
            expect($sql)->toBe("SELECT * FROM t WHERE msg = 'unterminated :trap")
                ->and($map)->toBe([])
            ;
        });

        it('handles a very long string literal without false positives', function (): void {
            $longLiteral = str_repeat(':trap ', 1000);
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t WHERE msg = '{$longLiteral}' AND id = :id");
            expect($sql)->toBe("SELECT * FROM t WHERE msg = '{$longLiteral}' AND id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });
    });

    describe('Comments (Ignored)', function (): void {
        it('ignores placeholders inside standard SQL line comments (--)', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM users -- comment with :param\n WHERE id = :id");
            expect($sql)->toBe("SELECT * FROM users -- comment with :param\n WHERE id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('ignores placeholders inside SQLite hash line comments (#)', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM users # comment with :param\n WHERE id = :id");
            expect($sql)->toBe("SELECT * FROM users # comment with :param\n WHERE id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('ignores placeholders inside block comments', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM users /* comment with :param \n multiline :param2 */ WHERE id = :id");
            expect($sql)->toBe("SELECT * FROM users /* comment with :param \n multiline :param2 */ WHERE id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('resumes parsing correctly after a block comment', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT /* skip :x */ :a, :b FROM t');
            expect($sql)->toBe('SELECT /* skip :x */ ?, ? FROM t')
                ->and($map)->toBe([0 => 'a', 1 => 'b'])
            ;
        });

        it('resumes parsing correctly after a line comment', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT -- skip :x\n :a FROM t");
            expect($sql)->toBe("SELECT -- skip :x\n ? FROM t")
                ->and($map)->toBe([0 => 'a'])
            ;
        });

        it('exits a line comment correctly on Windows-style CRLF line endings', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t -- :trap\r\nWHERE id = :id");
            expect($sql)->toBe("SELECT * FROM t -- :trap\r\nWHERE id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('handles a named parameter immediately after a -- opener with no space', function (): void {
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t --:trap\nWHERE id = :id");
            expect($sql)->toBe("SELECT * FROM t --:trap\nWHERE id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('does NOT support nested block comments (correct SQLite behavior)', function (): void {
            // Unlike Postgres, SQLite stops the comment at the FIRST `*/`.
            // So `/* outer /* inner */ :real_param */` will parse `:real_param` as a parameter!
            [$sql, $map] = NameParamParser::parse('SELECT /* outer /* inner */ :real_param */');
            expect($sql)->toBe('SELECT /* outer /* inner */ ? */')
                ->and($map)->toBe([0 => 'real_param'])
            ;
        });

        it('handles an empty block comment', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT /**/ :id FROM t');
            expect($sql)->toBe('SELECT /**/ ? FROM t')
                ->and($map)->toBe([0 => 'id'])
            ;
        });

        it('treats a line comment at the end of the string as consuming the rest', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT 1 -- :trap');
            expect($sql)->toBe('SELECT 1 -- :trap')
                ->and($map)->toBe([])
            ;
        });

        it('handles an unterminated block comment gracefully', function (): void {
            [$sql, $map] = NameParamParser::parse('SELECT * FROM t /* unterminated :trap');
            expect($sql)->toBe('SELECT * FROM t /* unterminated :trap')
                ->and($map)->toBe([])
            ;
        });

        it('handles a very long block comment without false positives', function (): void {
            $longComment = str_repeat(':trap ', 1000);
            [$sql, $map] = NameParamParser::parse("SELECT * FROM t /* {$longComment} */ WHERE id = :id");
            expect($sql)->toBe("SELECT * FROM t /* {$longComment} */ WHERE id = ?")
                ->and($map)->toBe([0 => 'id'])
            ;
        });
    });

    describe('Complex Structures', function (): void {
        it('handles a large number of named parameters correctly', function (): void {
            $parts = array_map(fn (int $n) => "col{$n} = :param{$n}", range(1, 200));
            $query = 'SELECT * FROM t WHERE ' . implode(' AND ', $parts);

            [$sql, $map] = NameParamParser::parse($query);

            expect(count($map))->toBe(200)
                ->and($map[0])->toBe('param1')
                ->and($map[199])->toBe('param200')
                ->and(substr_count($sql, '?'))->toBe(200)
            ;
        });

        it('handles a WITH (CTE) containing named parameters', function (): void {
            [$sql, $map] = NameParamParser::parse(
                'WITH active AS (SELECT * FROM t WHERE status = :status) SELECT * FROM active WHERE id = :id'
            );
            expect($sql)->toBe('WITH active AS (SELECT * FROM t WHERE status = ?) SELECT * FROM active WHERE id = ?')
                ->and($map)->toBe([0 => 'status', 1 => 'id'])
            ;
        });

        it('returns the exact same result on repeated calls (idempotency)', function (): void {
            $query = 'SELECT * FROM t WHERE id = :id AND status = :status';
            [$sql1, $map1] = NameParamParser::parse($query);
            [$sql2, $map2] = NameParamParser::parse($query);

            expect($sql1)->toBe($sql2)
                ->and($map1)->toBe($map2)
            ;
        });
    });
});
