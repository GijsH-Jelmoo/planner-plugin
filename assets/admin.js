(function () {
    var STATUS_LABELS = {
        changed: 'Wordt gewijzigd',
        skipped: 'Wordt overgeslagen',
        suspect: 'Verdacht, wordt niet gewijzigd'
    };

    var MONTHS = ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
    var DAYS = ['zondag', 'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'];

    // De klok van de server telt, niet die van deze computer.
    var skew = jelmooPlanner.now * 1000 - Date.now();

    function now() {
        return Date.now() + skew;
    }

    var zoneFormat = new Intl.DateTimeFormat('en-US', {
        timeZone: jelmooPlanner.zone,
        hourCycle: 'h23',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        second: 'numeric'
    });

    function zoneParts(ms) {
        var parts = {};
        zoneFormat.formatToParts(new Date(ms)).forEach(function (part) {
            parts[part.type] = parseInt(part.value, 10);
        });
        return parts;
    }

    function zoneOffset(ms) {
        var p = zoneParts(ms);
        return Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second) - Math.floor(ms / 1000) * 1000;
    }

    /* Wat iemand invult is tijd in de Planner-zone, ongeacht waar deze computer staat. */
    function zoneToMs(value) {
        var match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(value);
        if (!match) {
            return null;
        }

        var guess = Date.UTC(+match[1], +match[2] - 1, +match[3], +match[4], +match[5]);
        var ms = guess - zoneOffset(guess);

        return guess - zoneOffset(ms);
    }

    function pad(number) {
        return number < 10 ? '0' + number : String(number);
    }

    function clock(ms) {
        var p = zoneParts(ms);
        return pad(p.hour) + ':' + pad(p.minute);
    }

    function longDate(ms) {
        var p = zoneParts(ms);
        var weekday = new Date(Date.UTC(p.year, p.month - 1, p.day)).getUTCDay();
        return DAYS[weekday] + ' ' + p.day + ' ' + MONTHS[p.month - 1] + ' ' + p.year;
    }

    function span(ms) {
        var minutes = Math.ceil(Math.abs(ms) / 60000);
        if (minutes < 1) {
            return 'minder dan een minuut';
        }

        var days = Math.floor(minutes / 1440);
        var hours = Math.floor((minutes % 1440) / 60);
        var rest = minutes % 60;

        if (days > 0) {
            return days + (days === 1 ? ' dag' : ' dagen') + (hours ? ' en ' + hours + ' uur' : '');
        }

        if (hours > 0) {
            return hours + ' uur' + (rest ? ' en ' + rest + (rest === 1 ? ' minuut' : ' minuten') : '');
        }

        return rest + (rest === 1 ? ' minuut' : ' minuten');
    }

    function tick() {
        document.querySelectorAll('[data-clock]').forEach(function (element) {
            element.textContent = clock(now());
        });

        document.querySelectorAll('[data-countdown]').forEach(function (element) {
            var left = parseInt(element.dataset.countdown, 10) * 1000 - now();
            var late = element.hasAttribute('data-late');

            if (left > 0) {
                element.textContent = 'over ' + span(left);
            } else {
                element.textContent = span(left) + (late ? ' te laat' : ' geleden');
            }

            element.classList.toggle('is-past', late && left <= 0);
        });
    }

    tick();
    setInterval(tick, 15000);

    /* Bevestigen met een eigen venster in plaats van window.confirm. */
    var dialog = document.getElementById('jp-dialog');

    document.querySelectorAll('form[data-confirm]').forEach(function (actionForm) {
        actionForm.addEventListener('submit', function (event) {
            if (actionForm.dataset.confirmed) {
                return;
            }

            event.preventDefault();
            document.getElementById('jp-dialog-text').textContent = actionForm.dataset.confirm;
            dialog.showModal();

            document.getElementById('jp-dialog-yes').onclick = function () {
                actionForm.dataset.confirmed = '1';
                dialog.close();
                actionForm.requestSubmit();
            };
        });
    });

    if (dialog) {
        document.getElementById('jp-dialog-no').addEventListener('click', function () {
            dialog.close();
        });
    }

    var form = document.getElementById('jp-form');
    if (!form) {
        return;
    }

    var scanButton = document.getElementById('jp-scan');
    var submitButton = document.getElementById('jp-submit');
    var submitHint = document.getElementById('jp-submit-hint');
    var statusText = document.getElementById('jp-scan-status');
    var preview = document.getElementById('jp-preview');
    var runInput = document.getElementById('jp-run');
    var scanned = false;

    function selectedType() {
        return form.querySelector('input[name="type"]:checked').value;
    }

    function updateLabels() {
        var type = selectedType();

        form.querySelectorAll('label[data-link]').forEach(function (label) {
            if (label.dataset[type]) {
                label.textContent = label.dataset[type];
            }
        });

        form.querySelectorAll('[data-for]').forEach(function (block) {
            block.hidden = block.dataset.for.split(' ').indexOf(type) === -1;
        });

        document.getElementById('jp-old').required = type !== 'show';
        document.getElementById('jp-new').required = type !== 'show';
        document.getElementById('jp-page').required = type === 'show';
        document.getElementById('jp-selector').required = type === 'show';
    }

    /* Inplannen kan pas na een scan en met een moment in de toekomst. */
    function updateMoment() {
        var ms = zoneToMs(runInput.value);
        var future = ms !== null && ms > now();

        document.getElementById('jp-live-time').textContent = ms === null ? '--:--' : clock(ms);
        document.getElementById('jp-live-date').textContent = ms === null ? 'Kies een datum en tijd' : longDate(ms);

        var count = document.getElementById('jp-live-count');
        count.textContent = ms === null ? '' : (future ? 'over ' + span(ms - now()) : 'Dit moment is al voorbij');
        count.classList.toggle('is-past', ms !== null && !future);

        submitButton.disabled = !(scanned && future);
        submitHint.hidden = scanned && future;
        submitHint.classList.toggle('is-bad', scanned && !future);
        submitHint.textContent = scanned ? 'Kies een moment in de toekomst.' : 'Scan eerst wat er verandert.';
    }

    // Een oude preview hoort niet meer bij gewijzigde invoer.
    function invalidate() {
        scanned = false;
        preview.textContent = '';
        statusText.textContent = '';
        updateMoment();
    }

    form.querySelectorAll('input[name="type"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            updateLabels();
            invalidate();
        });
    });

    ['jp-old', 'jp-new', 'jp-page', 'jp-selector', 'jp-hide', 'jp-display'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', invalidate);
    });

    runInput.addEventListener('input', updateMoment);
    setInterval(updateMoment, 15000);

    form.querySelectorAll('.jp-pick').forEach(function (button) {
        button.addEventListener('click', function () {
            var frame = wp.media({ title: 'Kies een bestand', multiple: false });
            frame.on('select', function () {
                var input = document.getElementById(button.dataset.target);
                input.value = frame.state().get('selection').first().toJSON().url;
                invalidate();
            });
            frame.open();
        });
    });

    function notice(tone, text) {
        var box = document.createElement('div');
        box.className = 'jp-callout jp-callout-' + tone;
        var paragraph = document.createElement('p');
        paragraph.textContent = text;
        box.appendChild(paragraph);
        preview.appendChild(box);
    }

    function cell(row, text) {
        var td = document.createElement('td');
        td.textContent = text;
        row.appendChild(td);
        return td;
    }

    function renderRows(rows) {
        var wrap = document.createElement('div');
        wrap.className = 'jp-table-wrap';
        var table = document.createElement('table');
        table.className = 'jp-table';

        var head = document.createElement('tr');
        ['Plek', 'Veld', 'Wat er gebeurt', 'Treffers', 'Toelichting'].forEach(function (title) {
            var th = document.createElement('th');
            th.textContent = title;
            head.appendChild(th);
        });

        var thead = document.createElement('thead');
        thead.appendChild(head);
        table.appendChild(thead);

        var body = document.createElement('tbody');
        rows.forEach(function (item) {
            var row = document.createElement('tr');
            var place = cell(row, '');

            if (item.editUrl) {
                var link = document.createElement('a');
                link.href = item.editUrl;
                link.textContent = item.label;
                place.appendChild(link);
            } else {
                place.textContent = item.label;
            }

            cell(row, '').appendChild(document.createElement('code')).textContent = item.detail;
            cell(row, STATUS_LABELS[item.status] || item.status);
            cell(row, item.count ? String(item.count) : '');
            cell(row, item.reason);
            body.appendChild(row);
        });
        table.appendChild(body);
        wrap.appendChild(table);
        preview.appendChild(wrap);
    }

    function request(cursor) {
        var data = new URLSearchParams();
        data.append('action', 'jelmoo_planner_scan');
        data.append('_ajax_nonce', jelmooPlanner.nonce);
        data.append('type', selectedType());
        data.append('old_url', document.getElementById('jp-old').value);
        data.append('new_url', document.getElementById('jp-new').value);
        data.append('page_url', document.getElementById('jp-page').value);
        data.append('selector', document.getElementById('jp-selector').value);
        data.append('hide_selector', document.getElementById('jp-hide').value);
        data.append('display_value', document.getElementById('jp-display').value);
        data.append('cursor', JSON.stringify(cursor));

        return fetch(jelmooPlanner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
            return response.json();
        });
    }

    function done() {
        scanButton.disabled = false;
        statusText.textContent = '';
    }

    function finish(rows, warnings, notes, file) {
        done();

        warnings.forEach(function (warning) {
            notice('warn', warning);
        });

        notes.forEach(function (note) {
            notice('good', note);
        });

        if (file !== undefined) {
            if (file) {
                notice('info', file);
            }
        } else {
            var changes = rows.filter(function (row) {
                return row.status === 'changed';
            });

            if (changes.length === 0) {
                notice('warn', 'De oude URL is nergens gevonden op een plek die de plugin kan wijzigen. Inplannen kan, maar controleer of de URL klopt en waar de link staat.');
            } else {
                notice('info', changes.length + ' plek(ken) worden gewijzigd. Bij het uitvoeren wordt opnieuw gescand, dus latere bewerkingen tellen mee.');
            }

            if (rows.length) {
                renderRows(rows);
            }
        }

        scanned = true;
        updateMoment();
    }

    function step(cursor, rows, warnings, notes) {
        request(cursor).then(function (result) {
            if (!result.success) {
                done();
                notice('bad', (result.data && result.data.message) || 'Scannen is mislukt.');
                return;
            }

            rows = rows.concat(result.data.rows);
            warnings = warnings.concat(result.data.warnings || []);
            notes = notes.concat(result.data.notes || []);

            if (result.data.done) {
                finish(rows, warnings, notes, result.data.file);
            } else {
                step(result.data.cursor, rows, warnings, notes);
            }
        }).catch(function () {
            done();
            notice('bad', 'Scannen is mislukt: geen geldig antwoord van de server.');
        });
    }

    scanButton.addEventListener('click', function () {
        invalidate();
        scanButton.disabled = true;

        var spinner = document.createElement('span');
        spinner.className = 'jp-spinner';
        statusText.appendChild(spinner);
        statusText.appendChild(document.createTextNode('Bezig met scannen...'));

        step([], [], [], []);
    });

    updateLabels();
    updateMoment();
})();
