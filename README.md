# laravel-ffmpeg-api

Run [`pbmedia/laravel-ffmpeg`](https://github.com/protonemedia/laravel-ffmpeg)
jobs on a remote **[ffmpeg-api](https://verygoodffmpeg.com)** endpoint instead
of the local `ffmpeg` binary — with automatic fallback to local.

It is backend-agnostic: point it at the hosted `verygoodffmpeg.com` or at any
self-hosted instance. The endpoint is the only thing that changes.

## How it works

`laravel-ffmpeg` builds ffmpeg commands and runs them through php-ffmpeg's
`FFMpegDriver`. This package subclasses that driver and overrides the single
`command()` choke point:

- **remotable** commands are translated into an ffmpeg-api job
  (`POST /api/ffmpeg?wait=true`) — local input files are uploaded via
  `POST /api/tmp-file`, outputs are downloaded back to the exact temp paths
  `laravel-ffmpeg` expects, so the rest of the pipeline is untouched;
- everything else runs on the **local binary** via `parent::command()`.

Nothing in your application code changes — you keep using the `FFMpeg` facade.

## Install

```bash
composer require zupolgec/laravel-ffmpeg-api
php artisan vendor:publish --tag=ffmpeg-api-config
```

```env
FFMPEG_API_ENDPOINT=https://verygoodffmpeg.com   # or http://10.0.0.5:8080
FFMPEG_API_KEY=sk_xxx
FFMPEG_API_DRIVER=auto                            # local | remote | auto
```

## What runs remotely

The translator classifies ffmpeg args by option arity, so it handles a broad
set of commands and sends them to the API:

- **single-output** transcodes / muxes — remux, downscale, poster frames, audio;
- **multiple outputs** in one invocation (e.g. split streams);
- **HLS**, playlist + segment glob — including **AES-encrypted** HLS: the key is
  uploaded and the `-hls_key_info_file` keyinfo is rewritten to a workdir-relative
  key (the playlist `URI` line is kept verbatim);
- **multipass** (`-pass` / `-passlogfile`) — php-ffmpeg emits each pass as a
  separate `command()` call, so the driver **buffers the analysis pass(es) and
  chains them with the final pass into ONE job** (they run sequentially in the
  same node workdir, sharing the pass log). Nothing to change in your code.

It **falls back to the local binary** for what it can't model exactly: probes
(`-version`), commands with no capturable output, tokens containing both quote
characters, or any leftover unmodeled local path. Fallback is always safe — it
never ships a job it isn't sure about.

### Progress

Per-frame progress is **not** forwarded for remote jobs. The public API is
request/response (`?wait=true` blocks; webhooks fire only on completion) and
exposes no ffmpeg progress stream on the bearer API, so php-ffmpeg's stderr
progress listeners have nothing to consume. Jobs still run to completion; you
just don't get a live percentage. (If the endpoint later exposes a job
log/progress stream on the bearer API, this can be wired up.)

## Config

| key | default | meaning |
|---|---|---|
| `endpoint` | – | base URL, no path (client appends `/api`) |
| `key` | – | bearer API key |
| `driver` | `auto` | `local` \| `remote` \| `auto` |
| `fallback_to_local` | `true` | on remote failure, retry locally instead of throwing |
| `wait_timeout` | `1800` | seconds for blocking job / upload / download |
| `connect_timeout` | `10` | seconds |

## Tests

```bash
composer install
vendor/bin/pest                       # unit (translator)
FFMPEG_API_ENDPOINT=… FFMPEG_API_KEY=… vendor/bin/pest --group=live
```

## License

MIT.
