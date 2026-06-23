<?php
declare(strict_types=1);

/**
 * Convertit les expressions LaTeX d'un texte en notation Unicode lisible.
 *
 * Cette conversion est volontairement textuelle : Mastodon ne rend pas
 * MathJax, mais le contenu mathématique doit rester compréhensible.
 */
function mastodon_latex_to_text(string $text): string
{
    $patterns = [
        '~\$\$(.*?)\$\$~su',
        '~\\\\\[(.*?)\\\\\]~su',
        '~\\\\\((.*?)\\\\\)~su',
        '~(?<!\\\\)(?<!\$)\$(?!\$)(.+?)(?<!\\\\)(?<!\$)\$(?!\$)~su',
    ];

    foreach ($patterns as $pattern) {
        $text = preg_replace_callback(
            $pattern,
            static fn(array $matches): string => mastodon_parse_latex_expression(trim($matches[1])),
            $text
        ) ?? $text;
    }

    return $text;
}

/**
 * Analyse une expression LaTeX sans dépendance externe.
 */
function mastodon_parse_latex_expression(string $latex): string
{
    $position = 0;
    $result = mastodon_parse_latex_sequence($latex, $position);

    $result = str_replace(
        [' & ', '&', '\\\\', '~'],
        [' ; ', ' ; ', ' ; ', ' '],
        $result
    );
    $result = preg_replace('/[ \t]+/u', ' ', $result) ?? $result;
    $result = preg_replace('/\s*([,;])\s*/u', '$1 ', $result) ?? $result;
    $result = preg_replace('/\s+([)\]}])/u', '$1', $result) ?? $result;
    $result = preg_replace('/([([{])\s+/u', '$1', $result) ?? $result;

    return trim($result, " \t\n\r\0\x0B;");
}

function mastodon_parse_latex_sequence(string $latex, int &$position, ?string $terminator = null): string
{
    $result = '';
    $length = strlen($latex);

    while ($position < $length) {
        $character = $latex[$position];

        if ($terminator !== null && $character === $terminator) {
            $position++;
            break;
        }

        if ($character === '\\') {
            $result .= mastodon_parse_latex_command($latex, $position);
            continue;
        }

        if ($character === '{') {
            $position++;
            $result .= mastodon_parse_latex_sequence($latex, $position, '}');
            continue;
        }

        if ($character === '^' || $character === '_') {
            $position++;
            $argument = mastodon_parse_latex_argument($latex, $position);
            $result .= $character . mastodon_format_script_argument($argument);
            continue;
        }

        $result .= $character;
        $position++;
    }

    return $result;
}

function mastodon_parse_latex_command(string $latex, int &$position): string
{
    $position++;
    $length = strlen($latex);

    if ($position >= $length) {
        return '';
    }

    if (!ctype_alpha($latex[$position])) {
        $escaped = $latex[$position++];

        return match ($escaped) {
            ',', ';', ':', '!', ' ' => ' ',
            '\\' => ' ; ',
            '{', '}', '_', '%', '#', '&', '$' => $escaped,
            default => $escaped,
        };
    }

    $start = $position;

    while ($position < $length && ctype_alpha($latex[$position])) {
        $position++;
    }

    $command = substr($latex, $start, $position - $start);
    $hadTrailingWhitespace = mastodon_skip_latex_whitespace($latex, $position);

    if (in_array($command, ['frac', 'dfrac', 'tfrac'], true)) {
        $numerator = mastodon_parse_latex_argument($latex, $position);
        mastodon_skip_latex_whitespace($latex, $position);
        $denominator = mastodon_parse_latex_argument($latex, $position);

        return mastodon_parenthesize_math_operand($numerator)
            . ' / '
            . mastodon_parenthesize_math_operand($denominator);
    }

    if ($command === 'sqrt') {
        $degree = mastodon_parse_latex_optional_argument($latex, $position);
        mastodon_skip_latex_whitespace($latex, $position);
        $radicand = mastodon_parse_latex_argument($latex, $position);

        return $degree === null
            ? '√(' . $radicand . ')'
            : 'racine[' . $degree . '](' . $radicand . ')';
    }

    if (in_array($command, ['text', 'textrm', 'textnormal', 'mathrm', 'mathbf', 'mathit', 'mathsf', 'mathtt', 'operatorname'], true)) {
        return mastodon_parse_latex_argument($latex, $position);
    }

    if (in_array($command, ['left', 'right'], true)) {
        if ($position < $length && $latex[$position] === '.') {
            $position++;
            return '';
        }

        if ($position < $length && $latex[$position] === '\\') {
            return mastodon_parse_latex_command($latex, $position);
        }

        return $position < $length ? $latex[$position++] : '';
    }

    if (in_array($command, ['begin', 'end'], true)) {
        mastodon_parse_latex_argument($latex, $position);
        return $command === 'begin' ? '' : ' ';
    }

    $wrappers = [
        'vec' => 'vec',
        'overrightarrow' => 'vec',
        'overline' => 'barre',
        'underline' => 'souligne',
        'hat' => 'chapeau',
        'tilde' => 'tilde',
        'abs' => 'abs',
    ];

    if (isset($wrappers[$command])) {
        return $wrappers[$command] . '(' . mastodon_parse_latex_argument($latex, $position) . ')';
    }

    $symbols = mastodon_latex_symbol_map();

    if (array_key_exists($command, $symbols)) {
        $symbol = $symbols[$command];

        if (
            $hadTrailingWhitespace
            && $symbol !== ''
            && !str_ends_with($symbol, ' ')
        ) {
            $symbol .= ' ';
        }

        return $symbol;
    }

    if ($position < $length && $latex[$position] === '{') {
        return $command . '(' . mastodon_parse_latex_argument($latex, $position) . ')';
    }

    return $command;
}

function mastodon_parse_latex_argument(string $latex, int &$position): string
{
    mastodon_skip_latex_whitespace($latex, $position);
    $length = strlen($latex);

    if ($position >= $length) {
        return '';
    }

    if ($latex[$position] === '{') {
        $position++;
        return trim(mastodon_parse_latex_sequence($latex, $position, '}'));
    }

    if ($latex[$position] === '\\') {
        return trim(mastodon_parse_latex_command($latex, $position));
    }

    return $latex[$position++];
}

function mastodon_parse_latex_optional_argument(string $latex, int &$position): ?string
{
    mastodon_skip_latex_whitespace($latex, $position);

    if (($latex[$position] ?? '') !== '[') {
        return null;
    }

    $position++;
    return trim(mastodon_parse_latex_sequence($latex, $position, ']'));
}

function mastodon_skip_latex_whitespace(string $latex, int &$position): bool
{
    $length = strlen($latex);
    $start = $position;

    while ($position < $length && ctype_space($latex[$position])) {
        $position++;
    }

    return $position > $start;
}

function mastodon_parenthesize_math_operand(string $operand): string
{
    $operand = trim($operand);

    if ($operand === '' || preg_match('/^[\p{L}\p{N}.]+(?:\^[\p{L}\p{N}.]+)?$/u', $operand)) {
        return $operand;
    }

    return '(' . $operand . ')';
}

function mastodon_format_script_argument(string $argument): string
{
    $argument = trim($argument);

    return preg_match('/^[\p{L}\p{N}.]+$/u', $argument)
        ? $argument
        : '(' . $argument . ')';
}

function mastodon_latex_symbol_map(): array
{
    return [
        'alpha' => 'α', 'beta' => 'β', 'gamma' => 'γ', 'delta' => 'δ',
        'epsilon' => 'ε', 'varepsilon' => 'ϵ', 'zeta' => 'ζ', 'eta' => 'η',
        'theta' => 'θ', 'vartheta' => 'ϑ', 'iota' => 'ι', 'kappa' => 'κ',
        'lambda' => 'λ', 'mu' => 'μ', 'nu' => 'ν', 'xi' => 'ξ',
        'pi' => 'π', 'varpi' => 'ϖ', 'rho' => 'ρ', 'sigma' => 'σ',
        'tau' => 'τ', 'upsilon' => 'υ', 'phi' => 'φ', 'varphi' => 'ϕ',
        'chi' => 'χ', 'psi' => 'ψ', 'omega' => 'ω',
        'Gamma' => 'Γ', 'Delta' => 'Δ', 'Theta' => 'Θ', 'Lambda' => 'Λ',
        'Xi' => 'Ξ', 'Pi' => 'Π', 'Sigma' => 'Σ', 'Upsilon' => 'Υ',
        'Phi' => 'Φ', 'Psi' => 'Ψ', 'Omega' => 'Ω',
        'times' => ' × ', 'cdot' => ' · ', 'div' => ' ÷ ', 'pm' => ' ± ', 'mp' => ' ∓ ',
        'le' => ' ≤ ', 'leq' => ' ≤ ', 'ge' => ' ≥ ', 'geq' => ' ≥ ',
        'ne' => ' ≠ ', 'neq' => ' ≠ ', 'approx' => ' ≈ ', 'sim' => ' ∼ ',
        'equiv' => ' ≡ ', 'propto' => ' ∝ ',
        'rightarrow' => ' → ', 'to' => ' → ', 'leftarrow' => ' ← ',
        'leftrightarrow' => ' ↔ ', 'Rightarrow' => ' ⇒ ', 'Leftarrow' => ' ⇐ ',
        'Leftrightarrow' => ' ⇔ ', 'mapsto' => ' ↦ ',
        'in' => ' ∈ ', 'notin' => ' ∉ ', 'subset' => ' ⊂ ', 'supset' => ' ⊃ ',
        'subseteq' => ' ⊆ ', 'supseteq' => ' ⊇ ', 'cup' => ' ∪ ', 'cap' => ' ∩ ',
        'emptyset' => '∅', 'varnothing' => '∅',
        'sum' => 'Σ', 'prod' => 'Π', 'int' => '∫', 'iint' => '∬',
        'oint' => '∮', 'infty' => '∞', 'partial' => '∂', 'nabla' => '∇',
        'forall' => '∀', 'exists' => '∃', 'neg' => '¬', 'land' => ' ∧ ',
        'lor' => ' ∨ ', 'ldots' => '…', 'cdots' => '⋯', 'vdots' => '⋮',
        'quad' => ' ', 'qquad' => ' ', 'enspace' => ' ', 'space' => ' ',
        'sin' => 'sin', 'cos' => 'cos', 'tan' => 'tan', 'log' => 'log',
        'ln' => 'ln', 'exp' => 'exp', 'lim' => 'lim', 'min' => 'min',
        'max' => 'max', 'det' => 'det', 'gcd' => 'pgcd',
    ];
}

/**
 * Convertit le Markdown/LaTeX en texte simple pour un statut Mastodon.
 */
function mastodon_plain_text(string $text): string
{
    $text = mastodon_latex_to_text($text);

    $text = preg_replace('/!\[([^\]]*)\]\([^)]+\)/u', '$1', $text) ?? $text;
    $text = preg_replace('/\[([^\]]+)\]\(((?:https?:\/\/|file\.php\?)[^)]+)\)/u', '$1 ($2)', $text) ?? $text;
    $text = preg_replace('/^#{1,6}\s*/m', '', $text) ?? $text;
    $text = preg_replace('/^\s*[-*+]\s+/m', '• ', $text) ?? $text;
    $text = preg_replace('/^\s*>\s?/m', '', $text) ?? $text;
    $text = preg_replace('/```(?:[a-zA-Z0-9_-]+)?\s*\R?/u', '', $text) ?? $text;
    $text = str_replace(['**', '__', '*', '`'], '', $text);
    $text = str_replace(['\$', '\%', '\#', '\&'], ['$', '%', '#', '&'], $text);

    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ *\R */u', "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

    return trim($text);
}

/**
 * Construit un statut court en réservant la place de l'URL.
 */
function mastodon_build_status(string $title, string $summary, string $url, int $maxLength = 480): string
{
    $title = mastodon_plain_text($title);
    $summary = mastodon_plain_text($summary);
    $url = trim($url);

    $prefix = $title !== '' ? $title . "\n\n" : '';
    $suffix = $url !== '' ? "\n\n" . $url : '';
    $available = $maxLength - mb_strlen($prefix, 'UTF-8') - mb_strlen($suffix, 'UTF-8');

    if ($available <= 0) {
        $fallback = trim($title . "\n\n" . $url);

        return mb_strlen($fallback, 'UTF-8') > $maxLength
            ? mb_substr($fallback, 0, max(0, $maxLength - 1), 'UTF-8') . '…'
            : $fallback;
    }

    if (mb_strlen($summary, 'UTF-8') > $available) {
        $summary = mb_substr($summary, 0, max(0, $available - 1), 'UTF-8') . '…';
    }

    return trim($prefix . $summary . $suffix);
}
