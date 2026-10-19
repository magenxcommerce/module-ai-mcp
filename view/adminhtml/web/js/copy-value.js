/**
 * Copyright © Magenx. All rights reserved.
 */

/**
 * Copies the text of the element named by `target` when the button is clicked,
 * and says so on the button for a moment.
 */
define([], function () {
    'use strict';

    /**
     * The clipboard API needs a secure context; fall back to selecting the text and the old copy command.
     *
     * @param {HTMLElement} source
     * @return {Promise}
     */
    function copy(source) {
        var text = source.textContent.trim(),
            range,
            selection;

        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }

        range = document.createRange();
        range.selectNodeContents(source);
        selection = window.getSelection();
        selection.removeAllRanges();
        selection.addRange(range);

        return document.execCommand('copy') ? Promise.resolve() : Promise.reject();
    }

    return function (config, button) {
        var label = button.querySelector('span') || button,
            original = label.textContent,
            timer;

        button.addEventListener('click', function () {
            var source = document.querySelector(config.target);

            if (!source) {
                return;
            }

            copy(source).then(function () {
                label.textContent = config.copiedLabel;
                button.classList.add('magenx-mcp-copied');
                clearTimeout(timer);
                timer = setTimeout(function () {
                    label.textContent = original;
                    button.classList.remove('magenx-mcp-copied');
                }, 2000);
            }, function () {
                // Copying was refused; the chip's text is left selected so the admin can copy it by hand.
            });
        });
    };
});
