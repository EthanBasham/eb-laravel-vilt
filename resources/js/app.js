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
 * Milestone completion toggle — the AJAX example.
 *
 * Each milestone checkbox PATCHes its own route and the server returns the new
 * state as JSON. The surrounding <form> is a real, working non-JS fallback:
 * with JS off the checkbox is accompanied by a submit button that posts the
 * same route normally, so this only removes a page reload.
 */
$(function () {
    const $list = $('#milestones');

    if (!$list.length) {
        return;
    }

    // The no-JS submit buttons are only needed when this handler isn't running,
    // so they're removed here rather than hidden with CSS — that way they're
    // genuinely present for anyone without JS.
    $list.find('.milestone__submit').remove();

    $list.on('change', '.milestone__checkbox', function () {
        const $checkbox = $(this);
        const $milestone = $checkbox.closest('.milestone');

        $milestone.addClass('is-saving');
        $checkbox.prop('disabled', true);

        $.ajax({
            url: $milestone.data('toggle-url'),
            method: 'PATCH',
            dataType: 'json',
            data: { is_complete: $checkbox.is(':checked') ? 1 : 0 },
        })
            .done(function (response) {
                $milestone.toggleClass('is-complete', response.is_complete);
                $('#milestone-progress').text(response.progress_label);
            })
            .fail(function () {
                // Put the checkbox back where it was — the server is the source
                // of truth and it didn't accept the change.
                $checkbox.prop('checked', !$checkbox.is(':checked'));
                window.alert('Could not save that change. Please try again.');
            })
            .always(function () {
                $milestone.removeClass('is-saving');
                $checkbox.prop('disabled', false);
            });
    });
});
