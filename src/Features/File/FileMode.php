<?php

declare(strict_types=1);

namespace SConcur\Features\File;

use SConcur\Exceptions\File\InvalidFileModeException;

/**
 * A validated fopen-style mode. The single source of truth for the supported mode
 * set on the PHP side; the platform open flags themselves live on the Go side
 * (file_feature.modeToFlags), so PHP never hardcodes O_* constants.
 *
 * The optional binary/text suffix (b/t) is accepted and stripped — Unix is
 * binary-safe by default, so it carries no meaning here.
 */
readonly class FileMode
{
    /** @var list<string> */
    protected const array ALLOWED = ['r', 'r+', 'w', 'w+', 'a', 'a+', 'x', 'x+', 'c', 'c+'];

    protected function __construct(
        public string $value,
    ) {
    }

    public static function fromString(string $mode): self
    {
        $normalized = str_replace(['b', 't'], '', $mode);

        if (!in_array($normalized, self::ALLOWED, true)) {
            throw new InvalidFileModeException(
                message: "Invalid file mode [$mode].",
            );
        }

        return new self(value: $normalized);
    }

    /**
     * Read-only (`r`) and every read/write (`+`) mode can read; the write-only
     * modes (`w`, `a`, `x`, `c`) cannot.
     */
    public function isReadable(): bool
    {
        return ($this->value === 'r') || str_contains($this->value, '+');
    }

    /**
     * Every mode except plain `r` can write.
     */
    public function isWritable(): bool
    {
        return $this->value !== 'r';
    }
}
