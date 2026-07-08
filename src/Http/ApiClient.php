<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Http;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Zupolgec\FFMpegApi\Exceptions\RemoteExecutionException;

/**
 * Thin client for an ffmpeg-api compatible endpoint (verygoodffmpeg.com or a
 * self-hosted instance). Five endpoints under {endpoint}/api, bearer auth.
 */
final class ApiClient
{
    public function __construct(
        private readonly Factory $http,
        private readonly string $endpoint,
        private readonly string $key,
        private readonly int $waitTimeout = 1800,
        private readonly int $connectTimeout = 10,
    ) {}

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->key !== '';
    }

    /**
     * Presign a temporary slot, stream the local file to it with a PUT, and
     * return a download URL the agent can fetch as an input.
     */
    public function uploadInput(string $localPath): string
    {
        $pair = $this->api()
            ->timeout(30)
            ->post('/tmp-file')
            ->throw()
            ->json('data');

        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RemoteExecutionException("cannot open input for upload: {$localPath}");
        }

        try {
            $this->http
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->waitTimeout)
                ->withBody($handle)
                ->put($pair['upload_url'])
                ->throw();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return $pair['download_url'];
    }

    /**
     * Submit a blocking job and return the completed record.
     *
     * @param  array<string, string>  $inputFiles  name => URL
     * @param  list<string>  $outputFiles  literal names or glob patterns
     * @param  list<string>  $commands
     */
    public function run(array $inputFiles, array $outputFiles, array $commands, ?int $timeoutSeconds = null): JobResult
    {
        $payload = [
            'input_files' => (object) $inputFiles,
            'output_files' => array_values($outputFiles),
            'ffmpeg_commands' => array_values($commands),
        ];

        if ($timeoutSeconds !== null) {
            $payload['timeout_seconds'] = $timeoutSeconds;
        }

        $data = $this->api()
            ->timeout($this->waitTimeout)
            ->post('/ffmpeg?wait=true', $payload)
            ->throw()
            ->json('data');

        return JobResult::fromArray($data ?? []);
    }

    /**
     * Stream a produced output URL to a local path.
     */
    public function download(string $url, string $localPath): void
    {
        $dir = dirname($localPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->http
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->waitTimeout)
            ->sink($localPath)
            ->get($url)
            ->throw();
    }

    private function api(): PendingRequest
    {
        return $this->http
            ->baseUrl(rtrim($this->endpoint, '/').'/api')
            ->withToken($this->key)
            ->connectTimeout($this->connectTimeout)
            ->acceptJson();
    }
}
