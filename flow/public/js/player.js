/**
 * ArjanBurger Flow Player v1
 * YouTube embed met custom overlay + engine.js tracking
 *
 * Gebruik:
 *   <div class="flow-player" data-video="VIDEO_ID"></div>
 *   <script src="/js/player.js"></script>
 *
 * Optionele data-attributen:
 *   data-video="VIDEO_ID"       (verplicht)
 *   data-autoplay="false"       (default: true, muted autoplay)
 *   data-overlay-text="..."     (default: "Klik om af te spelen")
 */
(function () {
    'use strict';

    // ── Config ──────────────────────────────────────────────
    var containers = document.querySelectorAll('.flow-player');
    if (!containers.length) return;

    var players = [];

    // ── Build DOM per container ─────────────────────────────
    containers.forEach(function (container, index) {
        var videoId = container.getAttribute('data-video');
        if (!videoId) return;

        var autoplay = container.getAttribute('data-autoplay') !== 'false';
        var overlayText = container.getAttribute('data-overlay-text') || 'Klik om af te spelen';

        // Wrapper voor 16:9 aspect ratio
        container.innerHTML = '';
        var wrap = document.createElement('div');
        wrap.className = 'flow-player-wrap';

        var playerDiv = document.createElement('div');
        playerDiv.className = 'flow-player-inner';
        var ytDiv = document.createElement('div');
        ytDiv.id = 'flow-yt-' + index;
        playerDiv.appendChild(ytDiv);

        // Overlay
        var overlay = document.createElement('div');
        overlay.className = 'flow-player-overlay';
        overlay.innerHTML =
            '<button class="flow-player-btn" type="button">' +
            '<svg width="32" height="32" viewBox="0 0 24 24" fill="currentColor"><polygon points="5,3 19,12 5,21"/></svg>' +
            '</button>' +
            '<span class="flow-player-label">' + overlayText + '</span>';
        playerDiv.appendChild(overlay);

        wrap.appendChild(playerDiv);
        container.appendChild(wrap);

        players.push({
            index: index,
            videoId: videoId,
            autoplay: autoplay,
            overlay: overlay,
            ytDivId: ytDiv.id,
            player: null,
        });
    });

    // ── YouTube API laden ───────────────────────────────────
    if (!document.querySelector('script[src*="youtube.com/iframe_api"]')) {
        var tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
    }

    // ── Init players wanneer API klaar is ───────────────────
    var oldCallback = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = function () {
        if (oldCallback) oldCallback();
        initPlayers();
    };

    if (window.YT && window.YT.Player) {
        initPlayers();
    }

    function initPlayers() {
        players.forEach(function (cfg) {
            if (cfg.player) return;

            cfg.player = new YT.Player(cfg.ytDivId, {
                videoId: cfg.videoId,
                playerVars: {
                    autoplay: cfg.autoplay ? 1 : 0,
                    mute: cfg.autoplay ? 1 : 0,
                    controls: 0,
                    showinfo: 0,
                    rel: 0,
                    modestbranding: 1,
                    iv_load_policy: 3,
                    disablekb: 1,
                    fs: 0,
                    playsinline: 1,
                    loop: 1,
                    playlist: cfg.videoId,
                },
                events: {
                    onReady: function (e) {
                        if (cfg.autoplay) e.target.playVideo();
                        setupOverlay(cfg);
                        setupTracking(cfg);
                    },
                },
            });
        });
    }

    // ── Overlay: klik → unmute + play + controls ────────────
    function setupOverlay(cfg) {
        var overlay = cfg.overlay;
        var btn = overlay.querySelector('.flow-player-btn');

        function handleUnmute() {
            if (!cfg.player) return;

            overlay.classList.add('hidden');

            cfg.player.unMute();
            cfg.player.setVolume(100);
            cfg.player.seekTo(0, true);
            cfg.player.playVideo();

            // Controls aanzetten via src replace + re-unmute na load
            var iframe = cfg.player.getIframe();
            if (iframe) {
                var newSrc = iframe.src.replace('controls=0', 'controls=1');
                iframe.src = newSrc;
                iframe.addEventListener('load', function () {
                    setTimeout(function () {
                        cfg.player.unMute();
                        cfg.player.setVolume(100);
                        cfg.player.playVideo();
                    }, 300);
                }, { once: true });
            }
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
        var duration = 0;
        var progressInterval = null;

        function track(event, extraData) {
            var data = {
                event: event,
                video_id: cfg.videoId,
                seconds_watched: Math.round((cfg.player.getCurrentTime && cfg.player.getCurrentTime()) || 0),
                duration: Math.round(duration),
            };
            if (extraData) {
                for (var k in extraData) data[k] = extraData[k];
            }

            if (window.FlowEngine && window.FlowEngine.track) {
                window.FlowEngine.track('track/video', data);
            }
        }

        cfg.player.addEventListener('onStateChange', function (e) {
            duration = cfg.player.getDuration() || duration;

            if (e.data === YT.PlayerState.PLAYING) {
                track('play');

                if (progressInterval) clearInterval(progressInterval);
                progressInterval = setInterval(function () {
                    var ct = cfg.player.getCurrentTime() || 0;
                    var d = cfg.player.getDuration() || duration;
                    if (d <= 0) return;
                    var pct = Math.round((ct / d) * 100);

                    [25, 50, 75, 100].forEach(function (m) {
                        if (pct >= m && !milestones[m]) {
                            milestones[m] = true;
                            track('progress_' + m);
                        }
                    });
                }, 2000);
            }

            if (e.data === YT.PlayerState.PAUSED || e.data === YT.PlayerState.ENDED) {
                if (progressInterval) clearInterval(progressInterval);

                if (e.data === YT.PlayerState.ENDED) {
                    track('complete');
                }
            }
        });
    }
})();
