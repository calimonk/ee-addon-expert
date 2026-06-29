<?php

namespace Nivoli\AddonExpert\Service;

/**
 * Best-effort "is this add-on actually used anywhere?" scan, run before an
 * uninstall so the admin sees what removing it might break. It looks for the
 * add-on's footprint in content the uninstall WON'T clean up for them:
 *
 *   - templates  — its tags `{exp:<short>:…}` in any template (layout
 *                  templates included — they're rows in exp_templates too)
 *   - snippets   — the same tags inside snippets
 *   - fields     — channel fields whose fieldtype IS this add-on
 *   - extensions — extension classes it registers (informational)
 *
 * It is heuristic: it can't see tags built dynamically, PHP that calls the
 * add-on, or usage in third-party storage (e.g. Low Variables — intentionally
 * out of scope). The DB access is injectable (`$probe`) so the assembly +
 * validation logic is unit-testable without a live EE.
 */
class UsageScanner
{
    public const KINDS = ['templates', 'snippets', 'fields', 'extensions'];

    /** @var callable|null fn(string $kind, string $short): array{count:int,names:array} */
    private $probe;

    public function __construct(?callable $probe = null)
    {
        $this->probe = $probe;
    }

    public static function isValidShort(string $short): bool
    {
        return (bool) preg_match('/^[a-z0-9_]+$/', $short);
    }

    /** The template-tag prefix this add-on contributes. */
    public static function tagNeedle(string $short): string
    {
        return '{exp:' . $short . ':';
    }

    /**
     * @return array{valid:bool,has_usage:bool,templates:array,snippets:array,fields:array,extensions:array}
     */
    public function scan(string $short): array
    {
        $blank = static fn() => ['count' => 0, 'names' => []];
        $out = [
            'valid'      => false,
            'has_usage'  => false,
            'templates'  => $blank(),
            'snippets'   => $blank(),
            'fields'     => $blank(),
            'extensions' => $blank(),
        ];

        if (! self::isValidShort($short)) {
            return $out;
        }
        $out['valid'] = true;

        $any = false;
        foreach (self::KINDS as $kind) {
            $r = $this->probeKind($kind, $short);
            $out[$kind] = $r;
            // Extensions are informational (the add-on's own hooks, removed
            // with it) — they don't count toward the breakage warning.
            if ($kind !== 'extensions' && $r['count'] > 0) {
                $any = true;
            }
        }
        $out['has_usage'] = $any;

        return $out;
    }

    /** @return array{count:int,names:array} */
    private function probeKind(string $kind, string $short): array
    {
        if ($this->probe !== null) {
            $r = ($this->probe)($kind, $short);
            return ['count' => (int) ($r['count'] ?? 0), 'names' => array_values((array) ($r['names'] ?? []))];
        }
        if (! function_exists('ee')) {
            return ['count' => 0, 'names' => []];
        }
        try {
            return $this->queryEe($kind, $short);
        } catch (\Throwable $e) {
            // Best-effort — a query failure must never block the remove flow.
            return ['count' => 0, 'names' => []];
        }
    }

    /** @return array{count:int,names:array} */
    private function queryEe(string $kind, string $short): array
    {
        $db = ee()->db;
        $needle = self::tagNeedle($short);

        switch ($kind) {
            case 'templates':
                $count = (int) $db->like('template_data', $needle)->count_all_results('templates');
                $names = [];
                if ($count > 0) {
                    $rows = $db->select('template_name')->like('template_data', $needle)
                        ->order_by('template_name')->limit(15)->get('templates')->result_array();
                    $names = array_column($rows, 'template_name');
                }
                return ['count' => $count, 'names' => $names];

            case 'snippets':
                $count = (int) $db->like('snippet_contents', $needle)->count_all_results('snippets');
                $names = [];
                if ($count > 0) {
                    $rows = $db->select('snippet_name')->like('snippet_contents', $needle)
                        ->order_by('snippet_name')->limit(15)->get('snippets')->result_array();
                    $names = array_column($rows, 'snippet_name');
                }
                return ['count' => $count, 'names' => $names];

            case 'fields':
                $count = (int) $db->where('field_type', $short)->count_all_results('channel_fields');
                $names = [];
                if ($count > 0) {
                    $rows = $db->select('field_label, field_name')->where('field_type', $short)
                        ->order_by('field_label')->limit(15)->get('channel_fields')->result_array();
                    $names = array_map(static fn($r) => (string) ($r['field_label'] ?: $r['field_name']), $rows);
                }
                return ['count' => $count, 'names' => $names];

            case 'extensions':
                $count = (int) $db->like('class', $short)->count_all_results('extensions');
                $names = [];
                if ($count > 0) {
                    $rows = $db->select('class')->like('class', $short)->group_by('class')
                        ->limit(15)->get('extensions')->result_array();
                    $names = array_column($rows, 'class');
                }
                return ['count' => $count, 'names' => $names];
        }

        return ['count' => 0, 'names' => []];
    }
}
