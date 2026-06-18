<?php

declare(strict_types=1);

namespace Hibla\Sqlite\Utilities;

/**
 * @internal
 *
 * Handles reconstruction and stack-trace merging of exceptions thrown inside worker processes.
 */
final class ExceptionHandler
{
    /**
     * Reconstructs a Throwable from worker error data and merges its stack trace.
     *
     * @param array<string, mixed> $errorData Decoded JSON error frame from the worker.
     */
    public static function createFromWorkerError(array $errorData): \Throwable
    {
        $className = isset($errorData['class']) && \is_string($errorData['class'])
            ? $errorData['class']
            : \RuntimeException::class;

        $message = isset($errorData['message']) && \is_string($errorData['message'])
            ? $errorData['message']
            : 'Unknown worker error';

        $codeValue = $errorData['code'] ?? 0;
        $code = \is_int($codeValue) ? $codeValue : (is_numeric($codeValue) ? (int)$codeValue : 0);

        $file = isset($errorData['file']) && \is_string($errorData['file']) ? $errorData['file'] : 'unknown';
        $line = isset($errorData['line']) && \is_int($errorData['line']) ? $errorData['line'] : 0;
        $workerTrace = isset($errorData['stack_trace']) && \is_string($errorData['stack_trace']) ? $errorData['stack_trace'] : '';

        if ($className === 'SQLite3Exception' || $className === \RuntimeException::class) {
            $mapped = ExceptionMapper::map($code, $message);
            $className = \get_class($mapped);
        }

        $exception = self::instantiateException($className, $message, $code);

        $reflection = new \ReflectionObject($exception);
        self::setExceptionLocation($exception, $file, $line, $reflection);

        if ($workerTrace !== '') {
            self::appendWorkerStackTrace($exception, $workerTrace, $reflection);
        }

        return $exception;
    }

    private static function instantiateException(string $className, string $message, int $code): \Throwable
    {
        if (! \class_exists($className) || ! \is_subclass_of($className, \Throwable::class)) {
            return new \RuntimeException($message, $code);
        }

        try {
            $reflector = new \ReflectionClass($className);
            $constructor = $reflector->getConstructor();

            if ($constructor === null) {
                return $reflector->newInstance();
            }

            return $reflector->newInstance($message, $code);
        } catch (\Throwable $e) {
            return new \RuntimeException($message, $code);
        }
    }

    private static function setExceptionLocation(\Throwable $exception, string $file, int $line, \ReflectionObject $reflection): void
    {
        try {
            $reflectionClass = self::findReflectionWithProperty($reflection, 'file');
            if ($reflectionClass !== null) {
                $fileProp = $reflectionClass->getProperty('file');
                $fileProp->setValue($exception, $file);

                $lineProp = $reflectionClass->getProperty('line');
                $lineProp->setValue($exception, $line);
            }
        } catch (\Throwable $t) {
            // Ignore if reflection fails
        }
    }

    private static function appendWorkerStackTrace(\Throwable $exception, string $workerTrace, \ReflectionObject $reflection): void
    {
        try {
            $reflectionClass = self::findReflectionWithProperty($reflection, 'trace');
            if ($reflectionClass === null) {
                return;
            }

            $traceProp = $reflectionClass->getProperty('trace');
            $currentTrace = $traceProp->getValue($exception);

            if (! \is_array($currentTrace)) {
                return;
            }

            $workerTraceArray = self::parseWorkerStackTrace($workerTrace);
            if (\count($workerTraceArray) === 0) {
                return;
            }

            $currentTrace[] = [
                'file' => '--- WORKER STACK TRACE ---',
                'line' => 0,
                'function' => '',
                'args' => [],
            ];

            $currentTrace = \array_merge($currentTrace, $workerTraceArray);
            $traceProp->setValue($exception, $currentTrace);
        } catch (\Throwable $t) {
            // Ignore if reflection fails
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function parseWorkerStackTrace(string $workerTrace): array
    {
        $lines = \explode("\n", $workerTrace);
        $traceArray = [];

        foreach ($lines as $line) {
            $trimmed = \trim($line);
            $matches = [];

            if (\preg_match('/^#\d+\s+(.+?)(?:\((\d+)\))?: (.+)$/', $trimmed, $matches) === 1) {
                $traceArray[] = [
                    'file' => $matches[1],
                    'line' => $matches[2] !== '' ? (int)$matches[2] : 0,
                    'function' => $matches[3],
                    'args' => [],
                ];
            } elseif (\preg_match('/^#\d+\s+\{main\}$/', $trimmed) === 1) {
                $traceArray[] = [
                    'file' => '[worker main]',
                    'line' => 0,
                    'function' => '{main}',
                    'args' => [],
                ];
            }
        }

        return $traceArray;
    }

    /**
     * @param \ReflectionClass<object> $reflection
     *
     * @return \ReflectionClass<object>|null
     */
    private static function findReflectionWithProperty(\ReflectionClass $reflection, string $propertyName): ?\ReflectionClass
    {
        while ($reflection instanceof \ReflectionClass && ! $reflection->hasProperty($propertyName)) {
            $parent = $reflection->getParentClass();
            if ($parent === false) {
                return null;
            }
            $reflection = $parent;
        }

        return $reflection;
    }
}
