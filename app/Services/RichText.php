<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Rich message text pasted from Word, Excel, Outlook or a web page: formatting, tables, pictures and charts.
 * Only an allow-list of tags, attributes and CSS properties is kept. Pasted pictures (data: URIs) are saved
 * in storage/news_images and linked by URL, so the stored HTML stays small. Old plain-text messages still work.
 */
final class RichText
{
    private const TAGS = ['p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup', 'small', 'mark',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr', 'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col'];
    /** Removed with everything inside them. */
    private const DROP = ['script', 'style', 'head', 'title', 'meta', 'link', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'input', 'button', 'select', 'textarea', 'template', 'noscript', 'xml'];
    private const ATTRS = ['style', 'colspan', 'rowspan', 'width', 'height', 'align', 'valign', 'href', 'src', 'alt', 'title', 'span', 'start'];
    private const CSS = ['color', 'background-color', 'background', 'font-weight', 'font-style', 'font-size', 'font-family', 'text-decoration',
        'text-align', 'vertical-align', 'white-space', 'width', 'min-width', 'max-width', 'height', 'list-style-type', 'border-collapse',
        'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-style', 'border-width',
        'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left', 'margin-left', 'text-indent'];
    private const IMAGE_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    private const MAX_IMAGE_BYTES = 8 * 1048576;

    /** True for a body written in the rich editor (stored HTML starts with a tag); false for old plain text. */
    public static function isHtml(?string $body): bool
    {
        return $body !== null && preg_match('/^\s*<[a-z]/i', $body) === 1;
    }

    /** Safe HTML for display. Plain text is escaped with its line breaks kept. */
    public static function render(?string $body): string
    {
        $body = (string) $body;
        return self::isHtml($body) ? self::clean($body, false) : '<div style="white-space:pre-wrap">' . e($body) . '</div>';
    }

    /** Plain text, for list previews and length checks. */
    public static function toText(?string $body): string
    {
        $body = (string) $body;
        if (!self::isHtml($body)) {
            return $body;
        }
        $body = preg_replace(['#<(br|/p|/div|/tr|/li|/h\d)\b[^>]*>#i', '#<img\b[^>]*>#i', '#</t[dh]>#i'], ["$0\n", '[picture] ', ' '], $body);
        return trim(html_entity_decode(strip_tags((string) $body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Keeps only allowed markup. With $saveImages, pasted pictures (data: URIs) are stored as files and linked;
     * otherwise only pictures already stored here are kept.
     */
    public static function clean(string $html, bool $saveImages = true): string
    {
        // Office tags such as <o:p> lose their prefix in the HTML parser (<o:p> would become <p>); drop the tags, keep the text.
        $html = (string) preg_replace('#</?[a-z]+:[a-z]+\b[^>]*>#i', '', $html);
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><!DOCTYPE html><html><body><div id="rt-root">' . $html . '</div></body></html>', LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $doc->getElementById('rt-root');
        if (!$root) {
            return '';
        }
        self::walk($root, $saveImages);
        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }
        return trim($out);
    }

    private static function walk(\DOMNode $node, bool $saveImages): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP, true)) {
                $node->removeChild($child);
                continue;
            }
            self::walk($child, $saveImages);
            if (!in_array($tag, self::TAGS, true)) {
                // Unknown wrapper (font, o:p, center…): keep its contents.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }
            self::cleanAttributes($child, $tag, $saveImages);
            if ($tag === 'img' && !$child->hasAttribute('src')) {
                $node->removeChild($child);
            }
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $tag, bool $saveImages): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            $keep = in_array($name, self::ATTRS, true);
            if ($name === 'href') {
                $keep = $tag === 'a' && preg_match('#^(https?:|mailto:)#i', $value) === 1;
            } elseif ($name === 'src') {
                $keep = false;
                if ($tag === 'img' && ($src = self::imageSrc($value, $saveImages)) !== null) {
                    $el->setAttribute('src', $src);
                    continue;
                }
            } elseif ($name === 'style') {
                $style = self::cleanStyle($value);
                $keep = $style !== '';
                if ($keep) {
                    $el->setAttribute('style', $style);
                    continue;
                }
            } elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'span', 'start'], true)) {
                $keep = preg_match('/^\d{1,4}(%|px)?$/', $value) === 1;
            } elseif (in_array($name, ['align', 'valign'], true)) {
                $keep = preg_match('/^(left|right|center|justify|top|middle|bottom)$/i', $value) === 1;
            }
            if (!$keep) {
                $el->removeAttribute($attr->name);
            }
        }
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function cleanStyle(string $style): string
    {
        $kept = [];
        foreach (explode(';', $style) as $rule) {
            [$prop, $value] = array_map('trim', explode(':', $rule, 2) + [1 => '']);
            $prop = strtolower($prop);
            if ($value === '' || !in_array($prop, self::CSS, true) || preg_match('/url\s*\(|expression|javascript|behavior|[<>\\\\@]/i', $value)) {
                continue;
            }
            $kept[] = $prop . ':' . $value;
        }
        return implode(';', $kept);
    }

    /** The URL to keep for a picture, or null to drop it. */
    private static function imageSrc(string $src, bool $saveImages): ?string
    {
        $prefix = url('/files/news-image/');
        if (str_starts_with($src, $prefix) && preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.(png|jpg|gif|webp)$#', substr($src, strlen($prefix)))) {
            return $src;
        }
        if (!$saveImages || !preg_match('#^data:(image/(?:png|jpeg|gif|webp));base64,([A-Za-z0-9+/=\s]+)$#', $src, $m)) {
            return null;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $m[2]), true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($bytes);
        $ext = self::IMAGE_TYPES[$info['mime'] ?? ''] ?? null;
        if ($ext === null) {
            return null;
        }
        $key = sprintf('news_images/%s/%s.%s', gmdate('Y/m'), bin2hex(random_bytes(16)), $ext);
        $path = FileStore::path($key);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0750, true);
        }
        file_put_contents($path, $bytes);
        return $prefix . substr($key, strlen('news_images/'));
    }
}
