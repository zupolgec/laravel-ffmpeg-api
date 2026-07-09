<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Translation;

/**
 * The result of translating a local ffmpeg argv into an ffmpeg-api job.
 *
 * When $remotable is false the command must run on the local binary and
 * $reason explains why (used for logging only).
 *
 * Multipass commands are remotable: php-ffmpeg emits each pass as a separate
 * command() call sharing one -passlogfile, so $passNumber / $passLogId let the
 * driver correlate them and chain the passes into a single remote job.
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
        public readonly ?int $passNumber = null,
        public readonly ?string $passLogId = null,
        public readonly ?KeyInfoRequest $keyInfo = null,
        /** Preferred worker pool: 'nvidia' when the command uses NVIDIA encoders/filters, else null. */
        public readonly ?string $machine = null,
    ) {}

    public static function local(string $reason): self
    {
        return new self(false, $reason, [], [], null);
    }

    /**
     * @param  array<string, string>  $inputs
     * @param  list<OutputTarget>  $outputs
     */
    public static function remote(
        array $inputs,
        array $outputs,
        string $commandString,
        ?int $passNumber = null,
        ?string $passLogId = null,
        ?KeyInfoRequest $keyInfo = null,
        ?string $machine = null,
    ): self {
        return new self(true, null, $inputs, $outputs, $commandString, $passNumber, $passLogId, $keyInfo, $machine);
    }

    public function isPass(): bool
    {
        return $this->passNumber !== null && $this->passLogId !== null;
    }

    /**
     * Render this pass as an analysis-only command: the real output is replaced
     * by a null sink so it produces no file, only the shared pass log.
     */
    public function analysisCommand(): string
    {
        $primary = $this->outputs[0] ?? null;

        if ($primary !== null && ! $primary->isGlob && $this->commandString !== null) {
            return str_replace('{{'.$primary->name.'}}', '-f null /dev/null', $this->commandString);
        }

        return (string) $this->commandString;
    }
}
