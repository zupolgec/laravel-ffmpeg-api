<?php

declare(strict_types=1);

use Zupolgec\FFMpegApi\Translation\CommandTranslator;

function tmpInput(string $suffix = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'ffapi_').$suffix;
    file_put_contents($path, 'x');

    return $path;
}

it('translates a single-output transcode into a remote job', function () {
    $in = tmpInput();
    $out = sys_get_temp_dir().'/ffapi_out.mp4';

    $plan = (new CommandTranslator)->translate([
        '-y', '-i', $in, '-vf', 'scale=160:120', '-c:v', 'libx264', $out,
    ]);

    expect($plan->remotable)->toBeTrue()
        ->and($plan->inputs)->toHaveCount(1)
        ->and($plan->outputs)->toHaveCount(1)
        ->and($plan->outputs[0]->isGlob)->toBeFalse()
        ->and($plan->outputs[0]->localPath)->toBe($out)
        ->and($plan->commandString)->toContain('{{'.array_key_first($plan->inputs).'}}')
        ->and($plan->commandString)->toContain('{{'.$plan->outputs[0]->name.'}}')
        ->and($plan->isPass())->toBeFalse();

    unlink($in);
});

it('double-quotes a filter token that carries single quotes so the remote splitter keeps them', function () {
    $in = tmpInput();

    $plan = (new CommandTranslator)->translate([
        '-i', $in, '-vf', "scale='min(2880,iw)':-2", sys_get_temp_dir().'/o.mp4',
    ]);

    expect($plan->remotable)->toBeTrue()
        ->and($plan->commandString)->toContain('"scale=\'min(2880,iw)\':-2"');

    unlink($in);
});

it('quotes a token containing spaces', function () {
    $in = tmpInput();

    $plan = (new CommandTranslator)->translate([
        '-i', $in, '-metadata', 'title=Hello World', sys_get_temp_dir().'/o.mp4',
    ]);

    expect($plan->remotable)->toBeTrue()
        ->and($plan->commandString)->toContain('"title=Hello World"');

    unlink($in);
});

it('passes http input URLs through without upload', function () {
    $plan = (new CommandTranslator)->translate([
        '-i', 'https://example.com/in.mp4', sys_get_temp_dir().'/o.mp4',
    ]);

    expect($plan->remotable)->toBeTrue()
        ->and($plan->inputs)->toContain('https://example.com/in.mp4');
});

it('derives a short input name from a presigned URL, ignoring the query string', function () {
    $url = 'https://s3.example.com/bucket/path/integrated.mp4?X-Amz-Signature='.str_repeat('a', 300);

    $plan = (new CommandTranslator)->translate([
        '-i', $url, sys_get_temp_dir().'/o.mp4',
    ]);

    $name = array_key_first($plan->inputs);
    expect($name)->toBe('integrated.mp4')
        ->and($plan->inputs[$name])->toBe($url);
});

it('models HLS segments as a glob output', function () {
    $in = tmpInput();
    $dir = sys_get_temp_dir();

    $plan = (new CommandTranslator)->translate([
        '-i', $in,
        '-f', 'hls', '-hls_time', '2',
        '-hls_segment_filename', $dir.'/seg_%05d.ts',
        $dir.'/index.m3u8',
    ]);

    expect($plan->remotable)->toBeTrue()
        ->and($plan->outputs)->toHaveCount(2);

    $glob = collect($plan->outputs)->firstWhere('isGlob', true);
    expect($glob)->not->toBeNull()
        ->and($glob->name)->toBe('seg_*.ts')
        ->and($glob->localDir)->toBe($dir)
        ->and($plan->commandString)->toContain('seg_%05d.ts');

    unlink($in);
});

it('supports multiple outputs in one command', function () {
    $in = tmpInput();
    $dir = sys_get_temp_dir();

    $plan = (new CommandTranslator)->translate([
        '-i', $in, '-map', '0:v', $dir.'/a.mp4', '-map', '0:a', $dir.'/b.m4a',
    ]);

    expect($plan->remotable)->toBeTrue()
        ->and($plan->outputs)->toHaveCount(2)
        ->and(collect($plan->outputs)->pluck('localPath')->all())
        ->toBe([$dir.'/a.mp4', $dir.'/b.m4a']);

    unlink($in);
});

it('marks a pass command with its number and log identity, and shares a relative pass log', function () {
    $in = tmpInput();
    $out = sys_get_temp_dir().'/two_pass.mp4';

    $pass1 = (new CommandTranslator)->translate([
        '-y', '-i', $in, '-c:v', 'libx264', '-b:v', '800k', '-pass', '1', '-passlogfile', '/tmp/pass-abc', $out,
    ]);
    $pass2 = (new CommandTranslator)->translate([
        '-y', '-i', $in, '-c:v', 'libx264', '-b:v', '800k', '-pass', '2', '-passlogfile', '/tmp/pass-abc', $out,
    ]);

    expect($pass1->isPass())->toBeTrue()
        ->and($pass1->passNumber)->toBe(1)
        ->and($pass1->passLogId)->toBe('/tmp/pass-abc')
        ->and($pass1->commandString)->toContain('-passlogfile passlog')
        ->and($pass2->passNumber)->toBe(2)
        ->and($pass2->passLogId)->toBe('/tmp/pass-abc');

    // Analysis rendering discards the real output.
    expect($pass1->analysisCommand())
        ->toContain('-f null /dev/null')
        ->not->toContain('{{'.$pass1->outputs[0]->name.'}}');

    unlink($in);
});

it('records an encrypted HLS keyinfo for driver-side rewrite', function () {
    $in = tmpInput();
    $keyinfo = tmpInput('.keyinfo');

    $plan = (new CommandTranslator)->translate([
        '-i', $in,
        '-hls_key_info_file', $keyinfo,
        '-f', 'hls', '-hls_time', '2',
        '-hls_segment_filename', sys_get_temp_dir().'/s_%03d.ts',
        sys_get_temp_dir().'/enc.m3u8',
    ]);

    expect($plan->remotable)->toBeTrue()
        ->and($plan->keyInfo)->not->toBeNull()
        ->and($plan->keyInfo->localPath)->toBe($keyinfo)
        ->and($plan->commandString)->toContain('{{'.$plan->keyInfo->name.'}}');

    unlink($in);
    unlink($keyinfo);
});

it('routes NVIDIA encoders to the gpu machine, cpu otherwise', function () {
    $in = tmpInput();
    $out = sys_get_temp_dir().'/o.mp4';

    $gpu = (new CommandTranslator)->translate([
        '-i', $in, '-c:v', 'h264_nvenc', '-b:v', '8000k', $out,
    ]);
    $cpu = (new CommandTranslator)->translate([
        '-i', $in, '-c:v', 'libx264', $out,
    ]);

    expect($gpu->machine)->toBe('nvidia')
        ->and($cpu->machine)->toBeNull();

    unlink($in);
});

it('falls back to local for probe/version calls', function () {
    expect((new CommandTranslator)->translate(['-version'])->remotable)->toBeFalse();
});

it('falls back to local when an unmodeled local path remains', function () {
    $in = tmpInput();

    $plan = (new CommandTranslator)->translate([
        '-i', $in, '-unknown_flag', '/weird/abs/path', sys_get_temp_dir().'/o.mp4',
    ]);

    expect($plan->remotable)->toBeFalse();

    unlink($in);
});

it('falls back to local when a token contains both quote characters', function () {
    $in = tmpInput();

    $plan = (new CommandTranslator)->translate([
        '-i', $in, '-metadata', 'title=x\'y"z', sys_get_temp_dir().'/o.mp4',
    ]);

    expect($plan->remotable)->toBeFalse();

    unlink($in);
});
