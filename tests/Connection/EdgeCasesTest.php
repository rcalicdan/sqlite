<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\PreparedException;

use function Hibla\await;

describe('AsyncConnection - Edge Cases', function () {

    it('does not allow SQL injection through a positional param value', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT ? AS v'));

            try {
                $row = await($stmt->execute(["' OR '1'='1"]))->fetchOne();
                expect($row['v'])->toBe("' OR '1'='1");
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('does not allow SQL injection through a named param value', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => "'; DROP TABLE users; --"]))->fetchOne();
                expect($row['v'])->toBe("'; DROP TABLE users; --");
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('preserves backslashes in param values without interpreting them as escapes', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => 'C:\\Users\\admin']))->fetchOne();
                expect($row['v'])->toBe('C:\\Users\\admin');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('preserves double-quote characters in param values', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => '"quoted"']))->fetchOne();
                expect($row['v'])->toBe('"quoted"');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('round-trips a unicode multibyte string unchanged over IPC', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => '日本語テスト 🎉']))->fetchOne();
                expect($row['v'])->toBe('日本語テスト 🎉');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('round-trips an empty string param', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => '']))->fetchOne();
                expect($row['v'])->toBe('');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('round-trips a large string param (64 KB)', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $large = str_repeat('x', 65536);
                $row = await($stmt->execute(['val' => $large]))->fetchOne();
                expect($row['v'])->toBe($large);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('handles zero and negative integer params natively', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val1 AS v1, :val2 AS v2'));

            try {
                $row = await($stmt->execute(['val1' => 0, 'val2' => -42]))->fetchOne();
                expect($row['v1'])->toBe(0)
                    ->and($row['v2'])->toBe(-42)
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('handles float params natively', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => 3.14]))->fetchOne();
                expect($row['v'])->toBe(3.14);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('normalizes boolean params to integers (1 and 0)', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :v1 AS v1, :v2 AS v2'));

            try {
                $row = await($stmt->execute(['v1' => true, 'v2' => false]))->fetchOne();
                expect($row['v1'])->toBe(1)
                    ->and($row['v2'])->toBe(0)
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('closing an already-closed statement is a no-op and does not throw', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT 1'));
            await($stmt->close());

            $result = await($stmt->close());
            expect($result)->toBeNull();
        } finally {
            $conn->close();
        }
    });

    it('rejects executeStream() on a closed prepared statement', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :v AS v'));
            await($stmt->close());

            expect(fn () => $stmt->executeStream(['v' => 1]))
                ->toThrow(PreparedException::class)
            ;
        } finally {
            $conn->close();
        }
    });

    it('streams an empty result set without error', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < :limit) SELECT x AS n FROM cnt WHERE x < 0;';
            $stmt = await($conn->prepare($sql));

            try {
                $stream = await($stmt->executeStream(['limit' => 5]));

                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = $row;
                }

                expect($rows)->toBe([]);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('safely inserts and retrieves raw binary BLOB data over the IPC pipe', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            await($conn->query('CREATE TABLE binary_test (id INTEGER PRIMARY KEY, payload BLOB)'));

            $binaryData = random_bytes(32);

            $stmt = await($conn->prepare('INSERT INTO binary_test (payload) VALUES (:payload)'));
            await($stmt->execute(['payload' => $binaryData]));
            await($stmt->close());

            $result = await($conn->query('SELECT payload FROM binary_test WHERE id = 1'));
            $retrieved = $result->fetchOne()['payload'];

            expect(strlen($retrieved))->toBe(32)
                ->and(bin2hex($retrieved))->toBe(bin2hex($binaryData))
            ;
        } finally {
            $conn->close(true);
        }
    });
});

describe('SyncConnection - Edge Cases', function () {

    it('does not allow SQL injection through a positional param value', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT ? AS v'));

            try {
                $row = await($stmt->execute(["' OR '1'='1"]))->fetchOne();
                expect($row['v'])->toBe("' OR '1'='1");
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('does not allow SQL injection through a named param value', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => "'; DROP TABLE users; --"]))->fetchOne();
                expect($row['v'])->toBe("'; DROP TABLE users; --");
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('preserves backslashes in param values without interpreting them as escapes', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => 'C:\\Users\\admin']))->fetchOne();
                expect($row['v'])->toBe('C:\\Users\\admin');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('preserves double-quote characters in param values', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => '"quoted"']))->fetchOne();
                expect($row['v'])->toBe('"quoted"');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('round-trips a unicode multibyte string unchanged', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => '日本語テスト 🎉']))->fetchOne();
                expect($row['v'])->toBe('日本語テスト 🎉');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('round-trips an empty string param', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => '']))->fetchOne();
                expect($row['v'])->toBe('');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('round-trips a large string param (64 KB)', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $large = str_repeat('x', 65536);
                $row = await($stmt->execute(['val' => $large]))->fetchOne();
                expect($row['v'])->toBe($large);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('handles zero and negative integer params natively', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val1 AS v1, :val2 AS v2'));

            try {
                $row = await($stmt->execute(['val1' => 0, 'val2' => -42]))->fetchOne();
                expect($row['v1'])->toBe(0)
                    ->and($row['v2'])->toBe(-42)
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('handles float params natively', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $row = await($stmt->execute(['val' => 3.14]))->fetchOne();
                expect($row['v'])->toBe(3.14);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('normalizes boolean params to integers (1 and 0)', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :v1 AS v1, :v2 AS v2'));

            try {
                $row = await($stmt->execute(['v1' => true, 'v2' => false]))->fetchOne();
                expect($row['v1'])->toBe(1)
                    ->and($row['v2'])->toBe(0)
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('closing an already-closed statement is a no-op and does not throw', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT 1'));
            await($stmt->close());

            $result = await($stmt->close());
            expect($result)->toBeNull();
        } finally {
            $conn->close();
        }
    });

    it('rejects executeStream() on a closed prepared statement', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :v AS v'));
            await($stmt->close());

            expect(fn () => $stmt->executeStream(['v' => 1]))
                ->toThrow(PreparedException::class)
            ;
        } finally {
            $conn->close();
        }
    });

    it('streams an empty result set without error', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < :limit) SELECT x AS n FROM cnt WHERE x < 0;';
            $stmt = await($conn->prepare($sql));

            try {
                $stream = await($stmt->executeStream(['limit' => 5]));

                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = $row;
                }

                expect($rows)->toBe([]);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('handles large prepared statement payloads (10MB) safely over the IPC pipe without truncation', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            await($conn->query('
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    payload TEXT NOT NULL
                )
            '));

            $insertStmt = await($conn->prepare('
                INSERT INTO users (username, payload) VALUES (:username, :payload)
            '));

            $targetSize = 10 * 1024 * 1024;
            $largeString = str_repeat('abcdefghij', (int) ($targetSize / 10));

            $result = await($insertStmt->execute([
                'username' => 'massive_user',
                'payload' => $largeString,
            ]));

            expect($result->affectedRows)->toBe(1)
                ->and($result->lastInsertId)->toBe(1)
            ;

            $selectStmt = await($conn->prepare('SELECT payload FROM users WHERE username = :search'));
            $selectResult = await($selectStmt->execute(['search' => 'massive_user']));
            $row = $selectResult->fetchOne();
            expect($row)->not->toBeNull()
                ->and($row['payload'])->toBeString()
            ;

            $retrievedLength = strlen($row['payload']);
            expect($retrievedLength)->toBe($targetSize)
                ->and($row['payload'])->toBe($largeString)
            ;

            await($insertStmt->close());
            await($selectStmt->close());
        } finally {
            $conn->close(true);
        }
    });

    it('handles massive raw SQL string payloads (100k multi-row inserts) safely over the IPC pipe without truncation', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            await($conn->query('
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL,
                    payload TEXT NOT NULL
                )
            '));

            $rowsToInsert = 100000;
            $values = [];
            $dummyPayload = str_repeat('abcdefghij', 10);

            for ($i = 1; $i <= $rowsToInsert; $i++) {
                $username = 'user_' . $i;
                $values[] = "('{$username}', '{$dummyPayload}')";
            }

            $sql = 'INSERT INTO users (username, payload) VALUES ' . implode(',', $values) . ';';

            $result = await($conn->query($sql));
            expect($result->affectedRows)->toBe($rowsToInsert);

            $countResult = await($conn->query('SELECT COUNT(*) as total FROM users'));
            $totalInDb = (int) $countResult->fetchOne()['total'];

            expect($totalInDb)->toBe($rowsToInsert);
        } finally {
            $conn->close(true);
        }
    });

    it('safely inserts and retrieves raw binary BLOB data over sync connection', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            await($conn->query('CREATE TABLE binary_test (id INTEGER PRIMARY KEY, payload BLOB)'));

            $binaryData = random_bytes(32);

            $stmt = await($conn->prepare('INSERT INTO binary_test (payload) VALUES (:payload)'));
            await($stmt->execute(['payload' => $binaryData]));
            await($stmt->close());

            $result = await($conn->query('SELECT payload FROM binary_test WHERE id = 1'));
            $retrieved = $result->fetchOne()['payload'];

            expect(strlen($retrieved))->toBe(32)
                ->and(bin2hex($retrieved))->toBe(bin2hex($binaryData))
            ;
        } finally {
            $conn->close(true);
        }
    });
});
