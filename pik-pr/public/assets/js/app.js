/*
 * Interaksi umum: menu mobile, tutup alert, konfirmasi aksi, dan dialog.
 * Semua logika bisnis tetap divalidasi di server; skrip ini hanya kenyamanan UI.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var body = document.body;
        var openButton = document.querySelector('[data-nav-open]');

        function setNav(open) {
            body.classList.toggle('nav-open', open);
            if (openButton) {
                openButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            }
        }

        if (openButton) {
            openButton.addEventListener('click', function () { setNav(true); });
        }
        document.querySelectorAll('[data-nav-close]').forEach(function (el) {
            el.addEventListener('click', function () { setNav(false); });
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') { setNav(false); }
        });

        // Tutup pesan flash
        document.querySelectorAll('[data-dismiss]').forEach(function (button) {
            button.addEventListener('click', function () {
                var alert = button.closest('.alert');
                if (alert) { alert.remove(); }
            });
        });

        // Konfirmasi sebelum submit form berisiko: <form data-confirm="Yakin?">
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (!window.confirm(form.getAttribute('data-confirm'))) {
                    event.preventDefault();
                }
            });
        });

        // Dialog: <button data-dialog-open="id">, <button data-dialog-close>
        document.querySelectorAll('[data-dialog-open]').forEach(function (button) {
            button.addEventListener('click', function () {
                var dialog = document.getElementById(button.getAttribute('data-dialog-open'));
                if (!dialog) { return; }
                if (typeof dialog.showModal === 'function') {
                    dialog.showModal();
                } else {
                    dialog.setAttribute('open', '');
                }
                var focusTarget = dialog.querySelector('textarea, input:not([type=hidden])');
                if (focusTarget) { focusTarget.focus(); }
            });
        });
        document.querySelectorAll('[data-dialog-close]').forEach(function (button) {
            button.addEventListener('click', function () {
                var dialog = button.closest('dialog');
                if (dialog) { dialog.close ? dialog.close() : dialog.removeAttribute('open'); }
            });
        });

        // Cegah submit ganda: tombol dinonaktifkan setelah form dikirim.
        document.querySelectorAll('form').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                if (event.defaultPrevented) { return; }
                if (form.dataset.submitting === '1') {
                    event.preventDefault();
                    return;
                }
                form.dataset.submitting = '1';
                window.setTimeout(function () { form.dataset.submitting = '0'; }, 4000);
            });
        });

        // Auto-submit filter select: <select data-autosubmit>
        document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
            select.addEventListener('change', function () {
                if (select.form) { select.form.submit(); }
            });
        });
    });
})();
