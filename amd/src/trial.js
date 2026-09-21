// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Wunderbyte free trial in the setup wizard: consent modal, then the trial web service.
 *
 * @module     local_contenttranslator/trial
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import Templates from 'core/templates';
import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import {get_strings as getStrings} from 'core/str';

const COMPONENT = 'local_contenttranslator';
const SELECTORS = {
    START: '[data-action="local-contenttranslator-trial-start"]',
    SPINNER: '[data-region="local-contenttranslator-trial-spinner"]',
    RESULT: '[data-region="local-contenttranslator-trial-result"]',
    CONSENT: '[data-region="local-contenttranslator-trial-consent-checkbox"]',
};

/** Milliseconds the success message stays visible before the page reloads. */
const RELOAD_DELAY = 2500;

/**
 * Turn a plain server message into safe HTML. Only [label](https://...) links are kept as links.
 *
 * @param {String} message
 * @returns {String}
 */
const renderMessage = (message) => {
    const escaped = String(message)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    return escaped.replace(
        /\[([^\]]+)\]\((https:\/\/[^\s)]+)\)/g,
        '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>'
    );
};

/**
 * Show a result message below the trial button.
 *
 * @param {String} message
 * @param {Boolean} success
 */
const showResult = (message, success) => {
    const result = document.querySelector(SELECTORS.RESULT);
    if (!result) {
        return;
    }
    result.innerHTML = '<div class="alert ' + (success ? 'alert-success' : 'alert-danger') + '">'
        + renderMessage(message) + '</div>';
};

/**
 * Show or hide the spinner and lock the start button while a request runs.
 *
 * @param {HTMLElement} button
 * @param {Boolean} busy
 */
const setBusy = (button, busy) => {
    const spinner = document.querySelector(SELECTORS.SPINNER);
    if (spinner) {
        spinner.hidden = !busy;
    }
    button.disabled = busy;
};

/**
 * Call the trial web service and report the result.
 *
 * @param {HTMLElement} button
 * @param {Boolean} consented The consent modal was accepted.
 * @param {Boolean} overwrite The admin confirmed that an existing provider configuration is replaced (4.5).
 */
const requestTrial = (button, consented, overwrite) => {
    setBusy(button, true);
    const result = document.querySelector(SELECTORS.RESULT);
    if (result) {
        result.innerHTML = '';
    }
    Ajax.call([{
        methodname: 'local_contenttranslator_request_trial_key',
        args: {consented: consented, strategy: '', confirmoverwrite: overwrite},
    }])[0].then((response) => {
        if (response.success) {
            // Keep the button locked: the reload shows the connected state.
            const spinner = document.querySelector(SELECTORS.SPINNER);
            if (spinner) {
                spinner.hidden = true;
            }
            showResult(response.message, true);
            setTimeout(() => window.location.reload(), RELOAD_DELAY);
            return;
        }
        setBusy(button, false);
        showResult(response.message, false);
    }, (error) => {
        setBusy(button, false);
        getStrings([{key: 'trial_js_unexpected_error', component: COMPONENT}]).then(([fallback]) => {
            showResult((error && error.message) || fallback, false);
            return;
        }).catch(Notification.exception);
    });
};

/**
 * Show the data-protection consent. The accept button stays disabled until the checkbox is ticked.
 *
 * @param {Boolean} overwrite Moodle 4.5: the trial replaces an existing provider configuration.
 * @param {Function} onAccept Called after the user agreed.
 * @returns {Promise}
 */
const showConsent = (overwrite, onAccept) => getStrings([
    {key: 'trial_consent_title', component: COMPONENT},
    {key: 'trial_consent_accept', component: COMPONENT},
]).then(([title, accept]) => Templates.render(COMPONENT + '/trial_consent_modal', {overwrite: overwrite})
    .then((body) => ModalSaveCancel.create({
        title: title,
        body: body,
        buttons: {save: accept},
        show: true,
        removeOnClose: true,
    }))
    .then((modal) => {
        modal.setButtonDisabled('save', true);
        const checkbox = document.querySelector(SELECTORS.CONSENT);
        if (checkbox) {
            checkbox.addEventListener('change', () => modal.setButtonDisabled('save', !checkbox.checked));
        }
        modal.getRoot().on(ModalEvents.save, () => onAccept());
        return modal;
    }));

/**
 * Bind the trial button of the setup wizard.
 */
export const init = () => {
    document.addEventListener('click', (event) => {
        const button = event.target.closest(SELECTORS.START);
        if (!button) {
            return;
        }
        event.preventDefault();
        const overwrite = button.dataset.overwrite === '1';
        if (button.dataset.needsconsent === '1') {
            showConsent(overwrite, () => requestTrial(button, true, overwrite)).catch(Notification.exception);
            return;
        }
        // Reusing the provider that already exists sends nothing to Wunderbyte: no consent needed.
        requestTrial(button, false, false);
    });
};
