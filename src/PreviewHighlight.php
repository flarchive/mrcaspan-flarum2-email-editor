<?php

/*
 * This file is part of mrcaspan/flarum2-email-editor.
 *
 * For license information, please view the LICENSE file
 * that was distributed with this source code.
 */

namespace MrCaspan\EmailEditor;

/**
 * Marks the texts of the email being edited, so the admin preview can ring
 * them in red. Only ever set while a preview renders; real emails and test
 * emails never get the marks.
 *
 * Each text is wrapped in two private-use characters while the email is
 * built. Afterwards they become <span class="ee-hl"> around every piece of
 * text between them (so a mark across paragraphs stays valid HTML), and any
 * that ended up inside a tag or the <head> are dropped.
 */
class PreviewHighlight
{
    public const START = "\u{E000}";
    public const END = "\u{E001}";

    /** @var array<string, true> */
    protected array $keys = [];

    /**
     * @param string[] $keys
     */
    public function set(array $keys): void
    {
        $this->keys = array_fill_keys(array_filter($keys, 'is_string'), true);
    }

    public function active(): bool
    {
        return (bool) $this->keys;
    }

    public function wrap(mixed $key, string $text): string
    {
        return is_string($key) && isset($this->keys[$key]) && trim($text) !== ''
            ? self::START.$text.self::END
            : $text;
    }

    public static function strip(string $text): string
    {
        return str_replace([self::START, self::END], '', $text);
    }

    public static function html(string $html): string
    {
        if (! str_contains($html, self::START)) {
            return $html;
        }

        // None in the <head> (the page title) or inside a tag (an href, say).
        $html = preg_replace_callback('/<head\b.*?<\/head>/is', fn ($m) => self::strip($m[0]), $html);
        $html = preg_replace_callback('/<[^>]*>/', fn ($m) => self::strip($m[0]), $html);

        // A text of several paragraphs: move the marks out to just outside its
        // first and last paragraph, so it gets one box around all of it.
        $block = 'p|div|h[1-6]|ul|ol|blockquote|pre|table';
        $html = preg_replace('/(<(?:'.$block.')\b[^>]*>)(\s*)'.self::START.'/iu', self::START.'$1$2', $html);
        $html = preg_replace('/'.self::END.'(\s*)(<\/(?:'.$block.')>)/iu', '$1$2'.self::END, $html);

        $html = preg_replace_callback(
            '/'.self::START.'(.*?)'.self::END.'/su',
            function ($m) use ($block) {
                $inner = $m[1];
                $hasBlocks = preg_match('/<\/?(?:'.$block.')\b/i', $inner);

                // Whole paragraphs: one box. Inside a line: one ring.
                if ($hasBlocks && preg_match('/^\s*<(?:'.$block.')\b/i', $inner) && preg_match('/<\/(?:'.$block.')>\s*$/i', $inner)) {
                    return '<div class="ee-hl">'.$inner.'</div>';
                }

                if (! $hasBlocks) {
                    return '<span class="ee-hl">'.$inner.'</span>';
                }

                // Starts or ends mid-paragraph across paragraphs: ring each piece of text.
                return preg_replace_callback(
                    '/(<[^>]*>)|([^<]+)/u',
                    fn ($t) => isset($t[2]) && trim($t[2]) !== '' ? '<span class="ee-hl">'.$t[2].'</span>' : $t[0],
                    $inner
                );
            },
            $html
        );

        $style = '<style>.ee-hl{outline:2px dashed #e53935;outline-offset:2px;background:rgba(229,57,53,.15);border-radius:2px}'
            .'div.ee-hl{outline-offset:4px}div.ee-hl>:first-child{margin-top:0}div.ee-hl>:last-child{margin-bottom:0}</style>';
        $html = stripos($html, '</head>') !== false ? preg_replace('/<\/head>/i', $style.'</head>', $html, 1) : $style.$html;

        return self::strip($html);
    }
}
