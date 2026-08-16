<?php

declare(strict_types=1);

use SConcur\Bson\ObjectId;
use SConcur\Bson\UTCDateTime;
use SConcur\Features\Mongodb\Serialization\DocumentSerializer;

require_once __DIR__ . '/../../../vendor/autoload.php';

/**
 * Direct micro-benchmark of the document codec, without a database.
 *
 * The document path is the only place SConcur converts MongoDB payloads, and it
 * is invisible in the end-to-end mongodb benchmarks — there the network, the
 * server and the state of the collection dominate, and their run-to-run spread
 * reaches 3x. This measures the conversion alone, so a change of codec stays
 * comparable: DocumentSerializer's API does not change, and the round trip feeds
 * serialize() straight into unserialize().
 *
 * Two documents are measured. The plain one holds nothing but scalars, nesting
 * and a list, so it is identical across codecs — the clean comparison. The rich
 * one adds the special values (an id, a date), whose representation is exactly
 * what a codec change alters.
 *
 * Usage: php tests/benchmarks/mongodb/serializer.php [iterations]
 */
$iterations = (int) ($_SERVER['argv'][1] ?? 20000);

$plainDocument = [
    '_id'     => '65f1c2a3b4d5e6f708192a3b',
    'title'   => 'A document title of a fairly ordinary length',
    'slug'    => 'a-document-title',
    'views'   => 128374,
    'rating'  => 4.75,
    'active'  => true,
    'tags'    => ['php', 'go', 'mongodb', 'concurrency', 'benchmark'],
    'author'  => ['id' => 42, 'name' => 'John Smith', 'email' => 'john@example.com'],
    'meta'    => ['lang' => 'en', 'version' => 3, 'flags' => [1, 2, 3, 4]],
    'created' => 1755200000000,
];

$richDocument            = $plainDocument;
$richDocument['_id']     = new ObjectId('65f1c2a3b4d5e6f708192a3b');
$richDocument['created'] = new UTCDateTime(1755200000000);

$measure = static function (string $name, callable $callback, int $iterations): float {
    $callback();

    $start = hrtime(true);

    for ($index = 0; $index < $iterations; $index++) {
        $callback();
    }

    $microsecondsPerCall = (hrtime(true) - $start) / $iterations / 1000;

    printf("%-34s %8.2f us\n", $name, $microsecondsPerCall);

    return $microsecondsPerCall;
};

foreach (['plain' => $plainDocument, 'with BSON types' => $richDocument] as $label => $document) {
    $encoded = DocumentSerializer::serialize(document: $document);

    printf("\n== %s document (%d bytes on the wire) ==\n", $label, strlen($encoded));

    $encode = $measure(
        'serialize (PHP -> wire)',
        static fn (): string => DocumentSerializer::serialize(document: $document),
        $iterations,
    );
    $decode = $measure(
        'unserialize (wire -> PHP)',
        static fn (): array => DocumentSerializer::unserialize(document: $encoded),
        $iterations,
    );

    printf("%-34s %8.2f us\n", 'round trip', $encode + $decode);
}
