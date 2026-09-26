<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/password_recovery.php';

if (!database_is_installed()) {
    redirect(root_url('install.php'));
}

if (is_logged_in()) {
    redirect(url('dashboard.php'));
}

start_app_session();

if (!headers_sent()) {
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
}

if (get_value('depuis') === 'messagerie') {
    $_SESSION[PASSWORD_RECOVERY_SESSION_ORIGIN] = 'messenger';
} elseif (get_value('depuis') === 'site') {
    $_SESSION[PASSWORD_RECOVERY_SESSION_ORIGIN] = 'site';
}

$fromMessenger = ($_SESSION[PASSWORD_RECOVERY_SESSION_ORIGIN] ?? 'site') === 'messenger';
$loginUrl = $fromMessenger ? url('messenger_login.php') : url('login.php');
$available = password_recovery_is_available();
$step = get_value('etape');
$errors = [];

if (!$available) {
    http_response_code(404);
    $step = 'indisponible';
}

/*
 * Lien reçu par mail : le jeton est placé en session puis retiré de l'adresse,
 * pour ne pas rester dans l'historique ni être transmis à une autre page.
 */
$linkToken = $available ? get_value('token') : '';

if ($linkToken !== '') {
    $retryAfter = auth_rate_limit_retry_after('password_reset', 'lien');

    if ($retryAfter > 0) {
        auth_send_rate_limited_status($retryAfter);
        $errors[] = auth_rate_limit_message($retryAfter);
        $step = 'lien-invalide';
    } elseif (password_recovery_user_by_token($linkToken)) {
        $_SESSION[PASSWORD_RECOVERY_SESSION_TOKEN] = $linkToken;
        redirect(url('forgot_password.php?etape=nouveau-mot-de-passe'));
    } else {
        auth_rate_limit_record('password_reset', 'lien', false);
        $step = 'lien-invalide';
    }
}

$sessionToken = (string)($_SESSION[PASSWORD_RECOVERY_SESSION_TOKEN] ?? '');
$recoveryUser = null;

if ($available && $step === 'nouveau-mot-de-passe') {
    $recoveryUser = $sessionToken !== '' ? password_recovery_user_by_token($sessionToken) : null;

    if (!$recoveryUser) {
        unset($_SESSION[PASSWORD_RECOVERY_SESSION_TOKEN]);
        $step = 'lien-invalide';
    }
}

if ($available && is_post()) {
    if (!csrf_verify()) {
        csrf_regenerate();
        $errors[] = 'Session expirée. Réessayez.';
    }

    $action = post_value('action');

    if ($action === 'request' && !$errors) {
        $email = mb_strtolower(post_value('email'), 'UTF-8');
        $retryAfter = auth_rate_limit_retry_after('password_recovery', $email);

        if ($retryAfter > 0) {
            auth_send_rate_limited_status($retryAfter);
            $errors[] = auth_rate_limit_message($retryAfter);
        } else {
            $botError = password_recovery_bot_error();

            if ($botError !== null) {
                $errors[] = $botError;
            } elseif (!is_valid_email($email)) {
                $errors[] = 'Veuillez saisir une adresse email valide.';
            } elseif (!password_recovery_answer_matches(post_value('recovery_answer'))) {
                $errors[] = 'La réponse à la question de contrôle est incorrecte.';
            }

            // Chaque demande compte, aboutie ou non : le limiteur freine aussi les
            // envois répétés vers une même adresse.
            auth_rate_limit_record('password_recovery', $email !== '' ? $email : 'vide', false);

            if (!$errors) {
                unset($_SESSION[PASSWORD_RECOVERY_SESSION_FORM_AT]);
                password_recovery_request($email);
                redirect(url('forgot_password.php?etape=envoye'));
            }
        }
    } elseif ($action === 'reset' && !$errors && $recoveryUser) {
        $retryAfter = auth_rate_limit_retry_after('password_reset', (string)$recoveryUser['id']);

        if ($retryAfter > 0) {
            auth_send_rate_limited_status($retryAfter);
            $errors[] = auth_rate_limit_message($retryAfter);
        } else {
            $password = (string)($_POST['new_password'] ?? '');
            $confirmation = (string)($_POST['new_password_confirmation'] ?? '');

            if ($password !== $confirmation) {
                $errors[] = 'Les deux mots de passe ne sont pas identiques.';
            } else {
                $errors = password_policy_errors($password);
            }

            if (!$errors) {
                $user = password_recovery_complete($sessionToken, $password);

                if ($user) {
                    unset($_SESSION[PASSWORD_RECOVERY_SESSION_TOKEN], $_SESSION[PASSWORD_RECOVERY_SESSION_ORIGIN]);
                    auth_rate_limit_record('password_reset', (string)$recoveryUser['id'], true);
                    login_user($user);
                    set_flash('success', 'Votre nouveau mot de passe est enregistré. Vous êtes maintenant connecté.');

                    if (user_must_accept_charter($user)) {
                        redirect(url('charter.php'));
                    }

                    redirect($fromMessenger ? url('messenger.php') : url('dashboard.php'));
                }

                $errors[] = 'Ce lien a expiré ou a déjà été utilisé. Faites une nouvelle demande.';
                $step = 'lien-invalide';
            } else {
                auth_rate_limit_record('password_reset', (string)$recoveryUser['id'], false);
            }
        }
    }
}

if ($available && !in_array($step, ['envoye', 'nouveau-mot-de-passe', 'lien-invalide'], true)) {
    $step = 'demande';
    password_recovery_mark_form_displayed();
}

$flashes = get_flashes();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Mot de passe oublié — <?= e(APP_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="<?= e(url('assets/app.css') . '?v=' . filemtime(__DIR__ . '/assets/app.css')) ?>">
</head>
<body class="auth-page">
    <?php $headerBrandOnly = true; ?>
    <?php require __DIR__ . '/../templates/header.php'; ?>

    <main class="auth-container education-login">
        <section class="card login-card">
            <div class="login-mark" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <p class="public-eyebrow"><?= $fromMessenger ? 'Messagerie' : 'Salle des profs' ?></p>

            <?php if ($step === 'nouveau-mot-de-passe'): ?>
                <h1>Nouveau mot de passe</h1>
                <p class="muted">Identifiant : <?= e((string)$recoveryUser['username']) ?></p>
            <?php else: ?>
                <h1>Mot de passe oublié</h1>
            <?php endif; ?>

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

            <?php if ($step === 'indisponible'): ?>
                <p class="muted">
                    La récupération du mot de passe n’est pas disponible. Contactez l’administrateur du site.
                </p>
                <p class="auth-return-link">
                    <a class="button-secondary" href="<?= e($loginUrl) ?>">Revenir à la connexion</a>
                </p>
            <?php elseif ($step === 'envoye'): ?>
                <div class="flash flash-success">
                    Si cette adresse correspond à un compte membre, un lien vient d’y être envoyé.
                    Il est valable <?= (int)(PASSWORD_RECOVERY_LINK_VALIDITY / 60) ?> minutes.
                </div>
                <p class="muted">
                    Pensez à vérifier le dossier des indésirables. Les comptes administrateurs ne peuvent pas être récupérés ainsi.
                </p>
                <p class="auth-return-link">
                    <a class="button-secondary" href="<?= e($loginUrl) ?>">Revenir à la connexion</a>
                </p>
            <?php elseif ($step === 'lien-invalide'): ?>
                <p class="muted">
                    Ce lien est invalide, a expiré ou a déjà été utilisé. Les liens de récupération sont valables
                    <?= (int)(PASSWORD_RECOVERY_LINK_VALIDITY / 60) ?> minutes.
                </p>
                <div class="form-actions">
                    <a class="button-primary" href="<?= e(url('forgot_password.php')) ?>">Faire une nouvelle demande</a>
                    <a class="button-secondary" href="<?= e($loginUrl) ?>">Revenir à la connexion</a>
                </div>
            <?php elseif ($step === 'nouveau-mot-de-passe'): ?>
                <form method="post" action="<?= e(url('forgot_password.php?etape=nouveau-mot-de-passe')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset">
                    <input type="text" name="username" value="<?= e((string)$recoveryUser['username']) ?>" autocomplete="username" hidden>

                    <label for="new_password">Nouveau mot de passe</label>
                    <input
                        type="password"
                        id="new_password"
                        name="new_password"
                        minlength="16"
                        required
                        autofocus
                        autocomplete="new-password"
                    >

                    <label for="new_password_confirmation">Confirmer le nouveau mot de passe</label>
                    <input
                        type="password"
                        id="new_password_confirmation"
                        name="new_password_confirmation"
                        minlength="16"
                        required
                        autocomplete="new-password"
                    >
                    <p class="meta">
                        16 caractères minimum, avec lettres, chiffres et caractères spéciaux.
                    </p>

                    <div class="form-actions">
                        <button type="submit" class="button-primary">Enregistrer et me connecter</button>
                        <a class="button-secondary" href="<?= e($loginUrl) ?>">Annuler</a>
                    </div>
                </form>
            <?php else: ?>
                <p class="muted">
                    Indiquez l’adresse email de votre compte. Si elle est reconnue, vous recevrez un lien
                    valable <?= (int)(PASSWORD_RECOVERY_LINK_VALIDITY / 60) ?> minutes pour choisir un nouveau mot de passe.
                </p>

                <form method="post" action="<?= e(url('forgot_password.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="request">

                    <label for="email">Adresse email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= e(post_value('email')) ?>"
                        required
                        autofocus
                        autocomplete="email"
                    >

                    <label for="recovery_answer"><?= e(password_recovery_question()) ?></label>
                    <input
                        type="text"
                        id="recovery_answer"
                        name="recovery_answer"
                        required
                        autocomplete="off"
                    >
                    <p class="meta">Question de contrôle, pour vérifier que vous n’êtes pas un robot.</p>

                    <div class="auth-honeypot" aria-hidden="true">
                        <label for="<?= e(PASSWORD_RECOVERY_HONEYPOT_FIELD) ?>">Ne pas remplir ce champ</label>
                        <input
                            type="text"
                            id="<?= e(PASSWORD_RECOVERY_HONEYPOT_FIELD) ?>"
                            name="<?= e(PASSWORD_RECOVERY_HONEYPOT_FIELD) ?>"
                            tabindex="-1"
                            autocomplete="off"
                        >
                    </div>

                    <div class="form-actions">
                        <button type="submit" class="button-primary">Recevoir un lien</button>
                        <a class="button-secondary" href="<?= e($loginUrl) ?>">Revenir à la connexion</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </main>

    <?php require __DIR__ . '/../templates/footer.php'; ?>

    <script src="<?= e(url('assets/app.js') . '?v=' . filemtime(__DIR__ . '/assets/app.js')) ?>"></script>
</body>
</html>
