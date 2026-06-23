<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/markdown.php';
require_once __DIR__ . '/../includes/pagination.php';

require_login();
ensure_content_group_column('articles');

$currentUser = current_user();
$selectedMemberId = (int)get_value('member_id', '0');
$contentType = get_value('content');
$selectedMember = null;
$memberContent = [];
$contentPage = current_page('content_page');
$contentTotalPages = 1;

if (!in_array($contentType, ['posts', 'articles'], true)) {
    $contentType = '';
}

if ($selectedMemberId > 0 && $contentType !== '') {
    $selectedMember = db_fetch_one(
        "SELECT id, username, display_name, avatar
         FROM users
         WHERE id = :id
           AND is_active = 1
         LIMIT 1",
        ['id' => $selectedMemberId]
    );

    if ($selectedMember) {
        $contentPerPage = 10;
        $contentOffset = pagination_offset($contentPage, $contentPerPage);
        $contentTable = $contentType === 'posts' ? 'posts' : 'articles';
        $visibilitySql = "(
            $contentTable.visibility IN ('public', 'members')
            OR (
                $contentTable.visibility = 'group'
                AND EXISTS (
                    SELECT 1
                    FROM group_members gm
                    WHERE gm.group_id = $contentTable.group_id
                      AND gm.user_id = :current_user_id
                )
            )
        )";
        $contentParams = [
            'author_id' => $selectedMemberId,
            'current_user_id' => (int)$currentUser['id'],
        ];

        if ($contentType === 'posts') {
            $contentTotal = (int)(db_fetch_one(
                "SELECT COUNT(*) AS total
                 FROM posts
                 WHERE author_id = :author_id
                   AND is_published = 1
                   AND $visibilitySql",
                $contentParams
            )['total'] ?? 0);
            $contentTotalPages = total_pages($contentTotal, $contentPerPage);
            $contentPage = min($contentPage, $contentTotalPages);
            $contentOffset = pagination_offset($contentPage, $contentPerPage);
            $memberContent = db_fetch_all(
                "SELECT posts.*, groups.name AS group_name
                 FROM posts
                 LEFT JOIN groups ON groups.id = posts.group_id
                 WHERE posts.author_id = :author_id
                   AND posts.is_published = 1
                   AND (
                        posts.visibility IN ('public', 'members')
                        OR (
                            posts.visibility = 'group'
                            AND EXISTS (
                                SELECT 1
                                FROM group_members gm
                                WHERE gm.group_id = posts.group_id
                                  AND gm.user_id = :current_user_id
                            )
                        )
                   )
                 ORDER BY posts.created_at DESC
                 LIMIT :limit OFFSET :offset",
                array_merge($contentParams, [
                    'limit' => $contentPerPage,
                    'offset' => $contentOffset,
                ])
            );
        } else {
            $contentTotal = (int)(db_fetch_one(
                "SELECT COUNT(*) AS total
                 FROM articles
                 WHERE author_id = :author_id
                   AND status = 'published'
                   AND $visibilitySql",
                $contentParams
            )['total'] ?? 0);
            $contentTotalPages = total_pages($contentTotal, $contentPerPage);
            $contentPage = min($contentPage, $contentTotalPages);
            $contentOffset = pagination_offset($contentPage, $contentPerPage);
            $memberContent = db_fetch_all(
                "SELECT articles.*, groups.name AS group_name
                 FROM articles
                 LEFT JOIN groups ON groups.id = articles.group_id
                 WHERE articles.author_id = :author_id
                   AND articles.status = 'published'
                   AND (
                        articles.visibility IN ('public', 'members')
                        OR (
                            articles.visibility = 'group'
                            AND EXISTS (
                                SELECT 1
                                FROM group_members gm
                                WHERE gm.group_id = articles.group_id
                                  AND gm.user_id = :current_user_id
                            )
                        )
                   )
                 ORDER BY articles.published_at DESC, articles.created_at DESC
                 LIMIT :limit OFFSET :offset",
                array_merge($contentParams, [
                    'limit' => $contentPerPage,
                    'offset' => $contentOffset,
                ])
            );
        }
    }
}

$perPage = 12;
$totalMembers = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM users
     WHERE is_active = 1"
)['total'] ?? 0);
$totalPages = total_pages($totalMembers, $perPage);
$page = min(current_page(), $totalPages);
$offset = pagination_offset($page, $perPage);

$members = db_fetch_all(
    "SELECT id, username, display_name, bio, avatar, role
     FROM users
     WHERE is_active = 1
     ORDER BY COALESCE(NULLIF(display_name, ''), username), username
     LIMIT :limit OFFSET :offset",
    [
        'limit' => $perPage,
        'offset' => $offset,
    ]
);
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Membres — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body>
    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="container page">
        <section class="card">
            <h1>Membres</h1>
            <p class="muted">
                <?= e((string)$totalMembers) ?> membre<?= $totalMembers > 1 ? 's' : '' ?> actif<?= $totalMembers > 1 ? 's' : '' ?> sur le site.
            </p>
        </section>

        <?php if ($selectedMember): ?>
            <?php $selectedMemberName = trim((string)($selectedMember['display_name'] ?? '')) ?: (string)$selectedMember['username']; ?>
            <section class="card member-content-section" id="contenus-membre">
                <div class="member-content-heading">
                    <div>
                        <h2>
                            <?= $contentType === 'posts' ? 'Annonces' : 'Articles' ?>
                            de <?= e($selectedMemberName) ?>
                        </h2>
                        <p class="muted">Seuls les contenus auxquels vous avez accès sont affichés.</p>
                    </div>
                    <a class="button-secondary" href="<?= e(url('members.php?page=' . $page)) ?>">Fermer</a>
                </div>

                <?php if (!$memberContent): ?>
                    <p class="muted">Aucun contenu visible dans cette catégorie.</p>
                <?php elseif ($contentType === 'posts'): ?>
                    <div class="member-content-list">
                        <?php foreach ($memberContent as $post): ?>
                            <article class="member-content-item">
                                <div class="markdown-content"><?= render_markdown((string)$post['content']) ?></div>
                                <p class="meta">
                                    <?= e((string)$post['created_at']) ?>
                                    · <?= e(visibility_label((string)$post['visibility'], $post['group_name'] ?? null)) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="member-content-list">
                        <?php foreach ($memberContent as $article): ?>
                            <article class="member-content-item">
                                <h3>
                                    <a href="<?= e(url('article.php?slug=' . urlencode((string)$article['slug']))) ?>">
                                        <?= e((string)$article['title']) ?>
                                    </a>
                                </h3>
                                <p><?= e(article_preview_text((string)($article['excerpt'] ?: $article['content']), 260)) ?></p>
                                <p class="meta">
                                    <?= e((string)($article['published_at'] ?: $article['created_at'])) ?>
                                    · <?= e(visibility_label((string)$article['visibility'], $article['group_name'] ?? null)) ?>
                                </p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($contentTotalPages > 1): ?>
                    <?= render_pagination($contentPage, $contentTotalPages, 'content_page') ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="members-directory" aria-label="Liste des membres">
            <?php foreach ($members as $member): ?>
                <?php $memberName = trim((string)($member['display_name'] ?? '')) ?: (string)$member['username']; ?>
                <?php $memberRoleClass = in_array($member['role'] ?? '', ['admin', 'syndicate'], true) ? ' member-directory-card-' . $member['role'] : ''; ?>
                <article class="card member-directory-card<?= e($memberRoleClass) ?>">
                    <span class="post-avatar member-directory-avatar" aria-hidden="true">
                        <?php if (!empty($member['avatar'])): ?>
                            <img src="<?= e(avatar_url((int)$member['id'], (string)$member['avatar'])) ?>" alt="">
                        <?php else: ?>
                            <?= e(mb_strtoupper(mb_substr($memberName, 0, 1, 'UTF-8'), 'UTF-8')) ?>
                        <?php endif; ?>
                    </span>
                    <div class="member-directory-content">
                        <h2><?= e($memberName) ?></h2>
                        <?php if (trim((string)($member['bio'] ?? '')) !== ''): ?>
                            <p><?= nl2br(e((string)$member['bio'])) ?></p>
                        <?php else: ?>
                            <p class="muted">Aucune bio renseignée.</p>
                        <?php endif; ?>
                        <div class="member-directory-actions">
                            <a class="button-secondary" href="<?= e(url('members.php?' . http_build_query([
                                'page' => $page,
                                'member_id' => (int)$member['id'],
                                'content' => 'posts',
                            ]) . '#contenus-membre')) ?>">Annonces</a>
                            <a class="button-secondary" href="<?= e(url('members.php?' . http_build_query([
                                'page' => $page,
                                'member_id' => (int)$member['id'],
                                'content' => 'articles',
                            ]) . '#contenus-membre')) ?>">Articles</a>
                            <a class="button-secondary" href="<?= e(url('chat.php?' . http_build_query([
                                'user_id' => (int)$member['id'],
                                'members_page' => $page,
                            ]))) ?>">Messages</a>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <?php if ($totalPages > 1): ?>
            <section class="members-pagination">
                <?= render_pagination($page, $totalPages) ?>
            </section>
        <?php endif; ?>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
