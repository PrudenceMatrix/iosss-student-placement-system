/* Sign-in and sign-up pages: messages from the server (?error=, ?notice=) and the live password checklist. */
(function () {
    'use strict';

    var params = new URLSearchParams(window.location.search);
    var messages = {
        error: {
            invalid:  'The details you entered are not correct. Check them and try again.',
            locked:   'Too many failed attempts. Wait 15 minutes and try again.',
            required: 'Please sign in to open that page.',
            expired:  'You were signed out after a period of inactivity. Sign in again.'
        },
        notice: {
            created:   'Your account is ready. Sign in to complete your application.',
            signedout: 'You have signed out.'
        }
    };

    var box = document.getElementById('message');
    if (box) {
        var text = messages.error[params.get('error')] || '';
        var kind = 'error';
        if (!text && messages.notice[params.get('notice')]) { text = messages.notice[params.get('notice')]; kind = 'success'; }
        if (text) {
            box.className = 'alert alert-' + kind;
            box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
            box.textContent = text;
            box.hidden = false;
        }
    }

    /* Live password rules on the sign-up form */
    var pw = document.getElementById('password');
    var confirmPw = document.getElementById('password_confirm');
    var rules = document.querySelectorAll('[data-rule]');
    if (pw && rules.length) {
        var checks = {
            len:    function () { return pw.value.length >= 8; },
            letter: function () { return /[A-Za-z]/.test(pw.value); },
            digit:  function () { return /\d/.test(pw.value); },
            match:  function () { return pw.value !== '' && pw.value === (confirmPw ? confirmPw.value : ''); }
        };
        var update = function () {
            rules.forEach(function (li) {
                var ok = checks[li.getAttribute('data-rule')]();
                li.classList.toggle('is-ok', ok);
            });
        };
        pw.addEventListener('input', update);
        if (confirmPw) { confirmPw.addEventListener('input', update); }
        update();
    }
}());