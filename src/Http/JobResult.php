<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Http;

final class JobResult
{
    public function __construct(
        public readonly string $id,
        public readonly string $status,
        public readonly string $errorMessage,
        /** @var array<string, string> concrete file name => presigned download URL */
        public readonly array $outputFiles,
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
            raw: $data,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === 'succeeded';
    }
}
