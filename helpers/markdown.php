<?php
/*
 * Markdown
 * ========
 * A small, safe Markdown renderer for notes, forum posts and announcements.
 * Everything is HTML-escaped FIRST, then a few formatting rules are applied, so any HTML a user
 * types is shown as text and can never run.
 * Supports: ## headings, **bold**, *italic*, `code`, ``` code blocks, lists, > quotes,
 * simple | tables |, --- lines and [links](https://...).
 */

function markdown(string $text): string
{
    $lines = preg_split('/\R/', $text);
    $html = '';
    $paragraph = [];
    $list = null; // 'ul' or 'ol'
    $flushParagraph = function () use (&$paragraph, &$html) {
        if ($paragraph) {
            $html .= '<p>' . implode('<br>', array_map('md_inline', $paragraph)) . '</p>';
            $paragraph = [];
        }
    };
    $closeList = function () use (&$list, &$html) {
        if ($list) {
            $html .= "</$list>";
            $list = null;
        }
    };

    for ($i = 0; $i < count($lines); $i++) {
        $line = $lines[$i];

        if (str_starts_with(trim($line), '```')) { // code block
            $flushParagraph();
            $closeList();
            $code = [];
            while (++$i < count($lines) && !str_starts_with(trim($lines[$i]), '```')) {
                $code[] = $lines[$i];
            }
            $html .= '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (trim($line) === '') {
            $flushParagraph();
            $closeList();
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $m)) {
            $flushParagraph();
            $closeList();
            $level = min(4, strlen($m[1]) + 1); // "#" becomes h2: the page title is the only h1
            $html .= "<h$level>" . md_inline($m[2]) . "</h$level>";
            continue;
        }
        if (preg_match('/^\s*(-{3,}|\*{3,})\s*$/', $line)) {
            $flushParagraph();
            $closeList();
            $html .= '<hr>';
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushParagraph();
            $closeList();
            $html .= '<blockquote>' . md_inline($m[1]) . '</blockquote>';
            continue;
        }
        if (preg_match('/^\s*([-*]|\d+\.)\s+(.*)$/', $line, $m)) {
            $flushParagraph();
            $type = ctype_digit(rtrim($m[1], '.')) ? 'ol' : 'ul';
            if ($list !== $type) {
                $closeList();
                $html .= "<$type>";
                $list = $type;
            }
            $html .= '<li>' . md_inline($m[2]) . '</li>';
            continue;
        }
        if (str_starts_with(trim($line), '|') && isset($lines[$i + 1]) && preg_match('/^\s*\|[\s|:-]+\|\s*$/', $lines[$i + 1])) {
            $flushParagraph();
            $closeList();
            $cells = fn ($row) => array_map('trim', explode('|', trim(trim($row), '|')));
            $html .= '<table><thead><tr>' . implode('', array_map(fn ($c) => '<th>' . md_inline($c) . '</th>', $cells($line))) . '</tr></thead><tbody>';
            $i++;
            while (isset($lines[$i + 1]) && str_starts_with(trim($lines[$i + 1]), '|')) {
                $i++;
                $html .= '<tr>' . implode('', array_map(fn ($c) => '<td>' . md_inline($c) . '</td>', $cells($lines[$i]))) . '</tr>';
            }
            $html .= '</tbody></table>';
            continue;
        }
        $closeList();
        $paragraph[] = $line;
    }
    $flushParagraph();
    $closeList();
    return $html;
}

/** Bold, italic, inline code and links inside one line. The text is escaped before any tag is added. */
function md_inline(string $text): string
{
    $codes = [];
    $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) {
        $codes[] = '<code>' . e($m[1]) . '</code>';
        return "\x01" . (count($codes) - 1) . "\x01";
    }, $text);
    $text = e($text);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $text);
    // Links: only http(s), mailto and links inside the site are allowed (no "javascript:").
    $text = preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|mailto:|index\.php)[^\s)]*)\)/', function ($m) {
        return '<a href="' . $m[2] . '" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $text);
    return preg_replace_callback("/\x01(\d+)\x01/", fn ($m) => $codes[(int) $m[1]], $text);
}
