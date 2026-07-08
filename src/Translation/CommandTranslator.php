<?php

declare(strict_types=1);

namespace Zupolgec\FFMpegApi\Translation;

/**
 * Translates a local ffmpeg argv (the array php-ffmpeg hands to
 * FFMpegDriver::command(), without the binary) into an ffmpeg-api job:
 * named inputs, declared outputs, and a single command string with
 * {{name}} placeholders.
 *
 * The translation is deliberately conservative: anything it cannot represent
 * with confidence yields a non-remotable result so the driver falls back to
 * the local binary rather than producing a wrong job.
 */
final class CommandTranslator
{
    /**
     * Flags that force local execution:
     *  -pass / -passlogfile  multipass is emitted by php-ffmpeg as SEPARATE
     *                        command() calls; each becomes an independent,
     *                        stateless remote job, so the pass-1 log never
     *                        reaches pass 2.
     *  -hls_key_info_file    encrypted HLS references a key file by a path the
     *                        remote node cannot resolve.
     */
    private const LOCAL_ONLY_FLAGS = ['-pass', '-passlogfile', '-hls_key_info_file'];

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

        foreach ($argv as $token) {
            if (in_array($token, self::LOCAL_ONLY_FLAGS, true)) {
                return TranslatedCommand::local("unsupported flag {$token}");
            }
        }

        // Inputs: the token following each -i.
        $inputs = [];               // name => path/url
        $rewriteAs = [];            // argv index => replacement token
        for ($i = 0; $i < $count; $i++) {
            if ($argv[$i] === '-i' && $i + 1 < $count) {
                $value = $argv[$i + 1];

                if ($this->isUrl($value)) {
                    $name = $this->allocate($value);
                    $inputs[$name] = $value;
                    $rewriteAs[$i + 1] = '{{'.$name.'}}';
                } elseif (is_file($value)) {
                    $name = $this->allocate($value);
                    $inputs[$name] = $value;
                    $rewriteAs[$i + 1] = '{{'.$name.'}}';
                }
                // else: virtual input (lavfi, color=, anullsrc, pipe:, -) — leave verbatim.
            }
        }

        // Optional HLS segment template: -hls_segment_filename <path>
        $segmentIndex = null;
        $segmentTemplate = null;
        for ($i = 0; $i < $count; $i++) {
            if ($argv[$i] === '-hls_segment_filename' && $i + 1 < $count) {
                $segmentIndex = $i + 1;
                $segmentTemplate = $argv[$i + 1];
            }
        }

        // Primary output: php-ffmpeg always appends the output path last.
        $lastIndex = $count - 1;
        $last = $count > 0 ? $argv[$lastIndex] : '';
        if ($last === '' || str_starts_with($last, '-') || isset($rewriteAs[$lastIndex])) {
            return TranslatedCommand::local('no output file to capture (probe or piped output)');
        }

        $outputs = [];

        $primaryName = $this->allocate($last);
        $rewriteAs[$lastIndex] = '{{'.$primaryName.'}}';
        $outputs[] = OutputTarget::literal($primaryName, $last);

        if ($segmentIndex !== null && $segmentTemplate !== null) {
            $segBase = basename($segmentTemplate);
            $rewriteAs[$segmentIndex] = $segBase;          // relative -> node workdir
            $glob = $this->globFromTemplate($segBase);     // seg_%05d.ts -> seg_*.ts
            $outputs[] = OutputTarget::glob($glob, dirname($last));
        }

        // Apply rewrites and quote each token for the (shell-aware) remote splitter.
        $tokens = [];
        foreach ($argv as $i => $token) {
            $tokens[$i] = $rewriteAs[$i] ?? $token;
        }

        // Safety net: any leftover token that looks like a real local path means
        // we failed to model an input/output (e.g. a second output in one call).
        // Fall back to local rather than ship a broken job.
        foreach ($tokens as $i => $token) {
            if (isset($rewriteAs[$i])) {
                continue;
            }
            if ($token === '' || str_starts_with($token, '-')) {
                continue;
            }
            if (str_starts_with($token, '/') || is_file($token)) {
                return TranslatedCommand::local("unmodeled path token: {$token}");
            }
        }

        $encoded = [];
        foreach ($tokens as $token) {
            $quoted = $this->quoteToken($token);
            if ($quoted === null) {
                return TranslatedCommand::local("token cannot be quoted safely: {$token}");
            }
            $encoded[] = $quoted;
        }

        return TranslatedCommand::remote($inputs, $outputs, implode(' ', $encoded));
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
        // seg_%05d.ts / img%d.png -> seg_*.ts / img*.png
        $glob = preg_replace('/%\d*d/', '*', $base) ?? $base;

        // Keep only characters the API allows for output patterns.
        return $this->sanitize($glob, allowGlob: true);
    }

    private function allocate(string $path): string
    {
        $name = $this->sanitize(basename($path), allowGlob: false);
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
