import $ from 'jquery';

// Exposed globally so one-off inline snippets and future sub-projects can use
// `$` without importing it. Everything below stays in this module.
window.jQuery = window.$ = $;

// Send the CSRF token with every jQuery AJAX request, so POST/PATCH/DELETE
// calls don't each have to remember it. Laravel's VerifyCsrfToken middleware
// reads the X-CSRF-TOKEN header; the token comes from the <meta> tag in
// layouts/app.blade.php.
$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
    },
});

/**
 * Mobile nav toggle.
 *
 * Progressive enhancement: the menu's collapsed/expanded behaviour below `md`
 * lives in _components.scss, and above `md` the menu is always visible via a
 * media query. With JS off the nav is permanently expanded rather than broken.
 */
$(function () {
    const $toggle = $('.nav-toggle');
    const $menu = $('#nav-menu');

    if (!$toggle.length || !$menu.length) {
        return;
    }

    $toggle.on('click', function () {
        const expanded = $toggle.attr('aria-expanded') === 'true';

        $toggle.attr('aria-expanded', String(!expanded));
        $menu.toggleClass('is-open');
    });
});

/**
 * Dropdown menus — the jQuery replacement for Breeze's Alpine `x-dropdown`.
 *
 * Markup contract: a `.dropdown` wrapper containing a `.dropdown__trigger`
 * button and a `.dropdown__menu`. The trigger carries aria-expanded, which is
 * kept in sync here.
 */
$(function () {
    const $dropdowns = $('.dropdown');

    if (!$dropdowns.length) {
        return;
    }

    const closeAll = () => {
        $dropdowns.removeClass('is-open').find('.dropdown__trigger').attr('aria-expanded', 'false');
    };

    $dropdowns.on('click', '.dropdown__trigger', function (event) {
        event.stopPropagation();

        const $dropdown = $(this).closest('.dropdown');
        const willOpen = !$dropdown.hasClass('is-open');

        closeAll();
        $dropdown.toggleClass('is-open', willOpen);
        $(this).attr('aria-expanded', String(willOpen));
    });

    // Clicking anywhere else, or pressing Escape, closes whatever is open.
    $(document).on('click', closeAll);
    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll();
        }
    });
});

/**
 * Modals, built on the native <dialog> element.
 *
 * `<button data-modal-open="some-id">` opens `#some-id`; any
 * `[data-modal-close]` inside closes it. The browser handles the focus trap,
 * Escape, and the backdrop, so there's nothing to reimplement here.
 */
$(function () {
    $(document).on('click', '[data-modal-open]', function (event) {
        event.preventDefault();

        document.getElementById($(this).data('modal-open'))?.showModal();
    });

    $(document).on('click', '[data-modal-close]', function (event) {
        event.preventDefault();

        this.closest('dialog')?.close();
    });

    // A modal rendered already-open (because validation failed inside it) needs
    // showModal() rather than the `open` attribute, or it won't be in the top
    // layer and the backdrop won't render.
    $('dialog[data-modal-initially-open]').each(function () {
        this.showModal();
    });
});

/**
 * Transient status messages. `<p class="auto-dismiss" data-dismiss-after="2000">`
 * fades out after the given delay; the fade itself is a CSS transition.
 */
$(function () {
    $('.auto-dismiss').each(function () {
        const $message = $(this);
        const delay = Number($message.data('dismiss-after')) || 2000;

        window.setTimeout(() => $message.addClass('is-dismissed'), delay);
    });
});

/**
 * Pipeline check on the home page — the AJAX example.
 *
 * The form is a real, working plain form: with JS off it posts normally and the
 * controller redirects back with the result in the session. This only removes
 * the page reload, which is the shape every jQuery behaviour here should take.
 *
 * The CSRF token comes from the $.ajaxSetup call at the top of this file.
 */
$(function () {
    const $form = $('#pipeline-check');

    if (!$form.length) {
        return;
    }

    const $result = $('#pipeline-result');
    const $error = $form.find('[data-error]');
    const $button = $form.find('button[type="submit"]');

    $form.on('submit', function (event) {
        event.preventDefault();

        $button.prop('disabled', true);

        $.ajax({
            url: $form.attr('action'),
            method: 'POST',
            dataType: 'json',
            data: { message: $('#pipeline-message').val() },
        })
            .done(function (response) {
                $error.text('').addClass('hidden');

                // Each <dd> declares which field it shows, so adding a field to
                // the JSON only means adding markup — not editing this loop.
                $result.removeClass('hidden').find('[data-field]').each(function () {
                    $(this).text(response[$(this).data('field')] ?? '');
                });
            })
            .fail(function (xhr) {
                $result.addClass('hidden');

                // 422 carries Laravel's validation errors; anything else is a
                // genuine failure and shouldn't be reported as a bad message.
                const message = xhr.status === 422
                    ? (xhr.responseJSON?.errors?.message?.[0] ?? 'That message was rejected.')
                    : 'Could not reach the server. Please try again.';

                $error.text(message).removeClass('hidden');
            })
            .always(function () {
                $button.prop('disabled', false);
            });
    });
});
