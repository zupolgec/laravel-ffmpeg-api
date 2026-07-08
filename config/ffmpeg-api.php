<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    |
    | Base URL of an ffmpeg-api compatible service (scheme://host[:port], no
    | path). The client appends "/api". Works with the hosted
    | https://verygoodffmpeg.com or any self-hosted instance.
    |
    */
    'endpoint' => env('FFMPEG_API_ENDPOINT'),

    'key' => env('FFMPEG_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Driver mode
    |--------------------------------------------------------------------------
    |
    | local  - never call the API; behave exactly like plain laravel-ffmpeg.
    | remote - send every remotable command to the API (see fallback below).
    | auto   - send remotable commands to the API, run the rest locally.
    |
    | Commands the translator cannot safely represent (multipass, encrypted
    | HLS, unmodeled paths, probes) always run locally regardless of mode.
    |
    */
    'driver' => env('FFMPEG_API_DRIVER', 'auto'),

    /*
    | When true, a remote failure (network, timeout, job error) falls back to
    | the local binary instead of throwing. Set false in "remote" mode to make
    | remote failures loud (e.g. on nodes without a local ffmpeg).
    */
    'fallback_to_local' => (bool) env('FFMPEG_API_FALLBACK_LOCAL', true),

    /*
    | Seconds to wait for a blocking job and for input upload / output download.
    */
    'wait_timeout' => (int) env('FFMPEG_API_WAIT_TIMEOUT', 1800),

    'connect_timeout' => (int) env('FFMPEG_API_CONNECT_TIMEOUT', 10),
];
