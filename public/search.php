<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

require_once __DIR__ . '/../includes/pagination.php';

require_login();

$user = current_user();
$query = get_value('q');

    $posts = [];
    $articles = [];
    $groups = [];
    $messages = [];
    
    $page = current_page();
    $perPage = 20;
    $offset = pagination_offset($page, $perPage);
    $totalMessages = 0;
    $totalPages = 1;

if ($query !== '') {
    $like = '%' . $query . '%';

    $posts = db_fetch_all(
        "SELECT posts.*, users.username, users.display_name
         FROM posts
         JOIN users ON users.id = posts.author_id
         WHERE posts.is_published = 1
           AND posts.content LIKE :q
         ORDER BY posts.created_at DESC
         LIMIT 20",
        [
            'q' => $like,
        ]
    );

    $articles = db_fetch_all(
        "SELECT articles.*, users.username, users.display_name
         FROM articles
         JOIN users ON users.id = articles.author_id
         WHERE articles.status = 'published'
           AND (
                articles.title LIKE :q
                OR articles.content LIKE :q
                OR articles.excerpt LIKE :q
           )
         ORDER BY articles.published_at DESC, articles.created_at DESC
         LIMIT 20",
        [
            'q' => $like,
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

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
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
                Rechercher dans les annonces, articles, groupes et messages.
            </p>

            <form method="get" action="">
                <label for="q">Recherche</label>
                <input
                    type="search"
                    id="q"
                    name="q"
                    value="<?= e($query) ?>"
                    placeholder="Mot-clé, titre, message..."
                    required
                >

                <div class="form-actions">
                    <button type="submit" class="button-primary">Rechercher</button>
                </div>
            </form>
        </section>

        <?php if ($query === ''): ?>
            <section class="card">
                <p class="muted">Saisissez une recherche pour commencer.</p>
            </section>
        <?php else: ?>
            <section class="grid grid-2 search-results-grid">
                <div class="search-results-column">
                    <article class="card">
                        <h2>Annonces</h2>

                        <?php if (!$posts): ?>
                            <p class="muted">Aucune annonce trouvée.</p>
                        <?php else: ?>
                            <?php foreach ($posts as $post): ?>
                                <div class="post">
                                    <p><?= nl2br(e(excerpt($post['content'], 220))) ?></p>
                                    <p class="meta">
                                        <?= e($post['display_name'] ?: $post['username']) ?>
                                        · <?= e($post['created_at']) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>

                    <article class="card">
                        <h2>Articles</h2>

                        <?php if (!$articles): ?>
                            <p class="muted">Aucun article trouvé.</p>
                        <?php else: ?>
                            <?php foreach ($articles as $article): ?>
                                <div class="article-preview">
                                    <h3>
                                        <a href="<?= e(url('article.php?slug=' . urlencode($article['slug']))) ?>">
                                            <?= e($article['title']) ?>
                                        </a>
                                    </h3>
                                    <p><?= e(excerpt($article['excerpt'] ?: $article['content'], 180)) ?></p>
                                    <p class="meta">
                                        <?= e($article['display_name'] ?: $article['username']) ?>
                                        · <?= e($article['published_at'] ?: $article['created_at']) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>
                </div>

                <div class="search-results-column">
                    <article class="card">
                        <h2>Groupes</h2>

                        <?php if (!$groups): ?>
                            <p class="muted">Aucun groupe trouvé.</p>
                        <?php else: ?>
                            <?php foreach ($groups as $group): ?>
                                <div class="group-card">
                                    <h3>
                                        <a href="<?= e(url('group.php?id=' . (int)$group['id'])) ?>">
                                            <?= e($group['name']) ?>
                                        </a>
                                    </h3>
                                    <p><?= e(excerpt($group['description'] ?? '', 160)) ?></p>
                                    <p class="meta">
                                        <?= e((string)$group['member_count']) ?> membre(s)
                                        · <?= e(visibility_label($group['visibility'])) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>

                    <article class="card">
                        <h2>Messages</h2>

                        <?php if (!$messages): ?>
                            <p class="muted">Aucun message trouvé.</p>
                        <?php else: ?>
                            <?php foreach ($messages as $message): ?>
                                <div class="message-search-result">
                                    <p><?= nl2br(e(excerpt($message['content'] ?? '', 200))) ?></p>

                                    <p class="meta">
                                        De <?= e($message['sender_display_name'] ?: $message['sender_username']) ?>
                                        <?php if (!empty($message['group_id'])): ?>
                                            · groupe :
                                            <a href="<?= e(url('group.php?id=' . (int)$message['group_id'])) ?>">
                                                <?= e($message['group_name'] ?? 'Groupe') ?>
                                            </a>
                                        <?php elseif (!empty($message['receiver_id'])): ?>
                                            · conversation privée
                                            <?php
                                                $peerId = (int)$message['sender_id'] === (int)$user['id']
                                                    ? (int)$message['receiver_id']
                                                    : (int)$message['sender_id'];
                                            ?>
                                            <a href="<?= e(url('chat.php?user_id=' . $peerId)) ?>">
                                                ouvrir
                                            </a>
                                        <?php endif; ?>
                                        · <?= e($message['created_at']) ?>
                                    </p>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        
                        <?php if ($totalPages > 1): ?>
                            <section class="card">
                                <?= render_pagination($page, $totalPages) ?>
                            </section>
                        <?php endif; ?>
                        
                    </article>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
