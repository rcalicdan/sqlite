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
});
