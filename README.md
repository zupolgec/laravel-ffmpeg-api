# laravel-ffmpeg-api

[![tests](https://github.com/zupolgec/laravel-ffmpeg-api/actions/workflows/tests.yml/badge.svg)](https://github.com/zupolgec/laravel-ffmpeg-api/actions/workflows/tests.yml)
[![Packagist](https://img.shields.io/packagist/v/zupolgec/laravel-ffmpeg-api.svg)](https://packagist.org/packages/zupolgec/laravel-ffmpeg-api)
[![License](https://img.shields.io/packagist/l/zupolgec/laravel-ffmpeg-api.svg)](LICENSE)

Run [`pbmedia/laravel-ffmpeg`](https://github.com/protonemedia/laravel-ffmpeg)
jobs on a remote **ffmpeg-api** endpoint instead of the local `ffmpeg` binary —
with automatic fallback to local. Your application code doesn't change: you keep
using the `FFMpeg` facade.

Works with any [ffmpeg-api](https://verygoodffmpeg.com) compatible endpoint
(the hosted `verygoodffmpeg.com` or your own self-hosted instance) — the
endpoint URL is the only thing that changes.

- **Transparent** — subclasses php-ffmpeg's driver; no changes to your exports.
- **Broad coverage** — single & multiple outputs, HLS (incl. AES-encrypted),
  and 2-pass, all handled automatically.
- **GPU-aware** — commands using NVIDIA encoders/filters route to a GPU worker.
- **Live progress** — `->onProgress()` keeps working over the remote job.
- **Safe** — anything it can't model exactly runs on the local binary instead.

## How it works

`laravel-ffmpeg` builds ffmpeg commands and runs them through php-ffmpeg's
`FFMpegDriver`. This package subclasses that driver and overrides the single
`command()` choke point:

- **remotable** commands become an ffmpeg-api job — local inputs are uploaded
  (`POST /api/tmp-file`), http(s) inputs are passed straight through, the job is
  submitted and polled, and outputs are downloaded back to the exact temp paths
  `laravel-ffmpeg` expects, so the rest of your pipeline is untouched;
- **everything else** runs on the **local binary** via `parent::command()`.

## Install

```bash
composer require zupolgec/laravel-ffmpeg-api
php artisan vendor:publish --tag=ffmpeg-api-config
```

```env
FFMPEG_API_ENDPOINT=https://verygoodffmpeg.com   # or your own https://ffmpeg.example.com
FFMPEG_API_KEY=sk_xxx
FFMPEG_API_DRIVER=auto                            # local | remote | auto
```

Leave `FFMPEG_API_ENDPOINT` unset and the package is inert — every command runs
locally, exactly like plain `laravel-ffmpeg`.

## Usage

Nothing changes. Use the facade as you always do:

```php
use ProtoneMedia\LaravelFFMpeg\Support\FFMpeg;

FFMpeg::openUrl('https://cdn.example.com/master.mp4')
    ->export()
    ->onProgress(fn ($percentage) => logger("transcoding: {$percentage}%"))
    ->inFormat(new \FFMpeg\Format\Video\X264)
    ->save('lowres.mp4');
```

## What runs remotely

The translator classifies ffmpeg args by option arity, so it handles a broad set
of commands and sends them to the API:

- **single-output** transcodes / muxes — remux, downscale, poster frames, audio;
- **multiple outputs** in one invocation (e.g. split streams);
- **HLS** (playlist + segment glob), including **AES-encrypted** HLS: the key is
  uploaded and the `-hls_key_info_file` keyinfo is rewritten to a workdir-relative
  key (the playlist `URI` line is kept verbatim);
- **multipass** (`-pass` / `-passlogfile`): php-ffmpeg emits each pass as a
  separate `command()` call, so the driver **buffers the analysis pass(es) and
  chains them with the final pass into one job** — they run sequentially in the
  same worker, sharing the pass log.

It **falls back to the local binary** for what it can't model exactly: probes
(`-version`), commands with no capturable output, tokens containing both quote
characters, or any leftover unmodeled local path. Fallback is always safe — it
never ships a job it isn't sure about.

### GPU routing

A command that uses NVIDIA encoders or filters (`h264_nvenc`, `scale_cuda`, …)
is automatically submitted to the endpoint's `nvidia` worker pool; everything
else runs on `cpu`. Force a pool with `FFMPEG_API_MACHINE=nvidia|cpu`.

### Progress

`->onProgress()` works over the remote job. The driver submits non-blocking and
polls `GET /api/jobs/{id}`, forwarding the endpoint's `progress` (0–100),
`eta_seconds`, and `speed` into php-ffmpeg's progress listeners — so your
`->onProgress($percentage, $remaining, $rate)` callback fires as usual, with a
single 0→100 sweep across chained commands (2-pass, HLS).

Progress needs a discoverable duration: file inputs and any command with
`-t`/`-to` report a live percentage; a pure generated source with no duration
reports `null` until it finishes (then 100).

## Config

| key | env | default | meaning |
|---|---|---|---|
| `endpoint` | `FFMPEG_API_ENDPOINT` | – | base URL, no path (client appends `/api`) |
| `key` | `FFMPEG_API_KEY` | – | bearer API key |
| `driver` | `FFMPEG_API_DRIVER` | `auto` | `local` \| `remote` \| `auto` |
| `fallback_to_local` | `FFMPEG_API_FALLBACK_LOCAL` | `true` | on remote failure, retry locally instead of throwing |
| `machine` | `FFMPEG_API_MACHINE` | – | force `cpu`/`nvidia`; empty = auto-detect from the command |
| `wait_timeout` | `FFMPEG_API_WAIT_TIMEOUT` | `1800` | seconds for a job / upload / download |
| `connect_timeout` | `FFMPEG_API_CONNECT_TIMEOUT` | `10` | seconds |
| `poll_interval_ms` | `FFMPEG_API_POLL_INTERVAL_MS` | `1000` | job poll cadence |

## Testing

```bash
composer install
vendor/bin/pest --testsuite=Unit                 # pure, no endpoint needed
FFMPEG_API_ENDPOINT=… FFMPEG_API_KEY=… vendor/bin/pest --group=live
```

## License

MIT — see [LICENSE](LICENSE).
