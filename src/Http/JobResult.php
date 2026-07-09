<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Http;

final class JobResult
{
    private const TERMINAL = ['succeeded', 'failed', 'cancelled'];

    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $errorMessage,
        /** @var array<string, string> concrete file name => presigned download URL */
        public readonly array $outputFiles,
        public readonly ?float $progress,
        public readonly ?float $speed,
        public readonly ?float $fps,
        public readonly ?float $etaSeconds,
        public readonly ?int $commandIndex,
        public readonly ?int $commandsTotal,
        /** @var array<string, mixed> */
        public readonly array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) ($data['id'] ?? ''),
            status: (string) ($data['status'] ?? 'unknown'),
            errorMessage: (string) ($data['error_message'] ?? ''),
            outputFiles: is_array($data['output_files'] ?? null) ? $data['output_files'] : [],
            progress: self::floatOrNull($data['progress'] ?? null),
            speed: self::floatOrNull($data['speed'] ?? null),
            fps: self::floatOrNull($data['fps'] ?? null),
            etaSeconds: self::floatOrNull($data['eta_seconds'] ?? null),
            commandIndex: isset($data['command_index']) ? (int) $data['command_index'] : null,
            commandsTotal: isset($data['commands_total']) ? (int) $data['commands_total'] : null,
            raw: $data,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
