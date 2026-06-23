<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Rendu Markdown minimaliste et sécurisé.
 *
 * Prend en charge :
 * - titres # ## ###
 * - gras **texte**
 * - italique *texte*
 * - code inline `code`
 * - blocs de code ```text ... ```
 * - liens [texte](url)
 * - listes simples - item
 * - tableaux Markdown simples
 * - paragraphes
 * - LaTeX inline \( ... \)
 * - LaTeX inline $ ... $
 * - LaTeX bloc $$ ... $$
 */
function render_markdown(?string $text): string
{
    $text = trim($text ?? '');

    if ($text === '') {
        return '';
    }

    if (markdown_contains_latex($text)) {
        $GLOBALS['markdown_requires_mathjax'] = true;
    }

    $latexBlocks = [];
    $text = protect_latex_blocks($text, $latexBlocks);

    $lines = preg_split('/\R/u', $text);
    $html = '';
    $inList = false;
    $paragraph = [];

    $inCodeBlock = false;
    $codeBlockLanguage = '';
    $codeBlockLines = [];

    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];
        $trimmed = trim($line);

        /**
         * Bloc de code :
         *
         * ```text
         * contenu
         * ```
         */
        if (preg_match('/^```([a-zA-Z0-9_-]*)\s*$/', $trimmed, $matches)) {
            if ($inCodeBlock) {
                $html .= render_markdown_code_block($codeBlockLines, $codeBlockLanguage);

                $inCodeBlock = false;
                $codeBlockLanguage = '';
                $codeBlockLines = [];
            } else {
                flush_markdown_paragraph($html, $paragraph);

                if ($inList) {
                    $html .= '</ul>' . "\n";
                    $inList = false;
                }

                $inCodeBlock = true;
                $codeBlockLanguage = $matches[1] ?? '';
                $codeBlockLines = [];
            }

            continue;
        }

        if ($inCodeBlock) {
            $codeBlockLines[] = $line;
            continue;
        }

        if ($trimmed === '') {
            flush_markdown_paragraph($html, $paragraph);

            if ($inList) {
                $html .= '</ul>' . "\n";
                $inList = false;
            }

            continue;
        }

        /**
         * Tableau Markdown.
         *
         * Exemple :
         * | A | B |
         * | --- | --- |
         * | 1 | 2 |
         */
        if (
            str_starts_with($trimmed, '|')
            && isset($lines[$i + 1])
            && is_markdown_table_separator(trim($lines[$i + 1]))
        ) {
            flush_markdown_paragraph($html, $paragraph);

            if ($inList) {
                $html .= '</ul>' . "\n";
                $inList = false;
            }

            $tableLines = [$trimmed, trim($lines[$i + 1])];
            $i += 2;

            while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
                $tableLines[] = trim($lines[$i]);
                $i++;
            }

            $i--;

            $html .= render_markdown_table($tableLines);
            continue;
        }

        if (preg_match('/^###\s+(.+)$/u', $trimmed, $matches)) {
            flush_markdown_paragraph($html, $paragraph);

            if ($inList) {
                $html .= '</ul>' . "\n";
                $inList = false;
            }

            $html .= '<h3>' . render_markdown_inline($matches[1]) . '</h3>' . "\n";
            continue;
        }

        if (preg_match('/^##\s+(.+)$/u', $trimmed, $matches)) {
            flush_markdown_paragraph($html, $paragraph);

            if ($inList) {
                $html .= '</ul>' . "\n";
                $inList = false;
            }

            $html .= '<h2>' . render_markdown_inline($matches[1]) . '</h2>' . "\n";
            continue;
        }

        if (preg_match('/^#\s+(.+)$/u', $trimmed, $matches)) {
            flush_markdown_paragraph($html, $paragraph);

            if ($inList) {
                $html .= '</ul>' . "\n";
                $inList = false;
            }

            $html .= '<h1>' . render_markdown_inline($matches[1]) . '</h1>' . "\n";
            continue;
        }

        if (preg_match('/^-\s+(.+)$/u', $trimmed, $matches)) {
            flush_markdown_paragraph($html, $paragraph);

            if (!$inList) {
                $html .= '<ul>' . "\n";
                $inList = true;
            }

            $html .= '<li>' . render_markdown_inline($matches[1]) . '</li>' . "\n";
            continue;
        }

        if ($inList) {
            $html .= '</ul>' . "\n";
            $inList = false;
        }

        $paragraph[] = $trimmed;
    }

    if ($inCodeBlock) {
        $html .= render_markdown_code_block($codeBlockLines, $codeBlockLanguage);
    }

    flush_markdown_paragraph($html, $paragraph);

    if ($inList) {
        $html .= '</ul>' . "\n";
    }

    return restore_latex_blocks($html, $latexBlocks);
}

function markdown_contains_latex(?string $text): bool
{
    $text = (string)$text;

    if ($text === '') {
        return false;
    }

    return preg_match('~\$\$(.*?)\$\$|\\\\\((.*?)\\\\\)|(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)~su', $text) === 1;
}

/**
 * Vide un paragraphe en conservant les retours à la ligne simples.
 */
function flush_markdown_paragraph(string &$html, array &$paragraph): void
{
    if (!$paragraph) {
        return;
    }

    $renderedLines = [];

    foreach ($paragraph as $line) {
        $renderedLines[] = render_markdown_inline($line);
    }

    $html .= '<p>' . implode("<br>\n", $renderedLines) . '</p>' . "\n";

    $paragraph = [];
}

/**
 * Rend un bloc de code Markdown.
 */
function render_markdown_code_block(array $lines, string $language = ''): string
{
    $language = preg_replace('/[^a-zA-Z0-9_-]/', '', $language) ?? '';

    $class = $language !== ''
        ? ' class="language-' . e($language) . '"'
        : '';

    return '<pre class="markdown-code-block"><code' . $class . '>'
        . e(implode("\n", $lines))
        . '</code></pre>' . "\n";
}

/**
 * Protège les blocs et expressions LaTeX avant l’échappement HTML.
 */
    function protect_latex_blocks(string $text, array &$latexBlocks): string
    {
        /*
        * Blocs LaTeX :
        * $$ ... $$
        */
        $text = preg_replace_callback(
            '~\$\$(.*?)\$\$~su',
            static function (array $matches) use (&$latexBlocks): string {
                $key = '%%LATEX_BLOCK_' . count($latexBlocks) . '%%';
                
                $latexBlocks[$key] = '$$' . trim($matches[1]) . '$$';
                
                return "\n" . $key . "\n";
            },
            $text
        ) ?? $text;
        
        /*
        * Inline LaTeX :
        * \( ... \)
        *
        * On utilise chr(92) pour produire l’antislash,
        * afin d’éviter toute corruption du code.
        */
        $text = preg_replace_callback(
            '~\\\\\((.*?)\\\\\)~su',
            static function (array $matches) use (&$latexBlocks): string {
                $key = '%%LATEX_INLINE_' . count($latexBlocks) . '%%';
                
                $latexBlocks[$key] = chr(92) . '(' . trim($matches[1]) . chr(92) . ')';
                
                return $key;
            },
            $text
        ) ?? $text;
        
        /*
        * Inline LaTeX :
        * $ ... $
        *
        * On le restaure en \( ... \) pour MathJax.
        */
        $text = preg_replace_callback(
            '~(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)~su',
            static function (array $matches) use (&$latexBlocks): string {
                $key = '%%LATEX_INLINE_' . count($latexBlocks) . '%%';
                
                $latexBlocks[$key] = chr(92) . '(' . trim($matches[1]) . chr(92) . ')';
                
                return $key;
            },
            $text
        ) ?? $text;
        
        return $text;
    }

/**
 * Restaure les expressions LaTeX après le rendu Markdown.
 */
function restore_latex_blocks(string $html, array $latexBlocks): string
{
    foreach ($latexBlocks as $key => $latex) {
        $safeLatex = e($latex);

        $html = str_replace(e($key), $safeLatex, $html);
        $html = str_replace($key, $safeLatex, $html);
    }

    return $html;
}

/**
 * Rendu inline sécurisé.
 */
function render_markdown_inline(string $text): string
{
    $text = e($text);

    $text = preg_replace('#`([^`]+)`#u', '<code>$1</code>', $text) ?? $text;
    $text = preg_replace('#\*\*([^*]+)\*\*#u', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('#\*([^*]+)\*#u', '<em>$1</em>', $text) ?? $text;
    $text = render_markdown_images($text);

    return render_markdown_links($text);
}

function render_markdown_images(string $text): string
{
    return preg_replace_callback(
        '#!\[([^\]]*)\]\(([^)]+)\)#u',
        static function (array $matches): string {
            $alt = $matches[1];
            $url = html_entity_decode($matches[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if (!is_markdown_safe_url($url)) {
                return $matches[0];
            }

            return '<img class="markdown-image" src="' . e($url) . '" alt="' . e($alt) . '" loading="lazy">';
        },
        $text
    ) ?? $text;
}

/**
 * Convertit les liens Markdown [texte](https://exemple.fr)
 * sans regex complexe.
 */
function render_markdown_links(string $text): string
{
    $result = '';
    $offset = 0;

    while (true) {
        $startLabel = strpos($text, '[', $offset);

        if ($startLabel === false) {
            $result .= substr($text, $offset);
            break;
        }

        $endLabel = strpos($text, ']', $startLabel);

        if ($endLabel === false) {
            $result .= substr($text, $offset);
            break;
        }

        $startUrl = strpos($text, '(', $endLabel);

        if ($startUrl !== $endLabel + 1) {
            $result .= substr($text, $offset, $endLabel - $offset + 1);
            $offset = $endLabel + 1;
            continue;
        }

        $endUrl = strpos($text, ')', $startUrl);

        if ($endUrl === false) {
            $result .= substr($text, $offset);
            break;
        }

        $label = substr($text, $startLabel + 1, $endLabel - $startLabel - 1);
        $url = substr($text, $startUrl + 1, $endUrl - $startUrl - 1);

        $result .= substr($text, $offset, $startLabel - $offset);

        if (is_markdown_file_image_link($label, $url)) {
            $alt = html_entity_decode($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $result .= '<img class="markdown-image" src="' . e($url) . '" alt="' . e($alt) . '" loading="lazy">';
        } elseif (is_markdown_safe_url($url)) {
            $result .= '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
        } else {
            $result .= '[' . $label . '](' . $url . ')';
        }

        $offset = $endUrl + 1;
    }

    return $result;
}

function is_markdown_file_image_link(string $label, string $url): bool
{
    if (!preg_match('/\.(?:jpe?g|png|gif|webp)$/i', html_entity_decode($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))) {
        return false;
    }

    return preg_match('/^file\.php\?id=\d+$/', $url) === 1
        || preg_match('#^https?://[^\\s]+/public/file\.php\?id=\d+$#', $url) === 1;
}

function is_markdown_safe_url(string $url): bool
{
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return true;
    }

    return preg_match('/^file\.php\?id=\d+$/', $url) === 1
        || preg_match('#^https?://[^\\s]+/public/file\.php\?id=\d+$#', $url) === 1;
}

function is_markdown_table_separator(string $line): bool
{
    if (!str_starts_with($line, '|')) {
        return false;
    }

    $cells = parse_markdown_table_row($line);

    if (count($cells) < 2) {
        return false;
    }

    foreach ($cells as $cell) {
        $cell = trim($cell);

        if (!preg_match('/^:?-{3,}:?$/', $cell)) {
            return false;
        }
    }

    return true;
}

function parse_markdown_table_row(string $line): array
{
    $line = trim($line);

    if (str_starts_with($line, '|')) {
        $line = substr($line, 1);
    }

    if (str_ends_with($line, '|')) {
        $line = substr($line, 0, -1);
    }

    return array_map('trim', explode('|', $line));
}

function render_markdown_table(array $tableLines): string
{
    if (count($tableLines) < 2) {
        return '';
    }

    $headers = parse_markdown_table_row($tableLines[0]);
    $rows = array_slice($tableLines, 2);

    $html = '<div class="markdown-table-wrapper">' . "\n";
    $html .= '<table class="markdown-table">' . "\n";
    $html .= '<thead><tr>';

    foreach ($headers as $header) {
        $html .= '<th>' . render_markdown_inline($header) . '</th>';
    }

    $html .= '</tr></thead>' . "\n";
    $html .= '<tbody>' . "\n";

    foreach ($rows as $rowLine) {
        $cells = parse_markdown_table_row($rowLine);

        $html .= '<tr>';

        for ($i = 0; $i < count($headers); $i++) {
            $cell = $cells[$i] ?? '';
            $html .= '<td>' . render_markdown_inline($cell) . '</td>';
        }

        $html .= '</tr>' . "\n";
    }

    $html .= '</tbody>' . "\n";
    $html .= '</table>' . "\n";
    $html .= '</div>' . "\n";

    return $html;
}
