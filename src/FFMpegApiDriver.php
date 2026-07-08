<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi;

use FFMpeg\Driver\FFMpegDriver;
use Psr\Log\LoggerInterface;
use Throwable;
use Zupolgec\FFMpegApi\Exceptions\RemoteExecutionException;
use Zupolgec\FFMpegApi\Http\ApiClient;
use Zupolgec\FFMpegApi\Http\JobResult;
use Zupolgec\FFMpegApi\Translation\CommandTranslator;
use Zupolgec\FFMpegApi\Translation\TranslatedCommand;

/**
 * A php-ffmpeg driver that routes remotable commands to an ffmpeg-api endpoint
 * instead of the local binary. It subclasses FFMpegDriver so the local binary
 * stays fully available for fallback via parent::command().
 */
class FFMpegApiDriver extends FFMpegDriver
{
    private ?ApiClient $client = null;

    private string $mode = 'auto';

    private bool $fallbackToLocal = true;

    private ?int $timeoutSeconds = null;

    private ?LoggerInterface $apiLogger = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function configureRemote(ApiClient $client, array $config, ?LoggerInterface $logger = null): void
    {
        $this->client = $client;
        $this->mode = (string) ($config['driver'] ?? 'auto');
        $this->fallbackToLocal = (bool) ($config['fallback_to_local'] ?? true);
        $this->timeoutSeconds = isset($config['wait_timeout']) ? (int) $config['wait_timeout'] : null;
        $this->apiLogger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function command($command, $bypassErrors = false, $listeners = null)
    {
        if ($this->mode === 'local' || $this->client === null || ! $this->client->isConfigured()) {
            return parent::command($command, $bypassErrors, $listeners);
        }

        $argv = is_array($command) ? array_values($command) : [$command];

        $plan = (new CommandTranslator)->translate($argv);

        if (! $plan->remotable) {
            $this->apiLogger?->debug('[ffmpeg-api] local: '.$plan->reason);

            return parent::command($command, $bypassErrors, $listeners);
        }

        try {
            return $this->runRemote($plan);
        } catch (Throwable $e) {
            if ($this->mode === 'remote' && ! $this->fallbackToLocal) {
                throw new RemoteExecutionException('remote ffmpeg execution failed: '.$e->getMessage(), 0, $e);
            }

            $this->apiLogger?->warning('[ffmpeg-api] remote failed, running locally: '.$e->getMessage());

            return parent::command($command, $bypassErrors, $listeners);
        }
    }

    private function runRemote(TranslatedCommand $plan): string
    {
        $inputFiles = [];
        foreach ($plan->inputs as $name => $pathOrUrl) {
            $inputFiles[$name] = preg_match('#^https?://#i', $pathOrUrl)
                ? $pathOrUrl
                : $this->client->uploadInput($pathOrUrl);
        }

        $outputDecls = array_map(static fn ($o) => $o->name, $plan->outputs);

        $job = $this->client->run($inputFiles, $outputDecls, [$plan->commandString], $this->timeoutSeconds);

        if (! $job->succeeded()) {
            throw new RemoteExecutionException("job {$job->id} {$job->status}: {$job->errorMessage}");
        }

        $this->reconcileOutputs($plan, $job);

        return "[ffmpeg-api] job {$job->id} succeeded";
    }

    private function reconcileOutputs(TranslatedCommand $plan, JobResult $job): void
    {
        $remaining = $job->outputFiles; // name => url

        foreach ($plan->outputs as $output) {
            if ($output->isGlob) {
                foreach ($remaining as $name => $url) {
                    if (fnmatch($output->name, $name)) {
                        $this->client->download($url, rtrim((string) $output->localDir, '/').'/'.$name);
                        unset($remaining[$name]);
                    }
                }

                continue;
            }

            if (! isset($remaining[$output->name])) {
                throw new RemoteExecutionException(
                    "job {$job->id} did not return expected output '{$output->name}'"
                );
            }

            $this->client->download($remaining[$output->name], (string) $output->localPath);
            unset($remaining[$output->name]);
        }
    }
}
