<?php
declare(strict_types=1);

/**
 * Récupération de mot de passe en libre-service (« Mot de passe oublié »).
 *
 * - réservée aux membres actifs : les administrateurs en sont exclus ;
 * - lien à usage unique valable 10 minutes, construit uniquement depuis l'URL
 *   canonique configurée (jamais depuis l'en-tête Host de la requête) ;
 * - anti-robot : question paramétrable, champ piège et délai minimal ;
 * - même limiteur que la connexion, avec réponse HTTP 429 en cas d'abus ;
 * - les administrateurs sont informés des demandes et des changements.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/password_setup.php';

const PASSWORD_RECOVERY_LINK_VALIDITY = 600;
const PASSWORD_RECOVERY_MIN_FILL_SECONDS = 3;
const PASSWORD_RECOVERY_FORM_MAX_AGE = 3600;
const PASSWORD_RECOVERY_HONEYPOT_FIELD = 'website';
const PASSWORD_RECOVERY_SESSION_TOKEN = 'password_recovery_token';
const PASSWORD_RECOVERY_SESSION_FORM_AT = 'password_recovery_form_at';
const PASSWORD_RECOVERY_SESSION_ORIGIN = 'password_recovery_origin';

function password_recovery_question(): string
{
    return trim(setting_value('password_recovery_question', ''));
}

function password_recovery_answer(): string
{
    return trim(setting_value('password_recovery_answer', ''));
}

/**
 * Adresse publique du site pour les liens envoyés par mail. APP_BASE_URL ou
 * l'URL canonique des réglages : jamais l'hôte fourni par le navigateur, qui
 * permettrait de faire pointer le lien vers un domaine pirate.
 */
function password_recovery_base_url(): string
{
    $candidates = [
        trim((string)(getenv('APP_BASE_URL') ?: '')),
        trim(setting_value('site_canonical_url', '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate !== '' && http_url_has_allowed_scheme($candidate) && filter_var($candidate, FILTER_VALIDATE_URL) !== false) {
            return rtrim($candidate, '/');
        }
    }

    return '';
}

function password_recovery_is_available(): bool
{
    return setting_value('password_recovery_enabled', '0') === '1'
        && password_recovery_question() !== ''
        && password_recovery_answer() !== ''
        && password_recovery_base_url() !== '';
}

/**
 * Compare les réponses sans tenir compte de la casse, des accents ni des espaces.
 */
function password_recovery_normalize_answer(string $answer): string
{
    $answer = mb_strtolower(trim($answer), 'UTF-8');

    if (class_exists('Normalizer')) {
        $decomposed = Normalizer::normalize($answer, Normalizer::FORM_D);

        if (is_string($decomposed)) {
            $answer = preg_replace('/\p{Mn}+/u', '', $decomposed) ?? $answer;
        }
    }

    return preg_replace('/\s+/u', ' ', $answer) ?? $answer;
}

function password_recovery_answer_matches(string $answer): bool
{
    $expected = password_recovery_normalize_answer(password_recovery_answer());

    return $expected !== '' && hash_equals($expected, password_recovery_normalize_answer($answer));
}

/**
 * Mémorise l'heure d'affichage du formulaire : un robot le soumet aussitôt.
 */
function password_recovery_mark_form_displayed(): void
{
    start_app_session();
    $_SESSION[PASSWORD_RECOVERY_SESSION_FORM_AT] = time();
}

/**
 * Retourne un message d'erreur si la soumission ressemble à celle d'un robot.
 */
function password_recovery_bot_error(): ?string
{
    start_app_session();

    if (trim((string)($_POST[PASSWORD_RECOVERY_HONEYPOT_FIELD] ?? '')) !== '') {
        return 'Votre demande n’a pas pu être traitée.';
    }

    $displayedAt = (int)($_SESSION[PASSWORD_RECOVERY_SESSION_FORM_AT] ?? 0);
    $elapsed = time() - $displayedAt;

    if ($displayedAt <= 0 || $elapsed > PASSWORD_RECOVERY_FORM_MAX_AGE) {
        return 'Le formulaire a expiré. Merci de le remplir à nouveau.';
    }

    if ($elapsed < PASSWORD_RECOVERY_MIN_FILL_SECONDS) {
        return 'Merci de prendre quelques secondes pour remplir le formulaire.';
    }

    return null;
}

/**
 * Membre éligible : compte actif, adresse connue, et pas administrateur.
 */
function password_recovery_find_user(string $email): ?array
{
    $user = db_fetch_one(
        "SELECT id, username, email, display_name, role, is_active
         FROM users
         WHERE LOWER(email) = :email
         LIMIT 1",
        ['email' => mb_strtolower(trim($email), 'UTF-8')]
    );

    if (!$user || (int)$user['is_active'] !== 1 || ($user['role'] ?? '') === 'admin') {
        return null;
    }

    return $user;
}

function password_recovery_url(string $token): string
{
    return password_recovery_base_url() . '/forgot_password.php?token=' . rawurlencode($token);
}

/**
 * Lien vers la fiche du membre dans l'administration, lui aussi construit
 * depuis l'URL canonique (la demande vient d'un visiteur non authentifié).
 */
function password_recovery_admin_user_url(int $userId): string
{
    $root = preg_replace('#/public$#', '', password_recovery_base_url()) ?? password_recovery_base_url();

    return $root . '/admin/users.php?edit_id=' . $userId;
}

function password_recovery_user_label(array $user): string
{
    $displayName = trim((string)($user['display_name'] ?? ''));
    $username = (string)($user['username'] ?? '');

    return $displayName !== '' ? $displayName . ' (@' . $username . ')' : '@' . $username;
}

/**
 * Traite une demande valide : envoie le lien si l'adresse correspond à un
 * membre éligible. Ne révèle jamais le résultat à l'appelant.
 */
function password_recovery_request(string $email): void
{
    $user = password_recovery_find_user($email);

    if (!$user) {
        return;
    }

    $token = user_password_setup_token_create((int)$user['id'], PASSWORD_RECOVERY_LINK_VALIDITY);

    if (!send_password_recovery_email($user, $token)) {
        user_password_setup_revoke((int)$user['id']);
        error_log('Récupération de mot de passe : échec de l’envoi du mail pour l’utilisateur ' . (int)$user['id']);
        return;
    }

    password_recovery_notify_admins(
        'password_recovery_request',
        'Demande de réinitialisation du mot de passe par ' . password_recovery_user_label($user) . '.',
        $user,
        false
    );
}

function send_password_recovery_email(array $user, string $token): bool
{
    $recoveryUrl = password_recovery_url($token);
    $displayName = trim((string)($user['display_name'] ?? ''));
    $recipientName = $displayName !== '' ? $displayName : (string)$user['username'];
    $minutes = (int)(PASSWORD_RECOVERY_LINK_VALIDITY / 60);
    $subject = 'Réinitialisation de votre mot de passe — ' . site_name();
    $text = "Bonjour " . $recipientName . ",\n\n"
        . "Une demande de réinitialisation du mot de passe a été faite pour votre compte sur " . site_name() . ".\n\n"
        . "Identifiant : " . $user['username'] . "\n\n"
        . "Choisissez un nouveau mot de passe :\n"
        . $recoveryUrl . "\n\n"
        . "Ce lien est valable " . $minutes . " minutes et ne peut être utilisé qu’une seule fois.\n\n"
        . "Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.";
    $html = '<p>Bonjour ' . e($recipientName) . ',</p>'
        . '<p>Une demande de réinitialisation du mot de passe a été faite pour votre compte sur <strong>' . e(site_name()) . '</strong>.</p>'
        . '<p>Identifiant : <code>' . e((string)$user['username']) . '</code></p>'
        . '<p><a href="' . e($recoveryUrl) . '">Choisir un nouveau mot de passe</a></p>'
        . '<p>Ce lien est valable ' . $minutes . ' minutes et ne peut être utilisé qu’une seule fois.</p>'
        . '<p>Si vous n’êtes pas à l’origine de cette demande, ignorez ce message : votre mot de passe actuel reste valable.</p>';

    return mail_send_text((string)$user['email'], $recipientName, $subject, $text, $html);
}

function send_password_recovery_confirmation_email(array $user): bool
{
    $displayName = trim((string)($user['display_name'] ?? ''));
    $recipientName = $displayName !== '' ? $displayName : (string)$user['username'];
    $subject = 'Votre mot de passe a été modifié — ' . site_name();
    $text = "Bonjour " . $recipientName . ",\n\n"
        . "Le mot de passe de votre compte sur " . site_name() . " vient d’être modifié "
        . "à l’aide du lien « Mot de passe oublié ».\n\n"
        . "Si vous n’êtes pas à l’origine de ce changement, contactez immédiatement l’administrateur du site.";
    $html = '<p>Bonjour ' . e($recipientName) . ',</p>'
        . '<p>Le mot de passe de votre compte sur <strong>' . e(site_name()) . '</strong> vient d’être modifié '
        . 'à l’aide du lien « Mot de passe oublié ».</p>'
        . '<p>Si vous n’êtes pas à l’origine de ce changement, contactez immédiatement l’administrateur du site.</p>';

    return mail_send_text((string)$user['email'], $recipientName, $subject, $text, $html);
}

/**
 * Informe tous les administrateurs actifs : notification sur le site et,
 * pour les changements effectifs, un mail.
 */
function password_recovery_notify_admins(string $type, string $message, array $user, bool $sendEmail): void
{
    $admins = db_fetch_all(
        "SELECT id, username, email, display_name
         FROM users
         WHERE role = 'admin'
           AND is_active = 1"
    );
    $link = password_recovery_admin_user_url((int)$user['id']);

    foreach ($admins as $admin) {
        try {
            db_insert(
                "INSERT INTO notifications (user_id, type, content, link, is_read, created_at)
                 VALUES (:user_id, :type, :content, :link, 0, :created_at)",
                [
                    'user_id' => (int)$admin['id'],
                    'type' => $type,
                    'content' => $message,
                    'link' => $link,
                    'created_at' => now(),
                ]
            );
        } catch (Throwable $e) {
            error_log('Récupération de mot de passe : notification impossible — ' . $e->getMessage());
        }

        if ($sendEmail && is_valid_email((string)$admin['email'])) {
            $adminName = trim((string)($admin['display_name'] ?? '')) ?: (string)$admin['username'];
            $subject = 'Mot de passe réinitialisé — ' . site_name();
            $text = "Bonjour " . $adminName . ",\n\n" . $message . "\n\n"
                . "Fiche du membre : " . $link . "\n\n"
                . "Ce message est envoyé à tous les administrateurs de " . site_name() . ".";
            $html = '<p>Bonjour ' . e($adminName) . ',</p>'
                . '<p>' . e($message) . '</p>'
                . '<p><a href="' . e($link) . '">Voir la fiche du membre</a></p>'
                . '<p>Ce message est envoyé à tous les administrateurs de ' . e(site_name()) . '.</p>';

            mail_send_text((string)$admin['email'], $adminName, $subject, $text, $html);
        }
    }
}

/**
 * Membre associé au lien, s'il est encore valide et toujours éligible.
 */
function password_recovery_user_by_token(string $token): ?array
{
    $user = user_password_setup_by_token($token);

    if (!$user || ($user['role'] ?? '') === 'admin') {
        return null;
    }

    return $user;
}

/**
 * Enregistre le nouveau mot de passe, puis prévient le membre et les
 * administrateurs. Retourne le membre, ou null si le lien n'est plus valide.
 */
function password_recovery_complete(string $token, string $password): ?array
{
    if (!password_recovery_user_by_token($token)) {
        return null;
    }

    $user = complete_user_password_setup($token, $password);

    if (!$user) {
        return null;
    }

    send_password_recovery_confirmation_email($user);
    password_recovery_notify_admins(
        'password_recovery_done',
        'Le mot de passe de ' . password_recovery_user_label($user) . ' a été réinitialisé avec « Mot de passe oublié ».',
        $user,
        true
    );

    return $user;
}
