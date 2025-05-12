// TODO Remove dead code.

$(document).ready(function() {

    const alertFail = (jqXHR, textStatus) => {
        if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
            alert(jqXHR.responseJSON.message);
        } else {
            alert(Omeka.jsTranslate('Something went wrong'));
        }
    }

    const alertDataError = (data) => {
        if (data.data && Object.keys(data.data).length) {
            var i = 0;
            const flat = (obj, out) => {
                Object.keys(obj).forEach(key => {
                    if (typeof obj[key] == 'object') {
                        out = flat(obj[key], out);
                    } else if (key in out) {
                        out[key + '_' + (++i).toString()] = obj[key];
                    } else {
                        out[key] = obj[key];
                    }
                })
                return out;
            }
            var message = data.message && data.message.length
                ? data.message
                : Omeka.jsTranslate('Generated resource is not valid.');
            var flatData = flat(data.data, {});
            Object.keys(flatData).reduce(function (r, k) {
                message += "\n" + flatData[k];
             }, []);
            alert(message);
        } else if (data.message) {
            alert(data.message);
        } else {
            alert(Omeka.jsTranslate('Something went wrong'));
        }
    }

    // Manage resource form check box to generate resource metadata.
    $('.resource-form').on('change', 'input[type=checkbox]#generate-metadata', function(e) {
        if ($('.resource-form input[type=checkbox]#generate-metadata').prop("checked")) {
            $('.resource-form textarea#generate-prompt').prop('disabled', false).closest('.field').show();
        } else {
            $('.resource-form textarea#generate-prompt').prop('disabled', true).closest('.field').hide();
        }
    });

    // Mark a generated resource reviewed/unreviewed.
    $('#content').on('click', '.generated-resource a.status-toggle', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('status-toggle-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                status = data.data.generated_resource.status;
                button.data('status', status);
                button.prop('title', data.data.generated_resource.statusLabel);
                button.prop('aria-label', data.data.generated_resource.statusLabel);
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    // Validate all values of a generated resource.
    $('#content').on('click', '.generated-resource .actions .o-icon-add', function(e) {
        e.preventDefault();
        var button = $(this);
        var url = button.prop('href');
        var status = 'add';
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                button.closest('td').find('span.title')
                    .wrap('<a href="' + data.data.url + '"></a>');
                let newButton = '<a class="o-icon-edit"'
                    + ' title="'+ Omeka.jsTranslate('Edit') + '"'
                    + ' href="' + data.data.url + '"'
                    + ' aria-label="' + Omeka.jsTranslate('Edit') + '"></a>';
                button.replaceWith(newButton);
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    // Validate all values of a generated resource.
    $('#content').on('click', '.generated-resource a.validate', function(e) {
        e.preventDefault();
        var button = $(this);
        var url = button.data('validate-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                // Set the generated resource reviewed in all cases.
                status = data.data.generated_resource.reviewed.status;
                buttonReviewed = button.closest('th').find('a.status-toggle');
                buttonReviewed.data('status', status);
                buttonReviewed.addClass('o-icon-' + status);

                // Update the validate button.
                status = data.data.generated_resource.status;
                // button.prop('title', statusLabel);
                // button.prop('aria-label', statusLabel);

                // Reload the page to update the default show view.
                // TODO Dynamically update default show view after generated resource.
                location.reload();
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    // Validate a specific value of a generated resource.
    $('#content').on('click', '.generate-resource a.validate-value', function(e) {
        e.preventDefault();

        var button = $(this);
        var url = button.data('validate-value-url');
        var status = button.data('status');
        $.ajax({
            url: url,
            beforeSend: function() {
                button.removeClass('o-icon-' + status).addClass('fas fa-sync fa-spin');
            }
        })
        .done(function(data) {
            if (!data.status || data.status !== 'success') {
                alertDataError(data);
            } else {
                // Update the validate button.
                status = data.data.generated_resource.status;
                button.prop('title', data.data.generated_resource.statusLabel);
                button.prop('aria-label', data.data.generated_resource.statusLabel);
                // TODO Update the value in the main metadata tab.
            }
        })
        .fail(alertFail)
        .always(function () {
            button.removeClass('fas fa-sync fa-spin').addClass('o-icon-' + status);
        });
    });

    /**
     * Search sidebar.
     */
    $('#content').on('click', 'a.search', function(e) {
        e.preventDefault();
        var sidebar = $('#sidebar-search');
        Omeka.openSidebar(sidebar);

        // Auto-close if other sidebar opened
        $('body').one('o:sidebar-opened', '.sidebar', function () {
            if (!sidebar.is(this)) {
                Omeka.closeSidebar(sidebar);
            }
        });
    });

});
