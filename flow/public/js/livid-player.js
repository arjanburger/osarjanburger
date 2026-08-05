/**
 * ArjanBurger Flow Player — Livid variant
 * Livid embed met custom overlay + engine.js tracking
 *
 * Livid draait de standaard Player.js v2.0 receiver (context "player.js"),
 * dus alles gaat via postMessage. Zie onderaan voor de volledige API.
 *
 * Gebruik:
 *   <div class="flow-livid" data-livid="VIDEO_SLUG"></div>
 *   <script src="/js/livid-player.js"></script>
 *
 * Optionele data-attributen:
 *   data-livid="SLUG"           (verplicht — de code uit /embed/<SLUG>)
 *   data-autoplay="false"       (default: true, muted autoplay)
 *   data-overlay-text-top="..."
 *   data-overlay-text-bottom="..."
 */
(function () {
    'use strict';

    var ORIGIN = 'https://livid.com';

    var containers = document.querySelectorAll('.flow-livid');
    if (!containers.length) return;

    // ── Embed URL bouwen ────────────────────────────────────
    // Livid gebruikt Vimeo-compatibele parameters (true/false of 1/0).
    // Alles wat de player rommelig maakt staat hier uit; alleen play/pause,
    // progressbar en volume blijven over.
    // LET OP: alle chrome-parameters hieronder werken alleen op een Pro-account.
    var CHROME_OFF = {
        title: 'false',          // titel-overlay bovenin
        livid_logo: 'false',     // Livid-logo in de balk
        custom_logo: 'false',
        share: 'false',          // deel-knop
        pip: 'false',            // picture-in-picture
        airplay: 'false',
        chromecast: 'false',
        cc: 'false',             // ondertiteling-knop
        speed: 'false',          // afspeelsnelheid
        quality_selector: 'false',
        fullscreen: 'false',
        keyboard: 'false',
        // Wél behouden:
        progress_bar: 'true',
        volume: 'true',
    };

    function embedUrl(videoId, opts) {
        var params = {
            autoplay: opts.autoplay ? 'true' : 'false',
            muted: opts.muted ? 'true' : 'false',
            loop: 'true',
            controls: opts.controls ? 'true' : 'false',
            playsinline: 'true',
            preload: 'auto',
            color: 'c8a55c',
        };
        Object.keys(CHROME_OFF).forEach(function (k) { params[k] = CHROME_OFF[k]; });

        var qs = Object.keys(params).map(function (k) {
            return k + '=' + encodeURIComponent(params[k]);
        }).join('&');
        return ORIGIN + '/embed/' + videoId + '?' + qs;
    }

    // ── Player.js client (postMessage naar de Livid iframe) ──
    function Client(iframe) {
        this.iframe = iframe;
        this.isReady = false;
        this.queue = [];
        this.listeners = {};
        this.readyCallbacks = [];
        this.pollTimer = null;
    }

    Client.prototype.post = function (payload) {
        var win = this.iframe.contentWindow;
        if (!win) return;
        payload.context = 'player.js';
        payload.version = '2.0';
        win.postMessage(JSON.stringify(payload), ORIGIN);
    };

    Client.prototype.send = function (method, value) {
        if (!this.isReady) {
            this.queue.push([method, value]);
            return;
        }
        this.post({ method: method, value: value });
    };

    // Vaste listener-id per event: de receiver negeert een dubbele subscriptie
    // met dezelfde id, dus opnieuw aanmelden kan zonder dubbele events.
    Client.prototype.subscribe = function (event) {
        this.post({ method: 'addEventListener', value: event, listener: 'flow-' + event });
    };

    Client.prototype.on = function (event, fn) {
        if (!this.listeners[event]) this.listeners[event] = [];
        this.listeners[event].push(fn);
        this.subscribe(event);
    };

    Client.prototype.onReady = function (fn) {
        if (this.isReady) return fn();
        this.readyCallbacks.push(fn);
    };

    // De receiver wordt pas aangemaakt in een React effect, dus een enkele
    // handshake kan te vroeg zijn. Blijf 'ready' aanvragen tot hij antwoordt.
    Client.prototype.handshake = function () {
        var self = this;
        var tries = 0;

        function ping() {
            if (self.isReady) return stop();
            if (++tries > 40) return stop();
            self.subscribe('ready');
        }

        function stop() {
            if (self.pollTimer) clearInterval(self.pollTimer);
            self.pollTimer = null;
        }

        ping();
        this.pollTimer = setInterval(ping, 300);
        this.iframe.addEventListener('load', ping);
    };

    Client.prototype.reset = function () {
        this.isReady = false;
        this.queue = [];
        this.readyCallbacks = [];
        this.handshake();
    };

    Client.prototype.receive = function (msg) {
        if (msg.event === 'ready' && !this.isReady) {
            if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
            this.isReady = true;

            var self = this;
            this.queue.splice(0).forEach(function (item) {
                self.post({ method: item[0], value: item[1] });
            });

            // Bestaande subscripties opnieuw aanmelden na een iframe-reload.
            Object.keys(this.listeners).forEach(function (event) { self.subscribe(event); });

            this.readyCallbacks.splice(0).forEach(function (fn) { fn(); });
        }

        var fns = this.listeners[msg.event];
        if (fns) fns.forEach(function (fn) { fn(msg.value); });
    };

    // ── Build DOM per container ─────────────────────────────
    var players = [];

    containers.forEach(function (container) {
        var videoId = container.getAttribute('data-livid');
        if (!videoId) return;

        var autoplay = container.getAttribute('data-autoplay') !== 'false';
        var overlayTextTop = container.getAttribute('data-overlay-text-top') || 'Video is al gestart';
        var overlayTextBottom = container.getAttribute('data-overlay-text-bottom') || 'Klik om te luisteren';

        container.innerHTML = '';
        var wrap = document.createElement('div');
        wrap.className = 'flow-player-wrap';

        var playerDiv = document.createElement('div');
        playerDiv.className = 'flow-player-inner';

        var iframe = document.createElement('iframe');
        iframe.setAttribute('allow', 'autoplay; fullscreen; picture-in-picture; encrypted-media; clipboard-write; web-share');
        iframe.setAttribute('allowfullscreen', '');
        iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        iframe.setAttribute('frameborder', '0');
        iframe.setAttribute('title', 'Doorbraak!');
        iframe.src = embedUrl(videoId, { autoplay: autoplay, muted: true, controls: false });
        playerDiv.appendChild(iframe);

        var overlay = document.createElement('div');
        overlay.className = 'flow-player-overlay';
        overlay.innerHTML =
            '<span class="flow-player-label-top">' + overlayTextTop + '</span>' +
            '<button class="flow-player-btn" type="button">' +
            '<svg class="flow-player-icon" width="48" height="48" viewBox="0 0 48 48" fill="none">' +
            '<polygon points="8,16 8,32 16,32 24,40 24,8 16,16" fill="white"/>' +
            '<line x1="4" y1="4" x2="44" y2="44" stroke="white" stroke-width="3" stroke-linecap="round"/>' +
            '<path d="M32 17.5a7 7 0 0 1 0 13" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none" class="flow-sound-wave flow-wave-1"/>' +
            '<path d="M36 12a13 13 0 0 1 0 24" stroke="white" stroke-width="2.5" stroke-linecap="round" fill="none" class="flow-sound-wave flow-wave-2"/>' +
            '</svg>' +
            '</button>' +
            '<span class="flow-player-label-bottom">' + overlayTextBottom + '</span>';
        playerDiv.appendChild(overlay);

        wrap.appendChild(playerDiv);
        container.appendChild(wrap);

        var cfg = {
            videoId: videoId,
            iframe: iframe,
            overlay: overlay,
            client: new Client(iframe),
        };
        players.push(cfg);

        cfg.client.handshake();
        cfg.client.onReady(function () {
            cfg.client.send('setLoop', true);
        });

        setupOverlay(cfg);
        setupTracking(cfg);
    });

    // ── Eén message-listener voor alle players ──────────────
    window.addEventListener('message', function (e) {
        if (e.origin !== ORIGIN) return;

        var msg = e.data;
        if (typeof msg === 'string') {
            try { msg = JSON.parse(msg); } catch (err) { return; }
        }
        if (!msg || msg.context !== 'player.js') return;

        players.forEach(function (cfg) {
            if (cfg.iframe.contentWindow === e.source) cfg.client.receive(msg);
        });
    });

    // ── Overlay: klik → controls + unmute + terug naar 0 ────
    function setupOverlay(cfg) {
        var overlay = cfg.overlay;
        var btn = overlay.querySelector('.flow-player-btn');

        function handleUnmute() {
            overlay.classList.add('hidden');

            // controls is alleen een URL-parameter, niet runtime schakelbaar →
            // iframe herladen met controls=true en muted=false.
            cfg.iframe.src = embedUrl(cfg.videoId, { autoplay: true, muted: false, controls: true });
            cfg.client.reset();

            cfg.client.onReady(function () {
                cfg.client.send('setLoop', true);
                cfg.client.send('setCurrentTime', 0);
                // LET OP: Livid's play-handler stuurt ook een mute-request mee,
                // dus unmute moet ná play — anders wordt hij meteen overschreven.
                cfg.client.send('play');
                setTimeout(function () {
                    cfg.client.send('unmute');
                    cfg.client.send('setVolume', 100);
                }, 250);
            });
        }

        overlay.addEventListener('click', handleUnmute);
        if (btn) btn.addEventListener('click', function (e) {
            e.stopPropagation();
            handleUnmute();
        });
    }

    // ── Tracking via FlowEngine ─────────────────────────────
    function setupTracking(cfg) {
        var milestones = {};
        var seconds = 0;
        var duration = 0;

        function track(event) {
            if (window.FlowEngine && window.FlowEngine.track) {
                window.FlowEngine.track('track/video', {
                    event: event,
                    video_id: cfg.videoId,
                    seconds_watched: Math.round(seconds),
                    duration: Math.round(duration),
                });
            }
        }

        cfg.client.on('play', function () { track('play'); });

        cfg.client.on('timeupdate', function (v) {
            if (!v) return;
            seconds = v.seconds || 0;
            duration = v.duration || duration;
            if (duration <= 0) return;

            var pct = Math.round((seconds / duration) * 100);
            [25, 50, 75, 100].forEach(function (m) {
                if (pct >= m && !milestones[m]) {
                    milestones[m] = true;
                    track(m === 100 ? 'complete' : 'progress_' + m);
                }
            });
        });

        // Vuurt niet bij loop=true, vandaar 'complete' ook via timeupdate.
        cfg.client.on('ended', function () {
            if (!milestones[100]) {
                milestones[100] = true;
                track('complete');
            }
        });
    }
})();
