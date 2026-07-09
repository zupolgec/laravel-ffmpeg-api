<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Translation;

/**
 * Translates a local ffmpeg argv (the array php-ffmpeg hands to
 * FFMpegDriver::command(), without the binary) into an ffmpeg-api job:
 * named inputs, declared outputs, and a single command string with
 * {{name}} placeholders.
 *
 * Tokens are classified by option arity: a flag that is not a known boolean
 * consumes the next token as its value, so trailing positional tokens are
 * outputs (one or many) and each -i value is an input. Multipass and encrypted
 * HLS are represented via extra metadata the driver acts on; anything the
 * translator cannot model with confidence yields a non-remotable result so the
 * driver falls back to the local binary.
 */
final class CommandTranslator
{
    /** ffmpeg options that take NO argument; everything else consumes the next token. */
    private const BOOLEAN_FLAGS = [
        '-y', '-n', '-nostdin', '-nostats', '-hide_banner', '-stats',
        '-an', '-vn', '-sn', '-dn',
        '-shortest', '-copyts', '-start_at_zero', '-re', '-ignore_unknown',
    ];

    /** Output tokens that are sinks, not files to capture. */
    private const DISCARD_OUTPUTS = ['/dev/null', '/dev/stdout', '-', 'nul', 'null'];

    /** @var array<string, true> */
    private array $usedNames = [];

    /**
     * @param  list<string>  $argv
     */
    public function translate(array $argv): TranslatedCommand
    {
        $this->usedNames = [];
        $argv = array_values(array_map('strval', $argv));
        $count = count($argv);

        if ($count === 0) {
            return TranslatedCommand::local('empty command');
        }

        // 1. Classify every token by arity.
        $role = array_fill(0, $count, 'positional'); // positional | flag | value | input
        for ($i = 0; $i < $count;) {
            $token = $argv[$i];

            if (str_starts_with($token, '-') && $token !== '-') {
                $role[$i] = 'flag';

                if (! in_array($token, self::BOOLEAN_FLAGS, true) && $i + 1 < $count) {
                    $role[$i + 1] = $token === '-i' ? 'input' : 'value';
                    $i += 2;

                    continue;
                }
            }

            $i++;
        }

        $rewrite = []; // argv index => replacement token

        // 2. Inputs (the value after each -i).
        $inputs = [];
        for ($i = 0; $i < $count; $i++) {
            if ($role[$i] !== 'input') {
                continue;
            }

            $value = $argv[$i];

            if ($this->isUrl($value) || is_file($value)) {
                $name = $this->allocate($value);
                $inputs[$name] = $value;
                $rewrite[$i] = '{{'.$name.'}}';
            }
            // else: virtual input (lavfi, color=, anullsrc, pipe:) — leave verbatim.
        }

        // 3. Special flags.
        $passNumber = null;
        $passLogId = null;
        $keyInfo = null;
        $segmentIndex = null;
        $segmentTemplate = null;

        for ($i = 0; $i < $count; $i++) {
            if ($role[$i] !== 'flag') {
                continue;
            }

            $valueIndex = $i + 1;
            $value = $valueIndex < $count ? $argv[$valueIndex] : null;

            switch ($argv[$i]) {
                case '-passlogfile':
                    if ($value !== null) {
                        $passLogId = $value;
                        $rewrite[$valueIndex] = 'passlog';
                    }
                    break;

                case '-pass':
                    if ($value !== null && ctype_digit($value)) {
                        $passNumber = (int) $value;
                    }
                    break;

                case '-hls_segment_filename':
                    if ($value !== null) {
                        $segmentIndex = $valueIndex;
                        $segmentTemplate = $value;
                    }
                    break;

                case '-hls_key_info_file':
                    if ($value !== null) {
                        if (! is_file($value)) {
                            return TranslatedCommand::local('keyinfo file not found');
                        }
                        $name = $this->allocate('keyinfo');
                        $keyInfo = new KeyInfoRequest($name, $value);
                        $rewrite[$valueIndex] = '{{'.$name.'}}';
                    }
                    break;
            }
        }

        // 4. Outputs: trailing positional tokens, plus HLS segments as a glob.
        $outputs = [];
        for ($i = 0; $i < $count; $i++) {
            if ($role[$i] !== 'positional') {
                continue;
            }

            $token = $argv[$i];
            if ($token === '' || in_array(strtolower($token), self::DISCARD_OUTPUTS, true)) {
                continue;
            }

            $name = $this->allocate($token);
            $rewrite[$i] = '{{'.$name.'}}';
            $outputs[] = OutputTarget::literal($name, $token);
        }

        if ($segmentIndex !== null && $segmentTemplate !== null) {
            $segBase = basename($segmentTemplate);
            $rewrite[$segmentIndex] = $segBase;
            $localDir = isset($outputs[0]) && $outputs[0]->localPath !== null
                ? dirname($outputs[0]->localPath)
                : dirname($segmentTemplate);
            $outputs[] = OutputTarget::glob($this->globFromTemplate($segBase), $localDir);
        }

        if ($outputs === []) {
            return TranslatedCommand::local('no capturable output');
        }

        // 5. Apply rewrites.
        $tokens = [];
        foreach ($argv as $i => $token) {
            $tokens[$i] = $rewrite[$i] ?? $token;
        }

        // 6. Safety net: any leftover token that looks like a real local path means
        // we failed to model an input/output. Fall back rather than ship a broken job.
        foreach ($tokens as $i => $token) {
            if (isset($rewrite[$i]) || $token === '' || str_starts_with($token, '-')) {
                continue;
            }
            if (in_array(strtolower($token), self::DISCARD_OUTPUTS, true)) {
                continue;
            }
            if (str_starts_with($token, '/') || is_file($token)) {
                return TranslatedCommand::local("unmodeled path token: {$token}");
            }
        }

        // 7. Quote for the (shell-aware) remote splitter.
        $encoded = [];
        foreach ($tokens as $token) {
            $quoted = $this->quoteToken($token);
            if ($quoted === null) {
                return TranslatedCommand::local("token cannot be quoted safely: {$token}");
            }
            $encoded[] = $quoted;
        }

        return TranslatedCommand::remote(
            $inputs,
            $outputs,
            implode(' ', $encoded),
            $passNumber,
            $passLogId,
            $keyInfo,
        );
    }

    private function isUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    /**
     * Quote a token for the remote splitter (single/double quotes, no escapes).
     * Returns null when the token contains both quote characters, which the
     * splitter cannot represent.
     */
    private function quoteToken(string $token): ?string
    {
        if ($token !== '' && ! preg_match('/[\s\'"]/', $token)) {
            return $token;
        }

        $hasSingle = str_contains($token, "'");
        $hasDouble = str_contains($token, '"');

        if (! $hasDouble) {
            return '"'.$token.'"';
        }
        if (! $hasSingle) {
            return "'".$token."'";
        }

        return null;
    }

    private function globFromTemplate(string $base): string
    {
        $glob = preg_replace('/%\d*d/', '*', $base) ?? $base;

        return $this->sanitize($glob, allowGlob: true);
    }

    private function allocate(string $path): string
    {
        // For URLs, derive the name from the path only — a query string (e.g. a
        // presigned URL's signature) would blow past the filesystem name limit
        // when the node writes the input to its workdir.
        $source = $this->isUrl($path) ? (parse_url($path, PHP_URL_PATH) ?: $path) : $path;
        $name = $this->sanitize(basename($source), allowGlob: false);
        $candidate = $name;
        $n = 1;

        while (isset($this->usedNames[$candidate])) {
            $n++;
            $candidate = $n.'_'.$name;
        }

        $this->usedNames[$candidate] = true;

        return $candidate;
    }

    private function sanitize(string $value, bool $allowGlob): string
    {
        $pattern = $allowGlob ? '/[^A-Za-z0-9_.%:*?-]/' : '/[^A-Za-z0-9_.-]/';
        $clean = preg_replace($pattern, '_', $value) ?? '';
        $clean = ltrim($clean, '.');

        return $clean === '' ? 'file' : $clean;
    }
}
