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
        private readonly int $pollIntervalMs = 1000,
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
     * Submit a job without blocking; returns immediately with a queued job.
     *
     * @param  array<string, string>  $inputFiles  name => URL
     * @param  list<string>  $outputFiles  literal names or glob patterns
     * @param  list<string>  $commands
     */
    public function submit(array $inputFiles, array $outputFiles, array $commands, ?int $timeoutSeconds = null, ?string $machine = null): JobResult
    {
        $payload = [
            'input_files' => (object) $inputFiles,
            'output_files' => array_values($outputFiles),
            'ffmpeg_commands' => array_values($commands),
        ];

        if ($timeoutSeconds !== null) {
            $payload['timeout_seconds'] = $timeoutSeconds;
        }

        if ($machine !== null && $machine !== '') {
            $payload['machine'] = $machine;
        }

        $data = $this->api()
            ->timeout(30)
            ->post('/ffmpeg?wait=false', $payload)
            ->throw()
            ->json('data');

        return JobResult::fromArray($data ?? []);
    }

    public function getJob(string $id): JobResult
    {
        $data = $this->api()
            ->timeout(30)
            ->get('/jobs/'.$id)
            ->throw()
            ->json('data');

        return JobResult::fromArray($data ?? []);
    }

    /**
     * Poll a job to a terminal state, invoking $onTick(JobResult) after each
     * refresh (used to forward progress). Returns the terminal job.
     */
    public function await(JobResult $job, ?callable $onTick = null, ?int $timeoutSeconds = null): JobResult
    {
        $deadline = time() + ($timeoutSeconds ?? $this->waitTimeout);

        while (! $job->isTerminal()) {
            usleep($this->pollIntervalMs * 1000);
            $job = $this->getJob($job->id);

            if ($onTick !== null) {
                $onTick($job);
            }

            if (time() > $deadline) {
                throw new RemoteExecutionException("timed out waiting for job {$job->id} (last status: {$job->status})");
            }
        }

        return $job;
    }

    /**
     * Submit and block until the job finishes (no progress forwarding).
     *
     * @param  array<string, string>  $inputFiles
     * @param  list<string>  $outputFiles
     * @param  list<string>  $commands
     */
    public function run(array $inputFiles, array $outputFiles, array $commands, ?int $timeoutSeconds = null, ?string $machine = null): JobResult
    {
        return $this->await($this->submit($inputFiles, $outputFiles, $commands, $timeoutSeconds, $machine), null, $timeoutSeconds);
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
