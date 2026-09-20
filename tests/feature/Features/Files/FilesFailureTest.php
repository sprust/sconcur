<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SConcur\Exceptions\Files\FileAlreadyExistsException;
use SConcur\Exceptions\Files\FileNotFoundException;
use SConcur\Exceptions\Files\FileOperationException;
use SConcur\Exceptions\Files\FilePermissionException;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Exceptions\Files\FileStoppedException;
use SConcur\Exceptions\Files\FileStreamClosedException;
use SConcur\Exceptions\Files\FileTimeoutException;
use SConcur\Exceptions\Files\FileTooLargeException;
use SConcur\Exceptions\Files\InvalidFileArgumentException;
use SConcur\Exceptions\Files\UnexpectedFileTypeException;
use SConcur\Exceptions\TaskErrorException;
use SConcur\Features\Files\Support\FilesFailure;

/**
 * FilesFailure is a pure function over the text the core writes, so it is tested without
 * the extension. What it must get right is the thing it was built to get right: the kind
 * comes from the prefix the core wrote, never from matching a message that carries a path
 * the caller chose.
 */
class FilesFailureTest extends TestCase
{
    /**
     * @return array<string, array{string, class-string}>
     */
    public static function kindProvider(): array
    {
        return [
            'not found'     => ['nf', FileNotFoundException::class],
            'permission'    => ['pd', FilePermissionException::class],
            'already there' => ['ae', FileAlreadyExistsException::class],
            'wrong type'    => ['ft', UnexpectedFileTypeException::class],
            'io'            => ['io', FileOperationException::class],
            'too large'     => ['big', FileTooLargeException::class],
            'timeout'       => ['timeout', FileTimeoutException::class],
            'stopped'       => ['stopped', FileStoppedException::class],
            'stream closed' => ['state', FileStreamClosedException::class],
            'argument'      => ['arg', InvalidFileArgumentException::class],
        ];
    }

    /**
     * @param class-string $expected
     */
    #[DataProvider('kindProvider')]
    public function testEveryKindTheCoreWritesBecomesItsOwnException(
        string $kind,
        string $expected,
    ): void {
        $exception = FilesFailure::from(
            new TaskErrorException(message: "files[$kind]: open /tmp/example: reason"),
        );

        self::assertInstanceOf($expected, $exception);
        self::assertSame('open /tmp/example: reason', $exception->getMessage());
    }

    public function testTheOriginalIsKeptAsThePrevious(): void
    {
        $original = new TaskErrorException(message: 'files[nf]: open /tmp/x: no such file');

        self::assertSame($original, FilesFailure::from($original)->getPrevious());
    }

    public function testAKindThisPackageDoesNotKnowBecomesThePlainBase(): void
    {
        // A core newer than this package. A guess would be worse than the base class.
        $exception = FilesFailure::from(
            new TaskErrorException(message: 'files[whatever]: something new'),
        );

        self::assertSame(FilesException::class, $exception::class);
        self::assertSame('something new', $exception->getMessage());
    }

    public function testAMessageWithoutThePrefixPassesThroughWhole(): void
    {
        // Raised before the core saw the payload — a push failure, say.
        $exception = FilesFailure::from(
            new TaskErrorException(message: 'the extension is not loaded'),
        );

        self::assertSame(FilesException::class, $exception::class);
        self::assertSame('the extension is not loaded', $exception->getMessage());
    }

    /**
     * The whole reason the kind is a prefix rather than something matched out of the
     * text: half the message is a path, and a path is whatever the caller named a file.
     */
    public function testAPathThatLooksLikeAPrefixDoesNotChooseTheException(): void
    {
        $exception = FilesFailure::from(
            new TaskErrorException(
                message: 'files[nf]: open /tmp/files[arg]: a trap: no such file',
            ),
        );

        // The path in the middle names the `arg` kind, which would map to a
        // LogicException. The real kind is the one at the front.
        self::assertSame(FileNotFoundException::class, $exception::class);
    }

    public function testAPrefixThatIsNotAtTheStartIsNotAPrefix(): void
    {
        $exception = FilesFailure::from(
            new TaskErrorException(message: 'wrapped: files[pd]: open /tmp/x'),
        );

        self::assertSame(FilesException::class, $exception::class);
    }

    /**
     * The split that matters for a caller: everything the filesystem did is a
     * RuntimeException, and only a bug in the call is a LogicException — so a catch
     * written for one does not swallow the other.
     */
    public function testOnlyTheUsageMistakeIsALogicException(): void
    {
        foreach (['nf', 'pd', 'ae', 'ft', 'io', 'big', 'timeout', 'stopped', 'state'] as $kind) {
            self::assertInstanceOf(
                FilesException::class,
                FilesFailure::from(new TaskErrorException(message: "files[$kind]: text")),
                "kind $kind must be a runtime failure",
            );
        }

        self::assertNotInstanceOf(
            FilesException::class,
            FilesFailure::from(new TaskErrorException(message: 'files[arg]: text')),
        );
    }

    public function testAMultilineMessageKeepsItsTail(): void
    {
        $exception = FilesFailure::from(
            new TaskErrorException(message: "files[io]: first line\nsecond line"),
        );

        self::assertSame("first line\nsecond line", $exception->getMessage());
    }
}
