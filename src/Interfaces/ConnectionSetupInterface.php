<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Interfaces;

use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Sql\Result;

/**
 * Provides a limited query surface for onConnect hooks.
 */
interface ConnectionSetupInterface
{
    /**
     * Executes a query and resolves with the result set value object.
     *
     * @return PromiseInterface<Result> Resolves with the result set value object, rejects with an exception
     */
    public function query(string $sql): PromiseInterface;

    /**
     * Executes a query and resolves with the number of affected rows.
     *
     * @return PromiseInterface<int> Resolves with the number of affected rows, rejects with an exception
     */
    public function execute(string $sql): PromiseInterface;
}
