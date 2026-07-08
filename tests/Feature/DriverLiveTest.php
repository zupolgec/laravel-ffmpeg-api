<?php

declare(strict_types=1);

use FFMpeg\Driver\FFMpegDriver;
use Illuminate\Http\Client\Factory;
use Zupolgec\FFMpegApi\FFMpegApiDriver;
use Zupolgec\FFMpegApi\Http\ApiClient;

/**
 * End-to-end tests that drive the real FFMpegApiDriver (translation + upload +
 * remote run + download) against a live endpoint. Skipped unless
 * FFMPEG_API_ENDPOINT and FFMPEG_API_KEY are set, and a local ffmpeg exists
 * (needed to build fixtures and for the driver's local-fallback binary).
 */
function liveDriver(): FFMpegApiDriver
{
    $endpoint = getenv('FFMPEG_API_ENDPOINT') ?: '';
    $key = getenv('FFMPEG_API_KEY') ?: '';

    if ($endpoint === '' || $key === '') {
        test()->markTestSkipped('set FFMPEG_API_ENDPOINT and FFMPEG_API_KEY to run live tests');
    }

    /** @var FFMpegApiDriver $driver */
    $driver = FFMpegApiDriver::create(
        app('laravel-ffmpeg-logger'),
        app('laravel-ffmpeg-configuration'),
    );

    // fallback_to_local = false so remote failures throw and fail the test loudly.
    $driver->configureRemote(
        new ApiClient(new Factory, $endpoint, $key, waitTimeout: 180, connectTimeout: 10),
        ['driver' => 'auto', 'fallback_to_local' => false, 'wait_timeout' => 180],
    );

    return $driver;
}

function makeFixtureMp4(string $extraArgs = ''): string
{
    $src = tempnam(sys_get_temp_dir(), 'ffapi_fix_').'.mp4';
    @unlink($src);
    exec('ffmpeg -y -f lavfi -i testsrc=duration=2:size=320x240:rate=10 -c:v libx264 -pix_fmt yuv420p '.$extraArgs.' '.escapeshellarg($src).' 2>/dev/null', $o, $code);
    if ($code !== 0 || ! file_exists($src)) {
        test()->markTestSkipped('local ffmpeg needed to build the fixture');
    }

    return $src;
}

it('runs a real 2-pass encode as a single chained remote job', function () {
    $driver = liveDriver();
    $in = makeFixtureMp4();
    $out = sys_get_temp_dir().'/ffapi_2pass_out.mp4';
    $passlog = tempnam(sys_get_temp_dir(), 'ffapi_passlog_');
    @unlink($out);

    $base = ['-y', '-i', $in, '-c:v', 'libx264', '-b:v', '250k'];

    // Emulate php-ffmpeg: two separate command() calls sharing one passlogfile.
    $driver->command([...$base, '-pass', '1', '-passlogfile', $passlog, $out]);
    expect(file_exists($out))->toBeFalse('pass 1 must be buffered, producing nothing yet');

    $driver->command([...$base, '-pass', '2', '-passlogfile', $passlog, $out]);

    expect(file_exists($out))->toBeTrue()
        ->and(filesize($out))->toBeGreaterThan(0);

    @unlink($in);
    @unlink($out);
})->group('live');

it('encrypts HLS remotely by uploading the key and rewriting the keyinfo', function () {
    $driver = liveDriver();
    $in = makeFixtureMp4();
    $dir = sys_get_temp_dir().'/ffapi_enc_'.substr(md5($in), 0, 8);
    @mkdir($dir, 0775, true);

    $keyFile = $dir.'/secret.key';
    file_put_contents($keyFile, random_bytes(16));

    // keyinfo: line 1 = URI written into the playlist, line 2 = local key path.
    $keyInfo = $dir.'/enc.keyinfo';
    file_put_contents($keyInfo, "https://cdn.example.com/keys/secret.key\n".$keyFile."\n");

    $m3u8 = $dir.'/index.m3u8';

    $driver->command([
        '-y', '-i', $in,
        '-c:v', 'libx264', '-pix_fmt', 'yuv420p',
        '-force_key_frames', 'expr:gte(t,n_forced*1)',
        '-hls_time', '1', '-hls_list_size', '0',
        '-hls_key_info_file', $keyInfo,
        '-hls_segment_filename', $dir.'/seg_%03d.ts',
        '-f', 'hls', $m3u8,
    ]);

    expect(file_exists($m3u8))->toBeTrue();
    $playlist = file_get_contents($m3u8);
    expect($playlist)->toContain('#EXT-X-KEY')
        ->and($playlist)->toContain('https://cdn.example.com/keys/secret.key')
        ->and(glob($dir.'/seg_*.ts'))->not->toBeEmpty();

    array_map('unlink', glob($dir.'/*'));
    @rmdir($dir);
    @unlink($in);
})->group('live');
