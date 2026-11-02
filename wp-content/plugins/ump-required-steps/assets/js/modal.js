/**
 * UMP Required Steps — modal bloqueante.
 *
 * El bloqueo real vive en el servidor (la modal se vuelve a pintar en cada carga
 * mientras el paso siga pendiente). Aquí solo se evita que el usuario la cierre:
 * sin botón de cierre, Esc y clic fuera no hacen nada.
 */
(function () {
    'use strict';

    var dialog = document.getElementById('umprs-modal');
    if (!dialog) { return; }

    document.body.classList.add('umprs-open');

    // Rastro del navegador hacia el servidor (se lee en Ajustes → Required Steps → "Ver estado y
    // envíos"): permite saber por qué un envío no sale cuando no hay log de PHP.
    var cfg = window.UMPRS || null;
    var submitted = false;
    var errorsSent = 0;
    function trace(event, detail) {
        if (!cfg || !window.FormData) { return; }
        var fd = new FormData();
        fd.append('action', 'umprs_client_log');
        fd.append('nonce', cfg.nonce);
        fd.append('event', event);
        fd.append('detail', String(detail || '').slice(0, 300));
        if (navigator.sendBeacon) {
            navigator.sendBeacon(cfg.ajaxurl, fd);
        } else if (window.fetch) {
            fetch(cfg.ajaxurl, { method: 'POST', body: fd, keepalive: true, credentials: 'same-origin' });
        }
    }
    trace('version_js', 'modal.js 1.4.7 cargado');

    // Fase de burbujeo: se ejecuta después del manejador de UMP, así se ve si hizo preventDefault().
    dialog.addEventListener('submit', function (e) {
        submitted = true;
        trace('submit', 'defaultPrevented=' + e.defaultPrevented);
    });
    var unloading = false;
    window.addEventListener('beforeunload', function () {
        unloading = true;
        if (submitted) { trace('beforeunload', 'navegando'); }
    });
    window.addEventListener('error', function (e) {
        if (submitted && errorsSent < 3) {
            errorsSent++;
            trace('js_error', e.message + ' @' + String(e.filename || '').split('/').pop() + ':' + e.lineno);
        }
    });
    // Tras la comprobación de UMP, lleva el primer aviso de error a la vista dentro de la modal
    // (el formulario es largo y el aviso puede quedar fuera de la pantalla).
    function revealFirstError() {
        var notice = dialog.querySelector('.ihc-register-notice');
        if (notice && notice.scrollIntoView) { notice.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }

    if (window.jQuery) {
        window.jQuery(document).ajaxComplete(function (ev, xhr, settings) {
            var data = settings && settings.data ? String(settings.data) : '';
            if (data.indexOf('ihc_ajax_forms_check_all_fields') !== -1) { window.setTimeout(revealFirstError, 50); }
        });

        // UMP, al validar bien, hace jQuery('.ihc-form-create-edit').submit() y confía en que
        // jQuery lance el envío nativo. Si algo en la página lo impide (otro plugin que cancele
        // el evento, scripts diferidos...), el formulario no sale. Si UMP da el visto bueno
        // (IhcProfileForm.must_submit == 1) y el navegador no ha empezado a navegar, se envía
        // de forma nativa.
        window.jQuery(dialog).on('submit', 'form', function () {
            var form = this;
            var ump  = window.IhcProfileForm;
            if (!ump || Number(ump.must_submit) !== 1) { return; }
            window.setTimeout(function () {
                if (unloading) { return; }
                trace('forzar_envio', 'UMP validó pero el navegador no envió: envío nativo');
                HTMLFormElement.prototype.submit.call(form);
            }, 400);
        });

        window.jQuery(document).ajaxComplete(function (ev, xhr, settings) {
            var data = settings && settings.data ? String(settings.data) : '';
            if (submitted && data.indexOf('ihc_ajax_forms_check_all_fields') !== -1) {
                trace('ajax_check', xhr.status + ' ' + String(xhr.responseText || '').replace(/\s+/g, ' '));
            }
        });
    }

    // El JS de UMP localiza los formularios por la clase .ihc-form-create-edit y, tras
    // validar, reenvía TODOS los que haya en la página. Si la página trae su propio
    // formulario de perfil, se pisarían con el de la modal. Mientras la modal está abierta
    // la página queda inerte, así que a los demás se les quita la clase antes de que UMP
    // inicie su script (en window.load).
    Array.prototype.forEach.call(document.querySelectorAll('.ihc-form-create-edit'), function (form) {
        if (!dialog.contains(form)) { form.classList.remove('ihc-form-create-edit'); }
    });

    if (typeof dialog.showModal !== 'function') {
        return; // Se queda el overlay de CSS (dialog[open]).
    }

    function openModal() {
        if (dialog.open) { dialog.removeAttribute('open'); }
        dialog.showModal();
    }

    // Esc: se cancela. Chrome cierra el diálogo si recibe Esc dos veces seguidas
    // sin interacción, por eso además se reabre en 'close'.
    dialog.addEventListener('cancel', function (e) { e.preventDefault(); });
    dialog.addEventListener('close', function () {
        if (document.body.contains(dialog)) { window.setTimeout(openModal, 0); }
    });

    // Clic en el backdrop (fuera del panel): no hace nada.
    // El texto de cada opción de UMP no es un <label>: se hace clicable la tarjeta entera.
    dialog.addEventListener('click', function (e) {
        e.stopPropagation();
        var option = e.target.closest('.iump-form-checkbox-wrapper > .iump-form-checkbox, .iump-form-radiobox-wrapper > .iump-form-radiobox');
        if (!option || e.target.tagName === 'INPUT') { return; }
        var input = option.querySelector('input');
        if (input && !input.disabled) { input.click(); }
    });

    openModal();
})();
