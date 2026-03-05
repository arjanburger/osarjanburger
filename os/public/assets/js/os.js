/**
 * ArjanBurger OS - Dashboard JS
 */
(function () {
    'use strict';
    // Keyboard shortcut: Escape sluit modals
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.os-modal.open').forEach(m => m.classList.remove('open'));
        }
    });
})();
