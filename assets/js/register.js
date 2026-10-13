/* Registration form (iosss_application.html): school drop-downs, date-of-birth limits and the application-window notice. */
(function () {
    'use strict';

    var selects = [1, 2, 3].map(function (n) { return document.getElementById('choice' + n); });
    var dob = document.getElementById('date_of_birth');
    var note = document.getElementById('window-note');
    var submit = document.getElementById('submitBtn');

    /* Date of birth: age 10 to 25, the same limits the server applies */
    function iso(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }
    var now = new Date();
    dob.max = iso(new Date(now.getFullYear() - 10, now.getMonth(), now.getDate()));
    dob.min = iso(new Date(now.getFullYear() - 26, now.getMonth(), now.getDate() + 1));

    /* Schools come from the database, so a school added by the administrator appears here at once */
    fetch('php/get_schools.php')
        .then(function (r) { if (!r.ok) { throw new Error('Request failed'); } return r.json(); })
        .then(function (schools) {
            selects.forEach(function (select) {
                select.replaceChildren(new Option('Select a school', ''));
                schools.forEach(function (s) { select.add(new Option(s.school_name, String(s.school_id))); });
            });
            selects[0].dispatchEvent(new Event('change'));   // lets app.js refresh the "different schools" rule
        })
        .catch(function () {
            selects.forEach(function (select) { select.replaceChildren(new Option('Schools unavailable', '')); });
            note.className = 'alert alert-error';
            note.textContent = 'The list of schools could not be loaded. Reload the page and try again.';
            note.hidden = false;
        });

    /* Tell people up front when applications are not open (the server refuses them anyway) */
    fetch('php/get_cycle.php')
        .then(function (r) { if (!r.ok) { throw new Error('Request failed'); } return r.json(); })
        .then(function (c) {
            var fmt = function (iso) {
                return new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'Africa/Nairobi' });
            };
            var text = '';
            if (c.status === 'open') {
                note.className = 'alert alert-info';
                text = 'Applications are open until ' + fmt(c.close_at) + '.';
            } else if (c.status === 'not_open') {
                note.className = 'alert alert-warning';
                text = 'Applications are not open yet. They open on ' + fmt(c.open_at) + '.';
            } else if (c.status === 'closed') {
                note.className = 'alert alert-warning';
                text = 'Applications closed on ' + fmt(c.close_at) + '. New registrations are no longer accepted.';
            } else {
                note.className = 'alert alert-warning';
                text = 'Application dates have not been announced yet.';
            }
            note.textContent = text;
            note.hidden = false;
            if (c.status !== 'open') { submit.disabled = true; }
        })
        .catch(function () { /* the server still enforces the window */ });
}());