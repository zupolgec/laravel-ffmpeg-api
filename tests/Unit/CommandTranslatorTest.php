<?php

declare(strict_types=1);

use Zupolgec\FFMpegApi\Translation\CommandTranslator;

function tmpInput(): string
{
    $path = tempnam(sys_get_temp_dir(), 'ffapi_');
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
        ->and($plan->commandString)->toContain('{{'.$plan->outputs[0]->name.'}}');

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

it('falls back to local for multipass', function () {
    $in = tmpInput();

    $plan = (new CommandTranslator)->translate([
        '-i', $in, '-pass', '1', '-passlogfile', '/tmp/p', '-f', 'null', '/dev/null',
    ]);

    expect($plan->remotable)->toBeFalse();

    unlink($in);
});

it('falls back to local for probe/version calls', function () {
    expect((new CommandTranslator)->translate(['-version'])->remotable)->toBeFalse();
});

it('falls back to local when an unmodeled local path remains (e.g. a second output)', function () {
    $in = tmpInput();
    $dir = sys_get_temp_dir();

    $plan = (new CommandTranslator)->translate([
        '-i', $in, '-map', '0:v', $dir.'/a.mp4', '-map', '0:a', $dir.'/b.mp4',
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
