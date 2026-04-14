/**
 * Flow Edit Mode
 * Klik op een element met [data-edit] om inline te bewerken.
 * Opslaan op blur → POST /api/save-content → dev server schrijft naar src/content/{page}.json
 *
 * Alleen actief in dev. Bouwscript verwijdert dit bestand uit dist/.
 */
(function () {
    var editActive = false;
    var editables = document.querySelectorAll('[data-edit]');
    var toggle = document.getElementById('editToggle');
    var badge = null;
    var statusEl = null;

    if (toggle) toggle.style.display = 'flex';

    function getPageName() {
        // flow pagina's hebben hun naam als eerste path segment
        var path = window.location.pathname.replace(/\/$/, '');
        if (path === '' || path === '/') return 'home';
        return path.replace(/^\//, '').replace(/\/.*$/, '');
    }

    function enableEdit() {
        editActive = true;
        if (toggle) toggle.classList.add('active');

        badge = document.createElement('div');
        badge.style.cssText = 'position:fixed;bottom:16px;right:16px;background:#c8a55c;color:#111;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:600;font-family:-apple-system,sans-serif;z-index:99999;pointer-events:none;letter-spacing:0.05em;';
        badge.textContent = 'EDIT MODE';
        document.body.appendChild(badge);

        statusEl = document.createElement('div');
        statusEl.style.cssText = 'position:fixed;bottom:16px;left:16px;padding:6px 12px;border-radius:6px;font-size:11px;font-weight:500;font-family:-apple-system,sans-serif;z-index:99999;pointer-events:none;color:#aaa;';
        document.body.appendChild(statusEl);

        editables.forEach(function (el) {
            el.style.cursor = 'pointer';
        });
    }

    function disableEdit() {
        editActive = false;
        if (toggle) toggle.classList.remove('active');

        if (badge) { badge.remove(); badge = null; }
        if (statusEl) { statusEl.remove(); statusEl = null; }

        document.querySelectorAll('[contenteditable="true"]').forEach(function (el) {
            el.contentEditable = 'false';
            el.style.outline = 'none';
            el.style.background = '';
        });

        editables.forEach(function (el) {
            el.style.cursor = '';
            el.style.outline = 'none';
        });
    }

    if (toggle) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (editActive) disableEdit();
            else enableEdit();
        });
    }

    if (window.location.search.includes('edit=true')) enableEdit();

    editables.forEach(function (el) {
        el.style.transition = 'outline 0.2s, background 0.2s';

        el.addEventListener('mouseenter', function () {
            if (!editActive || el.isContentEditable) return;
            el.style.outline = '2px dashed rgba(200, 165, 92, 0.6)';
            el.style.outlineOffset = '4px';
        });

        el.addEventListener('mouseleave', function () {
            if (!el.isContentEditable) {
                el.style.outline = 'none';
            }
        });

        el.addEventListener('click', function (e) {
            if (!editActive) return;
            e.preventDefault();
            e.stopPropagation();
            if (el.isContentEditable) return;

            document.querySelectorAll('[contenteditable="true"]').forEach(function (other) {
                other.contentEditable = 'false';
                other.style.outline = 'none';
                other.style.background = '';
            });

            el.contentEditable = 'true';
            el.style.outline = '2px solid #c8a55c';
            el.style.outlineOffset = '4px';
            el.style.background = 'rgba(200, 165, 92, 0.08)';
            el.focus();
        });

        el.addEventListener('blur', function () {
            if (!el.isContentEditable) return;
            el.contentEditable = 'false';
            el.style.outline = 'none';
            el.style.background = '';

            var page = getPageName();
            var key = el.dataset.edit;
            var value = el.innerText.trim();

            if (statusEl) {
                statusEl.textContent = 'Opslaan...';
                statusEl.style.color = '#c8a55c';
            }

            fetch('/api/save-content', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ page: page, key: key, value: value })
            }).then(function (res) {
                if (res.ok && statusEl) {
                    statusEl.textContent = 'Opgeslagen';
                    statusEl.style.color = '#4ade80';
                    setTimeout(function () { if (statusEl) statusEl.textContent = ''; }, 2000);
                } else if (statusEl) {
                    statusEl.textContent = 'Fout';
                    statusEl.style.color = '#f87171';
                }
            }).catch(function () {
                if (statusEl) {
                    statusEl.textContent = 'Fout';
                    statusEl.style.color = '#f87171';
                }
            });
        });

        el.addEventListener('keydown', function (e) {
            if (!editActive) return;
            if (e.key === 'Enter' && !e.shiftKey) {
                var tag = el.tagName.toLowerCase();
                if (['h1', 'h2', 'h3', 'h4', 'span', 'a', 'p', 'strong', 'em'].includes(tag)) {
                    e.preventDefault();
                    el.blur();
                }
            }
            if (e.key === 'Escape') {
                el.contentEditable = 'false';
                el.style.outline = 'none';
                el.style.background = '';
            }
        });
    });
})();
