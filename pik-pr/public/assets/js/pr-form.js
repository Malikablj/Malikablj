/*
 * Form PR: baris item dinamis dan pratinjau perhitungan.
 *
 * Perhitungan memakai BigInt (satuan sen) dengan aturan yang sama dengan server
 * (PrCalculator): pembulatan half-up ke 2 desimal. Nilai final tetap dihitung
 * ulang oleh server saat disimpan.
 */
(function () {
    'use strict';

    function toUnits(raw) {
        var value = String(raw == null ? '' : raw).trim().replace(/\s/g, '');
        if (value.indexOf(',') !== -1 && value.indexOf('.') === -1) { value = value.replace(',', '.'); }
        var match = /^(\d{1,15})(?:\.(\d{0,2})\d*)?$/.exec(value);
        if (!match) { return null; }
        var fraction = ((match[2] || '') + '00').slice(0, 2);
        return BigInt(match[1] + fraction);
    }

    function divideRoundHalfUp(numerator, denominator) {
        return (numerator + denominator / 2n) / denominator;
    }

    function formatRupiah(units) {
        var negative = units < 0n;
        var abs = negative ? -units : units;
        var integer = (abs / 100n).toString();
        var fraction = (abs % 100n).toString().padStart(2, '0');
        var grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return (negative ? '-' : '') + 'Rp' + grouped + (fraction !== '00' ? ',' + fraction : '');
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.querySelector('[data-pr-form]');
        if (!form) { return; }

        var tbody = form.querySelector('[data-item-rows]');
        var template = document.getElementById('item-row-template');
        var taxInput = form.querySelector('[data-tax-rate]');
        var master = [];
        try {
            master = JSON.parse(document.getElementById('master-items-data').textContent || '[]');
        } catch (e) {
            master = [];
        }
        var dirty = false;

        function rows() {
            return Array.prototype.slice.call(tbody.querySelectorAll('[data-item-row]'));
        }

        function field(row, name) {
            return row.querySelector('[data-field="' + name + '"]');
        }

        function recalc() {
            var subtotal = 0n;
            rows().forEach(function (row, index) {
                var number = row.querySelector('[data-row-number]');
                if (number) { number.textContent = String(index + 1); }
                var quantity = toUnits(field(row, 'quantity').value);
                var price = toUnits(field(row, 'unit_price').value);
                var line = (quantity !== null && price !== null) ? divideRoundHalfUp(quantity * price, 100n) : 0n;
                subtotal += line;
                row.querySelector('[data-line-total]').textContent = formatRupiah(line);
            });
            var rate = toUnits(taxInput ? taxInput.value : '0');
            if (rate === null) { rate = 0n; }
            var tax = divideRoundHalfUp(subtotal * rate, 10000n);
            form.querySelector('[data-subtotal]').textContent = formatRupiah(subtotal);
            form.querySelector('[data-tax-amount]').textContent = formatRupiah(tax);
            form.querySelector('[data-grand-total]').textContent = formatRupiah(subtotal + tax);
        }

        function applyMaster(row) {
            var name = field(row, 'name').value.trim().toLowerCase();
            var found = null;
            master.forEach(function (item) {
                if (String(item.name).toLowerCase() === name) { found = item; }
            });
            field(row, 'item_id').value = found ? String(found.id) : '';
            if (found) {
                var unit = field(row, 'unit');
                if (unit.value.trim() === '' || unit.value.trim() === 'pcs') { unit.value = found.unit; }
                var price = field(row, 'unit_price');
                if (price.value.trim() === '') { price.value = String(found.price).replace(/\.00$/, ''); }
                var quantity = field(row, 'quantity');
                if (quantity.value.trim() === '') { quantity.value = '1'; }
            }
            recalc();
        }

        function bindRow(row) {
            row.addEventListener('input', function () { dirty = true; recalc(); });
            field(row, 'name').addEventListener('change', function () { applyMaster(row); });
            row.querySelector('[data-remove-row]').addEventListener('click', function () {
                if (rows().length <= 1) {
                    row.querySelectorAll('input').forEach(function (input) {
                        input.value = input.getAttribute('data-field') === 'unit' ? 'pcs' : '';
                    });
                } else {
                    row.remove();
                }
                dirty = true;
                recalc();
            });
        }

        function nextKey() {
            var max = -1;
            rows().forEach(function (row) {
                var match = /items\[(\d+)\]/.exec(field(row, 'name').name);
                if (match) { max = Math.max(max, parseInt(match[1], 10)); }
            });
            return max + 1;
        }

        rows().forEach(bindRow);

        var addButton = form.querySelector('[data-add-row]');
        if (addButton && template) {
            addButton.addEventListener('click', function () {
                var holder = document.createElement('tbody');
                holder.innerHTML = template.innerHTML.split('__KEY__').join(String(nextKey()));
                var row = holder.querySelector('[data-item-row]');
                tbody.appendChild(row);
                bindRow(row);
                recalc();
                field(row, 'name').focus();
            });
        }

        if (taxInput) {
            taxInput.addEventListener('input', function () { dirty = true; recalc(); });
        }

        // Pratinjau format nomor PR mengikuti department & tanggal.
        var preview = form.querySelector('[data-number-preview]');
        var department = form.querySelector('[data-department]');
        var dateInput = form.querySelector('[data-pr-date]');
        function updatePreview() {
            if (!preview) { return; }
            var months = [];
            try { months = JSON.parse(preview.getAttribute('data-months') || '[]'); } catch (e) { months = []; }
            var option = department && department.selectedOptions ? department.selectedOptions[0] : null;
            var code = option && option.getAttribute('data-code') ? option.getAttribute('data-code') : 'XX';
            var parts = (dateInput && dateInput.value ? dateInput.value : '').split('-');
            if (parts.length !== 3) { return; }
            var month = months[parseInt(parts[1], 10) - 1] || parts[1];
            preview.value = preview.getAttribute('data-prefix') + '/' + month + '/' + parts[0] + '-' + code + 'PR###';
        }
        if (department) { department.addEventListener('change', updatePreview); }
        if (dateInput) { dateInput.addEventListener('change', updatePreview); }

        form.addEventListener('input', function () { dirty = true; });
        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (event) {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });

        recalc();
    });
})();
