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
use Zupolgec\FFMpegApi\Translation\KeyInfoRequest;
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
     * Buffered analysis passes, keyed by the shared -passlogfile identity.
     *
     * @var array<string, list<TranslatedCommand>>
     */
    private array $passBuffers = [];

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
            return $plan->isPass()
                ? $this->runPass($plan)
                : $this->runRemote($plan);
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
        $job = $this->client->run(
            $this->buildInputFiles($plan),
            array_map(static fn ($o) => $o->name, $plan->outputs),
            [$plan->commandString],
            $this->timeoutSeconds,
        );

        $this->assertSucceeded($job);
        $this->reconcileOutputs($plan, $job);

        return "[ffmpeg-api] job {$job->id} succeeded";
    }

    /**
     * Multipass: buffer the analysis pass(es), then chain them with the final
     * pass into ONE remote job so the shared pass log survives across passes
     * (they run sequentially in the same node workdir).
     */
    private function runPass(TranslatedCommand $plan): string
    {
        $id = (string) $plan->passLogId;

        if (($plan->passNumber ?? 0) <= 1) {
            $this->passBuffers[$id] = [$plan];

            return '[ffmpeg-api] buffered pass 1';
        }

        $buffered = $this->passBuffers[$id] ?? [];
        $this->passBuffers[$id] = [...$buffered, $plan];

        $commands = array_map(static fn (TranslatedCommand $p) => $p->analysisCommand(), $buffered);
        $commands[] = (string) $plan->commandString;

        $job = $this->client->run(
            $this->buildInputFiles($plan),
            array_map(static fn ($o) => $o->name, $plan->outputs),
            $commands,
            $this->timeoutSeconds,
        );

        $this->assertSucceeded($job);
        $this->reconcileOutputs($plan, $job);

        return "[ffmpeg-api] multipass job {$job->id} succeeded";
    }

    /**
     * @return array<string, string> input name => URL
     */
    private function buildInputFiles(TranslatedCommand $plan): array
    {
        $inputFiles = [];

        foreach ($plan->inputs as $name => $pathOrUrl) {
            $inputFiles[$name] = preg_match('#^https?://#i', $pathOrUrl)
                ? $pathOrUrl
                : $this->client->uploadInput($pathOrUrl);
        }

        if ($plan->keyInfo !== null) {
            $this->attachKeyInfo($plan->keyInfo, $inputFiles);
        }

        return $inputFiles;
    }

    /**
     * Encrypted HLS: upload the key under a workdir-relative name, upload a
     * keyinfo rewritten to point at it (URI line kept verbatim), so the node
     * encrypts with a key path it can actually resolve.
     *
     * @param  array<string, string>  $inputFiles
     */
    private function attachKeyInfo(KeyInfoRequest $keyInfo, array &$inputFiles): void
    {
        $lines = @file($keyInfo->localPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false || count($lines) < 2) {
            throw new RemoteExecutionException("cannot read keyinfo: {$keyInfo->localPath}");
        }

        [$uri, $keyPath] = $lines;
        $iv = $lines[2] ?? null;

        if (! is_file($keyPath)) {
            throw new RemoteExecutionException("key file not found: {$keyPath}");
        }

        $inputFiles[$keyInfo->keyName] = $this->client->uploadInput($keyPath);

        $content = $uri."\n".$keyInfo->keyName."\n";
        if ($iv !== null && $iv !== '') {
            $content .= $iv."\n";
        }

        $tmp = tempnam(sys_get_temp_dir(), 'ffapi_keyinfo_');
        file_put_contents($tmp, $content);

        try {
            $inputFiles[$keyInfo->name] = $this->client->uploadInput($tmp);
        } finally {
            @unlink($tmp);
        }
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

    private function assertSucceeded(JobResult $job): void
    {
        if (! $job->succeeded()) {
            throw new RemoteExecutionException("job {$job->id} {$job->status}: {$job->errorMessage}");
        }
    }
}
