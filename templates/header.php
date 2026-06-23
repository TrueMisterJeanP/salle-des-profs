<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';

$user = current_user();
$siteName = site_name();
$siteIcon = site_icon();
$siteLogoUrl = site_logo_url();
$currentScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
$currentProtectionType = str_contains($currentScript, '/admin/protection.php') ? get_value('type', 'events') : '';
$headerSearchQuery = get_value('q');
$currentUserAvatarUrl = $user && !empty($user['avatar'])
    ? avatar_url((int)$user['id'], (string)$user['avatar'])
    : '';
$currentUserAvatarCssUrl = $currentUserAvatarUrl !== ''
    ? str_replace(["\\", "'", "\r", "\n", "\f"], ["\\\\", "\\'", '', '', ''], $currentUserAvatarUrl)
    : '';
$currentUserAvatarInitial = $user ? user_avatar_initial($user['display_name'] ?? '', $user['username'] ?? '') : '';

$unreadNotifications = 0;

if ($user) {
    $notificationCache = $_SESSION['unread_notifications_cache'] ?? null;

    if (
        is_array($notificationCache)
        && (int)($notificationCache['user_id'] ?? 0) === (int)$user['id']
        && (int)($notificationCache['expires_at'] ?? 0) >= time()
    ) {
        $unreadNotifications = (int)($notificationCache['total'] ?? 0);
    } else {
        try {
            $row = db_fetch_one(
                "SELECT COUNT(*) AS total
                 FROM notifications
                 WHERE user_id = :user_id
                   AND is_read = 0",
                ['user_id' => $user['id']]
            );

            $unreadNotifications = (int)($row['total'] ?? 0);
            $_SESSION['unread_notifications_cache'] = [
                'user_id' => (int)$user['id'],
                'total' => $unreadNotifications,
                'expires_at' => time() + 10,
            ];
        } catch (Throwable $e) {
            $unreadNotifications = 0;
        }
    }
}

if (!function_exists('nav_is_current')) {
    function nav_is_current(string $script, string $path): bool
    {
        $currentBasename = basename($script);
        $targetBasename = basename(parse_url($path, PHP_URL_PATH) ?: $path);

        if ($targetBasename !== $currentBasename) {
            return false;
        }

        $targetIsAdmin = str_starts_with(ltrim($path, '/'), 'admin/');
        $currentIsAdmin = str_contains($script, '/admin/');

        return $targetIsAdmin === $currentIsAdmin;
    }
}

if (!function_exists('site_breadcrumb_items')) {
    function site_breadcrumb_items(string $script, array $context = []): array
    {
        $basename = basename($script);
        $isAdmin = str_contains($script, '/admin/');

        $items = [
            ['label' => 'Accueil', 'url' => url('dashboard.php')],
        ];

        if (!$isAdmin && $basename === 'dashboard.php') {
            return $items;
        }

        if ($isAdmin) {
            $items[] = ['label' => 'Administration', 'url' => admin_url('index.php')];

            $adminLabels = [
                'activity.php' => 'Activités',
                'articles.php' => 'Articles',
                'comments.php' => 'Commentaires des articles',
                'post_comments.php' => 'Commentaires des annonces',
                'contact_messages.php' => 'Messages de contact',
                'database.php' => 'Base de données',
                'groups.php' => 'Groupes',
                'mail.php' => 'Courriels',
                'mastodon_publications.php' => 'Publications Mastodon',
                'migrations.php' => 'Migrations',
                'posts.php' => 'Publications',
                'settings.php' => 'Configuration',
                'users.php' => 'Utilisateurs',
            ];

            if ($basename === 'index.php') {
                return $items;
            }

            if ($basename === 'protection.php') {
                $items[] = ['label' => 'Protection', 'url' => admin_url('protection.php?type=events')];
                $label = (string)($context['protection_title'] ?? '');
                if ($label !== '') {
                    $items[] = ['label' => $label, 'url' => null];
                }
                return $items;
            }

            $items[] = ['label' => $adminLabels[$basename] ?? pathinfo($basename, PATHINFO_FILENAME), 'url' => null];
            return $items;
        }

        $publicRoutes = [
            'about.php' => ['À propos'],
            'actions.php' => ['Établissement', 'Actions collectives'],
            'article_edit.php' => ['Mes articles', (string)($context['page_title'] ?? 'Édition')],
            'articles.php' => ['Mes articles'],
            'attachments.php' => ['Fichiers'],
            'charter.php' => ['Charte'],
            'chat.php' => ['Messagerie', 'Messages privés'],
            'contact.php' => ['Contact'],
            'discussion.php' => ['Discussion'],
            'events.php' => ['Établissement', 'Calendrier'],
            'feed.php' => ['Mes annonces'],
            'groups.php' => ['Messagerie', 'Groupes'],
            'incidents.php' => ['Établissement', 'Incidents'],
            'mastodon_publications.php' => ['Compte', 'Historique Mastodon'],
            'mastodon_settings.php' => ['Compte', 'Mastodon'],
            'members.php' => ['Outils', 'Membres'],
            'notifications.php' => ['Notifications'],
            'profile.php' => ['Compte', 'Profil'],
            'protection.php' => ['Établissement'],
            'resources.php' => ['Établissement', 'Textes et services'],
            'search.php' => ['Recherche'],
            'syndicates.php' => ['Syndicats'],
        ];

        $routeUrls = [
            'Mes articles' => url('articles.php'),
            'Compte' => url('profile.php'),
            'Messagerie' => url('chat.php'),
            'Établissement' => url('protection.php'),
        ];

        if ($basename === 'article.php') {
            $article = is_array($context['article'] ?? null) ? $context['article'] : [];
            $items[] = ['label' => 'Mes articles', 'url' => url('articles.php')];
            $items[] = ['label' => (string)($article['title'] ?? 'Article'), 'url' => null];
            return $items;
        }

        if ($basename === 'group.php') {
            $group = is_array($context['group'] ?? null) ? $context['group'] : [];
            $items[] = ['label' => 'Messagerie', 'url' => url('chat.php')];
            $items[] = ['label' => 'Groupes', 'url' => url('groups.php')];
            $items[] = ['label' => (string)($group['name'] ?? 'Groupe'), 'url' => null];
            return $items;
        }

        foreach ($publicRoutes[$basename] ?? [pathinfo($basename, PATHINFO_FILENAME)] as $label) {
            $items[] = [
                'label' => $label,
                'url' => $routeUrls[$label] ?? null,
            ];
        }

        return $items;
    }
}

$breadcrumbItems = $user ? site_breadcrumb_items($currentScript, [
    'article' => $article ?? null,
    'group' => $group ?? null,
    'page_title' => $pageTitle ?? null,
    'protection_title' => $config['title'] ?? null,
]) : [];
?>

<header class="site-header">
    <?php if ($user): ?>
        <input type="hidden" name="<?= e(CSRF_TOKEN_NAME) ?>" value="<?= e(csrf_token()) ?>" data-csrf-token>
    <?php endif; ?>

    <div class="container site-header-inner">
        <a class="brand <?= $siteLogoUrl !== '' || $siteIcon !== '' ? 'brand-has-icon' : '' ?>" href="<?= e(url('dashboard.php')) ?>">
            <?php if ($siteLogoUrl !== ''): ?>
                <span class="brand-icon brand-logo" aria-hidden="true">
                    <img src="<?= e($siteLogoUrl) ?>" alt="">
                </span>
            <?php elseif ($siteIcon !== ''): ?>
                <span class="brand-icon" aria-hidden="true"><?= e($siteIcon) ?></span>
            <?php endif; ?>
            <span class="brand-name"><?= e($siteName) ?></span>
        </a>

        <?php if ($user): ?>
            <button class="site-menu-toggle site-menu-vertical-toggle" type="button" data-site-menu-toggle="site-private-menu" aria-controls="site-private-menu" aria-expanded="false">
                <span class="site-menu-icon" aria-hidden="true"></span>
                <span>Menu</span>
            </button>

            <nav class="site-menu-bar" id="site-private-menu" aria-label="Navigation principale">
                <?php if (($user['role'] ?? '') === 'admin'): ?>
                <details class="site-menu-group site-menu-group-admin">
                    <summary class="site-menu-tab">Réglages</summary>
                    <div class="site-menu-dropdown">
                        <a href="<?= e(admin_url('index.php')) ?>" <?= nav_is_current($currentScript, 'admin/index.php') ? 'aria-current="page"' : '' ?>>Administration</a>
                        <a href="<?= e(admin_url('settings.php')) ?>" <?= nav_is_current($currentScript, 'admin/settings.php') ? 'aria-current="page"' : '' ?>>Configuration</a>
                        <a href="<?= e(admin_url('database.php')) ?>" <?= nav_is_current($currentScript, 'admin/database.php') ? 'aria-current="page"' : '' ?>>Base de données</a>
                        <a href="<?= e(admin_url('migrations.php')) ?>" <?= nav_is_current($currentScript, 'admin/migrations.php') ? 'aria-current="page"' : '' ?>>Migrations</a>
                        <a href="<?= e(admin_url('users.php')) ?>" <?= nav_is_current($currentScript, 'admin/users.php') ? 'aria-current="page"' : '' ?>>Utilisateurs</a>
                        <a href="<?= e(admin_url('mail.php')) ?>" <?= nav_is_current($currentScript, 'admin/mail.php') ? 'aria-current="page"' : '' ?>>Courriels</a>
                        <a href="<?= e(admin_url('groups.php')) ?>" <?= nav_is_current($currentScript, 'admin/groups.php') ? 'aria-current="page"' : '' ?>>Groupes</a>
                        <a href="<?= e(admin_url('activity.php')) ?>" <?= nav_is_current($currentScript, 'admin/activity.php') ? 'aria-current="page"' : '' ?>>Activités</a>
                        <a href="<?= e(admin_url('contact_messages.php')) ?>" <?= nav_is_current($currentScript, 'admin/contact_messages.php') ? 'aria-current="page"' : '' ?>>Messages de contact</a>
                        <a href="<?= e(admin_url('posts.php')) ?>" <?= nav_is_current($currentScript, 'admin/posts.php') ? 'aria-current="page"' : '' ?>>Annonces</a>
                        <a href="<?= e(admin_url('post_comments.php')) ?>" <?= nav_is_current($currentScript, 'admin/post_comments.php') ? 'aria-current="page"' : '' ?>>Commentaires des annonces</a>
                        <a href="<?= e(admin_url('articles.php')) ?>" <?= nav_is_current($currentScript, 'admin/articles.php') ? 'aria-current="page"' : '' ?>>Articles</a>
                        <a href="<?= e(admin_url('comments.php')) ?>" <?= nav_is_current($currentScript, 'admin/comments.php') ? 'aria-current="page"' : '' ?>>Commentaires des articles</a>
                        <a href="<?= e(admin_url('mastodon_publications.php')) ?>" <?= nav_is_current($currentScript, 'admin/mastodon_publications.php') ? 'aria-current="page"' : '' ?>>Publications Mastodon</a>
                        <a href="<?= e(admin_url('protection.php?type=events')) ?>" <?= $currentProtectionType === 'events' ? 'aria-current="page"' : '' ?>>Calendrier</a>
                        <a href="<?= e(admin_url('protection.php?type=incidents')) ?>" <?= $currentProtectionType === 'incidents' ? 'aria-current="page"' : '' ?>>Incidents</a>
                        <a href="<?= e(admin_url('protection.php?type=actions')) ?>" <?= $currentProtectionType === 'actions' ? 'aria-current="page"' : '' ?>>Actions gérées</a>
                        <a href="<?= e(admin_url('protection.php?type=resources')) ?>" <?= $currentProtectionType === 'resources' ? 'aria-current="page"' : '' ?>>Textes et services</a>
                        <a href="<?= e(admin_url('protection.php?type=syndicates')) ?>" <?= $currentProtectionType === 'syndicates' ? 'aria-current="page"' : '' ?>>Syndicats</a>
                    </div>
                </details>
                <?php endif; ?>

                <form class="site-menu-search" method="get" action="<?= e(url('search.php')) ?>" role="search">
                    <label class="sr-only" for="site-menu-search-q">Recherche</label>
                    <input
                        type="search"
                        id="site-menu-search-q"
                        name="q"
                        value="<?= e($headerSearchQuery) ?>"
                        placeholder="Recherche"
                        aria-label="Recherche"
                    >
                    <button type="submit">Rechercher</button>
                </form>
                
                <details class="site-menu-group">
                    <summary class="site-menu-tab">Navigation</summary>
                    <div class="site-menu-dropdown">
                        <a href="<?= e(url('dashboard.php')) ?>" <?= nav_is_current($currentScript, 'dashboard.php') ? 'aria-current="page"' : '' ?>>Accueil</a>
                        <a href="<?= e(url('protection.php')) ?>" <?= nav_is_current($currentScript, 'protection.php') ? 'aria-current="page"' : '' ?>>Établissement</a>
                        <a href="<?= e(url('syndicates.php')) ?>" <?= nav_is_current($currentScript, 'syndicates.php') ? 'aria-current="page"' : '' ?>>Syndicats</a>
                        <a href="<?= e(url('feed.php')) ?>" <?= nav_is_current($currentScript, 'feed.php') ? 'aria-current="page"' : '' ?>>Mes annonces</a>
                        <a href="<?= e(url('articles.php')) ?>" <?= nav_is_current($currentScript, 'articles.php') ? 'aria-current="page"' : '' ?>>Mes articles</a>
                        <a href="<?= e(url('chat.php')) ?>" <?= nav_is_current($currentScript, 'chat.php') ? 'aria-current="page"' : '' ?>>Messagerie</a>
                    </div>
                </details>

                <details class="site-menu-group">
                    <summary class="site-menu-tab">Outils</summary>
                    <div class="site-menu-dropdown">
                        <a href="<?= e(url('notifications.php')) ?>" <?= nav_is_current($currentScript, 'notifications.php') ? 'aria-current="page"' : '' ?>>
                            Notifications<?= $unreadNotifications > 0 ? ' (' . e((string)$unreadNotifications) . ')' : '' ?>
                        </a>
                        <a href="<?= e(url('attachments.php')) ?>" <?= nav_is_current($currentScript, 'attachments.php') ? 'aria-current="page"' : '' ?>>Fichiers</a>
                        <a href="<?= e(url('members.php')) ?>" <?= nav_is_current($currentScript, 'members.php') ? 'aria-current="page"' : '' ?>>Membres</a>
                    </div>
                </details>

                <details class="site-menu-group">
                    <summary class="site-menu-tab">Compte</summary>
                    <div class="site-menu-dropdown">
                        <a href="<?= e(url('profile.php')) ?>" <?= nav_is_current($currentScript, 'profile.php') ? 'aria-current="page"' : '' ?>>Profil</a>
                        <a href="<?= e(url('mastodon_settings.php')) ?>" <?= nav_is_current($currentScript, 'mastodon_settings.php') ? 'aria-current="page"' : '' ?>>Mastodon</a>
                        <a href="<?= e(url('mastodon_publications.php')) ?>" <?= nav_is_current($currentScript, 'mastodon_publications.php') ? 'aria-current="page"' : '' ?>>Historique Mastodon</a>
                    </div>
                </details>

                <form class="site-logout-form" method="post" action="<?= e(url('logout.php')) ?>">
                    <?= csrf_field() ?>
                    <button class="nav-link-danger" type="submit">Déconnexion</button>
                </form>

            </nav>
        <?php else: ?>
            <details class="site-menu">
                <summary class="site-menu-toggle">
                    <span class="site-menu-icon" aria-hidden="true"></span>
                    <span>Menu</span>
                </summary>
                <nav class="main-nav site-menu-panel site-menu-panel-public" aria-label="Navigation publique">
                    <div class="nav-section">
                        <span class="nav-section-title">Accès</span>
                        <a href="<?= e(url('login.php')) ?>" <?= nav_is_current($currentScript, 'login.php') ? 'aria-current="page"' : '' ?>>Connexion</a>
                    </div>
                </nav>
            </details>
        <?php endif; ?>
    </div>
</header>

<script>
    document.querySelectorAll('.site-menu-bar .site-menu-group').forEach((group) => {
        group.addEventListener('toggle', () => {
            if (!group.open) {
                return;
            }

            document.querySelectorAll('.site-menu-bar .site-menu-group[open]').forEach((openGroup) => {
                if (openGroup !== group) {
                    openGroup.open = false;
                }
            });
        });
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('.site-menu-bar')) {
            return;
        }

        document.querySelectorAll('.site-menu-bar .site-menu-group[open]').forEach((group) => {
            group.open = false;
        });
    });
</script>

<?php if ($currentUserAvatarUrl !== ''): ?>
    <style>
        body:not(.auth-page) main.page > .card:first-of-type:not(.article-full)::after {
            background: #ffffff url('<?= $currentUserAvatarCssUrl ?>') center / cover no-repeat;
        }
    </style>
<?php elseif ($currentUserAvatarInitial !== ''): ?>
    <style>
        body:not(.auth-page) main.page > .card:first-of-type:not(.article-full)::after {
            content: <?= json_encode($currentUserAvatarInitial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            align-items: center;
            justify-content: center;
            background: #fbf3e6;
            color: #9a5a1f;
            border-color: #ffffff;
            font-size: 2.4rem;
            font-weight: 850;
        }
    </style>
<?php endif; ?>

<?php if ($breadcrumbItems): ?>
    <nav class="site-breadcrumb" aria-label="Fil d’Ariane">
        <ol class="container site-breadcrumb-list">
            <?php foreach ($breadcrumbItems as $index => $item): ?>
                <?php $isLastBreadcrumbItem = $index === count($breadcrumbItems) - 1; ?>
                <li>
                    <?php if (!$isLastBreadcrumbItem && !empty($item['url'])): ?>
                        <a href="<?= e((string)$item['url']) ?>"><?= e((string)$item['label']) ?></a>
                    <?php elseif ($isLastBreadcrumbItem): ?>
                        <span aria-current="page"><?= e((string)$item['label']) ?></span>
                    <?php else: ?>
                        <span><?= e((string)$item['label']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
<?php endif; ?>
