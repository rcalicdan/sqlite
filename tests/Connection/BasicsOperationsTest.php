<?php

declare(strict_types=1);

use Hibla\Sql\Exceptions\ConnectionException;
use Hibla\Sql\Exceptions\PreparedException;
use Hibla\Sql\RowStream as RowStreamInterface;

use function Hibla\await;

describe('AsyncConnection - Basic Operations', function () {

    it('establishes a connection and can ping the database', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            expect($conn->isClosed())->toBeFalse();
            $ping = await($conn->ping());
            expect($ping)->toBeTrue();
        } finally {
            $conn->close();
        }
    });

    it('rejects commands on a closed connection', function () {
        $conn = sqliteConn(['force_sync' => false]);
        $conn->close();

        expect(fn () => await($conn->query('SELECT 1')))
            ->toThrow(ConnectionException::class)
        ;
    });

    it('executes a basic plain query', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $result = await($conn->query("SELECT 42 AS answer, 'hello' AS greeting"));
            $row = $result->fetchOne();

            expect($result->rowCount)->toBe(1)
                ->and((int) $row['answer'])->toBe(42)
                ->and($row['greeting'])->toBe('hello')
            ;
        } finally {
            $conn->close();
        }
    });

    it('executes a basic prepared statement', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $result = await($stmt->execute(['val' => 'async sqlite']));
                $row = $result->fetchOne();
                expect($row['v'])->toBe('async sqlite');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('streams a basic query row-by-row', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 5) SELECT x AS n FROM cnt;';
            $stream = await($conn->streamQuery($sql));

            expect($stream)->toBeInstanceOf(RowStreamInterface::class);

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = (int) $row['n'];
            }

            expect($rows)->toBe([1, 2, 3, 4, 5]);
        } finally {
            $conn->close();
        }
    });

    it('executes the same prepared statement multiple times with different params', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT ? AS n'));

            try {
                $result1 = await($stmt->execute(['first']));
                expect($result1->fetchOne()['n'])->toBe('first');

                $result2 = await($stmt->execute(['second']));
                expect($result2->fetchOne()['n'])->toBe('second');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('handles an empty result set from a prepared statement', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT 1 WHERE 1 = ?'));

            try {
                $result = await($stmt->execute([0]));
                expect($result->rowCount)->toBe(0)
                    ->and($result->fetchAll())->toBe([])
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('rejects execution on a closed statement', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT 1'));
            await($stmt->close());

            expect(fn () => $stmt->execute())
                ->toThrow(PreparedException::class, 'Cannot execute a closed prepared statement')
            ;
        } finally {
            $conn->close();
        }
    });

    it('can stream results from a prepared statement', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < :limit) SELECT x AS n FROM cnt;';
            $stmt = await($conn->prepare($sql));

            try {
                $stream = await($stmt->executeStream(['limit' => 5]));
                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = (int) $row['n'];
                }
                expect($rows)->toBe([1, 2, 3, 4, 5]);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('rejects execution if a required named parameter is missing', function () {
        $conn = sqliteConn(['force_sync' => false]);

        try {
            $stmt = await($conn->prepare('SELECT :a, :b'));

            try {
                expect(fn () => await($stmt->execute(['a' => 1])))
                    ->toThrow(PreparedException::class, 'Missing value for named parameter: :b')
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });
});

describe('SyncConnection - Basic Operations', function () {

    it('establishes a connection and can ping the database', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            expect($conn->isClosed())->toBeFalse();
            $ping = await($conn->ping());
            expect($ping)->toBeTrue();
        } finally {
            $conn->close();
        }
    });

    it('rejects commands on a closed connection', function () {
        $conn = sqliteConn(['force_sync' => true]);
        $conn->close();

        expect(fn () => await($conn->query('SELECT 1')))
            ->toThrow(ConnectionException::class)
        ;
    });

    it('executes a basic plain query', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $result = await($conn->query("SELECT 42 AS answer, 'hello' AS greeting"));
            $row = $result->fetchOne();

            expect($result->rowCount)->toBe(1)
                ->and((int) $row['answer'])->toBe(42)
                ->and($row['greeting'])->toBe('hello')
            ;
        } finally {
            $conn->close();
        }
    });

    it('executes a basic prepared statement', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :val AS v'));

            try {
                $result = await($stmt->execute(['val' => 'sync sqlite']));
                $row = $result->fetchOne();
                expect($row['v'])->toBe('sync sqlite');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('streams a basic query row-by-row', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 5) SELECT x AS n FROM cnt;';
            $stream = await($conn->streamQuery($sql));

            expect($stream)->toBeInstanceOf(RowStreamInterface::class);

            $rows = [];
            foreach ($stream as $row) {
                $rows[] = (int) $row['n'];
            }

            expect($rows)->toBe([1, 2, 3, 4, 5]);
        } finally {
            $conn->close();
        }
    });

    it('executes the same prepared statement multiple times with different params', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT ? AS n'));

            try {
                $result1 = await($stmt->execute(['first']));
                expect($result1->fetchOne()['n'])->toBe('first');

                $result2 = await($stmt->execute(['second']));
                expect($result2->fetchOne()['n'])->toBe('second');
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('handles an empty result set from a prepared statement', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT 1 WHERE 1 = ?'));

            try {
                $result = await($stmt->execute([0]));
                expect($result->rowCount)->toBe(0)
                    ->and($result->fetchAll())->toBe([])
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('rejects execution on a closed statement', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT 1'));
            await($stmt->close());

            expect(fn () => $stmt->execute())
                ->toThrow(PreparedException::class, 'Cannot execute a closed prepared statement')
            ;
        } finally {
            $conn->close();
        }
    });

    it('can stream results from a prepared statement', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $sql = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < :limit) SELECT x AS n FROM cnt;';
            $stmt = await($conn->prepare($sql));

            try {
                $stream = await($stmt->executeStream(['limit' => 5]));
                $rows = [];
                foreach ($stream as $row) {
                    $rows[] = (int) $row['n'];
                }
                expect($rows)->toBe([1, 2, 3, 4, 5]);
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });

    it('rejects execution if a required named parameter is missing', function () {
        $conn = sqliteConn(['force_sync' => true]);

        try {
            $stmt = await($conn->prepare('SELECT :a, :b'));

            try {
                expect(fn () => await($stmt->execute(['a' => 1])))
                    ->toThrow(PreparedException::class, 'Missing value for named parameter: :b')
                ;
            } finally {
                await($stmt->close());
            }
        } finally {
            $conn->close();
        }
    });
});
