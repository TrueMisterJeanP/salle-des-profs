document.addEventListener('DOMContentLoaded', () => {
    [
        initDismissibleFlashes,
        initMessengerDrawer,
        initPrivateChat,
        initGroupChat,
        initMessengerWebNotifications,
        initUploadForm,
        initMarkdownToolbar,
        initPostPolls,
        initDashboardPagedPanels,
        initMatchedHeightTargets,
        initHeightMatchedPostPagers
    ].forEach((init) => {
        try {
            init();
        } catch (error) {
            console.error('Erreur pendant l’initialisation de l’interface.', error);
        }
    });
});

function initDismissibleFlashes() {
    document.querySelectorAll('.flash-success, .flash-info').forEach((flash) => {
        if (flash.querySelector('[data-dismiss-flash]')) {
            flash.classList.add('flash-is-dismissible');
            return;
        }

        flash.classList.add('flash-is-dismissible');

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'flash-dismiss';
        button.dataset.dismissFlash = 'true';
        button.setAttribute('aria-label', 'Fermer le message');
        button.textContent = 'x';

        flash.appendChild(button);
    });

    if (document.documentElement.dataset.flashDismissReady === 'true') {
        return;
    }

    document.documentElement.dataset.flashDismissReady = 'true';
    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dismiss-flash]');

        if (!button) {
            return;
        }

        const flash = button.closest('.flash');

        if (flash) {
            flash.remove();
        }
    });
}

function initMessengerDrawer() {
    const drawer = document.querySelector('[data-messenger-drawer]');
    const openButton = document.querySelector('[data-messenger-drawer-open]');
    const closeButtons = Array.from(document.querySelectorAll('[data-messenger-drawer-close]'));

    if (!drawer || !openButton) {
        return;
    }

    const closeDrawer = () => {
        document.body.classList.remove('is-messenger-drawer-open');
        openButton.setAttribute('aria-expanded', 'false');
    };

    const openDrawer = () => {
        document.body.classList.add('is-messenger-drawer-open');
        openButton.setAttribute('aria-expanded', 'true');
    };

    openButton.setAttribute('aria-expanded', 'false');
    openButton.addEventListener('click', openDrawer);
    closeButtons.forEach((button) => button.addEventListener('click', closeDrawer));

    drawer.querySelectorAll('a.contact-item').forEach((link) => {
        link.addEventListener('click', closeDrawer);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeDrawer();
        }
    });
}

function csrfToken() {
    const tokenInput = document.querySelector('[data-csrf-token], input[name="csrf_token"]');
    return tokenInput ? (tokenInput.value || tokenInput.dataset.csrfToken || '') : '';
}

function appendCsrf(formData) {
    const token = csrfToken();

    if (token && !formData.has('csrf_token')) {
        formData.append('csrf_token', token);
    }
}

function initPostPolls() {
    document.querySelectorAll('.post-poll input[type="radio"][name="option_id"]').forEach((input) => {
        input.addEventListener('change', () => {
            const form = input.closest('.post-poll');

            if (!form) {
                return;
            }

            form.querySelectorAll('.post-poll-option').forEach((option) => {
                option.classList.remove('selected');
            });

            const option = input.closest('.post-poll-option');

            if (option) {
                option.classList.add('selected');
            }
        });
    });
}

function renderMessageContent(message) {
    const rawContent = message.content || '';
    const renderedContent = message.content_html || nl2br(escapeHtml(rawContent));

    if (!rawContent) {
        return '';
    }

    return `
        <div class="message-content markdown-content" data-message-content data-raw-content="${escapeHtml(rawContent)}">
            ${renderedContent}
        </div>
    `;
}

function renderMessageAttachment(message) {
    if (!message.attachment_id) {
        return '';
    }

    const fileUrl = `file.php?id=${encodeURIComponent(message.attachment_id)}`;
    const downloadUrl = `${fileUrl}&download=1`;
    const fileName = message.attachment_original_name || 'Pièce jointe';
    const mimeType = message.attachment_mime_type || '';
    const fileSize = Number(message.attachment_size || 0);
    const meta = [mimeType, humanFileSize(fileSize)].filter(Boolean).join(' · ');
    const downloadLink = `<a class="button-secondary message-attachment-download" href="${downloadUrl}" download="${escapeHtml(fileName)}">Télécharger</a>`;
    const attachmentMeta = `
        <span class="message-attachment-meta-row">
            ${meta ? `<span class="meta">${escapeHtml(meta)}</span>` : ''}
            ${downloadLink}
        </span>
    `;

    if (mimeType.startsWith('image/')) {
        return `
            <div class="message-attachment message-attachment-image">
                <div class="message-attachment-header">
                    <span class="message-attachment-title">${escapeHtml(fileName)}</span>
                    ${attachmentMeta}
                </div>
                <a class="message-attachment-image-link" href="${fileUrl}" target="_blank" rel="noopener" aria-label="${escapeHtml(fileName)}">
                    <img src="${fileUrl}" alt="${escapeHtml(fileName)}" loading="lazy">
                </a>
            </div>
        `;
    }

    if (mimeType === 'application/pdf') {
        return `
            <div class="message-attachment message-attachment-preview">
                <div class="message-attachment-header">
                    <a href="${fileUrl}" target="_blank" rel="noopener">${escapeHtml(fileName)}</a>
                    ${attachmentMeta}
                </div>
                <iframe src="${fileUrl}#toolbar=0" title="${escapeHtml(fileName)}" loading="lazy"></iframe>
            </div>
        `;
    }

    if (mimeType.startsWith('audio/')) {
        return `
            <div class="message-attachment message-attachment-media">
                <div class="message-attachment-header">
                    <a href="${fileUrl}" target="_blank" rel="noopener">${escapeHtml(fileName)}</a>
                    ${attachmentMeta}
                </div>
                <audio controls preload="metadata" src="${fileUrl}"></audio>
            </div>
        `;
    }

    if (mimeType.startsWith('video/')) {
        return `
            <div class="message-attachment message-attachment-media">
                <div class="message-attachment-header">
                    <a href="${fileUrl}" target="_blank" rel="noopener">${escapeHtml(fileName)}</a>
                    ${attachmentMeta}
                </div>
                <video controls preload="metadata" src="${fileUrl}"></video>
            </div>
        `;
    }

    if (isPreviewableTextMime(mimeType)) {
        return `
            <div class="message-attachment message-attachment-preview">
                <div class="message-attachment-header">
                    <a href="${fileUrl}" target="_blank" rel="noopener">${escapeHtml(fileName)}</a>
                    ${attachmentMeta}
                </div>
                <iframe src="${fileUrl}" title="${escapeHtml(fileName)}" loading="lazy" sandbox=""></iframe>
            </div>
        `;
    }

    return `
        <div class="message-attachment message-attachment-file">
            <div class="message-file-icon" aria-hidden="true">F</div>
            <div>
                <a href="${fileUrl}" target="_blank" rel="noopener">
                    ${escapeHtml(fileName)}
                </a>
                ${attachmentMeta}
            </div>
        </div>
    `;
}

function isPreviewableTextMime(mimeType) {
    return mimeType === 'text/plain'
        || mimeType === 'text/markdown'
        || mimeType === 'text/csv'
        || mimeType === 'application/json'
        || mimeType === 'application/xml'
        || mimeType === 'text/xml';
}

function humanFileSize(bytes) {
    if (!bytes || bytes < 0) {
        return '';
    }

    if (bytes < 1024) {
        return `${bytes} o`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round((bytes / 1024) * 10) / 10} Ko`;
    }

    return `${Math.round((bytes / (1024 * 1024)) * 10) / 10} Mo`;
}

function messageListSignature(messages) {
    return messages.map((message) => [
        message.id,
        message.updated_at || '',
        message.content || '',
        message.content_html || '',
        message.attachment_id || '',
        message.attachment_mime_type || '',
        message.attachment_original_name || '',
        message.attachment_size || ''
    ].join(':')).join('|');
}

function typesetMath(container = document.body) {
    if (!container || !container.textContent || !containsLatex(container.textContent)) {
        return;
    }

    ensureMathJaxLoaded().then(() => window.MathJax.typesetPromise([container])).catch((error) => {
        console.error('Erreur MathJax.', error);
    });
}

function containsLatex(text) {
    return /\$\$[\s\S]+?\$\$|\\\([\s\S]+?\\\)|(^|[^$])\$[^$\n]+?\$([^$]|$)/.test(text || '');
}

function ensureMathJaxLoaded() {
    if (window.MathJax && typeof window.MathJax.typesetPromise === 'function') {
        return Promise.resolve();
    }

    window.MathJax = window.MathJax || {
        tex: {
            inlineMath: [['\\(', '\\)'], ['$', '$']],
            displayMath: [['$$', '$$']]
        },
        svg: {
            fontCache: 'global'
        }
    };

    const existingScript = document.querySelector('script[src*="mathjax@3"]');

    if (existingScript) {
        return new Promise((resolve) => {
            if (typeof window.MathJax.typesetPromise === 'function') {
                resolve();
                return;
            }

            existingScript.addEventListener('load', resolve, {once: true});
        });
    }

    return new Promise((resolve, reject) => {
        const mathJaxScript = document.createElement('script');
        mathJaxScript.defer = true;
        mathJaxScript.src = 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js';
        mathJaxScript.addEventListener('load', resolve, {once: true});
        mathJaxScript.addEventListener('error', reject, {once: true});
        document.head.appendChild(mathJaxScript);
    });
}

function avatarMarkup(userId, avatar, label) {
    const initial = (label || '?').trim().charAt(0).toUpperCase() || '?';

    if (avatar) {
        const version = avatarVersion(avatar);

        return `<span class="post-avatar"><img src="avatar.php?user_id=${encodeURIComponent(userId)}&v=${encodeURIComponent(version)}" alt=""></span>`;
    }

    return `<span class="post-avatar">${escapeHtml(initial)}</span>`;
}

function avatarVersion(value) {
    const text = String(value || '');
    let hash = 0;

    for (let index = 0; index < text.length; index += 1) {
        hash = ((hash << 5) - hash) + text.charCodeAt(index);
        hash |= 0;
    }

    return Math.abs(hash).toString(36);
}

function initPrivateChat() {
    const messagesBox = document.querySelector('#messages');
    const form = document.querySelector('#message-form');

    if (!messagesBox || !form) {
        return;
    }

    const peerId = messagesBox.dataset.peerId;
    const currentUserId = messagesBox.dataset.currentUserId;
    const textarea = form.querySelector('textarea[name="content"]');
    const fileInput = form.querySelector('input[name="file"]');
    const attachmentInput = form.querySelector('input[name="attachment_id"]');
    const existingAttachmentSelect = form.querySelector('select[name="existing_attachment_id"]');
    const uploadStatus = document.querySelector('#message-upload-status');

    let isSending = false;
    let lastMessagesSignature = '';

    async function fetchMessages() {
        try {
            const response = await fetch(`../api/fetch_messages.php?user_id=${encodeURIComponent(peerId)}`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                console.error(data.error || 'Impossible de charger les messages.');
                return;
            }

            renderMessages(data.messages || [], currentUserId);
        } catch (error) {
            console.error('Erreur réseau pendant le chargement des messages.', error);
        }
    }

    function renderMessages(messages, currentUserId) {
        const signature = messageListSignature(messages);

        if (signature === lastMessagesSignature) {
            return;
        }

        lastMessagesSignature = signature;

        if (messages.length === 0) {
            messagesBox.innerHTML = '<p class="muted">Aucun message pour l’instant.</p>';
            return;
        }

        const shouldScroll =
            messagesBox.scrollTop + messagesBox.clientHeight >= messagesBox.scrollHeight - 80;

        messagesBox.innerHTML = messages.map((message) => {
            const mine = String(message.sender_id) === String(currentUserId);
            const author = mine
                ? 'Vous'
                : (message.sender_display_name || message.sender_username || 'Membre');

            const attachmentHtml = renderMessageAttachment(message);

            return `
                <article class="chat-message ${mine ? 'mine' : 'other'}">
                    <div class="message-author-row">
                        <span class="message-author">${escapeHtml(author)}</span>
                        ${avatarMarkup(message.sender_id, message.sender_avatar, author)}
                    </div>
                    ${renderMessageContent(message)}
                    ${attachmentHtml}

                    <p class="meta">
                        ${escapeHtml(message.created_at || '')}
                        ${message.updated_at ? ' · <span class="edited-marker">modifié</span>' : ''}
                    </p>

                    <div class="message-tools">
                        <button type="button" data-transform-message="${escapeHtml(message.id)}" data-transform-type="post">
                            Transformer en publication
                        </button>
                        <button type="button" data-transform-message="${escapeHtml(message.id)}" data-transform-type="article">
                            Transformer en article
                        </button>
                        <button type="button" data-edit-message="${escapeHtml(message.id)}">
                            Modifier
                        </button>
                        ${message.can_delete ? `
                            <button type="button" data-delete-message="${escapeHtml(message.id)}">
                                Supprimer
                            </button>
                        ` : ''}
                    </div>
                </article>
            `;
        }).join('');

        if (shouldScroll) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }

        typesetMath(messagesBox);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (isSending) {
            return;
        }

        const content = textarea.value.trim();
        const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
        const existingAttachmentId = existingAttachmentSelect ? existingAttachmentSelect.value : '';

        if (!content && !hasFile && !existingAttachmentId) {
            return;
        }

        isSending = true;
        if (attachmentInput) {
            attachmentInput.value = hasFile ? '' : existingAttachmentId;
        }

        if (uploadStatus) {
            uploadStatus.textContent = '';
        }

        if (hasFile) {
            const uploadData = new FormData();
            uploadData.append('file', fileInput.files[0]);
            uploadData.append('type', 'attachment');
            appendCsrf(uploadData);

            if (uploadStatus) {
                uploadStatus.textContent = 'Envoi de la pièce jointe...';
            }

            try {
                const uploadResponse = await fetch('../api/upload.php', {
                    method: 'POST',
                    body: uploadData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const uploadResult = await uploadResponse.json();

                if (!uploadResponse.ok || !uploadResult.success) {
                    alert(uploadResult.error || 'Impossible d’envoyer la pièce jointe.');
                    return;
                }

                if (attachmentInput) {
                    attachmentInput.value = uploadResult.attachment_id;
                }

                if (uploadStatus) {
                    uploadStatus.textContent = 'Pièce jointe envoyée.';
                }
            } catch (error) {
                alert('Erreur réseau pendant l’envoi de la pièce jointe.');
                return;
            }
        }

        const formData = new FormData(form);
        formData.delete('file');
        formData.delete('existing_attachment_id');
        appendCsrf(formData);

        try {
            const response = await fetch('../api/send_message.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                alert(data.error || 'Impossible d’envoyer le message.');
                return;
            }

            textarea.value = '';

            if (fileInput) {
                fileInput.value = '';
            }

            if (attachmentInput) {
                attachmentInput.value = '';
            }

            if (existingAttachmentSelect) {
                existingAttachmentSelect.value = '';
            }

            if (uploadStatus) {
                uploadStatus.textContent = '';
            }

            await fetchMessages();
            messagesBox.scrollTop = messagesBox.scrollHeight;
            textarea.focus();
        } catch (error) {
            alert('Erreur réseau pendant l’envoi du message.');
        } finally {
            isSending = false;
        }
    });

    textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    fetchMessages();
    setInterval(fetchMessages, 3000);
}

function initGroupChat() {
    const messagesBox = document.querySelector('#group-messages');
    const form = document.querySelector('#group-message-form');

    if (!messagesBox || !form) {
        return;
    }

    const groupId = messagesBox.dataset.groupId;
    const currentUserId = messagesBox.dataset.currentUserId;
    const textarea = form.querySelector('textarea[name="content"]');
    const fileInput = form.querySelector('input[name="file"]');
    const attachmentInput = form.querySelector('input[name="attachment_id"]');
    const existingAttachmentSelect = form.querySelector('select[name="existing_attachment_id"]');
    const uploadStatus = document.querySelector('#group-message-upload-status');

    let isSending = false;
    let lastMessagesSignature = '';

    async function fetchGroupMessages() {
        try {
            const response = await fetch(`../api/fetch_group_messages.php?group_id=${encodeURIComponent(groupId)}`, {
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                messagesBox.innerHTML = `<p class="muted">${escapeHtml(data.error || 'Impossible de charger les messages du groupe.')}</p>`;
                return;
            }

            renderGroupMessages(data.messages || [], currentUserId);
        } catch (error) {
            messagesBox.innerHTML = '<p class="muted">Erreur réseau pendant le chargement des messages du groupe.</p>';
        }
    }

    function renderGroupMessages(messages, currentUserId) {
        const signature = messageListSignature(messages);

        if (signature === lastMessagesSignature) {
            return;
        }

        lastMessagesSignature = signature;

        if (messages.length === 0) {
            messagesBox.innerHTML = '<p class="muted">Aucun message dans ce groupe pour l’instant.</p>';
            return;
        }

        const shouldScroll =
            messagesBox.scrollTop + messagesBox.clientHeight >= messagesBox.scrollHeight - 80;

        messagesBox.innerHTML = messages.map((message) => {
            const mine = String(message.sender_id) === String(currentUserId);
            const author = message.display_name || message.username || 'Membre';

            const attachmentHtml = renderMessageAttachment(message);

            return `
                <article class="chat-message ${mine ? 'mine' : 'other'}">
                    <div class="message-author-row">
                        <span class="message-author">${escapeHtml(author)}</span>
                        ${avatarMarkup(message.sender_id, message.avatar, author)}
                    </div>
                    ${renderMessageContent(message)}
                    ${attachmentHtml}

                    <p class="meta">
                        ${escapeHtml(message.created_at || '')}
                        ${message.updated_at ? ' · <span class="edited-marker">modifié</span>' : ''}
                    </p>

                    <div class="message-tools">
                        <button type="button" data-transform-message="${escapeHtml(message.id)}" data-transform-type="post">
                            Transformer en publication
                        </button>
                        <button type="button" data-transform-message="${escapeHtml(message.id)}" data-transform-type="article">
                            Transformer en article
                        </button>
                        <button type="button" data-edit-message="${escapeHtml(message.id)}">
                            Modifier
                        </button>
                        ${message.can_delete ? `
                            <button type="button" data-delete-message="${escapeHtml(message.id)}">
                                Supprimer
                            </button>
                        ` : ''}
                    </div>
                </article>
            `;
        }).join('');

        if (shouldScroll) {
            messagesBox.scrollTop = messagesBox.scrollHeight;
        }

        typesetMath(messagesBox);
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (isSending) {
            return;
        }

        const content = textarea.value.trim();
        const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
        const existingAttachmentId = existingAttachmentSelect ? existingAttachmentSelect.value : '';

        if (!content && !hasFile && !existingAttachmentId) {
            return;
        }

        isSending = true;
        if (attachmentInput) {
            attachmentInput.value = hasFile ? '' : existingAttachmentId;
        }

        if (uploadStatus) {
            uploadStatus.textContent = '';
        }

        if (hasFile) {
            const uploadData = new FormData();
            uploadData.append('file', fileInput.files[0]);
            uploadData.append('type', 'attachment');
            appendCsrf(uploadData);

            if (uploadStatus) {
                uploadStatus.textContent = 'Envoi de la pièce jointe...';
            }

            try {
                const uploadResponse = await fetch('../api/upload.php', {
                    method: 'POST',
                    body: uploadData,
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const uploadResult = await uploadResponse.json();

                if (!uploadResponse.ok || !uploadResult.success) {
                    alert(uploadResult.error || 'Impossible d’envoyer la pièce jointe.');
                    return;
                }

                if (attachmentInput) {
                    attachmentInput.value = uploadResult.attachment_id;
                }

                if (uploadStatus) {
                    uploadStatus.textContent = 'Pièce jointe envoyée.';
                }
            } catch (error) {
                alert('Erreur réseau pendant l’envoi de la pièce jointe.');
                return;
            }
        }

        const formData = new FormData(form);
        formData.delete('file');
        formData.delete('existing_attachment_id');
        appendCsrf(formData);

        try {
            const response = await fetch('../api/send_group_message.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json'
                }
            });

            const data = await response.json();

            if (!response.ok || !data.success) {
                alert(data.error || 'Impossible d’envoyer le message dans le groupe.');
                return;
            }

            textarea.value = '';

            if (fileInput) {
                fileInput.value = '';
            }

            if (attachmentInput) {
                attachmentInput.value = '';
            }

            if (existingAttachmentSelect) {
                existingAttachmentSelect.value = '';
            }

            if (uploadStatus) {
                uploadStatus.textContent = '';
            }

            await fetchGroupMessages();
            messagesBox.scrollTop = messagesBox.scrollHeight;
            textarea.focus();
        } catch (error) {
            alert('Erreur réseau pendant l’envoi du message de groupe.');
        } finally {
            isSending = false;
        }
    });

    textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form.requestSubmit();
        }
    });

    fetchGroupMessages();
    setInterval(fetchGroupMessages, 3000);
}

function initMessengerWebNotifications() {
    const toggle = document.querySelector('[data-messenger-notification-toggle]');
    const status = document.querySelector('[data-messenger-notification-status]');
    const shouldPoll = document.body.classList.contains('messenger-page') || Boolean(toggle);
    const storageKey = 'protectionMessengerNotifiedIds';
    const preferenceStorageKey = 'protectionMessengerNotificationsEnabled';
    const preferenceEndpoint = toggle && toggle.dataset.preferenceEndpoint
        ? toggle.dataset.preferenceEndpoint
        : '../api/message_alert_preference.php';
    const messagesEndpoint = toggle && toggle.dataset.messagesEndpoint
        ? toggle.dataset.messagesEndpoint
        : '../api/message_alerts.php';
    const pollDelay = 5000;
    let messengerEnabled = toggle ? toggle.dataset.messengerNotificationEnabled === '1' : false;
    let pollTimer = null;

    if (!shouldPoll) {
        return;
    }

    if (!('Notification' in window)) {
        if (toggle) {
            toggle.hidden = false;
            toggle.textContent = 'Notifications indisponibles';
            toggle.disabled = true;
        }

        return;
    }

    if (toggle) {
        toggle.hidden = false;
    }

    function readSeenIds() {
        try {
            const stored = JSON.parse(localStorage.getItem(storageKey) || '[]');
            return new Set(Array.isArray(stored) ? stored.map((id) => String(id)) : []);
        } catch (error) {
            return new Set();
        }
    }

    function writeSeenIds(ids) {
        try {
            const limited = Array.from(ids).slice(-300);
            localStorage.setItem(storageKey, JSON.stringify(limited));
        } catch (error) {
            console.error('Stockage local indisponible pour les notifications.', error);
        }
    }

    function readStoredPreference() {
        try {
            return localStorage.getItem(preferenceStorageKey) === '1';
        } catch (error) {
            return messengerEnabled;
        }
    }

    function writeStoredPreference(enabled) {
        try {
            localStorage.setItem(preferenceStorageKey, enabled ? '1' : '0');
        } catch (error) {
            console.error('Stockage local indisponible pour la préférence de notifications.', error);
        }
    }

    function messengerUrlFromNotification(notification) {
        const rawLink = notification.link || '';

        try {
            const parsed = new URL(rawLink, window.location.href);
            const path = parsed.pathname;

            if (path.endsWith('/chat.php') && parsed.searchParams.has('user_id')) {
                return `messenger.php?type=private&user_id=${encodeURIComponent(parsed.searchParams.get('user_id'))}`;
            }

            if (path.endsWith('/group.php') && parsed.searchParams.has('id')) {
                return `messenger.php?type=group&group_id=${encodeURIComponent(parsed.searchParams.get('id'))}`;
            }

            if (path.endsWith('/messenger.php')) {
                return parsed.pathname.split('/').pop() + parsed.search;
            }
        } catch (error) {
            return 'messenger.php';
        }

        return 'messenger.php';
    }

    function titleForNotification(notification) {
        return notification.type === 'group_message'
            ? 'Nouveau message de groupe'
            : 'Nouveau message privé';
    }

    function showBrowserNotification(notification) {
        const browserNotification = new Notification(titleForNotification(notification), {
            body: notification.content || 'Vous avez reçu un nouveau message.',
            tag: `protection-message-${notification.id}`,
        });

        browserNotification.addEventListener('click', () => {
            window.focus();
            window.location.href = messengerUrlFromNotification(notification);
            browserNotification.close();
        });
    }

    function setStatus(message) {
        if (status) {
            status.textContent = message || '';
        }
    }

    function requestBrowserNotificationPermission() {
        if (typeof Notification.requestPermission !== 'function') {
            return Promise.resolve(Notification.permission);
        }

        return new Promise((resolve, reject) => {
            let settled = false;
            const finish = (permission) => {
                if (!settled) {
                    settled = true;
                    resolve(permission || Notification.permission);
                }
            };

            try {
                const permissionRequest = Notification.requestPermission(finish);

                if (permissionRequest && typeof permissionRequest.then === 'function') {
                    permissionRequest.then(finish).catch(reject);
                } else {
                    setTimeout(() => finish(Notification.permission), 1000);
                }
            } catch (error) {
                reject(error);
            }
        });
    }

    async function fetchPreference() {
        try {
            const response = await fetch(preferenceEndpoint, {
                cache: 'no-store',
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                return false;
            }

            messengerEnabled = Boolean(data.messenger_enabled);
            writeStoredPreference(messengerEnabled);
            return true;
        } catch (error) {
            console.error('Erreur réseau pendant le chargement de la préférence de notifications.', error);
            return false;
        }
    }

    async function savePreference(enabled) {
        const formData = new FormData();
        formData.append('messenger_enabled', enabled ? '1' : '0');

        if (toggle && toggle.dataset.csrfToken) {
            formData.append('csrf_token', toggle.dataset.csrfToken);
        } else {
            appendCsrf(formData);
        }

        const response = await fetch(preferenceEndpoint, {
            method: 'POST',
            cache: 'no-store',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });
        let data = null;

        try {
            data = await response.json();
        } catch (error) {
            throw new Error('Réponse invalide du serveur pendant l’enregistrement des notifications.');
        }

        if (!response.ok || !data || !data.success) {
            throw new Error((data && data.error) || 'Impossible d’enregistrer la préférence de notifications.');
        }

        messengerEnabled = Boolean(data.messenger_enabled);
        writeStoredPreference(messengerEnabled);
    }

    async function fetchMessengerNotifications({ baseline = false } = {}) {
        if (!messengerEnabled || Notification.permission !== 'granted') {
            return;
        }

        try {
            const response = await fetch(messagesEndpoint, {
                headers: {
                    'Accept': 'application/json'
                }
            });
            const data = await response.json();

            if (!response.ok || !data.success) {
                return;
            }

            const seenIds = readSeenIds();
            const notifications = data.notifications || [];

            notifications.forEach((notification) => {
                const id = String(notification.id);

                if (!baseline && !seenIds.has(id) && Notification.permission === 'granted') {
                    showBrowserNotification(notification);
                }

                seenIds.add(id);
            });

            writeSeenIds(seenIds);
        } catch (error) {
            console.error('Erreur réseau pendant le chargement des notifications de messagerie.', error);
        }
    }

    async function enableNotifications() {
        if (toggle) {
            toggle.textContent = 'Activation...';
            toggle.disabled = true;
        }

        if (Notification.permission === 'default') {
            const permission = await requestBrowserNotificationPermission();

            if (permission && permission !== Notification.permission) {
                // Safari peut résoudre la permission avant de mettre à jour Notification.permission.
                await new Promise((resolve) => setTimeout(resolve, 0));
            }
        }

        if (Notification.permission !== 'granted') {
            updateToggleState();
            setStatus('Autorisation navigateur nécessaire.');
            throw new Error('Autorisez les notifications dans le navigateur pour activer cette option.');
        }

        await savePreference(true);

        setStatus('Notifications activées.');
        updateToggleState();
        await fetchMessengerNotifications({ baseline: true });
        startPolling();
    }

    async function disableNotifications() {
        if (toggle) {
            toggle.textContent = 'Désactivation...';
            toggle.disabled = true;
        }

        await savePreference(false);
        setStatus('Notifications désactivées.');
        updateToggleState();
        stopPolling();
    }

    function updateToggleState() {
        if (!toggle) {
            return;
        }

        if (Notification.permission === 'denied') {
            toggle.textContent = 'Notifications bloquées par le navigateur';
            toggle.disabled = true;
            setStatus('Autorisez les notifications dans les réglages du navigateur.');
        } else if (messengerEnabled && Notification.permission === 'granted') {
            toggle.textContent = 'Désactiver les notifications';
            toggle.disabled = false;
            setStatus('');
        } else if (messengerEnabled && Notification.permission !== 'granted') {
            toggle.textContent = 'Autoriser les notifications';
            toggle.disabled = false;
            setStatus('Autorisation navigateur nécessaire.');
        } else {
            toggle.textContent = 'Activer les notifications';
            toggle.disabled = false;
            setStatus('');
        }
    }

    function startPolling() {
        if (pollTimer || !messengerEnabled || Notification.permission !== 'granted') {
            return;
        }

        pollTimer = setInterval(() => {
            fetchMessengerNotifications();
        }, pollDelay);
    }

    function stopPolling() {
        if (!pollTimer) {
            return;
        }

        clearInterval(pollTimer);
        pollTimer = null;
    }

    async function initializeNotifications() {
        messengerEnabled = readStoredPreference();
        updateToggleState();

        await fetchPreference();
        updateToggleState();

        if (messengerEnabled && Notification.permission === 'granted') {
            await fetchMessengerNotifications({ baseline: readSeenIds().size === 0 });
            startPolling();
        } else if (Notification.permission === 'denied') {
            stopPolling();
        }
    }

    if (toggle) {
        toggle.addEventListener('click', async () => {
            toggle.disabled = true;

            try {
                if (messengerEnabled) {
                    await disableNotifications();
                } else {
                    await enableNotifications();
                }
            } catch (error) {
                const message = error.message || 'Impossible de modifier les notifications.';
                setStatus(message);
                alert(message);
                updateToggleState();
            } finally {
                if (Notification.permission !== 'denied') {
                    toggle.disabled = false;
                }
            }
        });
    }

    initializeNotifications();
}

function initUploadForm() {
    const form = document.querySelector('#upload-form');
    const result = document.querySelector('#upload-result');

    if (!form || !result) {
        return;
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const fileInput = form.querySelector('input[type="file"][name="file"]');
        const selectedFile = fileInput && fileInput.files.length ? fileInput.files[0] : null;
        const maxUploadSize = Number(form.dataset.maxUploadSize || 0);

        if (selectedFile && maxUploadSize > 0 && selectedFile.size > maxUploadSize) {
            result.innerHTML = `<div class="flash flash-error">Le fichier est trop volumineux. Taille maximale : ${escapeHtml(humanFileSize(maxUploadSize))}.</div>`;
            return;
        }

        const formData = new FormData(form);
        appendCsrf(formData);
        result.innerHTML = '<div class="flash flash-info">Envoi en cours...</div>';

        try {
            const uploadUrl = new URL('upload.php', window.location.href);
            const response = await fetch(uploadUrl.href, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const contentType = response.headers.get('content-type') || '';
            const data = contentType.includes('application/json')
                ? await response.json()
                : {
                    success: false,
                    error: response.ok
                        ? 'Réponse serveur inattendue.'
                        : `Erreur serveur (${response.status}).`
                };

            if (!response.ok || !data.success) {
                result.innerHTML = `<div class="flash flash-error">${escapeHtml(data.error || 'Erreur pendant l’envoi.')}</div>`;
                return;
            }

            result.innerHTML = `
                <div class="flash flash-success">
                    Fichier envoyé : ${escapeHtml(data.original_name)}
                    <br>
                    <a href="file.php?id=${encodeURIComponent(data.attachment_id)}" target="_blank" rel="noopener">Ouvrir le fichier</a>
                </div>
            `;

            form.reset();
        } catch (error) {
            result.innerHTML = `<div class="flash flash-error">Erreur réseau pendant l’envoi : ${escapeHtml(error.message || 'requête impossible')}.</div>`;
        }
    });
}

function initMarkdownToolbar() {
    const toolbars = document.querySelectorAll('.markdown-toolbar');

    if (!toolbars.length) {
        return;
    }

    toolbars.forEach((toolbar) => {
        const targetId = toolbar.dataset.mdTarget;
        const fixedTextarea = targetId
            ? document.getElementById(targetId)
            : null;
        const form = toolbar.closest('form');
        const textareas = targetId
            ? [fixedTextarea].filter(Boolean)
            : Array.from((form || document).querySelectorAll('textarea[name="excerpt"], textarea[name="content"], textarea.article-editor'));

        if (!textareas.length) {
            return;
        }

        let activeTextarea = fixedTextarea
            || textareas.find((candidate) => candidate === document.activeElement)
            || textareas.find((candidate) => candidate.matches('textarea.article-editor[name="content"]'))
            || textareas[0];
        const selections = new WeakMap();

        function saveSelection(event) {
            const currentTextarea = event.currentTarget;

            activeTextarea = currentTextarea;
            selections.set(currentTextarea, {
                start: currentTextarea.selectionStart,
                end: currentTextarea.selectionEnd
            });
        }

        function selectionFor(textarea) {
            return selections.get(textarea) || {
                start: textarea.selectionStart || 0,
                end: textarea.selectionEnd || 0
            };
        }

        textareas.forEach((textarea) => {
            selections.set(textarea, {
                start: textarea.selectionStart || 0,
                end: textarea.selectionEnd || 0
            });

            textarea.addEventListener('keyup', saveSelection);
            textarea.addEventListener('mouseup', saveSelection);
            textarea.addEventListener('select', saveSelection);
            textarea.addEventListener('input', saveSelection);
            textarea.addEventListener('focus', saveSelection);
        });

        toolbar.addEventListener('mousedown', (event) => {
            const button = closestElement(event.target, '[data-md-action]');

            if (button) {
                event.preventDefault();
            }
        });

        toolbar.addEventListener('click', (event) => {
            const button = closestElement(event.target, '[data-md-action]');

            if (!button) {
                return;
            }

            event.preventDefault();
            const action = button.dataset.mdAction;
            const textarea = fixedTextarea || activeTextarea;

            if (!textarea) {
                return;
            }

            const selection = selectionFor(textarea);

            textarea.focus();
            textarea.selectionStart = selection.start;
            textarea.selectionEnd = selection.end;
            applyMarkdownAction(textarea, action);

            selections.set(textarea, {
                start: textarea.selectionStart,
                end: textarea.selectionEnd
            });
            activeTextarea = textarea;
            textarea.focus();
        });
    });
}

document.addEventListener('mousedown', (event) => {
    if (closestElement(event.target, '[data-md-action]')) {
        event.preventDefault();
    }
});

document.addEventListener('click', (event) => {
    if (event.defaultPrevented) {
        return;
    }

    const button = closestElement(event.target, '[data-md-action]');

    if (!button) {
        return;
    }

    event.preventDefault();

    const textarea = markdownTextareaForButton(button);

    if (!textarea) {
        return;
    }

    textarea.focus();
    applyMarkdownAction(textarea, button.dataset.mdAction || '');
});

function markdownTextareaForButton(button) {
    const toolbar = button.closest('.markdown-toolbar');
    const form = button.closest('form');
    const targetId = toolbar ? toolbar.dataset.mdTarget : '';

    if (targetId) {
        return document.getElementById(targetId);
    }

    if (
        document.activeElement
        && document.activeElement.tagName === 'TEXTAREA'
        && (!form || form.contains(document.activeElement))
    ) {
        return document.activeElement;
    }

    return (form || document).querySelector('textarea.article-editor[name="content"], textarea[name="content"], textarea[name="excerpt"]');
}

function closestElement(target, selector) {
    while (target && target !== document) {
        if (target.nodeType === 1 && target.matches(selector)) {
            return target;
        }

        target = target.parentNode;
    }

    return null;
}

function applyMarkdownAction(textarea, action) {
    switch (action) {
        case 'h1':
            insertMarkdownLinePrefix(textarea, '# ');
            break;

        case 'h2':
            insertMarkdownLinePrefix(textarea, '## ');
            break;

        case 'h3':
            insertMarkdownLinePrefix(textarea, '### ');
            break;

        case 'bold':
            wrapSelection(textarea, '**', '**', 'texte en gras');
            break;

        case 'italic':
            wrapSelection(textarea, '*', '*', 'texte en italique');
            break;

        case 'inline-code':
            wrapSelection(textarea, '`', '`', 'code');
            break;

        case 'link':
            insertLink(textarea);
            break;

        case 'list':
            insertList(textarea);
            break;

        case 'table':
            insertTable(textarea);
            break;

        case 'code-block':
            wrapSelection(textarea, "```text\n", "\n```", 'texte ou bilan');
            break;

        case 'math-inline':
            wrapSelection(textarea, '$', '$', '\\rightarrow');
            break;

        case 'math-block':
            wrapSelection(textarea, "$$\n", "\n$$", 'C_1V_1 = C_2V_2');
            break;

        default:
            break;
    }
}

document.addEventListener('click', (event) => {
    const button = closestElement(event.target, '[data-insert-article-attachment], [data-insert-markdown-attachment]');

    if (!button) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const form = button.closest('form');
    const toolbar = form ? form.querySelector('.markdown-toolbar') : null;
    const targetId = button.dataset.mdTarget || (toolbar ? toolbar.dataset.mdTarget : '') || '';
    const textarea = targetId
        ? document.getElementById(targetId)
        : (form || document).querySelector('textarea.article-editor[name="content"]');
    const attachmentScope = form || document;
    const selectId = button.dataset.attachmentSelect || '';
    let select = null;

    if (selectId) {
        select = document.getElementById(selectId);
    }

    if (targetId) {
        select = select || Array.from(attachmentScope.querySelectorAll('[data-markdown-attachment-select]'))
            .find((candidate) => candidate.dataset.mdTarget === targetId) || null;
    }

    if (!select) {
        select = attachmentScope.querySelector('[data-markdown-attachment-select], #article-attachments');
    }

    if (!textarea || !select) {
        return;
    }

    const option = select.selectedOptions[0];

    if (!option) {
        alert('Sélectionnez un fichier à insérer.');
        return;
    }

    const fileUrl = option.dataset.url || '';

    if (!option.value || !fileUrl) {
        alert('Sélectionnez un fichier à insérer.');
        return;
    }

    const fileName = option.dataset.name || option.textContent.trim() || 'fichier';
    const mimeType = option.dataset.mime || '';
    const safeFileName = fileName.replace(/[\[\]\r\n]/g, ' ').trim() || 'fichier';
    const markdown = mimeType.startsWith('image/')
        ? `![${safeFileName}](${fileUrl})`
        : `[${safeFileName}](${fileUrl})`;

    textarea.focus();
    replaceMarkdownPlaceholderLinkSelection(textarea);
    replaceTextareaSelection(textarea, markdown);
});

function replaceMarkdownPlaceholderLinkSelection(textarea) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const value = textarea.value;
    const beforeSelection = value.substring(0, start);
    const afterSelection = value.substring(end);
    const linkStart = beforeSelection.lastIndexOf('[');
    const linkLabelEnd = afterSelection.indexOf(']');

    if (linkStart === -1 || linkLabelEnd === -1) {
        return;
    }

    const afterLabel = afterSelection.substring(linkLabelEnd);

    if (!afterLabel.startsWith('](https://exemple.fr)')) {
        return;
    }

    const previousClose = beforeSelection.lastIndexOf(']');
    const previousOpenParen = beforeSelection.lastIndexOf('(');

    if (previousClose > linkStart || previousOpenParen > linkStart) {
        return;
    }

    textarea.selectionStart = linkStart;
    textarea.selectionEnd = end + linkLabelEnd + '](https://exemple.fr)'.length;
}

function getTextareaSelection(textarea) {
    return {
        start: textarea.selectionStart,
        end: textarea.selectionEnd,
        selected: textarea.value.substring(textarea.selectionStart, textarea.selectionEnd)
    };
}

function replaceTextareaSelection(textarea, replacement, selectStart = null, selectEnd = null) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;

    const before = textarea.value.substring(0, start);
    const after = textarea.value.substring(end);

    textarea.value = before + replacement + after;

    if (selectStart !== null && selectEnd !== null) {
        textarea.selectionStart = start + selectStart;
        textarea.selectionEnd = start + selectEnd;
    } else {
        textarea.selectionStart = start + replacement.length;
        textarea.selectionEnd = start + replacement.length;
    }

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

function wrapSelection(textarea, before, after, placeholder) {
    const selection = getTextareaSelection(textarea);
    const selectedText = selection.selected || placeholder;
    const replacement = before + selectedText + after;

    replaceTextareaSelection(
        textarea,
        replacement,
        before.length,
        before.length + selectedText.length
    );
}

function insertMarkdownLinePrefix(textarea, prefix) {
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const value = textarea.value;

    const selectedText = value.substring(start, end);

    if (selectedText.includes('\n')) {
        const lines = selectedText
            .split('\n')
            .map((line) => line.trim() !== '' ? prefix + line : line)
            .join('\n');

        replaceTextareaSelection(textarea, lines);
        return;
    }

    const lineStart = value.lastIndexOf('\n', start - 1) + 1;
    const before = value.substring(0, lineStart);
    const after = value.substring(lineStart);

    textarea.value = before + prefix + after;
    textarea.selectionStart = start + prefix.length;
    textarea.selectionEnd = end + prefix.length;

    textarea.dispatchEvent(new Event('input', { bubbles: true }));
}

function insertLink(textarea) {
    const selection = getTextareaSelection(textarea);
    const label = selection.selected || 'texte du lien';
    const replacement = `[${label}](https://exemple.fr)`;

    replaceTextareaSelection(
        textarea,
        replacement,
        1,
        1 + label.length
    );
}

function insertList(textarea) {
    const selection = getTextareaSelection(textarea);

    if (selection.selected) {
        const lines = selection.selected
            .split('\n')
            .map((line) => {
                if (line.trim() === '') {
                    return '';
                }

                return '- ' + line;
            })
            .join('\n');

        replaceTextareaSelection(textarea, lines);
        return;
    }

    replaceTextareaSelection(
        textarea,
        "- premier élément\n- deuxième élément\n- troisième élément",
        2,
        16
    );
}

function insertTable(textarea) {
    const table = [
        '',
        '| Colonne 1 | Colonne 2 | Colonne 3 |',
        '| --- | --- | --- |',
        '| Valeur 1 | Valeur 2 | Valeur 3 |',
        '| Valeur 4 | Valeur 5 | Valeur 6 |',
        ''
    ].join('\n');

    replaceTextareaSelection(textarea, table);
}

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-transform-message]');

    if (!button) {
        return;
    }

    const messageId = button.dataset.transformMessage;
    const type = button.dataset.transformType;

    if (!messageId || !type) {
        return;
    }

    const label = type === 'article' ? 'article' : 'post';

    if (!confirm(`Transformer ce message en ${label} ?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('message_id', messageId);
    formData.append('type', type);
    appendCsrf(formData);

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Transformation...';

    try {
        const response = await fetch('../api/transform_message.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            alert(data.error || 'Impossible de transformer ce message.');
            return;
        }

        if (data.redirect) {
            window.location.href = data.redirect;
            return;
        }

        alert('Message transformé.');
    } catch (error) {
        alert('Erreur réseau pendant la transformation.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete-message]');

    if (!button) {
        return;
    }

    const messageId = button.dataset.deleteMessage;

    if (!messageId) {
        return;
    }

    if (!confirm('Supprimer ce message ?')) {
        return;
    }

    const formData = new FormData();
    formData.append('message_id', messageId);
    appendCsrf(formData);

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Suppression...';

    try {
        const response = await fetch('../api/delete_message.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            alert(data.error || 'Impossible de supprimer ce message.');
            return;
        }

        const messageElement = button.closest('.chat-message');

        if (messageElement) {
            messageElement.remove();
        }
    } catch (error) {
        alert('Erreur réseau pendant la suppression.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete-conversation]');

    if (!button) {
        return;
    }

    const type = button.dataset.conversationType || '';
    const targetId = button.dataset.conversationTarget || '';

    if (!type || !targetId) {
        return;
    }

    if (!confirm('Supprimer toute cette conversation ? Cette action masquera tous ses messages.')) {
        return;
    }

    const formData = new FormData();
    formData.append('type', type);
    formData.append('target_id', targetId);
    appendCsrf(formData);

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Suppression...';

    try {
        const response = await fetch('../api/delete_conversation.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            alert(data.error || 'Impossible de supprimer cette conversation.');
            return;
        }

        window.location.reload();
    } catch (error) {
        alert('Erreur réseau pendant la suppression de la conversation.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-delete-group]');

    if (!button) {
        return;
    }

    const groupId = button.dataset.deleteGroup || '';

    if (!groupId) {
        return;
    }

    if (!confirm('Supprimer définitivement ce groupe ? Ses messages seront supprimés.')) {
        return;
    }

    const formData = new FormData();
    formData.append('group_id', groupId);
    appendCsrf(formData);

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Suppression...';

    try {
        const response = await fetch('../api/delete_group.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            alert(data.error || 'Impossible de supprimer ce groupe.');
            return;
        }

        window.location.href = window.location.pathname.endsWith('/messenger.php')
            ? 'messenger.php?type=group'
            : 'groups.php';
    } catch (error) {
        alert('Erreur réseau pendant la suppression du groupe.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-leave-group]');

    if (!button) {
        return;
    }

    const groupId = button.dataset.leaveGroup || '';

    if (!groupId) {
        return;
    }

    if (!confirm('Quitter ce groupe ? Vous ne participerez plus à cette discussion.')) {
        return;
    }

    const formData = new FormData();
    formData.append('group_id', groupId);
    appendCsrf(formData);

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Sortie...';

    try {
        const response = await fetch('../api/leave_group.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            alert(data.error || 'Impossible de quitter ce groupe.');
            return;
        }

        window.location.href = document.body.classList.contains('messenger-page')
            ? 'messenger.php?type=group'
            : 'groups.php';
    } catch (error) {
        alert('Erreur réseau pendant la sortie du groupe.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-edit-message]');

    if (!button) {
        return;
    }

    const messageId = button.dataset.editMessage;

    if (!messageId) {
        return;
    }

    const messageElement = button.closest('.chat-message');

    if (!messageElement) {
        return;
    }

    const contentElement = messageElement.querySelector('[data-message-content]');

    if (!contentElement) {
        alert('Ce message ne peut pas être modifié.');
        return;
    }

    const currentContent = contentElement.dataset.rawContent || contentElement.textContent || '';
    const newContent = prompt('Modifier le message :', currentContent);

    if (newContent === null) {
        return;
    }

    const trimmedContent = newContent.trim();

    if (!trimmedContent) {
        alert('Le message ne peut pas être vide.');
        return;
    }

    const formData = new FormData();
    formData.append('message_id', messageId);
    formData.append('content', trimmedContent);
    appendCsrf(formData);

    button.disabled = true;
    const originalText = button.textContent;
    button.textContent = 'Modification...';

    try {
        const response = await fetch('../api/edit_message.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            alert(data.error || 'Impossible de modifier ce message.');
            return;
        }

        contentElement.dataset.rawContent = trimmedContent;
        contentElement.innerHTML = data.content_html || nl2br(escapeHtml(trimmedContent));
        typesetMath(contentElement);

        const editedMarker = messageElement.querySelector('.edited-marker');

        if (!editedMarker) {
            const meta = messageElement.querySelector('.meta');

            if (meta) {
                meta.insertAdjacentHTML('beforeend', ' · <span class="edited-marker">modifié</span>');
            }
        }
    } catch (error) {
        alert('Erreur réseau pendant la modification.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-publish-mastodon]');

    if (!button) {
        return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();

    if (button.dataset.mastodonPublishing === '1') {
        return;
    }

    const contentType = button.dataset.contentType;
    const contentId = button.dataset.contentId;

    if (!contentType || !contentId) {
        return;
    }

    button.dataset.mastodonPublishing = '1';

    if (!confirm('Publier ce contenu sur Mastodon ?')) {
        delete button.dataset.mastodonPublishing;
        return;
    }

    const formData = new FormData();
    formData.append('type', contentType);
    formData.append('id', contentId);
    appendCsrf(formData);

    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Publication...';

    try {
        const response = await fetch('../api/publish_mastodon.php', {
            method: 'POST',
            body: formData,
            headers: {
                'Accept': 'application/json'
            }
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            alert(data.error || 'Impossible de publier sur Mastodon.');
            return;
        }

        const mastodonUrl = data.mastodon && data.mastodon.url ? data.mastodon.url : '';

        if (mastodonUrl && confirm('Contenu publié sur Mastodon. Ouvrir la publication ?')) {
            window.open(mastodonUrl, '_blank', 'noopener');
        } else {
            alert('Contenu publié sur Mastodon.');
        }
    } catch (error) {
        alert('Erreur réseau pendant la publication Mastodon.');
    } finally {
        button.disabled = false;
        button.textContent = originalText;
        delete button.dataset.mastodonPublishing;
    }
});

function initHeightMatchedPostPagers() {
    const panels = Array.from(document.querySelectorAll('[data-height-paged-posts]'));

    if (!panels.length) {
        return;
    }

    const states = panels.map((panel) => ({
        panel,
        list: panel.querySelector('[data-height-paged-list]'),
        pager: panel.querySelector('[data-height-post-pager]'),
        serverPagination: panel.querySelector('[data-height-server-pagination]'),
        target: document.querySelector(panel.dataset.heightTarget || ''),
        minHeight: Math.max(0, Number(panel.dataset.heightMin || 0)),
        itemLabel: panel.dataset.heightItemLabel || 'publications',
        numericOnly: panel.dataset.heightNumericOnly === 'true',
        page: 0,
        pages: []
    })).filter((state) => state.list && state.pager && state.target);

    if (!states.length) {
        return;
    }

    let resizeTimer = 0;
    const recalculate = () => states.forEach(recalculateHeightMatchedPostPager);
    const scheduleRecalculate = () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(recalculate, 120);
    };

    recalculate();
    window.addEventListener('load', recalculate);
    window.addEventListener('resize', scheduleRecalculate);

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(recalculate).catch(() => {});
    }

    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(scheduleRecalculate);

        states.forEach((state) => {
            observer.observe(state.target);
        });
    }
}

function initMatchedHeightTargets() {
    const targets = Array.from(document.querySelectorAll('[data-match-height-source]'));

    if (!targets.length) {
        return;
    }

    const applyHeights = () => {
        targets.forEach((target) => {
            const source = document.querySelector(target.dataset.matchHeightSource || '');

            target.style.height = '';

            if (!source) {
                return;
            }

            const sourceRect = source.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            const isStacked = Math.abs(sourceRect.top - targetRect.top) > 24;

            if (!isStacked && sourceRect.height > 0) {
                target.style.height = `${Math.ceil(sourceRect.height)}px`;
            }
        });
    };

    let resizeTimer = 0;
    const scheduleApply = () => {
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(applyHeights, 120);
    };

    applyHeights();
    window.addEventListener('load', applyHeights);
    window.addEventListener('resize', scheduleApply);

    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(applyHeights).catch(() => {});
    }

    if ('ResizeObserver' in window) {
        const observer = new ResizeObserver(scheduleApply);

        targets.forEach((target) => {
            const source = document.querySelector(target.dataset.matchHeightSource || '');

            if (source) {
                observer.observe(source);
            }
        });
    }
}

function initDashboardPagedPanels() {
    const panels = Array.from(document.querySelectorAll('[data-dashboard-paged-panel]'));

    panels.forEach((panel) => {
        const list = panel.querySelector('[data-dashboard-paged-list]');
        const pagination = panel.querySelector('[data-dashboard-paged-pagination]');

        if (!list || !pagination) {
            return;
        }

        const items = Array.from(list.children);
        const pageSize = Math.max(1, Number(panel.dataset.pageSize || 10));
        const totalPages = Math.ceil(items.length / pageSize);
        let currentPage = 0;

        const render = () => {
            items.forEach((item, index) => {
                const page = Math.floor(index / pageSize);
                item.hidden = page !== currentPage;
            });

            pagination.innerHTML = '';
            pagination.hidden = totalPages <= 1;

            if (totalPages <= 1) {
                window.dispatchEvent(new Event('resize'));
                return;
            }

            for (let index = 0; index < totalPages; index++) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = index === currentPage ? 'button-primary dashboard-panel-page' : 'button-secondary dashboard-panel-page';
                button.textContent = String(index + 1);
                button.setAttribute('aria-label', `Page ${index + 1}`);

                if (index === currentPage) {
                    button.setAttribute('aria-current', 'page');
                }

                button.addEventListener('click', () => {
                    currentPage = index;
                    render();
                });

                pagination.appendChild(button);
            }

            window.dispatchEvent(new Event('resize'));
        };

        render();
    });
}

function recalculateHeightMatchedPostPager(state) {
    const items = Array.from(state.list.children);

    if (!items.length) {
        return;
    }

    const panelRect = state.panel.getBoundingClientRect();
    const targetRect = state.target.getBoundingClientRect();
    const isStacked = Math.abs(panelRect.top - targetRect.top) > 24;

    if (isStacked || targetRect.height <= 0) {
        showAllHeightMatchedPosts(state, items);
        return;
    }

    const targetHeight = Math.max(targetRect.height, state.minHeight);

    items.forEach((item) => {
        item.hidden = false;
    });

    state.pager.classList.remove('pagination', 'is-visible');
    state.pager.innerHTML = '';

    const pagesWithoutPager = buildHeightMatchedPostPages(state, items, targetHeight, 0);
    const estimatedPagerHeight = pagesWithoutPager.length > 1 ? estimateHeightMatchedPagerHeight(state, pagesWithoutPager.length) : 0;
    const pages = buildHeightMatchedPostPages(state, items, targetHeight, estimatedPagerHeight);

    state.panel.style.minHeight = pages.length > 1 ? `${Math.ceil(targetHeight)}px` : '';

    state.pages = pages;
    state.page = Math.min(state.page, Math.max(0, pages.length - 1));

    renderHeightMatchedPostPage(state, items);
}

function buildHeightMatchedPostPages(state, items, targetHeight, pagerHeight) {
    const panelStyle = window.getComputedStyle(state.panel);
    const listTop = state.list.getBoundingClientRect().top - state.panel.getBoundingClientRect().top;
    const bottomPadding = parseFloat(panelStyle.paddingBottom) || 0;
    const availableHeight = Math.max(80, targetHeight - listTop - bottomPadding - pagerHeight);
    const listStyle = window.getComputedStyle(state.list);
    const rowGap = parseFloat(listStyle.rowGap || listStyle.gap) || 0;
    const pages = [];
    let currentPage = [];
    let currentHeight = 0;

    items.forEach((item) => {
        const itemStyle = window.getComputedStyle(item);
        const itemHeight = item.getBoundingClientRect().height
            + (parseFloat(itemStyle.marginTop) || 0)
            + (parseFloat(itemStyle.marginBottom) || 0);
        const extraGap = currentPage.length > 0 ? rowGap : 0;

        if (currentPage.length > 0 && currentHeight + extraGap + itemHeight > availableHeight) {
            pages.push(currentPage);
            currentPage = [];
            currentHeight = 0;
        }

        currentPage.push(item);
        currentHeight += (currentPage.length > 1 ? rowGap : 0) + itemHeight;
    });

    if (currentPage.length) {
        pages.push(currentPage);
    }

    return pages;
}

function estimateHeightMatchedPagerHeight(state, pageCount) {
    const previousHtml = state.pager.innerHTML;
    const previousDisplay = state.pager.style.display;

    state.pager.innerHTML = '';
    state.pager.classList.add('is-visible');
    state.pager.style.display = 'flex';

    for (let i = 0; i < pageCount; i++) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = i === 0 ? 'button-primary height-post-page' : 'button-secondary height-post-page';
        button.textContent = String(i + 1);
        state.pager.appendChild(button);
    }

    const height = Math.ceil(state.pager.getBoundingClientRect().height);

    state.pager.innerHTML = previousHtml;
    state.pager.style.display = previousDisplay;
    state.pager.classList.remove('is-visible');

    return height > 0 ? height : 54;
}

function renderHeightMatchedPostPage(state, items) {
    const currentItems = new Set(state.pages[state.page] || []);

    items.forEach((item) => {
        item.hidden = !currentItems.has(item);
    });

    state.pager.innerHTML = '';

    if (state.pages.length <= 1) {
        state.pager.classList.remove('pagination', 'is-visible');

        if (state.serverPagination) {
            state.serverPagination.hidden = false;
        }

        return;
    }

    if (!state.numericOnly && state.page > 0) {
        state.pager.appendChild(createHeightMatchedPagerButton('Précédent', state.page - 1, state, items));
    }

    state.pages.forEach((pageItems, index) => {
        state.pager.appendChild(createHeightMatchedPagerButton(String(index + 1), index, state, items));
    });

    if (!state.numericOnly && state.page < state.pages.length - 1) {
        state.pager.appendChild(createHeightMatchedPagerButton('Suivant', state.page + 1, state, items));
    }

    state.pager.classList.add('pagination', 'is-visible');

    if (state.serverPagination) {
        state.serverPagination.hidden = true;
    }
}

function createHeightMatchedPagerButton(label, pageIndex, state, items) {
    const isCurrent = pageIndex === state.page && /^\d+$/.test(label);
    const button = document.createElement('button');

    button.type = 'button';
    button.className = isCurrent
        ? 'button-primary pagination-current height-post-page'
        : 'button-secondary height-post-page';
    button.textContent = label;
    button.setAttribute('aria-label', /^\d+$/.test(label) ? `Afficher les ${state.itemLabel} page ${label}` : `${label} les ${state.itemLabel}`);

    if (isCurrent) {
        button.setAttribute('aria-current', 'page');
    }

    button.addEventListener('click', () => {
        state.page = pageIndex;
        renderHeightMatchedPostPage(state, items);
    });

    return button;
}

function showAllHeightMatchedPosts(state, items) {
    items.forEach((item) => {
        item.hidden = false;
    });

    state.panel.style.minHeight = '';
    state.pager.classList.remove('pagination', 'is-visible');
    state.pager.innerHTML = '';

    if (state.serverPagination) {
        state.serverPagination.hidden = false;
    }
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function nl2br(value) {
    return String(value).replace(/\n/g, '<br>');
}
