<?php

declare(strict_types=1);

namespace SConcur\Telemetry\Render;

use SConcur\Telemetry\Dto\Aggregate;

/**
 * Renders the aggregate as JSON — the machine format, schema-identical to the old
 * Go /api/stats JSON.
 */
class JsonRenderer
{
    public function contentType(): string
    {
        return 'application/json';
    }

    public function render(Aggregate $aggregate): string
    {
        $json = json_encode($aggregate->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}
