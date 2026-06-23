<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mastodon_text.php';

$tests = [
    'délimiteurs inline' => [
        'La relation $x \leq y$ est vraie.',
        'La relation x ≤ y est vraie.',
    ],
    'bloc conservé' => [
        "Résultat :\n$$\\frac{x + 1}{2} = 3$$",
        "Résultat :\n(x + 1) / 2 = 3",
    ],
    'racine et exposant' => [
        '\(\sqrt{x^2 + y^2}\)',
        '√(x^2 + y^2)',
    ],
    'grec et flèche' => [
        '$\alpha \rightarrow \Omega$',
        'α → Ω',
    ],
    'texte imbriqué' => [
        '$\frac{\text{distance}}{\text{temps}}$',
        'distance / temps',
    ],
    'indice composé' => [
        '$x_{n + 1}$',
        'x_(n + 1)',
    ],
    'markdown et latex' => [
        '**Formule** : $a \times b$' . "\n- premier",
        "Formule : a × b\n• premier",
    ],
    'dollars échappés' => [
        'Le prix est de \$5.',
        'Le prix est de $5.',
    ],
];

$failures = 0;

foreach ($tests as $name => [$input, $expected]) {
    $actual = mastodon_plain_text($input);

    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, sprintf(
            "%s\nAttendu : %s\nObtenu  : %s\n\n",
            $name,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

if ($failures > 0) {
    exit(1);
}

echo count($tests) . " tests réussis.\n";
