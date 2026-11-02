(function () {
    var STATUS_LABELS = {
        changed: 'Wordt gewijzigd',
        skipped: 'Wordt overgeslagen',
        suspect: 'Verdacht, wordt niet gewijzigd'
    };

    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (!window.confirm(form.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    var form = document.getElementById('jp-form');
    if (!form) {
        return;
    }

    var scanButton = document.getElementById('jp-scan');
    var submitButton = document.getElementById('jp-submit');
    var statusText = document.getElementById('jp-scan-status');
    var preview = document.getElementById('jp-preview');

    function selectedType() {
        return form.querySelector('input[name="type"]:checked').value;
    }

    function updateLabels() {
        form.querySelectorAll('label[data-link]').forEach(function (label) {
            label.textContent = label.dataset[selectedType()];
        });
    }

    // Een oude preview hoort niet meer bij gewijzigde invoer.
    function invalidate() {
        submitButton.disabled = true;
        preview.textContent = '';
        statusText.textContent = '';
    }

    form.querySelectorAll('input[name="type"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            updateLabels();
            invalidate();
        });
    });

    ['jp-old', 'jp-new'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', invalidate);
    });

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

    function notice(type, text) {
        var box = document.createElement('div');
        box.className = 'notice inline notice-' + type;
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
        var table = document.createElement('table');
        table.className = 'widefat striped';

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
        preview.appendChild(table);
    }

    function request(cursor) {
        var data = new URLSearchParams();
        data.append('action', 'jelmoo_planner_scan');
        data.append('_ajax_nonce', jelmooPlanner.nonce);
        data.append('type', selectedType());
        data.append('old_url', document.getElementById('jp-old').value);
        data.append('new_url', document.getElementById('jp-new').value);
        data.append('cursor', JSON.stringify(cursor));

        return fetch(jelmooPlanner.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data }).then(function (response) {
            return response.json();
        });
    }

    function finish(rows, warnings, file) {
        scanButton.disabled = false;
        statusText.textContent = '';

        warnings.forEach(function (warning) {
            notice('warning', warning);
        });

        if (file) {
            notice('info', file);
            submitButton.disabled = false;
            return;
        }

        var changes = rows.filter(function (row) {
            return row.status === 'changed';
        });

        if (changes.length === 0) {
            notice('warning', 'De oude URL is nergens gevonden op een plek die de plugin kan wijzigen. Inplannen kan, maar controleer of de URL klopt en waar de link staat.');
        } else {
            notice('info', changes.length + ' plek(ken) worden gewijzigd. Bij het uitvoeren wordt opnieuw gescand, dus latere bewerkingen tellen mee.');
        }

        if (rows.length) {
            renderRows(rows);
        }

        submitButton.disabled = false;
    }

    function step(cursor, rows, warnings) {
        request(cursor).then(function (result) {
            if (!result.success) {
                scanButton.disabled = false;
                statusText.textContent = '';
                notice('error', (result.data && result.data.message) || 'Scannen is mislukt.');
                return;
            }

            rows = rows.concat(result.data.rows);
            warnings = warnings.concat(result.data.warnings || []);

            if (result.data.done) {
                finish(rows, warnings, result.data.file);
            } else {
                step(result.data.cursor, rows, warnings);
            }
        }).catch(function () {
            scanButton.disabled = false;
            statusText.textContent = '';
            notice('error', 'Scannen is mislukt: geen geldig antwoord van de server.');
        });
    }

    scanButton.addEventListener('click', function () {
        invalidate();
        scanButton.disabled = true;
        statusText.textContent = 'Bezig met scannen...';
        step([], [], []);
    });

    updateLabels();
})();
