// TODO Remove dead code.

(function ($) {

    $(document).ready(function() {

        /**
         * @see ContactUs, Contribute, Guest, SearchHistory, Selection, TwoFactorAuth.
         */

        const beforeSpin = function (element) {
            var span = $(element).find('span');
            if (!span.length) {
                span = $(element).next('span.appended');
                if (!span.length) {
                    $('<span class="appended"></span>').insertAfter($(element));
                    span = $(element).next('span');
                }
            }
            element.hide();
            span.addClass('fas fa-sync fa-spin');
        };

        const afterSpin = function (element) {
            var span = $(element).find('span');
            if (!span.length) {
                span = $(element).next('span.appended');
                if (span.length) {
                    span.remove();
                }
            } else {
                span.removeClass('fas fa-sync fa-spin');
            }
            element.show();
        };

        /**
         * Get the main message of jSend output, in particular for status fail.
         */
        const jSendMessage = function(data) {
            if (typeof data !== 'object') {
                return null;
            }
            if (data.message) {
                return data.message;
            }
            if (!data.data) {
                return null;
            }
            if (data.data.message) {
                return data.data.message;
            }
            for (let value of Object.values(data.data)) {
                if (typeof value === 'string' && value.length) {
                    return value;
                }
            }
            return null;
        }

        const dialogMessage = function (message, nl2br = false) {
            // Use a dialog to display a message, that should be escaped.
            var dialog = document.querySelector('dialog.popup-message');
            if (!dialog) {
                dialog = `
    <dialog class="popup popup-dialog dialog-message popup-message" data-is-dynamic="1">
        <div class="dialog-background">
            <div class="dialog-panel">
                <div class="dialog-header">
                    <button type="button" class="dialog-header-close-button" title="Close" autofocus="autofocus">
                        <span class="dialog-close">🗙</span>
                    </button>
                </div>
                <div class="dialog-contents">
                    {{ message }}
                </div>
            </div>
        </div>
    </dialog>`;
                $('body').append(dialog);
                dialog = document.querySelector('dialog.dialog-message');
            }
            if (nl2br) {
                message = message.replace(/(?:\r\n|\r|\n)/g, '<br/>');
            }
            dialog.innerHTML = dialog.innerHTML.replace('{{ message }}', message);
            dialog.showModal();
            $(dialog).trigger('o:dialog-opened');
        };

        /**
         * Manage ajax fail.
         *
         * @param {Object} xhr
         * @param {string} textStatus
         * @param {string} errorThrown
         */
        const handleAjaxFail = function(xhr, textStatus, errorThrown) {
            const data = xhr.responseJSON;
            if (data && data.status === 'fail') {
                let msg = jSendMessage(data);
                dialogMessage(msg ? msg : 'Check input', true);
            } else {
                // Error is a server error (in particular cannot send mail).
                let msg = data && data.status === 'error' && data.message && data.message.length ? data.message : 'An error occurred.';
                dialogMessage(msg, true);
            }
        };

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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
                } else {
                    status = data.data.generated_resource.status;
                    button.data('status', status);
                    button.prop('title', data.data.generated_resource.statusLabel);
                    button.prop('aria-label', data.data.generated_resource.statusLabel);
                }
            })
            .fail(handleAjaxFail)
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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
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
            .fail(handleAjaxFail)
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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
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
            .fail(handleAjaxFail)
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
            .done(function(data, textStatus, xhr) {
                if (!data.status || data.status !== 'success') {
                    handleAjaxFail(xhr, textStatus);
                } else {
                    // Update the validate button.
                    status = data.data.generated_resource.status;
                    button.prop('title', data.data.generated_resource.statusLabel);
                    button.prop('aria-label', data.data.generated_resource.statusLabel);
                    // TODO Update the value in the main metadata tab.
                }
            })
            .fail(handleAjaxFail)
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

        $(document).on('click', '.dialog-header-close-button', function(e) {
            const dialog = this.closest('dialog.popup');
            if (dialog) {
                dialog.close();
                if (dialog.hasAttribute('data-is-dynamic') && dialog.getAttribute('data-is-dynamic')) {
                    dialog.remove();
                }
            } else {
                $(this).closest('.popup').addClass('hidden').hide();
            }
        });

    });
})(jQuery);
