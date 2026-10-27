/**
 * HubSpot Mapper — Admin UI
 */

(function ($) {
    'use strict';

    // Toggle sección HubSpot según checkbox do-not-collect
    function toggleCollectSection() {
        var checked  = $('#mgmit-do-not-collect').is(':checked');
        var $section = $('#mgmit-hs-collect-section');
        if (checked) {
            $section.css({ opacity: '0.4', 'pointer-events': 'none' });
            $section.find('input, button').prop('disabled', true);
        } else {
            $section.css({ opacity: '', 'pointer-events': '' });
            $section.find('input, button').prop('disabled', false);
        }
    }

    $('#mgmit-do-not-collect').on('change', toggleCollectSection);
    toggleCollectSection();

    $('#mgmit-mapping-form').on('submit', function (e) {
        e.preventDefault();

        var $btn         = $('#mgmit-save-btn');
        var doNotCollect = $('#mgmit-do-not-collect').is(':checked');
        $btn.prop('disabled', true).text(MGMIT_Admin.strings.saving);

        var fields = [];
        var staticFields = [];
        var valueMaps = [];
        if (!doNotCollect) {
            $('#mgmit-fields-body .mgmit-field-row').each(function () {
                var wp = $(this).find('.mgmit-wp-field').val().trim();
                var hs = $(this).find('.mgmit-hs-prop').val().trim();
                if (wp && hs) {
                    fields.push({ wp_field: wp, hs_prop: hs });
                    var pairs = getRowPairs($(this));
                    if (pairs.length) {
                        valueMaps.push({ wp_field: wp, pairs: pairs });
                    }
                }
            });
            var appendNonContactWarned = false;
            $('#mgmit-static-fields-body .mgmit-static-field-row').each(function () {
                var prop   = $(this).find('.mgmit-sf-prop').val().trim();
                var val    = $(this).find('.mgmit-sf-value').val().trim();
                var append = $(this).find('.mgmit-sf-append').is(':checked') ? 1 : 0;
                if (prop) {
                    if (append && prop.indexOf('-') !== -1 && prop.split('-')[0] !== 'contact') {
                        appendNonContactWarned = true;
                    }
                    staticFields.push({ hs_prop: prop, value: val, append: append });
                }
            });
            if (appendNonContactWarned) {
                showNotice('error', 'La opción "Concatenar" solo funciona con propiedades de contact. Los campos estáticos de otros objetos con "Concatenar" marcado se ignorarán.');
            }
        }

        $.post(MGMIT_Admin.ajaxurl, {
            action:           'mgmit_mapper_save_mapping',
            security:         MGMIT_Admin.nonce_save,
            id:               $('#mgmit-mapping-id').val(),
            name:             $('#mgmit-name').val().trim(),
            formId:           $('#mgmit-form-selector').val().trim(),
            hubspotFormName:  doNotCollect ? '' : $('#mgmit-hs-form-name').val().trim(),
            source:           doNotCollect ? '' : $('#mgmit-source').val(),
            email_field:      doNotCollect ? '' : $('#mgmit-email-field').val().trim(),
            fields:           fields,
            static_fields:    staticFields,
            value_maps:       valueMaps,
            do_not_collect:   doNotCollect ? 1 : 0,
        })
        .done(function (res) {
            if (res.success) {
                window.location.href = res.data.redirect;
            } else {
                showNotice('error', res.data || MGMIT_Admin.strings.error);
                $btn.prop('disabled', false).text('Guardar Cambios');
            }
        })
        .fail(function () {
            showNotice('error', MGMIT_Admin.strings.error);
            $btn.prop('disabled', false).text('Guardar Cambios');
        });
    });

    $('#mgmit-add-row').on('click', function () {
        var row = '<tr class="mgmit-field-row" data-value-map="{}">' +
            '<td><input type="text" class="mgmit-wp-field widefat code" placeholder="swpm-472"></td>' +
            '<td><input type="text" class="mgmit-hs-prop widefat code" placeholder="contact-firstname"></td>' +
            '<td style="text-align:center;white-space:nowrap;">' +
            '<button type="button" class="button button-small mgmit-toggle-values" title="Equivalencias de valores (select / radio / checkbox)">Valores</button> ' +
            '<button type="button" class="button button-small mgmit-remove-row" style="color:#b32d2e;border-color:#b32d2e;">&#10005;</button>' +
            '</td></tr>';
        $('#mgmit-fields-body').append(row);
    });

    $('#mgmit-fields-body').on('click', '.mgmit-remove-row', function () {
        var $row = $(this).closest('tr');
        $row.next('.mgmit-vm-row').remove();
        $row.remove();
    });

    // --- Equivalencias de valores (select / radio / checkbox) ---

    var optionsCache = {};

    function parseMap($row) {
        var pairs = [];
        var raw = $row.attr('data-value-map');
        try {
            var obj = JSON.parse(raw || '{}');
            Object.keys(obj).forEach(function (from) {
                pairs.push({ from: from, to: obj[from] });
            });
        } catch (e) { /* mapa corrupto: se ignora */ }
        return pairs;
    }

    // Pares actuales de una fila: desde el panel si está montado, si no desde el atributo guardado.
    function getRowPairs($row) {
        var $panel = $row.next('.mgmit-vm-row');
        if (!$panel.length || !$panel.data('options')) {
            return parseMap($row);
        }
        var pairs = [];
        $panel.find('.mgmit-vm-pair').each(function () {
            var from = $(this).find('.mgmit-vm-from').val().trim();
            var to   = $(this).find('.mgmit-vm-to').val();
            if (from && to) {
                pairs.push({ from: from, to: to });
            }
        });
        return pairs;
    }

    function updateBadge($row) {
        var n = getRowPairs($row).length;
        $row.find('.mgmit-toggle-values').text(n ? 'Valores (' + n + ')' : 'Valores');
    }

    function optionSelect(options, selected) {
        var $sel = $('<select class="mgmit-vm-to widefat"></select>');
        $sel.append($('<option value="">— Selecciona valor HubSpot —</option>'));
        var found = false;
        options.forEach(function (o) {
            var $o = $('<option></option>').val(o.value).text(o.label + (o.label !== o.value ? '  [' + o.value + ']' : ''));
            if (o.value === selected) { $o.prop('selected', true); found = true; }
            $sel.append($o);
        });
        if (selected && !found) {
            $sel.append($('<option></option>').val(selected).text(selected + ' (no existe en HubSpot)').prop('selected', true));
        }
        return $sel;
    }

    function addPairRow($panel, options, from, to) {
        var $tr = $('<tr class="mgmit-vm-pair"></tr>');
        $tr.append($('<td></td>').append($('<input type="text" class="mgmit-vm-from widefat" placeholder="Texto tal como aparece en el formulario">').val(from || '')));
        $tr.append($('<td></td>').append(optionSelect(options, to || '')));
        $tr.append($('<td style="text-align:center;"><button type="button" class="button button-small mgmit-vm-remove" style="color:#b32d2e;border-color:#b32d2e;">&#10005;</button></td>'));
        $panel.find('.mgmit-vm-body').append($tr);
    }

    function loadOptions(prop, cb) {
        if (optionsCache[prop]) {
            cb(null, optionsCache[prop]);
            return;
        }
        $.post(MGMIT_Admin.ajaxurl, {
            action:   'mgmit_mapper_get_options',
            security: MGMIT_Admin.nonce_options,
            prop:     prop,
        })
        .done(function (res) {
            if (res.success) {
                optionsCache[prop] = res.data.options;
                cb(null, res.data.options);
            } else {
                cb(res.data || MGMIT_Admin.strings.error);
            }
        })
        .fail(function () { cb(MGMIT_Admin.strings.error); });
    }

    function buildPanel($row, prop) {
        var pairs = getRowPairs($row);
        $row.next('.mgmit-vm-row').remove();

        var $panel = $(
            '<tr class="mgmit-vm-row"><td colspan="3" style="background:#f6f7f7;">' +
            '<p class="description mgmit-vm-msg" style="margin:4px 0 8px;"></p>' +
            '<table class="widefat" style="max-width:100%;"><thead><tr>' +
            '<th style="width:45%;">Texto del formulario</th><th style="width:48%;">Valor en HubSpot</th><th style="width:7%;"></th>' +
            '</tr></thead><tbody class="mgmit-vm-body"></tbody></table>' +
            '<p style="margin:8px 0 4px;"><button type="button" class="button button-small mgmit-vm-add">&#43; Añadir equivalencia</button></p>' +
            '</td></tr>'
        );
        $panel.attr('data-prop', prop);
        $row.after($panel);

        var $msg = $panel.find('.mgmit-vm-msg').text('Cargando opciones de HubSpot…');
        $panel.find('.mgmit-vm-add').prop('disabled', true);

        loadOptions(prop, function (err, options) {
            if (err) {
                $msg.css('color', '#b32d2e').text(err);
                return;
            }
            $panel.data('options', options);
            $msg.text('Escribe el texto del formulario y elige el valor de HubSpot al que corresponde. Lo que no esté aquí se intenta emparejar automáticamente (ignorando acentos y mayúsculas).');
            if (!pairs.length) { pairs.push({ from: '', to: '' }); }
            pairs.forEach(function (p) { addPairRow($panel, options, p.from, p.to); });
            $panel.find('.mgmit-vm-add').prop('disabled', false);
        });
    }

    $('#mgmit-fields-body').on('click', '.mgmit-toggle-values', function () {
        var $row  = $(this).closest('tr');
        var prop  = $row.find('.mgmit-hs-prop').val().trim();
        var $next = $row.next('.mgmit-vm-row');

        if ($next.length && $next.is(':visible')) {
            updateBadge($row);
            $next.hide();
            return;
        }
        if (!prop) {
            showNotice('error', 'Indica primero la propiedad HubSpot de este campo.');
            return;
        }
        if ($next.length && $next.attr('data-prop') === prop) {
            $next.show();
            return;
        }
        buildPanel($row, prop);
    });

    $('#mgmit-fields-body').on('click', '.mgmit-vm-add', function () {
        var $panel = $(this).closest('.mgmit-vm-row');
        addPairRow($panel, $panel.data('options') || [], '', '');
    });

    $('#mgmit-fields-body').on('click', '.mgmit-vm-remove', function () {
        var $panel = $(this).closest('.mgmit-vm-row');
        $(this).closest('tr').remove();
        updateBadge($panel.prev('.mgmit-field-row'));
    });

    $('#mgmit-fields-body').on('change', '.mgmit-vm-to, .mgmit-vm-from', function () {
        updateBadge($(this).closest('.mgmit-vm-row').prev('.mgmit-field-row'));
    });

    $('#mgmit-fields-body .mgmit-field-row').each(function () { updateBadge($(this)); });

    $('#mgmit-add-static-row').on('click', function () {
        var row = '<tr class="mgmit-static-field-row">' +
            '<td><input type="text" class="mgmit-sf-prop widefat code" placeholder="deal-dealname"></td>' +
            '<td><input type="text" class="mgmit-sf-value widefat code" placeholder="SWPM"></td>' +
            '<td style="text-align:center;"><input type="checkbox" class="mgmit-sf-append" value="1" title="Añadir al valor existente en lugar de sobreescribir"></td>' +
            '<td style="text-align:center;">' +
            '<button type="button" class="button button-small mgmit-remove-static-row" style="color:#b32d2e;border-color:#b32d2e;">&#10005;</button>' +
            '</td></tr>';
        $('#mgmit-static-fields-body').append(row);
    });

    $('#mgmit-static-fields-body').on('click', '.mgmit-remove-static-row', function () {
        $(this).closest('tr').remove();
    });

    $(document).on('click', '.mgmit-delete-mapping', function () {
        if (!confirm(MGMIT_Admin.strings.confirm_delete)) return;

        var $btn = $(this);
        var id   = $btn.data('id');
        $btn.prop('disabled', true).text(MGMIT_Admin.strings.deleting);

        $.post(MGMIT_Admin.ajaxurl, {
            action:   'mgmit_mapper_delete_mapping',
            security: MGMIT_Admin.nonce_delete,
            id:       id,
        })
        .done(function (res) {
            if (res.success) {
                window.location.href = res.data.redirect;
            } else {
                alert(res.data || MGMIT_Admin.strings.error);
                $btn.prop('disabled', false).text('Eliminar');
            }
        })
        .fail(function () {
            alert(MGMIT_Admin.strings.error);
            $btn.prop('disabled', false).text('Eliminar');
        });
    });

    $(document).on('click', '.mgmit-duplicate-mapping', function () {
        var $btn = $(this);
        var id   = $btn.data('id');
        var originalText = $btn.text();
        $btn.prop('disabled', true).text('Duplicando...');

        $.post(MGMIT_Admin.ajaxurl, {
            action:   'mgmit_mapper_duplicate_mapping',
            security: MGMIT_Admin.nonce_duplicate,
            id:       id,
        })
        .done(function (res) {
            if (res.success) {
                window.location.href = res.data.redirect;
            } else {
                alert(res.data || MGMIT_Admin.strings.error);
                $btn.prop('disabled', false).text(originalText);
            }
        })
        .fail(function () {
            alert(MGMIT_Admin.strings.error);
            $btn.prop('disabled', false).text(originalText);
        });
    });

    function showNotice(type, msg) {
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        $('#mgmit-save-notice')
            .removeClass('notice-error notice-success')
            .addClass('notice ' + cls)
            .html('<p>' + msg + '</p>')
            .show();
    }

    // --- Credenciales HubSpot (vista de lista) ---
    $('#mgmit-save-creds').on('click', function () {
        var $btn      = $(this);
        var token     = $('#mgmit-access-token').val().trim();
        var portalId  = $('#mgmit-portal-id').val().trim();

        if (!token && !portalId) {
            showCredsNotice('error', 'Introduce el Portal ID y/o el Access Token.');
            return;
        }

        $btn.prop('disabled', true).text(MGMIT_Admin.strings.saving);

        $.post(MGMIT_Admin.ajaxurl, {
            action:       'mgmit_mapper_save_credentials',
            security:     MGMIT_Admin.nonce_creds,
            access_token: token,
            portal_id:    portalId,
        })
        .done(function (res) {
            if (res.success) {
                showCredsNotice('success', (res.data && res.data.message) || 'Guardado.');
                $('#mgmit-access-token').val('');
            } else {
                showCredsNotice('error', res.data || MGMIT_Admin.strings.error);
            }
            $btn.prop('disabled', false).text('Guardar Credenciales');
        })
        .fail(function () {
            showCredsNotice('error', MGMIT_Admin.strings.error);
            $btn.prop('disabled', false).text('Guardar Credenciales');
        });
    });

    function showCredsNotice(type, msg) {
        var cls = type === 'error' ? 'notice-error' : 'notice-success';
        $('#mgmit-creds-notice')
            .removeClass('notice-error notice-success')
            .addClass('notice ' + cls)
            .html('<p>' + msg + '</p>')
            .show();
    }

})(jQuery);
