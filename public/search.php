<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pagination.php';
require_once __DIR__ . '/../includes/protection.php';

require_login();
protection_ensure_schema();

$user = current_user();
$query = get_value('q');
$posts = [];
$articles = [];
$groups = [];
$messages = [];
$events = [];
$incidents = [];
$actions = [];
$resources = [];
$page = current_page();
$perPage = 20;
$offset = pagination_offset($page, $perPage);
$totalMessages = 0;
$totalPages = 1;

if ($query !== '') {
    $like = '%' . $query . '%';
    $currentUserId = (int)$user['id'];
    $postVisibility = "(
        posts.visibility IN ('public', 'members')
        OR posts.author_id = :content_user_id
        OR (
            posts.visibility = 'group'
            AND EXISTS (
                SELECT 1 FROM group_members search_post_members
                WHERE search_post_members.group_id = posts.group_id
                  AND search_post_members.user_id = :content_user_id
            )
        )
    )";
    $articleVisibility = "(
        articles.visibility IN ('public', 'members')
        OR articles.author_id = :content_user_id
        OR (
            articles.visibility = 'group'
            AND EXISTS (
                SELECT 1 FROM group_members search_article_members
                WHERE search_article_members.group_id = articles.group_id
                  AND search_article_members.user_id = :content_user_id
            )
        )
    )";

    $posts = db_fetch_all(
        "SELECT posts.*, users.username, users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE posts.is_published = 1
           AND $postVisibility
           AND posts.content LIKE :q
         ORDER BY posts.created_at DESC
         LIMIT 20",
        [
            'q' => $like,
            'content_user_id' => $currentUserId,
        ]
    );

    $articles = db_fetch_all(
        "SELECT articles.*, users.username, users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE articles.status = 'published'
           AND $articleVisibility
           AND (
                articles.title LIKE :q
                OR articles.content LIKE :q
                OR articles.excerpt LIKE :q
           )
         ORDER BY articles.published_at DESC, articles.created_at DESC
         LIMIT 20",
        [
            'q' => $like,
            'content_user_id' => $currentUserId,
        ]
    );

    $groups = db_fetch_all(
        "SELECT groups.*,
                users.username,
                users.display_name,
                COUNT(group_members.id) AS member_count
         FROM groups
         JOIN users ON users.id = groups.created_by
         LEFT JOIN group_members ON group_members.group_id = groups.id
         WHERE (
                groups.name LIKE :q
                OR groups.description LIKE :q
            )
           AND (
                groups.created_by = :user_id
                OR EXISTS (
                    SELECT 1
                    FROM group_members gm
                    WHERE gm.group_id = groups.id
                      AND gm.user_id = :user_id
                )
           )
         GROUP BY groups.id
         ORDER BY groups.created_at DESC
         LIMIT 20",
        [
            'q' => $like,
            'user_id' => $user['id'],
        ]
    );

    $events = db_fetch_all(
        "SELECT protection_events.*, users.username, users.display_name
         FROM protection_events
         JOIN users ON users.id = protection_events.created_by
         WHERE protection_events.is_active = 1
           AND " . protection_user_visibility_where($user, 'protection_events') . "
           AND (
                protection_events.title LIKE :q
                OR protection_events.location LIKE :q
                OR protection_events.description LIKE :q
                OR protection_events.response_owner LIKE :q
                OR protection_events.response_summary LIKE :q
           )
         ORDER BY protection_events.starts_at DESC
         LIMIT 20",
        ['q' => $like]
    );

    $incidents = db_fetch_all(
        "SELECT protection_incidents.*, users.username, users.display_name
         FROM protection_incidents
         JOIN users ON users.id = protection_incidents.created_by
         WHERE protection_incidents.is_active = 1
           AND " . protection_user_visibility_where($user, 'protection_incidents') . "
           AND (
                protection_incidents.title LIKE :q
                OR protection_incidents.location LIKE :q
                OR protection_incidents.description LIKE :q
                OR protection_incidents.immediate_actions LIKE :q
                OR protection_incidents.institutional_response LIKE :q
                OR protection_incidents.follow_up LIKE :q
           )
         ORDER BY protection_incidents.occurred_at DESC, protection_incidents.created_at DESC
         LIMIT 20",
        ['q' => $like]
    );

    $actions = db_fetch_all(
        "SELECT protection_action_plans.*, users.username, users.display_name
         FROM protection_action_plans
         JOIN users ON users.id = protection_action_plans.created_by
         WHERE protection_action_plans.is_active = 1
           AND " . protection_user_visibility_where($user, 'protection_action_plans') . "
           AND (
                protection_action_plans.title LIKE :q
                OR protection_action_plans.objective LIKE :q
                OR protection_action_plans.steps LIKE :q
                OR protection_action_plans.legal_frame LIKE :q
                OR protection_action_plans.risks LIKE :q
                OR protection_action_plans.owner LIKE :q
           )
         ORDER BY protection_action_plans.created_at DESC
         LIMIT 20",
        ['q' => $like]
    );

    $resources = db_fetch_all(
        "SELECT protection_resources.*, users.username, users.display_name
         FROM protection_resources
         JOIN users ON users.id = protection_resources.created_by
         WHERE protection_resources.is_active = 1
           AND (
                protection_resources.title LIKE :q
                OR protection_resources.organization LIKE :q
                OR protection_resources.url LIKE :q
                OR protection_resources.contact_info LIKE :q
                OR protection_resources.description LIKE :q
           )
         ORDER BY protection_resources.title ASC
         LIMIT 20",
        ['q' => $like]
    );

    $messageAccessSql = "
    messages.is_deleted = 0
    AND messages.content LIKE :q
    AND (
        (
            messages.group_id IS NULL
            AND (
                messages.sender_id = :user_id
                OR messages.receiver_id = :user_id
            )
        )
        OR (
            messages.group_id IS NOT NULL
            AND EXISTS (
                SELECT 1
                FROM groups message_groups
                WHERE message_groups.id = messages.group_id
                  AND (
                        message_groups.created_by = :user_id
                        OR EXISTS (
                            SELECT 1
                            FROM group_members gm
                            WHERE gm.group_id = message_groups.id
                              AND gm.user_id = :user_id
                        )
                  )
            )
        )
    )
";
    
    $totalMessages = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
        FROM messages
        WHERE $messageAccessSql",
        [
            'q' => $like,
            'user_id' => $user['id'],
        ]
    )['total'] ?? 0);
    
    $totalPages = total_pages($totalMessages, $perPage);
    
    $messages = db_fetch_all(
        "SELECT messages.*,
        sender.username AS sender_username,
        sender.display_name AS sender_display_name,
        receiver.username AS receiver_username,
        receiver.display_name AS receiver_display_name,
        groups.name AS group_name
        FROM messages
        JOIN users sender ON sender.id = messages.sender_id
        LEFT JOIN users receiver ON receiver.id = messages.receiver_id
        LEFT JOIN groups ON groups.id = messages.group_id
        WHERE $messageAccessSql
        ORDER BY messages.created_at DESC
        LIMIT :limit OFFSET :offset",
        [
            'q' => $like,
            'user_id' => $user['id'],
            'limit' => $perPage,
            'offset' => $offset,
        ]
    );
}

$hasSearchResults = (bool)($posts || $articles || $events || $incidents || $actions || $resources || $groups || $messages);
$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta name="theme-color" content="#ffffff">
    <meta charset="utf-8">
    <title>Recherche — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="container page">
        <?php foreach ($flashes as $flash): ?>
            <div class="<?= e(flash_class($flash['type'])) ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach; ?>

        <section class="card">
            <h1>Recherche</h1>
            <p class="muted">
                Rechercher dans les annonces, articles, événements, incidents, actions, ressources, groupes et messages.
            </p>

            <form method="get" action="" class="search-page-form">
                <label for="q">Recherche</label>
                <div class="search-input-row">
                    <input
                        type="search"
                        id="q"
                        name="q"
                        value="<?= e($query) ?>"
                        placeholder="Mot-clé, titre, message..."
                        required
                    >
                    <button type="submit" class="button-primary">Rechercher</button>
                </div>
            </form>
        </section>

        <?php if ($query === ''): ?>
            <section class="card">
                <p class="muted">Saisissez une recherche pour commencer.</p>
            </section>
        <?php else: ?>
            <section class="search-results-list" aria-label="Résultats de recherche">
                <?php if (!$hasSearchResults): ?>
                    <article class="card search-result-empty">
                        <p class="muted">Aucun résultat trouvé pour « <?= e($query) ?> ».</p>
                    </article>
                <?php endif; ?>

                <article class="card search-result-category" <?= $posts ? '' : 'hidden' ?>>
                    <h2>Annonces</h2>
                    <?php if (!$posts): ?><p class="muted">Aucune annonce trouvée.</p><?php else: ?>
                        <ul><?php foreach ($posts as $post): ?><li>
                            <h3><a href="<?= e(url('discussion.php?post_id=' . (int)$post['id'])) ?>">Annonce de <?= e($post['display_name'] ?: $post['username']) ?></a></h3>
                            <p><?= nl2br(e(excerpt($post['content'], 220))) ?></p>
                            <p class="meta"><?= e($post['created_at']) ?></p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </article>

                <article class="card search-result-category" <?= $articles ? '' : 'hidden' ?>>
                    <h2>Articles</h2>
                    <?php if (!$articles): ?><p class="muted">Aucun article trouvé.</p><?php else: ?>
                        <ul><?php foreach ($articles as $article): ?><li>
                            <h3><a href="<?= e(url('article.php?slug=' . urlencode($article['slug']))) ?>"><?= e($article['title']) ?></a></h3>
                            <p><?= e(excerpt($article['excerpt'] ?: $article['content'], 180)) ?></p>
                            <p class="meta"><?= e($article['display_name'] ?: $article['username']) ?> · <?= e($article['published_at'] ?: $article['created_at']) ?></p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </article>

                <article class="card search-result-category" <?= $events ? '' : 'hidden' ?>>
                    <h2>Événements</h2>
                    <?php if (!$events): ?><p class="muted">Aucun événement trouvé.</p><?php else: ?>
                        <ul><?php foreach ($events as $event): ?><li>
                            <h3><a href="<?= e(url('events.php?month=' . urlencode(substr((string)$event['starts_at'], 0, 7)) . '&event=' . (int)$event['id'] . '#month-event-detail')) ?>"><?= e($event['title']) ?></a></h3>
                            <p><?= e(excerpt((string)($event['description'] ?? ''), 200)) ?></p>
                            <p class="meta"><?= e(protection_label(PROTECTION_EVENT_TYPES, (string)$event['event_type'])) ?> · <?= e($event['starts_at']) ?><?= $event['location'] ? ' · ' . e($event['location']) : '' ?></p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </article>

                <article class="card search-result-category" <?= $incidents ? '' : 'hidden' ?>>
                    <h2>Incidents</h2>
                    <?php if (!$incidents): ?><p class="muted">Aucun incident trouvé.</p><?php else: ?>
                        <ul><?php foreach ($incidents as $incident): ?><li>
                            <h3><a href="<?= e(url('incidents.php?incident=' . (int)$incident['id'] . '#incident-directory')) ?>"><?= e($incident['title']) ?></a></h3>
                            <p><?= e(excerpt((string)$incident['description'], 200)) ?></p>
                            <p class="meta"><?= e(protection_label(PROTECTION_INCIDENT_TYPES, (string)$incident['incident_type'])) ?> · <?= e($incident['occurred_at']) ?><?= $incident['location'] ? ' · ' . e($incident['location']) : '' ?></p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </article>

                <article class="card search-result-category" <?= $actions ? '' : 'hidden' ?>>
                    <h2>Actions</h2>
                    <?php if (!$actions): ?><p class="muted">Aucune action trouvée.</p><?php else: ?>
                        <ul><?php foreach ($actions as $action): ?><li>
                            <h3><a href="<?= e(url('actions.php?action=' . (int)$action['id'] . '#action-directory')) ?>"><?= e($action['title']) ?></a></h3>
                            <p><?= e(excerpt((string)$action['objective'], 200)) ?></p>
                            <p class="meta"><?= e(protection_label(PROTECTION_ACTION_CATEGORIES, (string)$action['category'])) ?> · <?= e(protection_label(PROTECTION_ACTION_STATUSES, (string)$action['status'])) ?></p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </article>

                <article class="card search-result-category" <?= $resources ? '' : 'hidden' ?>>
                    <h2>Ressources</h2>
                    <?php if (!$resources): ?><p class="muted">Aucune ressource trouvée.</p><?php else: ?>
                        <ul><?php foreach ($resources as $resource): ?><li>
                            <h3><a href="<?= e(url('resources.php?resource=' . (int)$resource['id'] . '#resource-directory')) ?>"><?= e($resource['title']) ?></a></h3>
                            <p><?= e(excerpt((string)($resource['description'] ?? ''), 200)) ?></p>
                            <p class="meta"><?= e(protection_label(PROTECTION_RESOURCE_TYPES, (string)$resource['resource_type'])) ?><?= $resource['organization'] ? ' · ' . e($resource['organization']) : '' ?></p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </article>

                <article class="card search-result-category" <?= $groups ? '' : 'hidden' ?>>
                    <h2>Groupes</h2>
                    <?php if (!$groups): ?><p class="muted">Aucun groupe trouvé.</p><?php else: ?>
                        <ul><?php foreach ($groups as $group): ?><li>
                            <h3><a href="<?= e(url('group.php?id=' . (int)$group['id'])) ?>"><?= e($group['name']) ?></a></h3>
                            <p><?= e(excerpt((string)($group['description'] ?? ''), 160)) ?></p>
                            <p class="meta"><?= e((string)$group['member_count']) ?> membre(s) · <?= e(visibility_label($group['visibility'])) ?></p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                </article>

                <article class="card search-result-category" <?= $messages ? '' : 'hidden' ?>>
                    <h2>Messages</h2>
                    <?php if (!$messages): ?><p class="muted">Aucun message trouvé.</p><?php else: ?>
                        <ul><?php foreach ($messages as $message): ?><li>
                            <p><?= nl2br(e(excerpt($message['content'] ?? '', 200))) ?></p>
                            <p class="meta">
                                De <?= e($message['sender_display_name'] ?: $message['sender_username']) ?>
                                <?php if (!empty($message['group_id'])): ?> · groupe : <a href="<?= e(url('group.php?id=' . (int)$message['group_id'])) ?>"><?= e($message['group_name'] ?? 'Groupe') ?></a>
                                <?php elseif (!empty($message['receiver_id'])): ?><?php $peerId = (int)$message['sender_id'] === (int)$user['id'] ? (int)$message['receiver_id'] : (int)$message['sender_id']; ?> · <a href="<?= e(url('chat.php?user_id=' . $peerId)) ?>">ouvrir la conversation privée</a><?php endif; ?>
                                · <?= e($message['created_at']) ?>
                            </p>
                        </li><?php endforeach; ?></ul>
                    <?php endif; ?>
                    <?php if ($totalPages > 1): ?><?= render_pagination($page, $totalPages) ?><?php endif; ?>
                </article>
            </section>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
