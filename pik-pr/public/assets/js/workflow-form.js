/*
 * Editor tahap approval workflow: tambah/hapus tahap dan tampilkan field sesuai jenis approver.
 */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var container = document.querySelector('[data-steps]');
        if (!container) { return; }
        var template = document.getElementById('step-template');
        var addButton = document.querySelector('[data-add-step]');

        function renumber() {
            container.querySelectorAll('[data-step]').forEach(function (card, index) {
                var number = card.querySelector('[data-step-number]');
                if (number) { number.textContent = String(index + 1); }
                card.querySelectorAll('[data-name]').forEach(function (input) {
                    input.name = 'steps[' + index + '][' + input.getAttribute('data-name') + ']';
                });
            });
        }

        function sync(card) {
            var type = card.querySelector('[data-approver-type]');
            var required = card.querySelector('[data-required-select]');
            card.querySelectorAll('[data-when]').forEach(function (field) {
                field.classList.toggle('hidden', !type || field.getAttribute('data-when') !== type.value);
            });
            var conditional = card.querySelector('[data-when-conditional]');
            if (conditional && required) {
                conditional.classList.toggle('hidden', required.value !== '0');
            }
        }

        function bind(card) {
            card.querySelectorAll('[data-approver-type], [data-required-select]').forEach(function (select) {
                select.addEventListener('change', function () { sync(card); });
            });
            var remove = card.querySelector('[data-remove-step]');
            if (remove) {
                remove.addEventListener('click', function () {
                    if (container.querySelectorAll('[data-step]').length <= 1) {
                        window.alert('Workflow minimal memiliki satu tahap.');
                        return;
                    }
                    card.remove();
                    renumber();
                });
            }
            sync(card);
        }

        container.querySelectorAll('[data-step]').forEach(bind);

        if (addButton && template) {
            addButton.addEventListener('click', function () {
                var index = container.querySelectorAll('[data-step]').length;
                var wrapper = document.createElement('div');
                wrapper.innerHTML = template.innerHTML.split('__INDEX__').join(String(index));
                var card = wrapper.firstElementChild;
                container.appendChild(card);
                bind(card);
                renumber();
                var label = card.querySelector('input[type=text]');
                if (label) { label.focus(); }
            });
        }
    });
})();
