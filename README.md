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

A command is sent to the API only when the translator can represent it exactly.
It **falls back to local** for:

- **multipass** (`-pass` / `-passlogfile`) — php-ffmpeg emits the two passes as
  separate `command()` calls, so they cannot share a pass-log across stateless
  remote jobs. Use single-pass (CRF or 1-pass ABR) to run these remotely;
- **encrypted HLS** (`-hls_key_info_file`) — the key file path is unresolvable
  on the node;
- **probes** (`-version`, etc.) and any command whose I/O it can't model
  (e.g. multiple outputs in one invocation).

Supported today: single-output transcodes/muxes (remux, downscale, poster
frames, audio) and plain HLS (playlist + segment glob). Progress listeners are
not forwarded for remote jobs.

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
