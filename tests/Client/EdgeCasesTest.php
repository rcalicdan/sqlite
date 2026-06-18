<?php

declare(strict_types=1);

use Hibla\Promise\Promise;

use function Hibla\await;

describe('SqliteClient - Statement Caching', function (): void {

    it('reuses the same prepared statement handle for identical queries (cache hit)', function (): void {
        $client = makeClient([
            'maxConnections' => 1,
            'enableStatementCache' => true,
        ]);

        try {
            $sql = 'SELECT :val AS val';

            $result1 = await($client->query($sql, ['val' => 'hello']));
            expect($result1->fetchOne()['val'])->toBe('hello');

            $result2 = await($client->query($sql, ['val' => 'world']));
            expect($result2->fetchOne()['val'])->toBe('world');

            expect($client->stats['statement_cache_enabled'])->toBeTrue()
                ->and($client->stats['active_connections'])->toBe(0)
            ;
        } finally {
            $client->close();
        }
    });

    it('purges and re-prepares statement handles after calling clearStatementCache()', function (): void {
        $client = makeClient([
            'maxConnections' => 1,
            'enableStatementCache' => true,
        ]);

        try {
            $sql = 'SELECT :val AS val';

            await($client->query($sql, ['val' => 'first']));

            $client->clearStatementCache();

            $result = await($client->query($sql, ['val' => 'second']));
            expect($result->fetchOne()['val'])->toBe('second');
        } finally {
            $client->close();
        }
    });

    it('evicts the oldest statement handle (LRU) when the statementCacheSize is exceeded', function (): void {
        $client = makeClient([
            'maxConnections' => 1,
            'enableStatementCache' => true,
            'statementCacheSize' => 2,
        ]);

        try {
            await($client->query('SELECT 1 AS a WHERE 1 = :x', ['x' => 1]));
            await($client->query('SELECT 2 AS b WHERE 2 = :y', ['y' => 2]));

            await($client->query('SELECT 3 AS c WHERE 3 = :z', ['z' => 3]));

            $result = await($client->query('SELECT 1 AS a WHERE 1 = :x', ['x' => 1]));
            expect((int)$result->fetchOne()['a'])->toBe(1);
        } finally {
            $client->close();
        }
    });

    it('wipes a connections statement cache when reset_connection is active and connection is recycled', function (): void {
        $client = makeClient([
            'maxConnections' => 1,
            'enableStatementCache' => true,
            'reset_connection' => true,
        ]);

        try {
            $sql = 'SELECT :val AS val';

            await($client->query($sql, ['val' => 'first']));

            $result = await($client->query($sql, ['val' => 'second']));
            expect($result->fetchOne()['val'])->toBe('second');
        } finally {
            $client->close();
        }
    });
});

describe('SqliteClient - Parameter & Type Edge Cases', function (): void {

    it('normalizes boolean query parameters to 1/0 integers correctly', function (): void {
        $client = makeClient();

        try {
            $result = await($client->query('SELECT :v1 AS v1, :v2 AS v2', ['v1' => true, 'v2' => false]));
            $row = $result->fetchOne();

            expect($row['v1'])->toBe(1)
                ->and($row['v2'])->toBe(0)
            ;
        } finally {
            $client->close();
        }
    });

    it('handles large parameter lists correctly', function (): void {
        $client = makeClient();

        try {
            $params = [];
            $placeholders = [];
            for ($i = 1; $i <= 50; $i++) {
                $placeholders[] = ":p{$i}";
                $params["p{$i}"] = $i;
            }

            $sql = 'SELECT ' . implode(' + ', $placeholders) . ' AS sum';
            $val = await($client->fetchValue($sql, null, $params));

            expect((int)$val)->toBe(1275);
        } finally {
            $client->close();
        }
    });
});

describe('SqliteClient - Queue & Concurrency', function () {

    it('safely queues and executes concurrent queries sequentially over a single connection', function () {
        $client = makeClient(['maxConnections' => 1]);

        try {
            $heavyQuery = 'WITH RECURSIVE cnt(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM cnt WHERE x < 100) SELECT count(x) AS c FROM cnt;';

            $promises = [
                $client->query($heavyQuery),
                $client->query($heavyQuery),
                $client->query($heavyQuery),
            ];

            $results = await(Promise::all($promises));

            expect($results)->toHaveCount(3)
                ->and((int)$results[0]->fetchOne()['c'])->toBe(100)
                ->and((int)$results[1]->fetchOne()['c'])->toBe(100)
                ->and((int)$results[2]->fetchOne()['c'])->toBe(100)
                ->and($client->stats['active_connections'])->toBe(0)
            ;
        } finally {
            $client->close();
        }
    });
});
