(function () {
    'use strict';

    function init() {
        var form = document.getElementById('clp-form');
        if (!form || typeof CLP_DATA === 'undefined') {
            return;
        }

        var toggle = document.getElementById('clp-toggle');
        var ticketInput = document.getElementById('clp-ticket');
        var emailInput = document.getElementById('clp-email');
        var verifyBtn = document.getElementById('clp-verify-btn');
        var verifyMsg = document.getElementById('clp-verify-message');
        var stepCat = document.getElementById('clp-step-cat');
        var stepFile = document.getElementById('clp-step-file');
        var typeRadios = form.querySelectorAll('input[name="clp-type"]');
        var subSelect = document.getElementById('clp-subcategory');
        var toFileBtn = document.getElementById('clp-to-file');
        var fileInput = document.getElementById('clp-file');
        var fileHint = document.getElementById('clp-file-hint');
        var depositMsg = document.getElementById('clp-deposit-message');
        var lockedSubcategory = '';

        if (toggle) {
            toggle.addEventListener('click', function () {
                form.classList.toggle('clp-hidden');
            });
        }

        function setMessage(el, text, ok) {
            el.textContent = text;
            el.classList.toggle('clp-ok', !!ok);
            el.classList.toggle('clp-err', !ok);
        }

        function currentType() {
            var checked = form.querySelector('input[name="clp-type"]:checked');
            return checked ? checked.value : 'texte';
        }

        function fillSubcategories() {
            var type = currentType();
            subSelect.innerHTML = '';
            CLP_DATA.subcategories.forEach(function (sub) {
                if (sub.type !== type) {
                    return;
                }
                var opt = document.createElement('option');
                opt.value = sub.key;
                opt.textContent = sub.label;
                subSelect.appendChild(opt);
            });
            if (lockedSubcategory !== '') {
                subSelect.value = lockedSubcategory;
                subSelect.disabled = true;
            } else {
                subSelect.disabled = false;
            }
            updateFileHint();
        }

        function updateFileHint() {
            var type = currentType();
            var exts = CLP_DATA.ext[type] || [];
            fileHint.textContent = 'Formats acceptés : ' + exts.join(', ') + ' — poids maximum ' + CLP_DATA.max_mb + ' Mo. Un seul fichier par dépôt.';
            fileInput.setAttribute('accept', exts.map(function (e) { return '.' + e; }).join(','));
        }

        typeRadios.forEach(function (radio) {
            radio.addEventListener('change', function () {
                if (lockedSubcategory !== '') {
                    return;
                }
                fillSubcategories();
            });
        });

        verifyBtn.addEventListener('click', function () {
            setMessage(verifyMsg, '', true);
            var fd = new FormData();
            fd.append('action', 'clp_verify');
            fd.append('nonce', CLP_DATA.nonce_verify);
            fd.append('ticket', ticketInput.value);
            fetch(CLP_DATA.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
                .then(function (res) {
                    var data = res.data || {};
                    if (data.success && data.valid) {
                        if (data.already_deposited && data.subcategory) {
                            lockedSubcategory = data.subcategory;
                        }
                        fillSubcategories();
                        setMessage(verifyMsg, 'Identifiant vérifié.', true);
                        stepCat.classList.remove('clp-hidden');
                    } else {
                        setMessage(verifyMsg, data.message || 'Identifiant invalide.', false);
                    }
                })
                .catch(function () {
                    setMessage(verifyMsg, 'Une erreur est survenue. Réessayez plus tard.', false);
                });
        });

        toFileBtn.addEventListener('click', function () {
            stepFile.classList.remove('clp-hidden');
        });

        var depositDetail = document.getElementById('clp-deposit-detail');

        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            setMessage(depositMsg, '', true);
            if (depositDetail) { depositDetail.textContent = ''; }
            if (!fileInput.files || fileInput.files.length === 0) {
                setMessage(depositMsg, 'Aucun fichier sélectionné.', false);
                return;
            }
            var fd = new FormData();
            fd.append('action', 'clp_deposit');
            fd.append('nonce', CLP_DATA.nonce_deposit);
            fd.append('ticket', ticketInput.value);
            fd.append('email', emailInput.value);
            fd.append('subcategory', subSelect.value);
            fd.append('file', fileInput.files[0]);
            var submitBtn = document.getElementById('clp-submit');
            submitBtn.disabled = true;
            fetch(CLP_DATA.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
                .then(function (res) {
                    var data = res.data || {};
                    if (data.success) {
                        setMessage(depositMsg, data.message || 'Votre contribution a bien été déposée. Merci !', true);
                        if (depositDetail && data.filename) {
                            depositDetail.textContent = 'Déposé sous le nom anonymisé : ' + data.filename + (data.size ? ' — ' + data.size : '');
                        }
                    } else {
                        setMessage(depositMsg, data.message || 'Le dépôt a été refusé.', false);
                    }
                })
                .catch(function () {
                    setMessage(depositMsg, 'Une erreur est survenue. Réessayez plus tard.', false);
                })
                .finally(function () {
                    submitBtn.disabled = false;
                });
        });

        fillSubcategories();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
