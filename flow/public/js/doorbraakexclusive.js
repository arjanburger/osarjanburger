/* ========================================
   DE DOORBRAAK METHODE — Page JS
   Countdown, scroll animations, form
   ======================================== */

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // ── Countdown Timer ──────────────────────────
    var WEBINAR_DATE = new Date('2026-05-08T20:00:00+02:00');

    function updateCountdown() {
        var now = new Date();
        var diff = WEBINAR_DATE - now;
        if (diff < 0) diff = 0;

        var d = Math.floor(diff / 86400000);
        var h = Math.floor((diff % 86400000) / 3600000);
        var m = Math.floor((diff % 3600000) / 60000);
        var s = Math.floor((diff % 60000) / 1000);

        var pad = function (n) { return String(n).padStart(2, '0'); };
        var el = function (id) { return document.getElementById(id); };

        if (el('cdDays')) el('cdDays').textContent = pad(d);
        if (el('cdHours')) el('cdHours').textContent = pad(h);
        if (el('cdMins')) el('cdMins').textContent = pad(m);
        if (el('cdSecs')) el('cdSecs').textContent = pad(s);
    }

    updateCountdown();
    setInterval(updateCountdown, 1000);

    // ── Scroll Reveal Animations ─────────────────
    var observer = new IntersectionObserver(
        function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.1, rootMargin: '0px 0px -40px 0px' }
    );

    document.querySelectorAll('.animate-in').forEach(function (el) {
        if (el.closest('.hero')) {
            setTimeout(function () { el.classList.add('visible'); }, 100);
        } else {
            observer.observe(el);
        }
    });

    // ── Form Handling ────────────────────────────
    var form = document.getElementById('signup-form');
    var success = document.getElementById('formSuccess');

    if (form) {
        var formStartTime = 0;

        form.addEventListener('focusin', function () {
            if (!formStartTime) formStartTime = Date.now();
        }, { once: true });

        form.addEventListener('submit', function (e) {
            e.preventDefault();

            var hp = form.querySelector('input[name="website"]');
            if (hp && hp.value) return;

            if (formStartTime && (Date.now() - formStartTime) < 2000) return;

            var ticket = document.querySelector('.ticket');
            if (ticket) ticket.style.display = 'none';
            if (success) success.style.display = 'block';

            if (success) {
                success.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    }

    // ── Smooth scroll for anchor links ───────────
    document.querySelectorAll('a[href^="#"]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
});
