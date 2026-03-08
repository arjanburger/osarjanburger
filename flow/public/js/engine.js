/**
 * ArjanBurger Flow Engine v2
 * Koppel aan elke pagina: <script src="https://flow.arjanburger.com/js/engine.js" data-page="slug">
 *
 * Functies:
 * - Cross-domain visitor tracking (cookie + URL param + server merge)
 * - Pageview tracking
 * - Formulier handling (submit → OS API)
 * - Conversie tracking (CTA clicks)
 * - Scroll depth tracking
 * - Time on page tracking
 * - YouTube video tracking
 */
(function () {
    'use strict';

    const SCRIPT_TAG = document.currentScript;
    const PAGE_SLUG = SCRIPT_TAG?.getAttribute('data-page') || window.location.pathname.replace(/^\/|\/$/g, '') || 'home';
    const API_BASE = SCRIPT_TAG?.getAttribute('data-api')
        || (/^(localhost|127\.|192\.|10\.)/.test(window.location.hostname)
            ? window.location.origin + '/api'
            : 'https://os.arjanburger.com/api');
    const DEBUG = SCRIPT_TAG?.hasAttribute('data-debug');

    // ── Helpers ──────────────────────────────────────────────
    function log(...args) {
        if (DEBUG) console.log('[Flow]', ...args);
    }

    // ── Cookie helpers ──────────────────────────────────────
    function getCookie(name) {
        const match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function getRootDomain(hostname) {
        // localhost / IP → geen domein-cookie mogelijk
        if (/^(localhost|127\.|192\.|10\.|172\.)/.test(hostname) || !hostname.includes('.')) {
            return null;
        }
        const parts = hostname.split('.');
        // bijv. flow.arjanburger.com → arjanburger.com
        return parts.slice(-2).join('.');
    }

    function setVisitorCookie(id) {
        const root = getRootDomain(location.hostname);
        if (root) {
            document.cookie = `_fvid=${encodeURIComponent(id)};domain=.${root};path=/;max-age=63072000;SameSite=Lax${location.protocol === 'https:' ? ';Secure' : ''}`;
        }
        // Altijd ook lokale cookie als fallback
        document.cookie = `_fvid=${encodeURIComponent(id)};path=/;max-age=63072000;SameSite=Lax${location.protocol === 'https:' ? ';Secure' : ''}`;
    }

    // ── Visitor ID (cross-domain) ───────────────────────────
    let _visitorId = null;

    function generateVisitorId() {
        if (_visitorId) return _visitorId;

        const sources = [];

        // 1. Cookie (werkt cross-subdomain)
        const cookieId = getCookie('_fvid');
        if (cookieId) sources.push({ id: cookieId, source: 'cookie' });

        // 2. URL parameter (handoff van ander domein)
        const urlId = new URLSearchParams(location.search).get('_fvid');
        if (urlId) sources.push({ id: urlId, source: 'url_param' });

        // 3. localStorage (domein-specifiek)
        const localId = localStorage.getItem('flow_vid');
        if (localId) sources.push({ id: localId, source: 'localStorage' });

        // Kies canonical: cookie > url > localStorage > nieuw
        if (sources.length > 0) {
            _visitorId = sources[0].id;

            // Als er meerdere verschillende IDs zijn, stuur alias naar server
            const uniqueIds = [...new Set(sources.map(s => s.id))];
            if (uniqueIds.length > 1) {
                sendToApi('track/alias', {
                    canonical_id: _visitorId,
                    alias_ids: uniqueIds.filter(id => id !== _visitorId),
                });
            }
        } else {
            _visitorId = crypto.randomUUID?.() || Math.random().toString(36).slice(2) + Date.now().toString(36);
        }

        // Sla overal op
        localStorage.setItem('flow_vid', _visitorId);
        setVisitorCookie(_visitorId);

        // Schoon URL param op (zonder reload)
        if (urlId) {
            const url = new URL(location.href);
            url.searchParams.delete('_fvid');
            history.replaceState(null, '', url.toString());
        }

        log('Visitor ID:', _visitorId, 'sources:', sources);
        return _visitorId;
    }

    // ── Outbound link decoration ────────────────────────────
    function decorateOutboundLinks() {
        document.addEventListener('click', function (e) {
            const a = e.target.closest('a[href]');
            if (!a) return;

            try {
                const linkUrl = new URL(a.href, location.origin);
                // Alleen externe domeinen decoreren (niet hetzelfde domein/subdomein)
                if (linkUrl.hostname !== location.hostname && !linkUrl.hostname.endsWith('.' + getRootDomain(location.hostname))) {
                    linkUrl.searchParams.set('_fvid', generateVisitorId());
                    a.href = linkUrl.toString();
                    log('Decorated outbound link:', a.href);
                }
            } catch (err) {
                // Ongeldige URL, skip
            }
        });
    }

    function getUtmParams() {
        const params = new URLSearchParams(window.location.search);
        const utm = {};
        ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(key => {
            const val = params.get(key);
            if (val) utm[key] = val;
        });
        return Object.keys(utm).length ? utm : null;
    }

    function sendToApi(endpoint, data) {
        const payload = {
            page: PAGE_SLUG,
            visitor_id: generateVisitorId(),
            timestamp: new Date().toISOString(),
            url: window.location.href,
            referrer: document.referrer || null,
            ...data,
        };

        log(endpoint, payload);

        if (navigator.sendBeacon) {
            navigator.sendBeacon(
                `${API_BASE}/${endpoint}`,
                new Blob([JSON.stringify(payload)], { type: 'text/plain' })
            );
        } else {
            fetch(`${API_BASE}/${endpoint}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true,
            }).catch(() => {});
        }
    }

    // ── Fingerprint hash ───────────────────────────────────────
    function generateFingerprint() {
        const components = [
            screen.width + 'x' + screen.height,
            screen.colorDepth,
            new Date().getTimezoneOffset(),
            navigator.language,
            navigator.userAgentData?.platform || navigator.platform || '',
            navigator.hardwareConcurrency || 0,
            navigator.deviceMemory || 0,
            navigator.maxTouchPoints || 0,
        ];
        // Simple djb2 hash → hex string
        const str = components.join('|');
        let hash = 5381;
        for (let i = 0; i < str.length; i++) {
            hash = ((hash << 5) + hash + str.charCodeAt(i)) >>> 0;
        }
        return hash.toString(16).padStart(8, '0');
    }

    // ── Pageview tracking ────────────────────────────────────
    function trackPageview() {
        const utm = getUtmParams();
        sendToApi('track/pageview', {
            utm: utm,
            screen: `${screen.width}x${screen.height}`,
            viewport: `${window.innerWidth}x${window.innerHeight}`,
            user_agent: navigator.userAgent,
            language: navigator.language || '',
            platform: navigator.userAgentData?.platform || navigator.platform || '',
            fingerprint: generateFingerprint(),
        });
    }

    // ── Conversie tracking ───────────────────────────────────
    function trackConversions() {
        document.addEventListener('click', function (e) {
            const cta = e.target.closest('[data-flow-cta]');
            if (cta) {
                sendToApi('track/conversion', {
                    action: cta.getAttribute('data-flow-cta'),
                    label: cta.textContent.trim().slice(0, 100),
                });
            }
        });
    }

    // ── Formulier handling ───────────────────────────────────
    function handleForms() {
        document.querySelectorAll('form[data-flow-form]').forEach(form => {
            const formId = form.getAttribute('data-flow-form') || 'default';
            let started = false;
            let submitted = false;
            let progressTimer = null;
            const formStartTime = Date.now();

            // Honeypot: verborgen veld voor spam detectie
            const honeypot = document.createElement('input');
            honeypot.type = 'text';
            honeypot.name = '_flow_hp';
            honeypot.tabIndex = -1;
            honeypot.autocomplete = 'off';
            honeypot.style.cssText = 'position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;width:0;';
            form.appendChild(honeypot);

            function getFields() {
                const data = {};
                new FormData(form).forEach((val, key) => {
                    if (key === '_flow_hp') return;
                    data[key] = val;
                });
                return data;
            }

            function filledCount() {
                return Object.values(getFields()).filter(v => v && v.trim() !== '').length;
            }

            // Form start: eerste focus
            form.addEventListener('focusin', function () {
                if (started) return;
                started = true;

                sendToApi('track/form-interaction', {
                    form_id: formId,
                    event: 'start',
                    fields: {},
                    field_count: 0,
                    time_spent: 0,
                });
                log('Form start:', formId);

                // Progress: stuur tussentijds veldwaarden (elke 5s)
                progressTimer = setInterval(function () {
                    if (submitted) return;
                    const count = filledCount();
                    if (count === 0) return;

                    sendToApi('track/form-interaction', {
                        form_id: formId,
                        event: 'progress',
                        fields: getFields(),
                        field_count: count,
                        time_spent: Math.round((Date.now() - formStartTime) / 1000),
                    });
                    log('Form progress:', formId, count, 'velden');
                }, 5000);
            });

            // Abandon: pagina verlaten zonder submit
            window.addEventListener('beforeunload', function () {
                if (!started || submitted) return;
                if (progressTimer) clearInterval(progressTimer);

                sendToApi('track/form-interaction', {
                    form_id: formId,
                    event: 'abandon',
                    fields: getFields(),
                    field_count: filledCount(),
                    time_spent: Math.round((Date.now() - formStartTime) / 1000),
                });
                log('Form abandoned:', formId);
            });

            // Submit
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                if (progressTimer) clearInterval(progressTimer);

                // Anti-spam
                const timeSpent = Math.round((Date.now() - formStartTime) / 1000);
                if (honeypot.value !== '' || timeSpent < 2) {
                    log('Spam detected, skipping');
                    showSuccess(form);
                    return;
                }

                submitted = true;
                sendToApi('track/form', {
                    form_id: formId,
                    fields: getFields(),
                });
                showSuccess(form);
                log('Form submitted:', formId);
            });
        });
    }

    function showSuccess(form) {
        const successEl = form.querySelector('[data-flow-success]');
        if (successEl) {
            form.style.display = 'none';
            successEl.style.display = 'block';
        } else {
            form.innerHTML = `
                <div style="text-align:center;padding:2rem;">
                    <p style="font-size:1.25rem;font-weight:600;">Bedankt voor je aanmelding!</p>
                    <p style="opacity:0.7;margin-top:0.5rem;">We nemen snel contact op.</p>
                </div>
            `;
        }
    }

    // ── Scroll depth tracking ────────────────────────────────
    function trackScrollDepth() {
        const milestones = [25, 50, 75, 100];
        const tracked = new Set();

        function check() {
            const scrollTop = window.scrollY;
            const docHeight = document.documentElement.scrollHeight - window.innerHeight;
            if (docHeight <= 0) return;
            const pct = Math.round((scrollTop / docHeight) * 100);

            milestones.forEach(m => {
                if (pct >= m && !tracked.has(m)) {
                    tracked.add(m);
                    sendToApi('track/scroll', { depth: m });
                }
            });
        }

        window.addEventListener('scroll', check, { passive: true });
    }

    // ── Time on page tracking ────────────────────────────────
    function trackTimeOnPage() {
        const start = Date.now();
        window.addEventListener('beforeunload', function () {
            const seconds = Math.round((Date.now() - start) / 1000);
            sendToApi('track/time', { seconds: seconds });
        });
    }

    // ── YouTube video tracking ───────────────────────────────
    function trackYouTubeVideos() {
        // Zoek YouTube iframes op de pagina
        const iframes = document.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtube-nocookie.com"]');
        if (iframes.length === 0) return;

        // Zorg dat iframes enablejsapi=1 hebben
        iframes.forEach(iframe => {
            const src = new URL(iframe.src);
            if (!src.searchParams.has('enablejsapi')) {
                src.searchParams.set('enablejsapi', '1');
                iframe.src = src.toString();
            }
            if (!iframe.id) {
                iframe.id = 'flow-yt-' + Math.random().toString(36).slice(2, 8);
            }
        });

        // Laad YouTube IFrame API als die er nog niet is
        if (!window.YT || !window.YT.Player) {
            const tag = document.createElement('script');
            tag.src = 'https://www.youtube.com/iframe_api';
            document.head.appendChild(tag);
        }

        // Wacht tot API geladen is
        const oldCallback = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            if (oldCallback) oldCallback();
            initYouTubePlayers(iframes);
        };

        // Als YT al geladen is, init direct
        if (window.YT && window.YT.Player) {
            initYouTubePlayers(iframes);
        }
    }

    function initYouTubePlayers(iframes) {
        iframes.forEach(iframe => {
            const milestones = new Set();
            let duration = 0;
            let progressInterval = null;

            try {
                const player = new YT.Player(iframe.id, {
                    events: {
                        onReady: function (e) {
                            duration = e.target.getDuration() || 0;
                            log('YT player ready, duration:', duration);
                        },
                        onStateChange: function (e) {
                            const videoId = extractVideoId(iframe.src);
                            const currentTime = e.target.getCurrentTime() || 0;

                            if (e.data === YT.PlayerState.PLAYING) {
                                sendToApi('track/video', {
                                    event: 'play',
                                    video_id: videoId,
                                    seconds_watched: Math.round(currentTime),
                                    duration: Math.round(duration),
                                });

                                // Start voortgang bijhouden
                                if (progressInterval) clearInterval(progressInterval);
                                progressInterval = setInterval(function () {
                                    const ct = player.getCurrentTime() || 0;
                                    const d = player.getDuration() || duration;
                                    if (d <= 0) return;
                                    const pct = Math.round((ct / d) * 100);

                                    [25, 50, 75, 100].forEach(m => {
                                        if (pct >= m && !milestones.has(m)) {
                                            milestones.add(m);
                                            sendToApi('track/video', {
                                                event: 'progress_' + m,
                                                video_id: videoId,
                                                seconds_watched: Math.round(ct),
                                                duration: Math.round(d),
                                            });
                                        }
                                    });
                                }, 2000);
                            }

                            if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.ENDED) {
                                if (progressInterval) clearInterval(progressInterval);

                                if (e.data === YT.PlayerState.ENDED) {
                                    sendToApi('track/video', {
                                        event: 'complete',
                                        video_id: videoId,
                                        seconds_watched: Math.round(duration),
                                        duration: Math.round(duration),
                                    });
                                }
                            }
                        }
                    }
                });
            } catch (err) {
                log('YT player init error:', err);
            }
        });
    }

    function extractVideoId(url) {
        try {
            const u = new URL(url);
            // /embed/VIDEO_ID
            const match = u.pathname.match(/\/embed\/([^/?]+)/);
            return match ? match[1] : u.pathname.split('/').pop();
        } catch {
            return 'unknown';
        }
    }

    // ── UTM opslag ───────────────────────────────────────────
    function storeUtmParams() {
        const utm = getUtmParams();
        if (utm) {
            sessionStorage.setItem('flow_utm', JSON.stringify(utm));
            log('UTM stored:', utm);
        }
    }

    // ── Init ─────────────────────────────────────────────────
    function init() {
        log('Engine v2 loaded for page:', PAGE_SLUG);
        generateVisitorId(); // Init visitor ID + cookie + merge
        storeUtmParams();
        trackPageview();
        trackConversions();
        handleForms();
        trackScrollDepth();
        trackTimeOnPage();
        trackYouTubeVideos();
        decorateOutboundLinks();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
