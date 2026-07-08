<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Translation;

/**
 * A `-hls_key_info_file` reference that the driver must materialise remotely:
 * upload the key, rewrite the keyinfo to point at a workdir-relative key name,
 * and upload the rewritten keyinfo as an input.
 */
final class KeyInfoRequest
{
    public function __construct(
        /** Input name / placeholder for the (rewritten) keyinfo file. */
        public readonly string $name,
        /** Original keyinfo file on local disk. */
        public readonly string $localPath,
        /** Workdir-relative name the key is uploaded under and the keyinfo points to. */
        public readonly string $keyName = 'enc.key',
    ) {}
}
