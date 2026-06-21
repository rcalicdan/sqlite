<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Utilities;

/**
 * @internal
 * Safely encodes and decodes non-UTF-8 binary strings (BLOBs) for JSON IPC transport.
 */
final class BlobCodec
{
    public const string BLOB_KEY = '__hblob';

    /**
     * @template TArray of array<int|string, mixed>
     *
     * @param TArray $data
     *
     * @return TArray
     */
    public static function encodeArray(array $data): array
    {
        foreach ($data as &$val) {
            // If it's a string but NOT valid UTF-8, it's raw binary data.
            if (\is_string($val) && ! \mb_check_encoding($val, 'UTF-8')) {
                $val = [self::BLOB_KEY => \base64_encode($val)];
            }
        }

        /** @var TArray $data */
        return $data;
    }

    /**
     * @template T of array<int|string, mixed>
     *
     * @param T $data
     *
     * @return T
     */
    public static function decodeArray(array $data): array
    {
        foreach ($data as &$val) {
            if (\is_array($val) && isset($val[self::BLOB_KEY]) && \is_string($val[self::BLOB_KEY])) {
                $decoded = \base64_decode($val[self::BLOB_KEY], true);
                if ($decoded !== false) {
                    $val = $decoded;
                }
            }
        }

        /** @var T $data */
        return $data;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    public static function encodeRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row = self::encodeArray($row);
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    public static function decodeRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row = self::decodeArray($row);
        }

        return $rows;
    }
}
