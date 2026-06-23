<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';

require_once __DIR__ . '/../includes/pagination.php';

require_login();

$user = current_user();
$errors = [];

if (is_post()) {
    require_csrf();

    $action = post_value('action');

    try {
        if ($action === 'mark_all_read') {
            db_query(
                "UPDATE notifications
                 SET is_read = 1
                 WHERE user_id = :user_id",
                ['user_id' => $user['id']]
            );
            unset($_SESSION['unread_notifications_cache']);

            set_flash('success', 'Toutes les notifications ont été marquées comme lues.');
            redirect(url('notifications.php'));
        }

        if ($action === 'delete_all') {
            db_query(
                "DELETE FROM notifications
                 WHERE user_id = :user_id",
                ['user_id' => $user['id']]
            );
            unset($_SESSION['unread_notifications_cache']);

            set_flash('success', 'Toutes les notifications ont été supprimées.');
            redirect(url('notifications.php'));
        }

        if ($action === 'mark_read') {
            $notificationId = (int)post_value('notification_id', '0');

            if ($notificationId <= 0) {
                $errors[] = 'Notification invalide.';
            } else {
                db_query(
                    "UPDATE notifications
                     SET is_read = 1
                     WHERE id = :id
                       AND user_id = :user_id",
                    [
                        'id' => $notificationId,
                        'user_id' => $user['id'],
                    ]
                );
                unset($_SESSION['unread_notifications_cache']);

                set_flash('success', 'Notification marquée comme lue.');
                redirect(url('notifications.php'));
            }
        }

        if (!in_array($action, ['mark_all_read', 'delete_all', 'mark_read'], true)) {
            $errors[] = 'Action inconnue.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Erreur : ' . $e->getMessage();
    }
}

    $page = current_page();
    $perPage = 25;
    $offset = pagination_offset($page, $perPage);
    
    $totalNotifications = (int)(db_fetch_one(
        "SELECT COUNT(*) AS total
        FROM notifications
        WHERE user_id = :user_id",
        ['user_id' => $user['id']]
    )['total'] ?? 0);
    
    $totalPages = total_pages($totalNotifications, $perPage);
    
    $notifications = db_fetch_all(
        "SELECT *
        FROM notifications
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT :limit OFFSET :offset",
        [
            'user_id' => $user['id'],
            'limit' => $perPage,
            'offset' => $offset,
        ]
    );

$unreadCount = (int)(db_fetch_one(
    "SELECT COUNT(*) AS total
     FROM notifications
     WHERE user_id = :user_id
       AND is_read = 0",
    ['user_id' => $user['id']]
)['total'] ?? 0);

$flashes = get_flashes();

$notificationTypeLabels = [
    'private_message' => 'Message privé',
    'group_message' => 'Message de groupe',
];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Notifications — <?= e(APP_NAME) ?></title>
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

        <?php if ($errors): ?>
            <div class="flash flash-error">
                <strong>Erreur :</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= e($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <section class="card">
            <h1>Notifications</h1>
            <p class="muted">
                Vous avez <?= e((string)$unreadCount) ?> notification(s) non lue(s).
            </p>

            <div class="form-actions">
                <form method="post" action="">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="mark_all_read">
                    <button type="submit" class="button-primary">
                        Tout marquer comme lu
                    </button>
                </form>

                <form method="post" action="" onsubmit="return confirm('Supprimer toutes les notifications ?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_all">
                    <button type="submit" class="button-danger">
                        Tout supprimer
                    </button>
                </form>
            </div>
        </section>

        <section class="notifications-list">
            <?php if (!$notifications): ?>
                <article class="card">
                    <p class="muted">Aucune notification.</p>
                </article>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <article class="card notification-card <?= (int)$notification['is_read'] === 0 ? 'unread' : '' ?>">
                        <div>
                            <h2><?= e($notificationTypeLabels[$notification['type']] ?? ucfirst(str_replace('_', ' ', $notification['type']))) ?></h2>
                            <p><?= e($notification['content']) ?></p>
                            <p class="meta"><?= e($notification['created_at']) ?></p>
                        </div>

                        <div class="form-actions">
                            <?php if (!empty($notification['link'])): ?>
                                <a class="button-secondary" href="<?= e($notification['link']) ?>">
                                    Ouvrir
                                </a>
                            <?php endif; ?>

                            <?php if ((int)$notification['is_read'] === 0): ?>
                                <form method="post" action="">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= e((string)$notification['id']) ?>">
                                    <button type="submit" class="button-secondary">
                                        Marquer comme lu
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
        
        <?php if ($totalPages > 1): ?>
            <section class="card">
                <?= render_pagination($page, $totalPages) ?>
            </section>
        <?php endif; ?>
        
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>
</body>
</html>
