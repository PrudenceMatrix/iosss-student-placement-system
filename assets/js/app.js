/* Shared behaviour for every page. No inline scripts anywhere, so the page security policy can stay strict. */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* ---------- Messages (toasts) ---------- */
    function dismiss(toast) {
        toast.classList.add('is-leaving');
        setTimeout(function () { toast.remove(); }, 260);
    }
    document.querySelectorAll('.toast').forEach(function (toast) {
        var close = toast.querySelector('.toast-close');
        if (close) { close.addEventListener('click', function () { dismiss(toast); }); }
        // Errors and one-time passwords stay until closed; everything else fades after 7 seconds.
        if (!toast.hasAttribute('data-sticky') && !toast.classList.contains('toast-error')) {
            setTimeout(function () { dismiss(toast); }, 7000);
        }
    });

    /* ---------- Confirm before a risky form: <form data-confirm="Are you sure?"> ---------- */
    var dialog = null;
    function ask(message, onYes) {
        if (!window.HTMLDialogElement) {
            if (window.confirm(message)) { onYes(); }
            return;
        }
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'confirm';
            dialog.innerHTML = '<form method="dialog"><p class="confirm-text"></p>' +
                '<div class="confirm-actions"><button value="no" class="btn btn-ghost">Cancel</button>' +
                '<button value="yes" class="btn btn-primary">Continue</button></div></form>';
            document.body.appendChild(dialog);
        }
        dialog.querySelector('.confirm-text').textContent = message;
        dialog.returnValue = '';
        dialog.onclose = function () { if (dialog.returnValue === 'yes') { onYes(); } };
        dialog.showModal();
    }
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute ? form.getAttribute('data-confirm') : null;
        if (!message || form.dataset.confirmed) { return; }
        event.preventDefault();
        ask(message, function () { form.dataset.confirmed = '1'; form.submit(); });
    });

    /* ---------- Buttons: print, go back ---------- */
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-print]')) { window.print(); return; }
        var back = event.target.closest('[data-back]');
        if (back && window.history.length > 1) {
            event.preventDefault();
            window.history.back();
        }
    });

    /* ---------- Show / hide password: <button data-toggle-password="#fieldId"> ---------- */
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.querySelector(button.getAttribute('data-toggle-password'));
            if (!input) { return; }
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    });

    /* ---------- Filter drop-downs that reload the list when changed ---------- */
    document.querySelectorAll('select[data-autosubmit]').forEach(function (select) {
        select.addEventListener('change', function () { select.form.submit(); });
    });

    /* ---------- Choose different schools: <fieldset data-unique-selects> ---------- */
    document.querySelectorAll('[data-unique-selects]').forEach(function (group) {
        var selects = Array.prototype.slice.call(group.querySelectorAll('select'));
        var error = group.querySelector('[data-unique-error]');

        function distinct() {
            var picked = selects.map(function (s) { return s.value; }).filter(Boolean);
            return new Set(picked).size === picked.length;
        }
        function refresh() {
            var values = selects.map(function (s) { return s.value; });
            selects.forEach(function (select, index) {
                Array.prototype.forEach.call(select.options, function (option) {
                    option.disabled = option.value !== '' && values.some(function (v, j) { return j !== index && v === option.value; });
                });
            });
            if (error) { error.textContent = distinct() ? '' : 'Choose a different school for each choice.'; }
        }
        selects.forEach(function (s) { s.addEventListener('change', refresh); });
        refresh();
        if (group.form) {
            group.form.addEventListener('submit', function (event) {
                if (!distinct()) { event.preventDefault(); selects[0].focus(); }
            });
        }
    });

    /* ---------- Numbers that count up: <span data-count="120"> ---------- */
    function countUp(el, target) {
        target = Number(target) || 0;
        if (reduceMotion || target < 2) { el.textContent = target.toLocaleString(); return; }
        var start = null, duration = 800;
        function frame(ts) {
            if (start === null) { start = ts; }
            var p = Math.min((ts - start) / duration, 1);
            el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))).toLocaleString();
            if (p < 1) { requestAnimationFrame(frame); }
        }
        requestAnimationFrame(frame);
    }
    document.querySelectorAll('[data-count]').forEach(function (el) { countUp(el, el.getAttribute('data-count')); });

    /* ---------- Fade sections in as they scroll into view: <section class="reveal"> ---------- */
    var reveals = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window && !reduceMotion) {
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { entry.target.classList.add('is-visible'); io.unobserve(entry.target); }
            });
        }, { threshold: 0.12 });
        reveals.forEach(function (el) { io.observe(el); });
    } else {
        reveals.forEach(function (el) { el.classList.add('is-visible'); });
    }

    window.IOSSS = { countUp: countUp, observeReveal: function (el) { el.classList.add('is-visible'); } };
}());