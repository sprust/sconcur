<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Files;

use ReflectionProperty;
use SConcur\Exceptions\Files\FilesException;
use SConcur\Features\Files\Files;
use SConcur\State;
use SConcur\Tests\Feature\BaseTestCase;

/**
 * A canary for the whole feature: after every shape of stream this package can open —
 * read to the end, broken out of, failed at the first push, failed mid-stream — the
 * package must be holding no synchronous flow at all.
 *
 * The other tests assert a delta, which is the right thing for a single behaviour and
 * says nothing about the sum. This one asserts the absolute, so a path that leaks one
 * flow per call is visible even when every individual test is happy.
 */
class FilesFlowRegistryTest extends BaseTestCase
{
    protected string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir() . '/sconcur-files-flows-' . uniqid();

        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $path) {
            is_dir($path) ? rmdir($path) : unlink($path);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function testNoStreamShapeLeavesASynchronousFlowBehind(): void
    {
        $path = $this->directory . '/lines.log';

        file_put_contents($path, str_repeat("line\n", 500));

        // Read to the end.
        iterator_to_array(Files::readLines(path: $path));

        // Broken out of after one value.
        foreach (Files::readChunks(path: $path, bufferSizeBytes: 64) as $chunk) {
            break;
        }

        // Walked and abandoned.
        foreach (Files::walk(path: $this->directory, batchEntries: 1) as $entry) {
            break;
        }

        // Refused at the first push: the file is not there.
        try {
            iterator_to_array(Files::readLines(path: $this->directory . '/absent.log'));
        } catch (FilesException) {
            //
        }

        // Refused mid-stream: the limit bites on the second batch, not the open.
        try {
            iterator_to_array(
                Files::readLines(path: $path, batchLines: 1, maxLineBytes: 1),
            );
        } catch (FilesException) {
            //
        }

        // A writer, closed, and a writer dropped without one.
        $writer = Files::openWriter(path: $this->directory . '/written.bin');

        $writer->write(chunk: 'x');
        $writer->close();

        $abandoned = Files::openWriter(path: $this->directory . '/abandoned.bin');

        $abandoned->write(chunk: 'x');

        unset($abandoned);

        self::assertSame([], $this->heldSyncFlows());
    }

    /**
     * @return array<string, string>
     */
    protected function heldSyncFlows(): array
    {
        $property = new ReflectionProperty(State::class, 'syncTaskFlows');

        /** @var array<string, string> $flows */
        $flows = $property->getValue();

        return $flows;
    }
}
