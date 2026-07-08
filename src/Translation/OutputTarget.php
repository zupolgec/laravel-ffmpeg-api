<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Translation;

/**
 * Where a produced output must land locally so laravel-ffmpeg's
 * copyAllFromTemporaryDirectory() can pick it up afterwards.
 */
final class OutputTarget
{
    private function __construct(
        /** API output_files entry: a literal file name or a glob pattern. */
        public readonly string $name,
        public readonly bool $isGlob,
        /** Destination for a literal output (the original argv path). */
        public readonly ?string $localPath,
        /** Destination directory for glob outputs (e.g. HLS segments). */
        public readonly ?string $localDir,
    ) {}

    public static function literal(string $name, string $localPath): self
    {
        return new self($name, false, $localPath, null);
    }

    public static function glob(string $pattern, string $localDir): self
    {
        return new self($pattern, true, null, $localDir);
    }
}
