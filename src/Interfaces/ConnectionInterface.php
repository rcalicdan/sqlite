<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Sql\RowStream as RowStreamInterface;
use Hibla\Sqlite\Internals\PreparedStatement;
use Hibla\Sqlite\Internals\Result;

/**
 * Defines the contract for both asynchronous (worker-backed) and
 * synchronous (direct-blocking) SQLite connections.
 */
interface ConnectionInterface
{
    /**
     * Establishes the database connection.
     *
     * @return PromiseInterface<self>
     */
    public function connect(): PromiseInterface;

    /**
     * Executes a standard SQL query (buffered, parameterless).
     *
     * @param string $sql SQL query statement to execute.
     *
     * @return PromiseInterface<Result>
     */
    public function query(string $sql): PromiseInterface;

    /**
     * Streams a standard SELECT query row-by-row.
     *
     * @param string $sql SQL query statement to stream.
     * @param int $bufferSize Maximum rows to buffer before applying backpressure.
     *
     * @return PromiseInterface<RowStreamInterface>
     */
    public function streamQuery(string $sql, int $bufferSize = 100): PromiseInterface;

    /**
     * Prepares a SQL statement. Compiles the named-parameter bindings on the client-side.
     *
     * @param string $sql SQL query statement with placeholders.
     *
     * @return PromiseInterface<PreparedStatement>
     */
    public function prepare(string $sql): PromiseInterface;

    /**
     * Executes a prepared statement with parameters.
     *
     * @param PreparedStatement $stmt The statement object.
     * @param array<int|string, mixed> $params The parameter list.
     *
     * @return PromiseInterface<Result>
     */
    public function executeStatement(PreparedStatement $stmt, array $params): PromiseInterface;

    /**
     * Executes a prepared statement returning an unbuffered stream.
     *
     * @param PreparedStatement $stmt The statement object.
     * @param array<int|string, mixed> $params The parameter list.
     * @param int $bufferSize Maximum rows to buffer before applying backpressure.
     *
     * @return PromiseInterface<RowStreamInterface>
     */
    public function executeStream(PreparedStatement $stmt, array $params, int $bufferSize = 100): PromiseInterface;

    /**
     * Performs a fast check to verify the connection is alive and responsive.
     *
     * @return PromiseInterface<bool>
     */
    public function ping(): PromiseInterface;

    /**
     * Resets the connection state (safe no-op on synchronous fallbacks).
     *
     * @return PromiseInterface<bool>
     */
    public function reset(): PromiseInterface;

    /**
     * Closes the connection and releases underlying daemon or file resources.
     *
     * @param bool $killProcess For async connections, whether to forcefully kill the worker tree.
     */
    public function close(bool $killProcess = true): void;

    /**
     * Checks if the connection has been closed.
     */
    public function isClosed(): bool;

    /**
     * Pauses the connection read loop (handles backpressure).
     */
    public function pause(): void;

    /**
     * Resumes the connection read loop (handles backpressure).
     */
    public function resume(): void;
}
