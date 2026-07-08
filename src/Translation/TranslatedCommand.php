<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Translation;

/**
 * The result of translating a local ffmpeg argv into an ffmpeg-api job.
 *
 * When $remotable is false the command must run on the local binary and
 * $reason explains why (used for logging only).
 */
final class TranslatedCommand
{
    private function __construct(
        public readonly bool $remotable,
        public readonly ?string $reason,
        /** @var array<string, string> input name => local path or http(s) URL */
        public readonly array $inputs,
        /** @var list<OutputTarget> */
        public readonly array $outputs,
        public readonly ?string $commandString,
    ) {}

    public static function local(string $reason): self
    {
        return new self(false, $reason, [], [], null);
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  list<OutputTarget>  $outputs
     */
    public static function remote(array $inputs, array $outputs, string $commandString): self
    {
        return new self(true, null, $inputs, $outputs, $commandString);
    }
}
