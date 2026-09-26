<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pagination.php';

$base = rtrim(BASE_URL, '/');
$tests = [];

$tests['URL membre lisible'] = article_url('mon-titre') === $base . '/article/mon-titre';
$tests['URL publique lisible'] = public_article_url('mon-titre') === $base . '/publication/mon-titre';
$tests['Slug encodé dans l’URL'] = article_url('a b/c') === $base . '/article/a%20b%2Fc';
$tests['URL de groupe lisible'] = group_url('equipe-sciences') === $base . '/groupe/equipe-sciences';
$tests['URL d’édition lisible'] = article_edit_url('mon-titre') === $base . '/article/mon-titre/modifier';
$tests['URL de conversation lisible'] = chat_url('alice', ['members_page' => 2]) === $base . '/messages/alice?members_page=2';
$tests['URL de messagerie privée lisible'] = messenger_private_url('alice') === $base . '/messagerie/prive/alice';
$tests['URL de messagerie de groupe lisible'] = messenger_group_url('equipe') === $base . '/messagerie/groupe/equipe';
$tests['Slug sans accent'] = slugify('Privé : Équipe Été') === 'prive-equipe-ete';

$_GET = ['slug' => 'equipe', 'page' => '1'];
$_SERVER['REQUEST_URI'] = '/public/groupe/equipe?page=1';
$tests['Pagination sans paramètre de réécriture'] = page_url(2) === '?page=2';

$failures = 0;

foreach ($tests as $name => $passed) {
    if (!$passed) {
        $failures++;
        fwrite(STDERR, $name . " : échec\n");
    }
}

if ($failures > 0) {
    exit(1);
}

echo count($tests) . " tests d’URL lisibles réussis.\n";
