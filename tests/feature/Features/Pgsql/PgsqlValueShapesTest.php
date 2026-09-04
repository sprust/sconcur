<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Features\Pgsql;

use PHPUnit\Framework\Attributes\DataProvider;
use SConcur\Features\Sql\Connection;
use SConcur\Tests\Feature\BaseTestCase;
use SConcur\Tests\Impl\TestPgsqlResolver;
use Throwable;

/**
 * The shapes every PostgreSQL type reaches PHP in.
 *
 * These are the server's own text forms: the statement travels through the
 * simple query protocol, so Postgres prints each value itself and the core hands
 * the string over untouched. See `Features/Sql/pg_simple.rs` for how a
 * parameterised statement reaches that protocol.
 *
 * The cases are kept because the shapes are a contract the PHP side reads, not
 * because the rendering is in doubt any more — and because they are what caught
 * the shapes going wrong when the core did render them itself.
 */
class PgsqlValueShapesTest extends BaseTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = TestPgsqlResolver::getConnection();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function textFormProvider(): array
    {
        return [
            // Numbers. NUMERIC keeps the scale the column declared, which is the
            // whole reason it is not routed through a decimal type.
            'numeric keeps its scale'   => ["1.500::numeric", '1.500'],
            'numeric NaN'               => ["'NaN'::numeric", 'NaN'],
            'money'                     => ["'12.34'::money", '$12.34'],
            'money is grouped'          => ["'1234567.89'::money", '$1,234,567.89'],
            'money is signed'           => ["'-12.34'::money", '-$12.34'],

            // Time. The infinities used to panic inside the decoder and come back
            // as an empty result set with no error at all.
            'timestamp infinity'        => ["'infinity'::timestamp", 'infinity'],
            'timestamp -infinity'       => ["'-infinity'::timestamp", '-infinity'],
            'date infinity'             => ["'infinity'::date", 'infinity'],
            'timetz'                    => ["'14:30:00+02'::timetz", '14:30:00+02'],
            'timetz west of UTC'        => ["'14:30:00-05:30'::timetz", '14:30:00-05:30'],
            'timetz with a fraction'    => ["'14:30:00.25+02'::timetz", '14:30:00.25+02'],

            // INTERVAL pluralises against 1, not against magnitude.
            'interval'                  => [
                "'1 year 2 mons 3 days 04:05:06.789'::interval",
                '1 year 2 mons 3 days 04:05:06.789',
            ],
            'interval negative'         => ["'-1 day -02:03:04'::interval", '-1 days -02:03:04'],
            'interval zero'             => ["'0'::interval", '00:00:00'],
            'interval past a day'       => ["'100:00:00'::interval", '100:00:00'],

            // Network types.
            'inet drops a full mask'    => ["'192.168.0.1'::inet", '192.168.0.1'],
            'inet keeps a partial mask' => ["'192.168.0.1/24'::inet", '192.168.0.1/24'],
            'inet v6'                   => ["'2001:db8::1'::inet", '2001:db8::1'],
            'cidr always has its mask'  => ["'10.0.0.0/8'::cidr", '10.0.0.0/8'],
            'macaddr'                   => ["'08:00:2b:01:02:03'::macaddr", '08:00:2b:01:02:03'],
            'macaddr8'                  => [
                "'08:00:2b:01:02:03:04:05'::macaddr8",
                '08:00:2b:01:02:03:04:05',
            ],

            // Bit strings, where the count says how much of the last byte counts.
            'bit'                       => ["b'1010'::bit(4)", '1010'],
            'varbit past one byte'      => ["b'1010101010101'::varbit", '1010101010101'],

            // Text search.
            'tsvector'                  => ["'a b'::tsvector", "'a' 'b'"],
            'tsvector with positions'   => [
                "to_tsvector('simple', 'the quick fox')",
                "'fox':3 'quick':2 'the':1",
            ],
            'tsquery'                   => ["'a & b'::tsquery", "'a' & 'b'"],
            'tsquery parenthesised'     => ["'(a | b) & c'::tsquery", "( 'a' | 'b' ) & 'c'"],
            'tsquery keeps precedence'  => ["'a | b & c'::tsquery", "'a' | 'b' & 'c'"],
            'tsquery negation'          => ["'!(a & b)'::tsquery", "!( 'a' & 'b' )"],
            'tsquery phrase'            => ["'a <3> b'::tsquery", "'a' <3> 'b'"],
            // The star goes before the weights, which is the sort of detail a
            // hand-written renderer gets wrong and the server never does.
            'tsquery prefix and weight' => ["'a:AB*'::tsquery", "'a':*AB"],

            // Geometry. A box prints its corners bare and upper-right first.
            'point'                     => ["'(1,2)'::point", '(1,2)'],
            'point with fractions'      => ["'(1.5,-2.25)'::point", '(1.5,-2.25)'],
            'point in exponent form'    => ["'(1e20,1e-7)'::point", '(1e+20,1e-07)'],
            'lseg'                      => ["'[(0,0),(1,1)]'::lseg", '[(0,0),(1,1)]'],
            'box'                       => ["'((0,0),(1,1))'::box", '(1,1),(0,0)'],
            'closed path'               => ["'((0,0),(1,1),(2,0))'::path", '((0,0),(1,1),(2,0))'],
            'open path'                 => ["'[(0,0),(1,1)]'::path", '[(0,0),(1,1)]'],
            'polygon'                   => ["'((0,0),(1,1),(2,0))'::polygon", '((0,0),(1,1),(2,0))'],
            'circle'                    => ["'<(0,0),1>'::circle", '<(0,0),1>'],
            'line'                      => ["'{1,2,3}'::line", '{1,2,3}'],

            // Arrays, and the quoting the array syntax forces.
            'array'                     => ['ARRAY[1,2,3]::int4[]', '{1,2,3}'],
            'array with a null'         => ['ARRAY[1,NULL,3]::int4[]', '{1,NULL,3}'],
            'two-dimensional array'     => ['ARRAY[[1,2],[3,4]]::int4[]', '{{1,2},{3,4}}'],
            'empty array'               => ["'{}'::int4[]", '{}'],
            'array not based at one'    => ["'[2:4]={7,8,9}'::int4[]", '[2:4]={7,8,9}'],
            'array quotes what it must' => [
                "ARRAY['a b', 'c,d', 'e\"f', '', 'NULL']::text[]",
                '{"a b","c,d","e\\"f","","NULL"}',
            ],
            'array of numerics'         => ['ARRAY[1.50, 2]::numeric[]', '{1.50,2}'],
            'array of floats'           => [
                'ARRAY[1e20::float8, 1e-7::float8, 2::float8]',
                '{1e+20,1e-07,2}',
            ],
            'array of booleans'         => ['ARRAY[true,false]', '{t,f}'],
            'array of dates'            => ["ARRAY['2026-12-06'::date]", '{2026-12-06}'],
            'array of timestamps'       => [
                "ARRAY['2026-12-06 14:30:00'::timestamp]",
                '{"2026-12-06 14:30:00"}',
            ],
            'array of timestamptz'      => [
                "ARRAY['2026-12-06 14:30:00+02'::timestamptz]",
                '{"2026-12-06 12:30:00+00"}',
            ],
            'array of bytea'            => ["ARRAY['\\x0102'::bytea]", '{"\\\\x0102"}'],
            'array of jsonb'            => ['ARRAY[\'{"a": 1}\'::jsonb]', '{"{\\"a\\": 1}"}'],

            // Ranges and multiranges.
            'range'                     => ["'[1,5)'::int4range", '[1,5)'],
            'empty range'               => ["'empty'::int4range", 'empty'],
            'unbounded range'           => ["'(,)'::int4range", '(,)'],
            'half-unbounded range'      => ["'(,5)'::int4range", '(,5)'],
            'range of timestamps'       => [
                "'[2026-01-01,2026-02-01)'::tsrange",
                '["2026-01-01 00:00:00","2026-02-01 00:00:00")',
            ],
            'multirange'                => ["'{[1,5),[7,9)}'::int4multirange", '{[1,5),[7,9)}'],

            // Records quote by their own rules: a doubled quote, and a null
            // written as nothing at all.
            'record'                     => ["ROW(1, 'a')", '(1,a)'],
            'record quotes what it must' => [
                "ROW(1, 'a,b', 'c\"d', NULL, '')",
                '(1,"a,b","c""d",,"")',
            ],
            'nested record'             => ["ROW(1, ROW(2, 'x'))", '(1,"(2,x)")'],
            'record holding an array'   => ['ROW(ARRAY[1,2])', '("{1,2}")'],

            // The odd ones out: vectors print space-separated, not as arrays.
            'oidvector'                 => ["'1 2 3'::oidvector", '1 2 3'],
            'int2vector'                => ["'1 2'::int2vector", '1 2'],
            'pg_lsn'                    => ["'0/16B374D'::pg_lsn", '0/16B374D'],
        ];
    }

    #[DataProvider('textFormProvider')]
    public function testAValueReachesPhpInItsPostgresTextForm(
        string $expression,
        string $expected,
    ): void {
        $rows = $this->connection->fetchAll(sql: "SELECT $expression AS v");

        self::assertSame($expected, $rows[0]['v']);
    }

    /**
     * OID and its two relatives are the one family pgx handed database/sql as an
     * integer, so PHP has always seen an int rather than a string.
     */
    public function testTheOidFamilyStaysAnInteger(): void
    {
        $rows = $this->connection->fetchAll(sql: "SELECT '42'::oid AS o, '42'::xid AS x");

        self::assertSame(42, $rows[0]['o']);
        self::assertSame(42, $rows[0]['x']);
    }

    /**
     * A user-defined enum, composite and domain resolve through the type sqlx
     * carries for the column rather than through a table of built-in OIDs, so
     * they work without being known here in advance.
     */
    public function testUserDefinedTypesRenderThroughTheColumnType(): void
    {
        $this->connection->exec(sql: 'DROP TYPE IF EXISTS sconcur_test_pair CASCADE');
        $this->connection->exec(sql: 'DROP TYPE IF EXISTS sconcur_test_mood CASCADE');
        $this->connection->exec(sql: 'DROP DOMAIN IF EXISTS sconcur_test_positive CASCADE');

        $this->connection->exec(sql: "CREATE TYPE sconcur_test_mood AS ENUM ('ok','sad')");
        $this->connection->exec(sql: 'CREATE TYPE sconcur_test_pair AS (a int, b text)');
        $this->connection->exec(sql: 'CREATE DOMAIN sconcur_test_positive AS int CHECK (VALUE > 0)');

        try {
            $rows = $this->connection->fetchAll(
                sql: "SELECT
                    'ok'::sconcur_test_mood                        AS enum_value,
                    ARRAY['ok','sad']::sconcur_test_mood[]         AS enum_array,
                    ROW(1,'x')::sconcur_test_pair                  AS composite_value,
                    ARRAY[ROW(1,'x')::sconcur_test_pair]           AS composite_array,
                    5::sconcur_test_positive                       AS domain_value,
                    ARRAY[5]::sconcur_test_positive[]              AS domain_array",
            );

            $row = $rows[0];

            self::assertSame('ok', $row['enum_value']);
            self::assertSame('{ok,sad}', $row['enum_array']);
            self::assertSame('(1,x)', $row['composite_value']);
            self::assertSame('{"(1,x)"}', $row['composite_array']);
            self::assertSame(5, $row['domain_value']);
            self::assertSame('{5}', $row['domain_array']);
        } finally {
            $this->connection->exec(sql: 'DROP TYPE IF EXISTS sconcur_test_pair CASCADE');
            $this->connection->exec(sql: 'DROP TYPE IF EXISTS sconcur_test_mood CASCADE');
            $this->connection->exec(sql: 'DROP DOMAIN IF EXISTS sconcur_test_positive CASCADE');
        }
    }

    /**
     * The two families no decoder could have answered. A `reg*` value is an OID
     * on the wire and an object name in text, which only a second query to the
     * catalog could supply; ACLITEM has no binary output function at all — ask
     * for it in that format and the server itself refuses. Both read fine here,
     * because the server is the one printing them.
     */
    public function testTheTypesWithNoBinaryFormReadAnyway(): void
    {
        $rows = $this->connection->fetchAll(
            sql: "SELECT
                'pg_class'::regclass AS class_name,
                (SELECT relacl FROM pg_class WHERE relacl IS NOT NULL LIMIT 1) AS privileges",
        );

        self::assertSame('pg_class', $rows[0]['class_name']);
        self::assertStringContainsString('=', (string) $rows[0]['privileges']);
    }

    /**
     * A streamed cursor reads its rows through the same decoder as `fetchAll`,
     * so the shapes cannot drift apart between the two paths.
     */
    public function testStreamingReadsTheSameShapes(): void
    {
        $collected = [];

        foreach (
            $this->connection->query(
                sql: "SELECT ARRAY[1,2] AS a, '1 day'::interval AS i FROM generate_series(1,3)",
                batchSize: 2,
            ) as $row
        ) {
            $collected[] = $row;
        }

        self::assertCount(3, $collected);

        foreach ($collected as $row) {
            self::assertSame('{1,2}', $row['a']);
            self::assertSame('1 day', $row['i']);
        }
    }

    /**
     * A failed statement leaves the connection usable. It matters more here than
     * it looks: a parameterised statement is a PREPARE/EXECUTE/DEALLOCATE batch,
     * and a failure part-way through must not leave a prepared statement behind
     * on the pooled connection.
     */
    public function testAConnectionSurvivesAFailedStatement(): void
    {
        try {
            $this->connection->fetchAll(sql: 'SELECT * FROM sconcur_no_such_table_42 WHERE id = $1', bindings: [1]);

            self::fail('the missing table should have thrown');
        } catch (Throwable) {
            // expected
        }

        $rows = $this->connection->fetchAll(sql: 'SELECT $1::int AS v', bindings: [1]);

        self::assertSame(1, $rows[0]['v']);
    }
}
