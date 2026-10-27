/**
 * HubSpot Mapper — Frontend (v2.2.0)
 *
 * Flujo:
 *  1. Bloquea LeadIn en todos los formularios configurados (data-hs-do-not-collect).
 *  2. Inyecta hidden mgmit_mapper_id para que PHP identifique el mapeo.
 *  3. Tras éxito server-side (PHP encola los datos en un transient + cookie):
 *     a. Formularios con redirect (SWPM, Woo, UM): wp_footer emite los datos y
 *        llama a window.__mgmitFireGhost() — definida aquí.
 *     b. Formularios AJAX en misma página (CF7): listener wpcf7mailsent →
 *        llama al endpoint mgmit_mapper_get_relay → dispara el ghost form.
 *  4. El ghost form es un <form> invisible con los campos renombrados (hs_prop)
 *     que LeadIn captura y envía a HubSpot → aparece en Marketing → Formularios.
 */
(function () {
    'use strict';

    var registry = (window.MGMIT_MAPPER_CONFIG && Array.isArray(window.MGMIT_MAPPER_CONFIG))
        ? window.MGMIT_MAPPER_CONFIG
        : [];

    // -------------------------------------------------------------------------
    // Bloqueo de LeadIn + inyección del id de mapeo
    // -------------------------------------------------------------------------

    function resolveForms(selector) {
        var nodes = document.querySelectorAll(selector);
        var forms = [];
        for (var i = 0; i < nodes.length; i++) {
            var el = nodes[i];
            if (el.tagName === 'FORM') {
                forms.push(el);
            } else {
                var inner = el.querySelector('form');
                if (inner) {
                    forms.push(inner);
                }
            }
        }
        return forms;
    }

    function setupForm(form, config) {
        form.setAttribute('data-hs-do-not-collect', 'true');
        if (config.do_not_collect || !config.id) {
            return;
        }
        if (!form.querySelector('input[name="mgmit_mapper_id"]')) {
            var hidden = document.createElement('input');
            hidden.type  = 'hidden';
            hidden.name  = 'mgmit_mapper_id';
            hidden.value = config.id;
            form.appendChild(hidden);
        }
    }

    function applyAll() {
        registry.forEach(function (config) {
            if (!config || !config.formId) { return; }
            resolveForms(config.formId).forEach(function (form) {
                setupForm(form, config);
            });
        });
    }

    if (registry.length) {
        applyAll();
        document.addEventListener('DOMContentLoaded', applyAll);

        if (typeof MutationObserver !== 'undefined' && document.body) {
            var scheduled = false;
            new MutationObserver(function () {
                if (scheduled) { return; }
                scheduled = true;
                window.setTimeout(function () { scheduled = false; applyAll(); }, 200);
            }).observe(document.body, { childList: true, subtree: true });
        }

        // Garantía de último recurso: inyectar en fase de captura del submit,
        // antes de que cualquier listener (CF7, AJAX) serialice el formulario.
        // Cubre el caso donde el script cargó diferido y applyAll() no llegó a tiempo.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || form.tagName !== 'FORM') { return; }
            registry.forEach(function (config) {
                if (!config || !config.formId || config.do_not_collect) { return; }
                resolveForms(config.formId).forEach(function (f) {
                    if (f === form) { setupForm(f, config); }
                });
            });
        }, true);
    }

    // -------------------------------------------------------------------------
    // Ghost Form Engine
    // -------------------------------------------------------------------------

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    /**
     * Crea un formulario invisible con los campos renombrados (hs_prop),
     * lo inyecta en el DOM y dispara un submit que LeadIn captura.
     *
     * @param {Object} data { form_name, fields: {hs_prop: value}, portal_id }
     */
    function fireGhostForm(data) {
        var formName = data.form_name || 'mgmit-ghost-form';
        var fields   = data.fields   || {};

        var form = document.createElement('form');
        form.id     = formName;
        form.name   = formName;
        form.method = 'POST';
        form.action = window.location.href;
        form.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;visibility:hidden;';

        // Campos mapeados
        Object.keys(fields).forEach(function (prop) {
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = prop;
            input.value = fields[prop];
            form.appendChild(input);
        });

        // hs_context: metadata que LeadIn usa para registrar la sesión
        var hutk = getCookie('hubspotutk');
        var ctx  = JSON.stringify({
            hutk:     hutk,
            pageUri:  window.location.href,
            pageName: document.title
        });
        var ctxInput = document.createElement('input');
        ctxInput.type  = 'hidden';
        ctxInput.name  = 'hs_context';
        ctxInput.value = ctx;
        form.appendChild(ctxInput);

        document.body.appendChild(form);

        // collectedforms.js (LeadIn) usa MutationObserver para detectar forms nuevos
        // y añade data-hs-cf-bound="true" cuando engancha su listener de submit.
        // Esperamos ese atributo antes de disparar el submit; máx. 3s de espera.
        var bindAttempts = 0;
        var bindCheck = window.setInterval(function () {
            bindAttempts++;
            var bound = form.getAttribute('data-hs-cf-bound') === 'true';
            if (bound || bindAttempts >= 30) {
                window.clearInterval(bindCheck);

                var evt;
                if (typeof Event === 'function') {
                    evt = new Event('submit', { bubbles: true, cancelable: true });
                } else {
                    evt = document.createEvent('Event');
                    evt.initEvent('submit', true, true);
                }
                form.dispatchEvent(evt);

                // Limpiar tras dar tiempo a LeadIn para procesar
                window.setTimeout(function () {
                    if (form.parentNode) {
                        form.parentNode.removeChild(form);
                    }
                }, 3000);
            }
        }, 100);
    }

    /**
     * Comprueba si el script de HubSpot está inicializado.
     * Si no lo está espera hasta 5s antes de disparar igualmente.
     */
    function fireWhenReady(data) {
        if (window._hsq && window._hsq.push) {
            fireGhostForm(data);
            return;
        }
        var attempts = 0;
        var interval = window.setInterval(function () {
            attempts++;
            if ((window._hsq && window._hsq.push) || attempts >= 50) {
                window.clearInterval(interval);
                fireGhostForm(data);
            }
        }, 100);
    }

    // Punto de entrada para el flujo redirect (llamado desde el inline script de wp_footer)
    window.__mgmitFireGhost = function (data) {
        fireWhenReady(data);
    };

    // Si los datos ya llegaron antes de que este script cargara (race condition teórica)
    if (window.__mgmitGhostData) {
        window.__mgmitFireGhost(window.__mgmitGhostData);
        window.__mgmitGhostData = null;
    }

    // -------------------------------------------------------------------------
    // Flujo AJAX — CF7 y otros formularios que no redirigen
    // -------------------------------------------------------------------------

    function fetchRelayAndFire() {
        if (!window.MGMIT_MAPPER_RELAY || !window.MGMIT_MAPPER_RELAY.ajaxurl) {
            return;
        }
        // Esperar a que la cookie esté disponible (la emite PHP en el response CF7)
        window.setTimeout(function () {
            if (!getCookie('mgmit_hs_relay')) {
                return;
            }
            var xhr = new XMLHttpRequest();
            xhr.open('POST', window.MGMIT_MAPPER_RELAY.ajaxurl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4 || xhr.status !== 200) { return; }
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success && res.data) {
                        fireWhenReady(res.data);
                    }
                } catch (e) {}
            };
            xhr.send('action=mgmit_mapper_get_relay');
        }, 200);
    }

    // CF7: escucha el evento de correo enviado con éxito
    document.addEventListener('wpcf7mailsent', fetchRelayAndFire, false);

    // Ultimate Member: si usa redirect, el flujo de cookie en wp_footer aplica.
    // Si UM no redirige (configuración custom), escuchar este evento:
    document.addEventListener('um_registration_complete', fetchRelayAndFire, false);

}());
