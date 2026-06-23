SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(120) NOT NULL UNIQUE,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(190),
    discipline VARCHAR(190),
    bio TEXT,
    avatar VARCHAR(255),
    admin_visible_password VARCHAR(255),
    role VARCHAR(40) NOT NULL DEFAULT 'user',
    is_active INTEGER NOT NULL DEFAULT 1,
    charter_accepted_at VARCHAR(32),
    email_verified_at VARCHAR(32),
    email_activation_token_hash VARCHAR(128),
    email_activation_expires_at VARCHAR(32),
    password_setup_token_hash VARCHAR(128),
    password_setup_expires_at VARCHAR(32),
    used_storage_bytes BIGINT NOT NULL DEFAULT 0,
    quota_storage_bytes BIGINT,
    last_seen_at VARCHAR(32),
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    action VARCHAR(40) NOT NULL,
    identifier_hash VARCHAR(128) NOT NULL,
    identifier_value VARCHAR(254),
    ip_hash VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45),
    password_fingerprint VARCHAR(128),
    success INTEGER NOT NULL DEFAULT 0,
    rate_limit_active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_auth_attempts_lookup (action, identifier_hash, ip_hash, success, created_at),
    INDEX idx_auth_attempts_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    contact_id INTEGER NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'accepted',
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, contact_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(contact_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS groups (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(190) NOT NULL,
    description TEXT,
    created_by INTEGER NOT NULL,
    visibility VARCHAR(40) NOT NULL DEFAULT 'private',
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS group_members (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    group_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role VARCHAR(40) NOT NULL DEFAULT 'member',
    joined_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(group_id, user_id),
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    sender_id INTEGER NOT NULL,
    receiver_id INTEGER,
    group_id INTEGER,
    content LONGTEXT,
    attachment_id INTEGER,
    message_type VARCHAR(40) NOT NULL DEFAULT 'text',
    is_read INTEGER NOT NULL DEFAULT 0,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(group_id) REFERENCES groups(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size INTEGER NOT NULL,
    path VARCHAR(500) NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS storage_quota (
    id INTEGER PRIMARY KEY,
    used_bytes BIGINT NOT NULL DEFAULT 0,
    quota_bytes BIGINT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posts (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    author_id INTEGER NOT NULL,
    content LONGTEXT NOT NULL,
    visibility VARCHAR(40) NOT NULL DEFAULT 'members',
    group_id INTEGER,
    source_message_id INTEGER,
    is_published INTEGER NOT NULL DEFAULT 1,
    pinned_at VARCHAR(32),
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(source_message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_attachments (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    post_id INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(post_id, attachment_id),
    FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE,
    FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_polls (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    post_id INTEGER NOT NULL UNIQUE,
    question TEXT NOT NULL,
    closes_at VARCHAR(32),
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(post_id) REFERENCES posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_poll_options (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    poll_id INTEGER NOT NULL,
    option_text VARCHAR(255) NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(poll_id) REFERENCES post_polls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS post_poll_votes (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    poll_id INTEGER NOT NULL,
    option_id INTEGER NOT NULL,
    user_id INTEGER,
    visitor_key VARCHAR(64),
    visitor_fingerprint VARCHAR(64),
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    UNIQUE(poll_id, user_id),
    UNIQUE(poll_id, visitor_key),
    UNIQUE(poll_id, visitor_fingerprint),
    FOREIGN KEY(poll_id) REFERENCES post_polls(id) ON DELETE CASCADE,
    FOREIGN KEY(option_id) REFERENCES post_poll_options(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    author_id INTEGER NOT NULL,
    title VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    content LONGTEXT NOT NULL,
    excerpt TEXT,
    visibility VARCHAR(40) NOT NULL DEFAULT 'members',
    group_id INTEGER,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    source_message_id INTEGER,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    published_at VARCHAR(32),
    FOREIGN KEY(author_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(source_message_id) REFERENCES messages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS article_attachments (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    article_id INTEGER NOT NULL,
    attachment_id INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(article_id, attachment_id),
    FOREIGN KEY(article_id) REFERENCES articles(id) ON DELETE CASCADE,
    FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS comments (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id INTEGER NOT NULL,
    content LONGTEXT NOT NULL,
    is_deleted INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL UNIQUE,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS content_tags (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    tag_id INTEGER NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id INTEGER NOT NULL,
    UNIQUE(tag_id, target_type, target_id),
    FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    type VARCHAR(80) NOT NULL,
    content LONGTEXT NOT NULL,
    link VARCHAR(500),
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS web_notification_preferences (
    user_id INTEGER PRIMARY KEY,
    messenger_enabled INTEGER NOT NULL DEFAULT 0,
    updated_at VARCHAR(32),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    email VARCHAR(254) NOT NULL,
    subject VARCHAR(220) NOT NULL,
    message LONGTEXT NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(500),
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replied_at VARCHAR(32),
    replied_by INTEGER,
    reply_subject VARCHAR(220),
    reply_message LONGTEXT,
    FOREIGN KEY(replied_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitor_activity (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER,
    visitor_hash VARCHAR(64),
    method VARCHAR(12) NOT NULL,
    path VARCHAR(255) NOT NULL,
    query_string VARCHAR(500),
    full_url VARCHAR(1000),
    referer VARCHAR(1000),
    user_agent VARCHAR(500),
    ip_address VARCHAR(45),
    http_status INTEGER,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(190) NOT NULL UNIQUE,
    setting_value LONGTEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    filename VARCHAR(255) NOT NULL UNIQUE,
    executed_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS federated_feed_items (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    source_index INTEGER NOT NULL,
    source_name VARCHAR(255) NOT NULL,
    source_url VARCHAR(500),
    item_type VARCHAR(40),
    type_label VARCHAR(80),
    title VARCHAR(255) NOT NULL,
    item_date VARCHAR(32) NOT NULL,
    badge VARCHAR(120),
    summary TEXT,
    location VARCHAR(255),
    meta VARCHAR(255),
    item_url VARCHAR(500),
    fetched_at VARCHAR(32) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mastodon_publications (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    target_type VARCHAR(40) NOT NULL,
    target_id INTEGER NOT NULL,
    mastodon_url VARCHAR(500),
    mastodon_id VARCHAR(255),
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, target_type, target_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activitypub_actors (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    actor_url VARCHAR(500) NOT NULL UNIQUE,
    inbox_url VARCHAR(500) NOT NULL,
    outbox_url VARCHAR(500) NOT NULL,
    followers_url VARCHAR(500) NOT NULL,
    public_key TEXT NOT NULL,
    private_key TEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activitypub_remote_actors (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    actor_url VARCHAR(500) NOT NULL UNIQUE,
    inbox_url VARCHAR(500) NOT NULL,
    shared_inbox_url VARCHAR(500),
    followers_url VARCHAR(500),
    preferred_username VARCHAR(120),
    public_key_id VARCHAR(500),
    public_key_pem TEXT NOT NULL,
    raw_json LONGTEXT,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activitypub_followers (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER NOT NULL,
    follow_id VARCHAR(500) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'accepted',
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    UNIQUE(user_id, remote_actor_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activitypub_inbox (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER,
    activity_id VARCHAR(500) NOT NULL UNIQUE,
    activity_type VARCHAR(64) NOT NULL,
    raw_json LONGTEXT NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activitypub_deliveries (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER NOT NULL,
    activity_id VARCHAR(500) NOT NULL,
    inbox_url VARCHAR(500) NOT NULL,
    activity_json LONGTEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_status_code INTEGER,
    last_error TEXT,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activitypub_blocks (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    user_id INTEGER NOT NULL,
    remote_actor_id INTEGER NOT NULL,
    block_id VARCHAR(500) NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(user_id, remote_actor_id),
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY(remote_actor_id) REFERENCES activitypub_remote_actors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protection_events (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    event_type VARCHAR(40) NOT NULL DEFAULT 'incident',
    starts_at VARCHAR(32) NOT NULL,
    ends_at VARCHAR(32),
    location VARCHAR(255),
    description LONGTEXT,
    response_owner VARCHAR(255),
    response_summary LONGTEXT,
    visibility VARCHAR(40) NOT NULL DEFAULT 'members',
    group_id INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protection_incidents (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    occurred_at VARCHAR(32) NOT NULL,
    incident_type VARCHAR(40) NOT NULL DEFAULT 'autre',
    source_type VARCHAR(40) NOT NULL DEFAULT 'autre',
    severity VARCHAR(40) NOT NULL DEFAULT 'medium',
    status VARCHAR(40) NOT NULL DEFAULT 'open',
    location VARCHAR(255),
    description LONGTEXT NOT NULL,
    immediate_actions LONGTEXT,
    institutional_response LONGTEXT,
    institutional_response_at VARCHAR(32),
    follow_up LONGTEXT,
    visibility VARCHAR(40) NOT NULL DEFAULT 'members',
    group_id INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protection_resources (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    resource_type VARCHAR(40) NOT NULL DEFAULT 'law',
    organization VARCHAR(255),
    url VARCHAR(1000),
    attachment_id INTEGER,
    contact_info LONGTEXT,
    description LONGTEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(attachment_id) REFERENCES attachments(id) ON DELETE SET NULL,
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protection_union_boards (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    union_name VARCHAR(190) NOT NULL,
    local_section VARCHAR(255),
    contact_name VARCHAR(255),
    email VARCHAR(254),
    phone VARCHAR(80),
    office_hours LONGTEXT,
    location VARCHAR(255),
    announcement LONGTEXT,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protection_union_board_members (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    board_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(board_id, user_id),
    FOREIGN KEY(board_id) REFERENCES protection_union_boards(id) ON DELETE CASCADE,
    FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS protection_action_plans (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'administrative',
    status VARCHAR(40) NOT NULL DEFAULT 'idea',
    objective LONGTEXT NOT NULL,
    steps LONGTEXT,
    legal_frame LONGTEXT,
    risks LONGTEXT,
    owner VARCHAR(255),
    planned_at VARCHAR(32),
    visibility VARCHAR(40) NOT NULL DEFAULT 'members',
    group_id INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_by INTEGER NOT NULL,
    created_at VARCHAR(32) NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at VARCHAR(32),
    FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
CREATE INDEX IF NOT EXISTS idx_contacts_user_id ON contacts(user_id);
CREATE INDEX IF NOT EXISTS idx_contacts_contact_id ON contacts(contact_id);
CREATE INDEX IF NOT EXISTS idx_group_members_group_id ON group_members(group_id);
CREATE INDEX IF NOT EXISTS idx_group_members_user_id ON group_members(user_id);
CREATE INDEX IF NOT EXISTS idx_messages_sender_id ON messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_messages_receiver_id ON messages(receiver_id);
CREATE INDEX IF NOT EXISTS idx_messages_group_id ON messages(group_id);
CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at);
CREATE INDEX IF NOT EXISTS idx_messages_group_active_created ON messages(group_id, is_deleted, created_at);
CREATE INDEX IF NOT EXISTS idx_messages_private_sender_receiver_active_created ON messages(sender_id, receiver_id, group_id, is_deleted, created_at);
CREATE INDEX IF NOT EXISTS idx_messages_private_receiver_sender_unread ON messages(receiver_id, sender_id, group_id, is_read, is_deleted);
CREATE INDEX IF NOT EXISTS idx_posts_author_id ON posts(author_id);
CREATE INDEX IF NOT EXISTS idx_posts_visibility ON posts(visibility);
CREATE INDEX IF NOT EXISTS idx_posts_created_at ON posts(created_at);
CREATE INDEX IF NOT EXISTS idx_posts_published_visibility_created ON posts(is_published, visibility, created_at);
CREATE INDEX IF NOT EXISTS idx_posts_group_published_created ON posts(group_id, is_published, created_at);
CREATE INDEX IF NOT EXISTS idx_post_attachments_post ON post_attachments(post_id);
CREATE INDEX IF NOT EXISTS idx_post_attachments_attachment ON post_attachments(attachment_id);
CREATE INDEX IF NOT EXISTS idx_post_poll_options_poll_id ON post_poll_options(poll_id);
CREATE INDEX IF NOT EXISTS idx_post_poll_votes_poll_id ON post_poll_votes(poll_id);
CREATE INDEX IF NOT EXISTS idx_post_poll_votes_option_id ON post_poll_votes(option_id);
CREATE UNIQUE INDEX IF NOT EXISTS idx_post_poll_votes_fingerprint ON post_poll_votes(poll_id, visitor_fingerprint);
CREATE INDEX IF NOT EXISTS idx_articles_author_id ON articles(author_id);
CREATE INDEX IF NOT EXISTS idx_articles_slug ON articles(slug);
CREATE INDEX IF NOT EXISTS idx_articles_status ON articles(status);
CREATE INDEX IF NOT EXISTS idx_articles_status_visibility_published ON articles(status, visibility, published_at, created_at);
CREATE INDEX IF NOT EXISTS idx_comments_target ON comments(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_contact_messages_created_at ON contact_messages(created_at);
CREATE INDEX IF NOT EXISTS idx_contact_messages_replied_at ON contact_messages(replied_at);
CREATE INDEX IF NOT EXISTS idx_article_attachments_article ON article_attachments(article_id);
CREATE INDEX IF NOT EXISTS idx_article_attachments_attachment ON article_attachments(attachment_id);
CREATE INDEX IF NOT EXISTS idx_notifications_user_unread ON notifications(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_visitor_activity_created_at ON visitor_activity(created_at);
CREATE INDEX IF NOT EXISTS idx_visitor_activity_user_id ON visitor_activity(user_id);
CREATE INDEX IF NOT EXISTS idx_federated_feed_items_date ON federated_feed_items(item_date);
CREATE INDEX IF NOT EXISTS idx_federated_feed_items_source ON federated_feed_items(source_index);
CREATE INDEX IF NOT EXISTS idx_content_tags_target ON content_tags(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_content_tags_tag_id ON content_tags(tag_id);
CREATE INDEX IF NOT EXISTS idx_activitypub_actors_user_id ON activitypub_actors(user_id);
CREATE INDEX IF NOT EXISTS idx_activitypub_followers_user_id ON activitypub_followers(user_id);
CREATE INDEX IF NOT EXISTS idx_activitypub_deliveries_status ON activitypub_deliveries(status);
CREATE INDEX IF NOT EXISTS idx_protection_events_starts_at ON protection_events(starts_at);
CREATE INDEX IF NOT EXISTS idx_protection_incidents_occurred_at ON protection_incidents(occurred_at);
CREATE INDEX IF NOT EXISTS idx_protection_incidents_status ON protection_incidents(status);
CREATE INDEX IF NOT EXISTS idx_protection_resources_type ON protection_resources(resource_type);
CREATE INDEX IF NOT EXISTS idx_protection_action_plans_status ON protection_action_plans(status);

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
('site_name', 'Salle des profs'),
('site_meta_title', 'Salle des profs'),
('site_meta_description', 'Plateforme de protection, soutien et coordination des enseignants.'),
('site_meta_keywords', ''),
('site_meta_author', 'Salle des profs'),
('site_meta_robots', 'index,follow'),
('site_canonical_url', ''),
('site_og_image_url', ''),
('google_site_verification', ''),
('bing_site_verification', ''),
('registration_enabled', '0'),
('default_visibility', 'members'),
('default_user_storage_quota_bytes', ''),
('federation_enabled', '0'),
('federation_name', ''),
('federation_follow_url', ''),
('federation_token', ''),
('federation_name_2', ''),
('federation_follow_url_2', ''),
('federation_token_2', ''),
('federation_name_3', ''),
('federation_follow_url_3', ''),
('federation_token_3', ''),
('federation_name_4', ''),
('federation_follow_url_4', ''),
('federation_token_4', ''),
('federation_publication_token', ''),
('federation_cache_last_refresh_at', ''),
('federation_cache_last_error', ''),
('ical_enabled', '0'),
('ical_token', ''),
('storage_quota_counters_initialized', '1'),
('storage_quota_innodb_checked', '1');

SET FOREIGN_KEY_CHECKS = 1;
