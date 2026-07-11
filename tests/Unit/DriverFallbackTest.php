<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Zupolgec\FFMpegApi\Exceptions\RemoteExecutionException;
use Zupolgec\FFMpegApi\FFMpegApiDriver;
use Zupolgec\FFMpegApi\Http\ApiClient;

/**
 * Records every local execution instead of shelling out to the ffmpeg binary,
 * so the fallback path can be asserted without a binary or a live endpoint.
 */
final class SpyFallbackDriver extends FFMpegApiDriver
{
    /** @var list<mixed> */
    public array $localCalls = [];

    protected function runLocal($command, bool $bypassErrors, $listeners): string
    {
        $this->localCalls[] = $command;

        return 'local-ok';
    }
}

/**
 * @param  array<string, mixed>  $config
 */
function makeDriver(Factory $http, array $config, int $pollMs = 0): SpyFallbackDriver
{
    // Bypass FFMpegDriver::create(), which would try to locate a real binary.
    $driver = (new ReflectionClass(SpyFallbackDriver::class))->newInstanceWithoutConstructor();
    $driver->configureRemote(new ApiClient($http, 'http://ffmpeg.test', 'key', pollIntervalMs: $pollMs), $config);

    return $driver;
}

/** ffmpeg-api returns 500 for every job submission. */
function failingHttp(): Factory
{
    $http = new Factory;
    $http->fake([
        '*/api/ffmpeg*' => $http->response(['message' => 'boom'], 500),
    ]);

    return $http;
}

function twoPassCommands(): array
{
    // HTTP input so buildInputFiles() needs no upload; the failure is isolated
    // to job submission.
    $in = 'http://cdn.test/in.mp4';
    $out = sys_get_temp_dir().'/ffapi_fallback.mp4';
    $log = '/tmp/pass-'.substr(md5($out), 0, 8);

    return [
        ['-y', '-i', $in, '-c:v', 'libx264', '-b:v', '800k', '-pass', '1', '-passlogfile', $log, $out],
        ['-y', '-i', $in, '-c:v', 'libx264', '-b:v', '800k', '-pass', '2', '-passlogfile', $log, $out],
    ];
}

it('buffers pass 1 without executing it locally or remotely', function () {
    [$pass1] = twoPassCommands();
    $driver = makeDriver(failingHttp(), ['driver' => 'auto', 'fallback_to_local' => true]);

    $result = $driver->command($pass1);

    expect($result)->toContain('buffered pass 1')
        ->and($driver->localCalls)->toBe([]);
});

it('replays every buffered pass locally when the multipass job fails', function () {
    [$pass1, $pass2] = twoPassCommands();
    $driver = makeDriver(failingHttp(), ['driver' => 'auto', 'fallback_to_local' => true]);

    $driver->command($pass1);
    $driver->command($pass2);

    // The analysis pass AND the final pass both run locally, in order, so the
    // shared pass log is regenerated before the final pass.
    expect($driver->localCalls)->toBe([$pass1, $pass2]);
});

it('does not leak the pass buffer across independent multipass jobs', function () {
    [$pass1, $pass2] = twoPassCommands();
    $driver = makeDriver(failingHttp(), ['driver' => 'auto', 'fallback_to_local' => true]);

    $driver->command($pass1);
    $driver->command($pass2);
    $driver->command($pass1);
    $driver->command($pass2);

    // A second identical job replays exactly two passes again — no accumulation
    // of stale buffered passes from the first job.
    expect($driver->localCalls)->toBe([$pass1, $pass2, $pass1, $pass2]);
});

it('falls back for a single remote job by running it locally once', function () {
    $in = 'http://cdn.test/in.mp4';
    $out = sys_get_temp_dir().'/ffapi_single.mp4';
    $cmd = ['-y', '-i', $in, '-c:v', 'libx264', $out];

    $driver = makeDriver(failingHttp(), ['driver' => 'auto', 'fallback_to_local' => true]);

    expect($driver->command($cmd))->toBe('local-ok')
        ->and($driver->localCalls)->toBe([$cmd]);
});

it('throws instead of falling back when remote mode forbids fallback', function () {
    [$pass1, $pass2] = twoPassCommands();
    $driver = makeDriver(failingHttp(), ['driver' => 'remote', 'fallback_to_local' => false]);

    $driver->command($pass1);

    expect(fn () => $driver->command($pass2))->toThrow(RemoteExecutionException::class)
        ->and($driver->localCalls)->toBe([]);
});

it('runs a remote job, forwards progress, and downloads the reconciled output', function () {
    $out = sys_get_temp_dir().'/recon_'.substr(md5((string) getmypid()), 0, 8).'.mp4';
    if (is_file($out)) {
        unlink($out);
    }

    $http = new Factory;
    $http->fake([
        '*/api/ffmpeg*' => $http->response(['data' => ['id' => 'job1', 'status' => 'queued']]),
        '*/api/jobs/*' => $http->sequence()
            ->push(['data' => ['id' => 'job1', 'status' => 'processing', 'progress' => 50, 'eta_seconds' => 10, 'speed' => 2]])
            ->push(['data' => ['id' => 'job1', 'status' => 'succeeded', 'progress' => 100, 'output_files' => [basename($out) => 'http://dl.test/out.mp4']]]),
        'http://dl.test/*' => $http->response('REMOTE-OUTPUT-BYTES', 200),
    ]);

    $driver = makeDriver($http, ['driver' => 'auto', 'fallback_to_local' => true]);

    $listener = new class
    {
        /** @var list<array{float, float, float}> */
        public array $progress = [];

        public function emit(string $event, array $args): void
        {
            if ($event === 'progress') {
                $this->progress[] = $args;
            }
        }
    };

    $result = $driver->command(['-y', '-i', 'http://cdn.test/in.mp4', '-c:v', 'libx264', $out], false, [$listener]);

    expect($result)->toContain('succeeded')
        ->and($driver->localCalls)->toBe([])
        ->and($listener->progress)->toBe([[50.0, 10.0, 2.0], [100.0, 0, 0]])
        ->and(file_get_contents($out))->toBe('REMOTE-OUTPUT-BYTES');

    if (is_file($out)) { unlink($out); }
});

it('throws when the job succeeds but omits an expected output', function () {
    $out = sys_get_temp_dir().'/missing_out.mp4';

    $http = new Factory;
    $http->fake([
        '*/api/ffmpeg*' => $http->response(['data' => ['id' => 'job2', 'status' => 'succeeded', 'output_files' => []]]),
    ]);

    // remote mode without fallback surfaces the reconciliation failure loudly.
    $driver = makeDriver($http, ['driver' => 'remote', 'fallback_to_local' => false]);

    expect(fn () => $driver->command(['-y', '-i', 'http://cdn.test/in.mp4', '-c:v', 'libx264', $out]))
        ->toThrow(RemoteExecutionException::class);
});

it('presigns, uploads a local input, and returns its download URL', function () {
    $local = tempnam(sys_get_temp_dir(), 'ffapi_up_');
    file_put_contents($local, 'INPUT-BYTES');

    $captured = null;
    $http = new Factory;
    $http->fake([
        '*/api/tmp-file' => $http->response(['data' => ['upload_url' => 'http://up.test/slot', 'download_url' => 'http://dl.test/slot']]),
        'http://up.test/*' => function ($request) use (&$captured, $http) {
            $captured = $request->body();

            return $http->response('', 200);
        },
    ]);

    $client = new ApiClient($http, 'http://ffmpeg.test', 'key');
    $url = $client->uploadInput($local);

    expect($url)->toBe('http://dl.test/slot')
        ->and($captured)->toBe('INPUT-BYTES');

    if (is_file($local)) { unlink($local); }
});
