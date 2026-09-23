(function () {
    'use strict';

    function init() {
        var form = document.getElementById('dac-form');
        if (!form) {
            return;
        }
        var message = document.getElementById('dac-message');
        var submit = form.querySelector('.dac-submit');
        var counters = document.querySelectorAll('.dac-counter-number');

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var data = new FormData(form);
            data.append('action', 'dac_upload');
            data.append('nonce', window.DAC_DATA && window.DAC_DATA.nonce ? window.DAC_DATA.nonce : '');

            message.textContent = 'Envoi en cours…';
            message.className = 'dac-message dac-loading';
            submit.disabled = true;

            fetch(window.DAC_DATA.ajax_url, {
                method: 'POST',
                credentials: 'same-origin',
                body: data
            })
                .then(function (response) {
                    return response.json().then(function (json) {
                        return { status: response.status, json: json };
                    });
                })
                .then(function (result) {
                    message.textContent = result.json.message || '';
                    message.className = 'dac-message ' + (result.json.success ? 'dac-success' : 'dac-error');
                    if (result.json.success) {
                        form.reset();
                        if (result.json.counters && counters.length >= 3) {
                            counters[0].textContent = result.json.counters.nouvelles;
                            counters[1].textContent = result.json.counters.poemes;
                            counters[2].textContent = result.json.counters.photos;
                        }
                    }
                })
                .catch(function () {
                    message.textContent = 'Erreur réseau. Merci de réessayer.';
                    message.className = 'dac-message dac-error';
                })
                .finally(function () {
                    submit.disabled = false;
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
