<?php

declare(strict_types=1);

namespace SConcur\Bson;

/**
 * Marker for a BSON value object, mirroring MongoDB\BSON\Type.
 *
 * These classes reproduce the ext-mongodb API one for one — same constructors,
 * same getters, same string and JSON forms — so moving an application to SConcur
 * is a change of `use` lines and nothing else.
 */
interface Type
{
}
