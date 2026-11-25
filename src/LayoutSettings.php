<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * The email layout options, read by the layout views. Values are cleaned here
 * so a bad setting can never break the HTML or CSS of an email.
 */
class LayoutSettings
{
    public const PREFIX = 'mrcaspan-email-editor.';

    public const DEFAULTS = [
        'custom_layout' => false,
        'show_title' => true,
        'show_greeting' => true,
        'show_signoff' => true,
        'logo_align' => 'left',
        'color_bg' => '#ffffff',
        'color_card' => '#ffffff',
        'color_border' => '#e1e1e1',
        'color_text' => '#333333',
        'color_accent' => '',
        'footer_text' => '',
    ];

    /** Unsaved values the admin page wants to preview. */
    protected ?array $preview = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function setPreview(?array $values): void
    {
        $this->preview = $values;
    }

    public function enabled(): bool
    {
        return $this->all()['custom_layout'];
    }

    public function all(): array
    {
        $out = [];

        foreach (self::DEFAULTS as $name => $default) {
            $raw = $this->preview !== null && array_key_exists($name, $this->preview)
                ? $this->preview[$name]
                : $this->settings->get(self::PREFIX.$name);

            $out[$name] = $this->clean($name, $raw, $default);
        }

        return $out;
    }

    protected function clean(string $name, mixed $value, mixed $default): mixed
    {
        if ($value === null || $value === '') {
            // An empty colour or footer means "none"; anything else falls back.
            return in_array($name, ['color_accent', 'footer_text'], true) ? '' : $default;
        }

        if (is_bool($default)) {
            return in_array($value, [true, 1, '1', 'true'], true);
        }

        if (str_starts_with($name, 'color_')) {
            return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $value) ? (string) $value : $default;
        }

        if ($name === 'logo_align') {
            return in_array($value, ['left', 'center'], true) ? $value : $default;
        }

        return (string) $value;
    }
}
