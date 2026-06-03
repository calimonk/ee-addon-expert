<?php

namespace JavidFazaeli\AddonInstaller\Service;

/**
 * Addon-level settings store. Persistent across cache wipes (lives in
 * user/config alongside the mappings). Read-mostly; we don't expect
 * heavy concurrent writes.
 */
class SettingsStore
{
    public const DEFAULTS = [
        // CP Custom-menu integration. Off-by-default-friendly behavior:
        // the menu item only appears if the admin has added our addon to
        // their role's Custom menu via Members → Roles. This setting
        // gates whether our extension hook *responds* when called.
        'show_in_custom_menu' => 'y',

        // Where the Custom-menu entry points: 'releases' | 'packages'
        // | 'index'.
        'custom_menu_target' => 'releases',

        // Whether to append a "(N)" count of pending GitHub updates to
        // the Custom-menu label.
        'custom_menu_show_count' => 'y',
    ];

    private string $file;
    private ?array $values = null;

    public function __construct(?string $file = null)
    {
        $this->file = $file ?: self::defaultFile();
    }

    public static function defaultFile(): string
    {
        $base = defined('SYSPATH') ? SYSPATH . 'user/config' : sys_get_temp_dir();
        return rtrim($base, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'addon_installer_settings.json';
    }

    public function get(string $key, $default = null)
    {
        $values = $this->load();
        if (array_key_exists($key, $values)) {
            return $values[$key];
        }
        if ($default !== null) {
            return $default;
        }
        return self::DEFAULTS[$key] ?? null;
    }

    public function all(): array
    {
        return array_merge(self::DEFAULTS, $this->load());
    }

    public function saveAll(array $incoming): bool
    {
        $clean = [];
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $incoming)) {
                continue;
            }
            $value = $incoming[$key];

            switch ($key) {
                case 'show_in_custom_menu':
                case 'custom_menu_show_count':
                    $clean[$key] = (string) $value === 'y' ? 'y' : 'n';
                    break;
                case 'custom_menu_target':
                    $clean[$key] = in_array((string) $value, ['releases', 'packages', 'index'], true)
                        ? (string) $value
                        : 'releases';
                    break;
                default:
                    $clean[$key] = $value;
            }
        }

        $this->values = array_merge($this->load(), $clean);

        $dir = dirname($this->file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        return false !== @file_put_contents(
            $this->file,
            json_encode($this->values, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    private function load(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }
        if (! is_file($this->file)) {
            return $this->values = [];
        }
        $body = @file_get_contents($this->file);
        if ($body === false || $body === '') {
            return $this->values = [];
        }
        $decoded = json_decode($body, true);
        return $this->values = is_array($decoded) ? $decoded : [];
    }
}
