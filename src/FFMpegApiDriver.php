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

    private ?string $machineOverride = null;

    private ?LoggerInterface $apiLogger = null;

    /**
     * Buffered passes, keyed by the shared -passlogfile identity. Each entry
     * keeps the translated plan (for chaining into the remote job) alongside
     * the original local argv (so a fallback can replay the pass locally).
     *
     * @var array<string, list<array{plan: TranslatedCommand, command: mixed, bypassErrors: bool, listeners: mixed}>>
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
        $this->machineOverride = ($config['machine'] ?? null) ?: null;
        $this->apiLogger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function command($command, $bypassErrors = false, $listeners = null)
    {
        if ($this->mode === 'local' || $this->client === null || ! $this->client->isConfigured()) {
            return $this->runLocal($command, $bypassErrors, $listeners);
        }

        $argv = is_array($command) ? array_values($command) : [$command];

        $plan = (new CommandTranslator)->translate($argv);

        if (! $plan->remotable) {
            $this->apiLogger?->debug('[ffmpeg-api] local: '.$plan->reason);

            return $this->runLocal($command, $bypassErrors, $listeners);
        }

        $progressListeners = $this->normalizeListeners($listeners);

        try {
            return $plan->isPass()
                ? $this->runPass($plan, $command, $bypassErrors, $listeners, $progressListeners)
                : $this->runRemote($plan, $progressListeners);
        } catch (Throwable $e) {
            if ($this->mode === 'remote' && ! $this->fallbackToLocal) {
                $this->forgetPasses($plan);

                throw new RemoteExecutionException('remote ffmpeg execution failed: '.$e->getMessage(), 0, $e);
            }

            $this->apiLogger?->warning('[ffmpeg-api] remote failed, running locally: '.$e->getMessage());

            return $this->runLocalFallback($plan, $command, $bypassErrors, $listeners);
        }
    }

    /**
     * Run a command on the local ffmpeg binary. Seam over parent::command() so
     * the fallback path is observable in tests without a real binary.
     */
    protected function runLocal($command, bool $bypassErrors, $listeners): string
    {
        return parent::command($command, $bypassErrors, $listeners);
    }

    /**
     * @param  list<object>  $listeners
     */
    private function runRemote(TranslatedCommand $plan, array $listeners): string
    {
        $job = $this->runJob(
            $this->buildInputFiles($plan),
            array_map(static fn ($o) => $o->name, $plan->outputs),
            [$plan->commandString],
            $listeners,
            $this->resolveMachine($plan),
        );

        $this->reconcileOutputs($plan, $job);

        return "[ffmpeg-api] job {$job->id} succeeded";
    }

    /**
     * Multipass: buffer the analysis pass(es), then chain them with the final
     * pass into ONE remote job so the shared pass log survives across passes
     * (they run sequentially in the same node workdir).
     *
     * @param  list<object>  $progressListeners
     */
    private function runPass(TranslatedCommand $plan, $command, bool $bypassErrors, $listeners, array $progressListeners): string
    {
        $id = (string) $plan->passLogId;
        $entry = ['plan' => $plan, 'command' => $command, 'bypassErrors' => $bypassErrors, 'listeners' => $listeners];

        if (($plan->passNumber ?? 0) <= 1) {
            $this->passBuffers[$id] = [$entry];

            return '[ffmpeg-api] buffered pass 1';
        }

        $buffered = $this->passBuffers[$id] ?? [];
        // Record the final pass before submitting so a fallback can replay the
        // whole chain even if job submission itself throws.
        $this->passBuffers[$id] = [...$buffered, $entry];

        $commands = array_map(static fn (array $e) => $e['plan']->analysisCommand(), $buffered);
        $commands[] = (string) $plan->commandString;

        $job = $this->runJob(
            $this->buildInputFiles($plan),
            array_map(static fn ($o) => $o->name, $plan->outputs),
            $commands,
            $progressListeners,
            $this->resolveMachine($plan),
        );

        $this->reconcileOutputs($plan, $job);
        unset($this->passBuffers[$id]);

        return "[ffmpeg-api] multipass job {$job->id} succeeded";
    }

    /**
     * Fall back to the local binary after a remote failure. For a multipass
     * final pass the earlier passes were only buffered for the remote job and
     * never executed, so replaying just the final pass locally would run
     * without the shared pass log. Replay every buffered pass locally, in
     * order, so the pass log is regenerated before the final pass.
     */
    private function runLocalFallback(TranslatedCommand $plan, $command, bool $bypassErrors, $listeners): string
    {
        if (! $plan->isPass() || ($plan->passNumber ?? 0) <= 1) {
            return $this->runLocal($command, $bypassErrors, $listeners);
        }

        $buffered = $this->passBuffers[(string) $plan->passLogId] ?? [];
        $this->forgetPasses($plan);

        if ($buffered === []) {
            return $this->runLocal($command, $bypassErrors, $listeners);
        }

        $result = '';
        foreach ($buffered as $entry) {
            $result = $this->runLocal($entry['command'], $entry['bypassErrors'], $entry['listeners']);
        }

        return $result;
    }

    private function forgetPasses(TranslatedCommand $plan): void
    {
        if ($plan->passLogId !== null) {
            unset($this->passBuffers[(string) $plan->passLogId]);
        }
    }

    /**
     * Submit the job and poll to completion, forwarding progress to any
     * php-ffmpeg progress listeners so laravel-ffmpeg's onProgress() fires.
     *
     * @param  array<string, string>  $inputFiles
     * @param  list<string>  $outputDecls
     * @param  list<string>  $commands
     * @param  list<object>  $listeners
     */
    private function runJob(array $inputFiles, array $outputDecls, array $commands, array $listeners, ?string $machine = null): JobResult
    {
        $job = $this->client->submit($inputFiles, $outputDecls, $commands, $this->timeoutSeconds, $machine);

        $onTick = $listeners === [] ? null : function (JobResult $j) use ($listeners): void {
            if ($j->progress === null) {
                return;
            }
            // The listener's 'progress' event is wired to the format, which drives
            // laravel-ffmpeg's onProgress($percentage, $remaining, $rate).
            foreach ($listeners as $listener) {
                $listener->emit('progress', [$j->progress, $j->etaSeconds ?? 0, $j->speed ?? 0]);
            }
        };

        $job = $this->client->await($job, $onTick, $this->timeoutSeconds);

        if (! $job->succeeded()) {
            throw new RemoteExecutionException("job {$job->id} {$job->status}: {$job->errorMessage}");
        }

        return $job;
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

    /**
     * The configured machine override wins; otherwise use what the command
     * implies (nvidia when it uses NVIDIA encoders/filters).
     */
    private function resolveMachine(TranslatedCommand $plan): ?string
    {
        return $this->machineOverride ?? $plan->machine;
    }

    /**
     * Normalise the listeners argument to a list of progress emitters.
     *
     * @return list<object>
     */
    private function normalizeListeners($listeners): array
    {
        if ($listeners === null) {
            return [];
        }

        $listeners = is_array($listeners) ? $listeners : [$listeners];

        return array_values(array_filter(
            $listeners,
            static fn ($l) => is_object($l) && method_exists($l, 'emit'),
        ));
    }
}
