<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Zupolgec\FFMpegApi\Http\ApiClient;

/**
 * Real round-trips against a live ffmpeg-api endpoint. Skipped unless both
 * FFMPEG_API_ENDPOINT and FFMPEG_API_KEY are set, e.g.:
 *
 *   FFMPEG_API_ENDPOINT=https://ffmpeg.example.com FFMPEG_API_KEY=sk_... \
 *     vendor/bin/pest --group=live
 */
function liveClient(): ApiClient
{
    $endpoint = getenv('FFMPEG_API_ENDPOINT') ?: '';
    $key = getenv('FFMPEG_API_KEY') ?: '';

    if ($endpoint === '' || $key === '') {
        test()->markTestSkipped('set FFMPEG_API_ENDPOINT and FFMPEG_API_KEY to run live tests');
    }

    return new ApiClient(new Factory, $endpoint, $key, waitTimeout: 120, connectTimeout: 10);
}

it('runs a single-output job and downloads the result', function () {
    $client = liveClient();

    $job = $client->run(
        inputFiles: [],
        outputFiles: ['clip.mp4'],
        commands: ['-f lavfi -i testsrc=duration=1:size=320x240:rate=10 -c:v libx264 -pix_fmt yuv420p {{clip.mp4}}'],
    );

    expect($job->succeeded())->toBeTrue()
        ->and($job->outputFiles)->toHaveKey('clip.mp4');

    $dest = sys_get_temp_dir().'/ffapi_live_clip.mp4';
    @unlink($dest);
    $client->download($job->outputFiles['clip.mp4'], $dest);

    expect(file_exists($dest))->toBeTrue()
        ->and(filesize($dest))->toBeGreaterThan(0);

    @unlink($dest);
})->group('live');

it('captures HLS segments through a glob', function () {
    $client = liveClient();

    $job = $client->run(
        inputFiles: [],
        outputFiles: ['index.m3u8', 'seg*.ts'],
        commands: ['-f lavfi -i testsrc=duration=6:size=320x240:rate=10 -c:v libx264 -pix_fmt yuv420p -force_key_frames expr:gte(t,n_forced*2) -f hls -hls_time 2 -hls_segment_filename seg%03d.ts -hls_list_size 0 {{index.m3u8}}'],
    );

    expect($job->succeeded())->toBeTrue()
        ->and($job->outputFiles)->toHaveKey('index.m3u8')
        ->and(collect(array_keys($job->outputFiles))->filter(fn ($k) => str_ends_with($k, '.ts'))->count())
        ->toBeGreaterThan(1);
})->group('live');

it('encodes on the GPU when routed to the nvidia machine', function () {
    if (! getenv('FFMPEG_API_HAS_NVIDIA')) {
        test()->markTestSkipped('set FFMPEG_API_HAS_NVIDIA=1 when the fleet has an NVENC-capable nvidia worker');
    }

    $client = liveClient();

    $job = $client->run(
        inputFiles: [],
        outputFiles: ['gpu.mp4'],
        commands: ['-f lavfi -i testsrc=duration=2:size=640x480:rate=25 -c:v h264_nvenc -b:v 4000k -pix_fmt yuv420p {{gpu.mp4}}'],
        machine: 'nvidia',
    );

    expect($job->succeeded())->toBeTrue()
        ->and($job->outputFiles)->toHaveKey('gpu.mp4');
})->group('live');

it('produces multiple outputs from one command', function () {
    $client = liveClient();

    $job = $client->run(
        inputFiles: [],
        outputFiles: ['clip.mp4', 'thumb.png'],
        commands: ['-f lavfi -i testsrc=duration=1:size=320x240:rate=10 -map 0:v -c:v libx264 -pix_fmt yuv420p {{clip.mp4}} -map 0:v -frames:v 1 {{thumb.png}}'],
    );

    expect($job->succeeded())->toBeTrue()
        ->and($job->outputFiles)->toHaveKey('clip.mp4')
        ->and($job->outputFiles)->toHaveKey('thumb.png');
})->group('live');

it('reports progress while polling a running job', function () {
    $client = liveClient();

    // A file input carries a discoverable duration, so progress is populated.
    $src = sys_get_temp_dir().'/ffapi_prog_src.mp4';
    @unlink($src);
    exec('ffmpeg -y -f lavfi -i testsrc=duration=8:size=640x480:rate=25 -c:v libx264 -pix_fmt yuv420p '.escapeshellarg($src).' 2>/dev/null', $o, $code);
    if ($code !== 0 || ! file_exists($src)) {
        test()->markTestSkipped('local ffmpeg needed to build the progress fixture');
    }

    $url = $client->uploadInput($src);

    $job = $client->submit(
        inputFiles: ['in.mp4' => $url],
        outputFiles: ['out.mp4'],
        commands: ['-i {{in.mp4}} -vf scale=1280:960 -c:v libx264 -preset slower -pix_fmt yuv420p {{out.mp4}}'],
    );

    $seen = [];
    $job = $client->await($job, function ($j) use (&$seen) {
        if ($j->progress !== null) {
            $seen[] = $j->progress;
        }
    });

    expect($job->succeeded())->toBeTrue()
        ->and($seen)->not->toBeEmpty()
        ->and(max($seen))->toBe(100.0);

    @unlink($src);
})->group('live');

it('uploads a local input and transcodes it', function () {
    $client = liveClient();

    // Make a tiny real mp4 locally to upload.
    $src = sys_get_temp_dir().'/ffapi_live_src.mp4';
    @unlink($src);
    exec('ffmpeg -y -f lavfi -i testsrc=duration=1:size=320x240:rate=10 -c:v libx264 -pix_fmt yuv420p '.escapeshellarg($src).' 2>/dev/null', $o, $code);
    if ($code !== 0 || ! file_exists($src)) {
        test()->markTestSkipped('local ffmpeg needed to build the upload fixture');
    }

    $url = $client->uploadInput($src);
    expect($url)->toStartWith('http');

    $job = $client->run(
        inputFiles: ['in.mp4' => $url],
        outputFiles: ['low.mp4'],
        commands: ['-i {{in.mp4}} -vf scale=160:120 -c:v libx264 -pix_fmt yuv420p {{low.mp4}}'],
    );

    expect($job->succeeded())->toBeTrue()
        ->and($job->outputFiles)->toHaveKey('low.mp4');

    @unlink($src);
})->group('live');
