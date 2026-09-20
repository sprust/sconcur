<?php

declare(strict_types=1);

namespace SConcur\Features\Files\Support;

use SConcur\Exceptions\Files\FileAlreadyExistsException;
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\FileOperationException;
use SConcur\Exceptions\Files\FilePermissionException;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Exceptions\Files\FileTimeoutException;
use SConcur\Exceptions\Files\FileTooLargeException;
use SConcur\Exceptions\Files\FileWriterClosedException;
use SConcur\Exceptions\Files\InvalidFileArgumentException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Exceptions\TaskExecutionException;

/**
 * Turns a failed task into the exception the caller expects.
 *
 * The kind is read, not guessed: the core writes it as `files[<kind>]: <text>`
 * (ext/src/features/files/errors.rs) and everything after that prefix is opaque. Matching
 * the message instead would classify a string that holds a path the caller chose — a file
 * named "permission denied" must not decide which exception the application catches.
 */
readonly class FilesFailure
{
    /**
     * The kinds the core writes, and what each is raised as. A kind missing from here is a
     * core newer than this package, and becomes a plain FilesException rather than a guess.
     *
     * @var array<string, class-string<FilesException|InvalidFileArgumentException>>
     */
    protected const array KINDS = [
        'nf'      => FileNotFoundException::class,
        'pd'      => FilePermissionException::class,
        'ae'      => FileAlreadyExistsException::class,
        'ft'      => UnexpectedFileTypeException::class,
        'io'      => FileOperationException::class,
        'big'     => FileTooLargeException::class,
        'timeout' => FileTimeoutException::class,
        'stopped' => FileOperationException::class,
        'state'   => FileWriterClosedException::class,
        'arg'     => InvalidFileArgumentException::class,
    ];

    public static function from(
        TaskErrorException|TaskExecutionException $exception,
    ): FilesException|InvalidFileArgumentException {
        [$kind, $message] = static::split($exception->getMessage());

        $class = static::KINDS[$kind] ?? null;

        if ($class === null) {
            return new FilesException(
                message: $message,
                previous: $exception,
            );
        }

        return new $class(
            message: $message,
            previous: $exception,
        );
    }

    /**
     * The kind and the text. A message without the prefix did not come from this feature —
     * a failure raised before the core saw the payload, say — and is passed through whole.
     *
     * @return array{0: string, 1: string}
     */
    protected static function split(string $message): array
    {
        if (preg_match('/^files\[([a-z]+)]: (.*)$/s', $message, $matches) !== 1) {
            return ['', $message];
        }

        return [$matches[1], $matches[2]];
    }
}
