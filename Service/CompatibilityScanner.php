<?php

namespace JavidFazaeli\AddonInstaller\Service;

/**
 * Heuristic static scan for version-gated PHP syntax / functions.
 *
 * Purpose: when an admin is about to force-install an add-on that
 * declares a newer PHP than the server runs, tell them whether the
 * code *actually* uses anything newer than the target — turning a
 * blind override into an informed one:
 *
 *   "Scanned 31 files. No PHP features newer than 8.2 detected —
 *    appears safe to force."
 *   vs
 *   "⚠ Uses json_validate() (PHP 8.3) in Tags/Foo.php. Forcing onto
 *    PHP 8.2 will fatal when that path runs."
 *
 * This is deliberately a HEURISTIC, not a parser. We regex raw source
 * (after a light comment strip) rather than tokenize, because the
 * running tokenizer is the target version and literally cannot know
 * about syntax newer than itself — token_get_all on PHP 8.2 has no
 * T_* for 8.3 features. Regex on source catches newer-than-current
 * syntax that the tokenizer would miss. The trade-off is occasional
 * imprecision, which is why every verdict is labelled "best-effort".
 *
 * Marker set is curated for LOW false-positive rate: distinctive,
 * unambiguous syntax + functions only. Better to miss an exotic
 * feature than to cry wolf on a method named match().
 */
class CompatibilityScanner
{
    private const MAX_FILES = 400;
    private const MAX_FILE_BYTES = 1024 * 1024;

    /**
     * version => list of [regex, human label]. Lookbehind (?<![\w$>:'"])
     * keeps function/keyword markers from matching method calls
     * (->foo), static calls (::foo), identifiers (preg_match), variables
     * ($foo) and array-key strings ('foo' => ...).
     */
    private const SYNTAX_MARKERS = [
        '8.0' => [
            ['/(?<![\w$>:\'"])match\s*\(/',                         'match expression'],
            ['/\?->/',                                              'nullsafe operator (?->)'],
        ],
        '8.1' => [
            ['/(?<![\w$>:\'"])enum\s+\w+\s*[:{]/',                  'enum declaration'],
            ['/(?<![\w$>:\'"])readonly\s+(?:public|protected|private|static|\$|[A-Z\\\\?])/', 'readonly property'],
            ['/\)\s*:\s*never\b/',                                  'never return type'],
            ['/\(\s*\.\.\.\s*\)/',                                  'first-class callable (...)'],
        ],
        '8.2' => [
            ['/(?<![\w$>:\'"])readonly\s+class\b/',                 'readonly class'],
            ['/#\[\s*\\\\?AllowDynamicProperties\s*\]/',            '#[AllowDynamicProperties]'],
        ],
        '8.3' => [
            ['/(?<![\w$>:\'"])const\s+[\w\\\\|?]+\s+[A-Z_]\w*\s*=/', 'typed class constant'],
            ['/::\{\s*\$/',                                         'dynamic class constant fetch'],
            ['/#\[\s*\\\\?Override\s*\]/',                          '#[Override] attribute'],
        ],
        '8.4' => [
            ['/(?<![\w$>:\'"])(?:public|protected|private)\(set\)/', 'asymmetric visibility'],
        ],
    ];

    /** version => function names introduced in that version. */
    private const FUNCTION_MARKERS = [
        '8.0' => ['str_contains', 'str_starts_with', 'str_ends_with', 'get_debug_type', 'fdiv'],
        '8.1' => ['array_is_list', 'enum_exists', 'fsync', 'fdatasync'],
        '8.2' => ['ini_parse_quantity', 'memory_reset_peak_usage', 'curl_upkeep'],
        '8.3' => ['json_validate', 'mb_str_pad', 'str_increment', 'str_decrement', 'ldap_exop_sync', 'stream_context_set_options'],
        '8.4' => ['array_find', 'array_any', 'array_all', 'array_find_key', 'mb_trim', 'mb_ltrim', 'mb_rtrim', 'request_parse_body'],
    ];

    /**
     * Scan a map of [filename => php source]. Returns a structured
     * verdict relative to $targetPhp (defaults to the running version).
     *
     * @param array<string,string> $files
     * @return array{
     *   target:string, files_scanned:int, max_required:?string,
     *   above_target:array<int,array{version:string,feature:string,file:string}>,
     *   all:array<int,array{version:string,feature:string,file:string}>,
     *   verdict:string, summary:string
     * }
     */
    public function scanFiles(array $files, ?string $targetPhp = null): array
    {
        $target = $targetPhp ?: PHP_VERSION;
        $findings = [];
        $scanned = 0;

        foreach ($files as $name => $source) {
            if ($scanned >= self::MAX_FILES) {
                break;
            }
            if (! is_string($source) || $source === '' || strlen($source) > self::MAX_FILE_BYTES) {
                continue;
            }
            $scanned++;
            $clean = $this->stripComments($source);

            foreach (self::SYNTAX_MARKERS as $version => $markers) {
                foreach ($markers as [$regex, $label]) {
                    if (preg_match($regex, $clean)) {
                        $findings[] = ['version' => $version, 'feature' => $label, 'file' => $this->shortName($name)];
                    }
                }
            }

            foreach (self::FUNCTION_MARKERS as $version => $fns) {
                $alt = implode('|', array_map('preg_quote', $fns));
                if (preg_match('/(?<![\w$>:\'"])(?:' . $alt . ')\s*\(/', $clean, $m)) {
                    // Identify which function matched for the label.
                    foreach ($fns as $fn) {
                        if (preg_match('/(?<![\w$>:\'"])' . preg_quote($fn, '/') . '\s*\(/', $clean)) {
                            $findings[] = ['version' => $version, 'feature' => $fn . '()', 'file' => $this->shortName($name)];
                        }
                    }
                }
            }
        }

        // De-dupe identical (version, feature, file) tuples.
        $findings = array_values(array_unique($findings, SORT_REGULAR));

        // Sort findings by version descending so the worst is first.
        usort($findings, static fn($a, $b) => version_compare($b['version'], $a['version']));

        $aboveTarget = array_values(array_filter(
            $findings,
            static fn($f) => version_compare($f['version'], $target, '>')
        ));

        $maxRequired = null;
        foreach ($findings as $f) {
            if ($maxRequired === null || version_compare($f['version'], $maxRequired, '>')) {
                $maxRequired = $f['version'];
            }
        }

        $verdict = empty($aboveTarget) ? 'clear' : 'risk';
        $summary = $this->buildSummary($verdict, $target, $scanned, $aboveTarget, $maxRequired);

        return [
            'target' => $target,
            'files_scanned' => $scanned,
            'max_required' => $maxRequired,
            'above_target' => $aboveTarget,
            'all' => $findings,
            'verdict' => $verdict,
            'summary' => $summary,
        ];
    }

    /** Convenience: scan every .php file under a directory. */
    public function scanDirectory(string $dir, ?string $targetPhp = null): array
    {
        $files = [];
        if (is_dir($dir)) {
            try {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $f) {
                    if ($f->isFile() && strtolower($f->getExtension()) === 'php') {
                        $files[$f->getPathname()] = @file_get_contents($f->getPathname());
                    }
                }
            } catch (\Throwable $e) {
                // best-effort
            }
        }
        return $this->scanFiles($files, $targetPhp);
    }

    private function buildSummary(string $verdict, string $target, int $scanned, array $above, ?string $maxRequired): string
    {
        $targetShort = $this->shortVersion($target);

        if ($verdict === 'clear') {
            $detected = $maxRequired !== null
                ? sprintf(' Highest version-specific feature detected needs PHP %s (≤ %s).', $maxRequired, $targetShort)
                : ' No version-specific PHP features detected at all.';
            return sprintf(
                'Best-effort scan of %d file%s: no PHP features newer than %s found — appears safe to force.%s',
                $scanned,
                $scanned === 1 ? '' : 's',
                $targetShort,
                $detected
            );
        }

        $bits = [];
        foreach (array_slice($above, 0, 4) as $f) {
            $bits[] = sprintf('%s (PHP %s) in %s', $f['feature'], $f['version'], $f['file']);
        }
        $more = count($above) > 4 ? sprintf(' +%d more', count($above) - 4) : '';

        return sprintf(
            'Best-effort scan of %d file%s found features NEWER than PHP %s: %s%s. Forcing onto %s will likely fatal when that code runs.',
            $scanned,
            $scanned === 1 ? '' : 's',
            $targetShort,
            implode('; ', $bits),
            $more,
            $targetShort
        );
    }

    /** Strip block + line comments (light) to cut false positives.
     *  Leaves `#[` attribute syntax intact. */
    private function stripComments(string $src): string
    {
        $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
        $src = preg_replace('#//[^\n]*#', '', $src) ?? $src;
        $src = preg_replace('#\#(?!\[)[^\n]*#', '', $src) ?? $src;
        return $src;
    }

    private function shortName(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $parts = explode('/', $path);
        return implode('/', array_slice($parts, -2));
    }

    private function shortVersion(string $v): string
    {
        if (preg_match('/^(\d+\.\d+)/', $v, $m)) {
            return $m[1];
        }
        return $v;
    }
}
