<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/markdown.php';

$footerContent = footer_content();
?>

<footer class="page-footer">
    <div class="container">
        <div class="muted markdown-content footer-content">
            <?= render_markdown($footerContent) ?>
            <p class="footer-contact-link">
                <a href="<?= e(url('about.php')) ?>">À propos</a>
                <a href="<?= e(url('messenger_login.php')) ?>">Accès direct à la messagerie</a>
                <a href="<?= e(url('contact.php')) ?>">Nous contacter</a>
            </p>
        </div>
    </div>
</footer>

<script>
(() => {
    document.querySelectorAll('[data-site-menu-toggle]').forEach((button) => {
        const menu = document.getElementById(button.dataset.siteMenuToggle || '');

        if (!menu) {
            return;
        }

        button.addEventListener('click', () => {
            const isOpen = menu.classList.toggle('is-site-menu-open');
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    document.querySelectorAll('form').forEach((form) => {
        const visibilitySelect = form.querySelector('select[name="visibility"]');
        const groupSelect = form.querySelector('select[name="group_id"]');

        if (!visibilitySelect || !groupSelect) {
            return;
        }

        const groupField = groupSelect.closest('[data-group-visibility-field]');
        const groupLabel = groupSelect.id
            ? Array.from(form.querySelectorAll('label')).find((label) => label.htmlFor === groupSelect.id)
            : null;
        const toggleGroupField = () => {
            const isGroupVisibility = visibilitySelect.value === 'group';

            if (groupField) {
                groupField.hidden = !isGroupVisibility;
            } else {
                if (groupLabel) {
                    groupLabel.hidden = !isGroupVisibility;
                }

                groupSelect.hidden = !isGroupVisibility;
            }

            groupSelect.disabled = !isGroupVisibility;
        };

        toggleGroupField();
        visibilitySelect.addEventListener('change', toggleGroupField);
    });

    const initDismissibleFlashes = (root = document) => {
        root.querySelectorAll('.flash-success, .flash-info').forEach((flash) => {
            if (flash.querySelector('[data-dismiss-flash]')) {
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
    };

    initDismissibleFlashes();

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-dismiss-flash]');

        if (!button) {
            return;
        }

        button.closest('.flash')?.remove();
    });

    const flashObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (!(node instanceof Element)) {
                    return;
                }

                if (node.matches('.flash-success, .flash-info')) {
                    initDismissibleFlashes(node.parentElement || document);
                    return;
                }

                initDismissibleFlashes(node);
            });
        });
    });

    flashObserver.observe(document.documentElement, {
        childList: true,
        subtree: true,
    });

    const closestMarkdownButton = (target) => {
        while (target && target !== document) {
            if (target.nodeType === 1 && target.matches('[data-md-action]')) {
                return target;
            }

            target = target.parentNode;
        }

        return null;
    };

    const markdownTargetForButton = (button) => {
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
    };

    const replaceMarkdownSelection = (textarea, replacement, selectStart, selectEnd) => {
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const before = textarea.value.substring(0, start);
        const after = textarea.value.substring(end);

        textarea.value = before + replacement + after;

        if (typeof selectStart === 'number' && typeof selectEnd === 'number') {
            textarea.selectionStart = start + selectStart;
            textarea.selectionEnd = start + selectEnd;
        } else {
            textarea.selectionStart = start + replacement.length;
            textarea.selectionEnd = start + replacement.length;
        }

        textarea.dispatchEvent(new Event('input', {bubbles: true}));
    };

    const wrapMarkdownSelection = (textarea, before, after, placeholder) => {
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const selected = textarea.value.substring(start, end) || placeholder;

        replaceMarkdownSelection(textarea, before + selected + after, before.length, before.length + selected.length);
    };

    const prefixMarkdownLine = (textarea, prefix) => {
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const value = textarea.value;
        const selected = value.substring(start, end);

        if (selected.indexOf('\n') !== -1) {
            replaceMarkdownSelection(textarea, selected.split('\n').map((line) => line.trim() !== '' ? prefix + line : line).join('\n'));
            return;
        }

        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        textarea.value = value.substring(0, lineStart) + prefix + value.substring(lineStart);
        textarea.selectionStart = start + prefix.length;
        textarea.selectionEnd = end + prefix.length;
        textarea.dispatchEvent(new Event('input', {bubbles: true}));
    };

    const applyMarkdownButton = (textarea, action) => {
        if (action === 'h1') prefixMarkdownLine(textarea, '# ');
        else if (action === 'h2') prefixMarkdownLine(textarea, '## ');
        else if (action === 'h3') prefixMarkdownLine(textarea, '### ');
        else if (action === 'bold') wrapMarkdownSelection(textarea, '**', '**', 'texte en gras');
        else if (action === 'italic') wrapMarkdownSelection(textarea, '*', '*', 'texte en italique');
        else if (action === 'inline-code') wrapMarkdownSelection(textarea, '`', '`', 'code');
        else if (action === 'link') {
            const start = textarea.selectionStart || 0;
            const end = textarea.selectionEnd || 0;
            const label = textarea.value.substring(start, end) || 'texte du lien';
            replaceMarkdownSelection(textarea, '[' + label + '](https://exemple.fr)', 1, 1 + label.length);
        } else if (action === 'list') {
            const start = textarea.selectionStart || 0;
            const end = textarea.selectionEnd || 0;
            const selected = textarea.value.substring(start, end);
            replaceMarkdownSelection(textarea, selected ? selected.split('\n').map((line) => line.trim() === '' ? '' : '- ' + line).join('\n') : '- Premier point\n- Deuxième point');
        } else if (action === 'table') {
            replaceMarkdownSelection(textarea, '| Colonne 1 | Colonne 2 |\n| --- | --- |\n| Texte | Texte |');
        } else if (action === 'code-block') {
            wrapMarkdownSelection(textarea, '```text\n', '\n```', 'texte ou bilan');
        } else if (action === 'math-inline') {
            wrapMarkdownSelection(textarea, '$', '$', '\\rightarrow');
        } else if (action === 'math-block') {
            wrapMarkdownSelection(textarea, '$$\n', '\n$$', 'C_1V_1 = C_2V_2');
        }
    };

    document.addEventListener('mousedown', (event) => {
        if (closestMarkdownButton(event.target)) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented) {
            return;
        }

        const button = closestMarkdownButton(event.target);

        if (!button) {
            return;
        }

        const textarea = markdownTargetForButton(button);

        if (!textarea) {
            return;
        }

        event.preventDefault();
        textarea.focus();
        applyMarkdownButton(textarea, button.dataset.mdAction || '');
    });
})();
</script>

<?php if (!empty($GLOBALS['markdown_requires_mathjax'])): ?>
    <script>
        if (!window.MathJax) {
            window.MathJax = {
                tex: {
                    inlineMath: [['\\(', '\\)'], ['$', '$']],
                    displayMath: [['$$', '$$']]
                },
                svg: {
                    fontCache: 'global'
                }
            };
        }

        if (!document.querySelector('script[src*="mathjax@3"]')) {
            const mathJaxScript = document.createElement('script');
            mathJaxScript.defer = true;
            mathJaxScript.src = 'https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-svg.js';
            document.head.appendChild(mathJaxScript);
        }
    </script>
<?php endif; ?>
