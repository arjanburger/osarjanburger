/* ========================================
   DE DOORBRAAK METHODE — Page JS
   Countdown, scroll animations, video, form
   ======================================== */

(function () {
    'use strict';

    // ── Countdown Timer ──────────────────────────
    const WEBINAR_DATE = new Date('2026-05-08T20:00:00+02:00');

    function updateCountdown() {
        const now = new Date();
        let diff = WEBINAR_DATE - now;
        if (diff < 0) diff = 0;

        const d = Math.floor(diff / 86400000);
        const h = Math.floor((diff % 86400000) / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        const s = Math.floor((diff % 60000) / 1000);

        const el = (id) => document.getElementById(id);
        const pad = (n) => String(n).padStart(2, '0');

        if (el('cdDays')) el('cdDays').textContent = pad(d);
        if (el('cdHours')) el('cdHours').textContent = pad(h);
        if (el('cdMins')) el('cdMins').textContent = pad(m);
        if (el('cdSecs')) el('cdSecs').textContent = pad(s);
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);

    // ── Scroll Reveal Animations ─────────────────
    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.1, rootMargin: '0px 0px -40px 0px' }
    );

    document.querySelectorAll('.animate-in').forEach((el) => {
        // Hero elements: make visible immediately
        if (el.closest('.hero')) {
            setTimeout(() => el.classList.add('visible'), 100);
        } else {
            observer.observe(el);
        }
    });

    // ── YouTube Player ───────────────────────────
    let player;

    window.onYouTubeIframeAPIReady = function () {
        player = new YT.Player('ytPlayer', {
            videoId: '0jA4PrLi49g',
            playerVars: {
                autoplay: 0,
                controls: 1,
                modestbranding: 1,
                rel: 0,
                showinfo: 0,
            },
            events: {
                onReady: function () {},
            },
        });
    };

    const overlay = document.getElementById('videoOverlay');
    const playBtn = document.getElementById('videoPlayBtn');

    function startVideo() {
        if (player && player.playVideo) {
            player.playVideo();
        }
        if (overlay) overlay.style.display = 'none';
    }

    if (overlay) overlay.addEventListener('click', startVideo);
    if (playBtn) playBtn.addEventListener('click', function (e) { e.stopPropagation(); startVideo(); });

    // ── Form Handling ────────────────────────────
    const form = document.getElementById('signup-form');
    const success = document.getElementById('formSuccess');

    if (form) {
        let formStartTime = 0;

        // Track form start
        form.addEventListener('focusin', function () {
            if (!formStartTime) formStartTime = Date.now();
        }, { once: true });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            // Honeypot check
            const hp = form.querySelector('input[name="website"]');
            if (hp && hp.value) return;

            // Time check (min 2 seconds)
            if (formStartTime && (Date.now() - formStartTime) < 2000) return;

            // Gather fields
            const data = {};
            new FormData(form).forEach((val, key) => {
                if (key !== 'website') data[key] = val;
            });

            // Submit via engine.js form tracking if available
            // The engine.js handles form submission tracking automatically
            // via data-flow-form attribute

            // Show success
            const ticket = document.querySelector('.ticket');
            if (ticket) ticket.style.display = 'none';
            if (success) success.style.display = 'block';

            // Scroll to success
            if (success) {
                success.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }

    // ── Smooth scroll for anchor links ───────────
    document.querySelectorAll('a[href^="#"]').forEach((a) => {
        a.addEventListener('click', function (e) {
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
})();
