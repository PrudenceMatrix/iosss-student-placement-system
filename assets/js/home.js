/* Home page: application status, countdown, key dates, totals and schools, all read live from the server. */
(function () {
    'use strict';

    var $ = function (id) { return document.getElementById(id); };
    var TZ = 'Africa/Nairobi';

    function fmt(iso, withTime) {
        if (!iso) { return 'To be announced'; }
        var opts = { day: 'numeric', month: 'short', year: 'numeric', timeZone: TZ };
        if (withTime) { opts.hour = 'numeric'; opts.minute = '2-digit'; opts.hour12 = true; }
        return new Date(iso).toLocaleString('en-GB', opts);
    }

    function renderCycle(c) {
        var loadedAt = Date.now();
        var serverNow = Date.parse(c.server_time);
        var now = function () { return serverNow + (Date.now() - loadedAt); };   // server clock, not the visitor's

        var chip = $('cycle-chip');
        var title = $('cycle-title');
        var line = $('cycle-line');
        var cdBox = $('countdown');
        var target = null;

        if (c.status === 'open') {
            chip.className = 'chip chip-open'; chip.textContent = 'Applications are open';
            title.textContent = 'Closes ' + fmt(c.close_at, true); line.textContent = c.name; target = Date.parse(c.close_at);
        } else if (c.status === 'not_open') {
            chip.className = 'chip chip-soon'; chip.textContent = 'Applications open soon';
            title.textContent = 'Opens ' + fmt(c.open_at, true); line.textContent = c.name; target = Date.parse(c.open_at);
        } else if (c.status === 'closed') {
            chip.className = 'chip chip-closed'; chip.textContent = 'Applications are closed';
            title.textContent = c.published ? 'Results are out' : 'Closed ' + fmt(c.close_at, true);
            line.textContent = c.published ? 'Sign in to see your placement.' : (c.release_at ? 'Results expected ' + fmt(c.release_at, true) : c.name);
        } else {
            chip.className = 'chip'; chip.textContent = 'Dates to be announced';
            title.textContent = 'Check back soon'; line.textContent = c.name;
        }

        function tick() {
            if (!target) { cdBox.hidden = true; return; }
            var ms = Math.max(0, target - now());
            var d = Math.floor(ms / 86400000), h = Math.floor(ms / 3600000) % 24, m = Math.floor(ms / 60000) % 60;
            cdBox.hidden = false;
            $('cd-days').textContent = d; $('cd-hours').textContent = h; $('cd-mins').textContent = m;
        }
        tick();
        setInterval(tick, 30000);

        /* Key dates: done / current / upcoming */
        var t = now();
        var opened = c.open_at && Date.parse(c.open_at) <= t;
        var closed = c.close_at && Date.parse(c.close_at) <= t;
        var items = [
            { title: 'Applications open', when: fmt(c.open_at, true), state: opened ? 'is-done' : (c.open_at ? 'is-current' : '') },
            { title: 'Applications close', when: fmt(c.close_at, true), state: closed ? 'is-done' : (opened ? 'is-current' : '') },
            { title: 'Marks and selection', when: closed ? (c.selection_run ? 'Selection completed' : 'In progress') : 'After applications close', state: c.selection_run ? 'is-done' : (closed ? 'is-current' : '') },
            { title: 'Results released', when: c.published ? 'Published' : fmt(c.release_at, true), state: c.published ? 'is-done' : (c.selection_run ? 'is-current' : '') }
        ];
        var list = $('dates-list');
        list.textContent = '';
        items.forEach(function (it) {
            var li = document.createElement('li');
            if (it.state) { li.className = it.state; }
            var s = document.createElement('strong'); s.textContent = it.title;
            var w = document.createElement('span'); w.textContent = it.when;
            li.appendChild(s); li.appendChild(w); list.appendChild(li);
        });

        window.IOSSS.countUp($('stat-apps'), c.applications);
        window.IOSSS.countUp($('stat-schools'), c.schools);
        window.IOSSS.countUp($('stat-seats'), c.seats);

        // Apply button only makes sense while open; otherwise point to sign-in
        if (c.status !== 'open') {
            var apply = $('hero-apply');
            if (apply) { apply.textContent = c.published ? 'See my result' : 'Student login'; apply.setAttribute('href', 'login.html'); }
        }
    }

    function renderSchools(schools) {
        var grid = $('schools-grid');
        grid.textContent = '';
        schools.forEach(function (s) {
            var card = document.createElement('article');
            card.className = 'school-card';
            var mono = document.createElement('div'); mono.className = 'school-mono'; mono.textContent = s.school_name.charAt(0).toUpperCase();
            var h = document.createElement('h3'); h.textContent = s.school_name;
            var p = document.createElement('p');
            p.textContent = s.selected === null ? s.capacity + ' seats' : s.selected + ' of ' + s.capacity + ' seats filled';
            card.appendChild(mono); card.appendChild(h); card.appendChild(p);
            grid.appendChild(card);
        });
    }

    fetch('php/get_cycle.php').then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); }).then(renderCycle)
        .catch(function () { $('cycle-chip').textContent = 'Dates unavailable'; $('cycle-title').textContent = 'Please refresh'; });
    fetch('php/get_schools.php').then(function (r) { if (!r.ok) { throw new Error(); } return r.json(); }).then(renderSchools)
        .catch(function () { $('schools-grid').textContent = 'Schools could not be loaded.'; });
}());