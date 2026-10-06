<?php

session_start();

require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/ui.php';

/*
 * Require the customer to be logged in.
 */

if (!isset($_SESSION['customer_id'])) {
    header('Location: login.php');
    exit;
}

$customerId = (int) $_SESSION['customer_id'];

/*
 * Load the logged-in customer's account record.
 */

$customerStmt = $pdo->prepare(
    'SELECT first_name, last_name, email, phone
     FROM customers
     WHERE id = :customer_id
     LIMIT 1'
);

$customerStmt->execute([':customer_id' => $customerId]);

$customer = $customerStmt->fetch();

/*
 * If the account record no longer exists, end the stale
 * customer session and return to login.
 */

if ($customer === false) {
    unset($_SESSION['customer_id'], $_SESSION['customer_name']);

    header('Location: login.php');
    exit;
}

/*
 * Ensure a CSRF token exists for the account forms. The token is shared
 * with the other authenticated customer forms (cart, checkout, order
 * actions) and is rotated by account-update.php after a successful save.
 */

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

/*
 * Normalise the record once so every view below reads the same values.
 * Nothing here is hard-coded: the identity area, the personal information
 * section, and the edit form all come from this single record.
 */

$firstName = trim((string) $customer['first_name']);
$lastName = trim((string) $customer['last_name']);
$email = trim((string) $customer['email']);
$phone = trim((string) ($customer['phone'] ?? ''));

$fullName = trim($firstName . ' ' . $lastName);
$initials = hopia_account_initials($firstName, $lastName);

/*
 * The eye control is repeated on every password input, so it is rendered
 * once here and reused. The button is type="button", so it never submits a
 * form, and the visible state is driven by input.type in the script below.
 */

function hopia_account_eye_toggle(string $inputId, string $label): void
{
    ?>
    <button
        type="button"
        class="input-password__toggle"
        aria-label="Show <?= hopia_e($label) ?>"
        aria-pressed="false"
        data-password-toggle="<?= hopia_e($inputId) ?>"
    >
        <svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
            <path class="eye-open" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
            <circle class="eye-open" cx="12" cy="12" r="3"/>
            <line class="eye-closed" x1="1" y1="1" x2="23" y2="23" style="display:none"/>
            <path class="eye-closed" d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24" style="display:none"/>
        </svg>
    </button>
    <?php
}

/*
 * Build the UI page. The shared head, header, and footer are included
 * below; ui.php itself is already loaded at the top of this file because
 * hopia_account_initials() is needed to render the identity area.
 */

$page_title = 'My Account - ' . hopia_site_name();
$page_description = 'Manage your account details at ' . hopia_site_name() . '.';
$ui_active = 'account.php';
$body_class = 'account-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';

?>

<section class="account-center">

    <!-- Profile identity area. The page masthead was removed deliberately,
         so this area is the page's primary visual identity: the initials
         mark, the name and email, the role, and the EDIT PROFILE action. -->
    <div class="account-identity">
        <div class="account-identity__main">
            <span class="account-identity__avatar" aria-hidden="true">
                <span class="account-identity__initials" data-account-initials><?= hopia_e($initials) ?></span>
            </span>

            <div class="account-identity__text">
                <h1 class="account-identity__name" data-account-field="name"><?= hopia_e($fullName) ?></h1>
                <p class="account-identity__email" data-account-field="email"><?= hopia_e($email) ?></p>
                <p class="account-identity__role">CUSTOMER</p>
            </div>
        </div>

        <div class="account-identity__actions">
            <button class="account-link" type="button" data-account-edit-open hidden>
                <span class="account-link__label">EDIT PROFILE</span>
                <span class="account-link__arrow" aria-hidden="true"><?= hopia_account_icon('arrow-right') ?></span>
            </button>
        </div>
    </div>

    <div class="account-grid">

        <!-- Personal information: the primary module. Read-only editorial
             rows by default, native inputs in edit mode. -->
        <section class="account-panel account-panel--personal" aria-labelledby="account-personal-heading">
            <div class="account-panel__head">
                <span class="account-panel__icon" aria-hidden="true"><?= hopia_account_icon('profile') ?></span>
                <h2 class="account-panel__title" id="account-personal-heading">PERSONAL INFORMATION</h2>
            </div>

            <form class="account-fields" method="POST" action="account-update.php" novalidate data-account-form="profile">
                <input type="hidden" name="action" value="profile">
                <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>" data-account-csrf>

                <p class="account-success" data-account-success="profile" role="status" aria-live="polite" hidden>
                    <span class="account-success__icon" aria-hidden="true"><?= hopia_account_icon('check') ?></span>
                    <span class="account-success__copy">
                        <span class="account-success__title">PROFILE UPDATED</span>
                        <span class="account-success__detail">Your account information has been saved.</span>
                    </span>
                </p>

                <div class="account-field">
                    <label class="account-field__label" for="account-first-name">
                        <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('user') ?></span>
                        <span class="account-field__label-text">FIRST NAME</span>
                    </label>
                    <div class="account-field__control">
                        <span class="account-field__value" data-account-value="first_name"><?= hopia_e($firstName) ?></span>
                        <input
                            class="input account-field__input"
                            id="account-first-name"
                            type="text"
                            name="first_name"
                            value="<?= hopia_e($firstName) ?>"
                            autocomplete="given-name"
                            maxlength="100"
                            required
                        >
                    </div>
                    <p class="account-field__error" data-account-error="first_name" hidden></p>
                </div>

                <div class="account-field">
                    <label class="account-field__label" for="account-last-name">
                        <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('user') ?></span>
                        <span class="account-field__label-text">LAST NAME</span>
                    </label>
                    <div class="account-field__control">
                        <span class="account-field__value" data-account-value="last_name"><?= hopia_e($lastName) ?></span>
                        <input
                            class="input account-field__input"
                            id="account-last-name"
                            type="text"
                            name="last_name"
                            value="<?= hopia_e($lastName) ?>"
                            autocomplete="family-name"
                            maxlength="100"
                            required
                        >
                    </div>
                    <p class="account-field__error" data-account-error="last_name" hidden></p>
                </div>

                <div class="account-field">
                    <label class="account-field__label" for="account-email">
                        <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('mail') ?></span>
                        <span class="account-field__label-text">EMAIL</span>
                    </label>
                    <div class="account-field__control">
                        <span class="account-field__value" data-account-value="email"><?= hopia_e($email) ?></span>
                        <input
                            class="input account-field__input"
                            id="account-email"
                            type="email"
                            name="email"
                            value="<?= hopia_e($email) ?>"
                            autocomplete="email"
                            maxlength="255"
                            required
                        >
                    </div>
                    <p class="account-field__error" data-account-error="email" hidden></p>
                </div>

                <div class="account-field">
                    <label class="account-field__label" for="account-phone">
                        <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('phone') ?></span>
                        <span class="account-field__label-text">PHONE</span>
                    </label>
                    <div class="account-field__control">
                        <span class="account-field__value<?= $phone === '' ? ' account-field__value--missing' : '' ?>" data-account-value="phone"><?= $phone === '' ? 'Not provided' : hopia_e($phone) ?></span>
                        <input
                            class="input account-field__input"
                            id="account-phone"
                            type="tel"
                            name="phone"
                            value="<?= hopia_e($phone) ?>"
                            autocomplete="tel"
                            maxlength="30"
                        >
                    </div>
                    <p class="account-field__error" data-account-error="phone" hidden></p>
                </div>

                <!-- Email confirmation. Only shown when the submitted email
                     differs from the stored one, so an ordinary name or phone
                     edit never asks for a password. -->
                <div class="account-confirm" data-account-email-confirm hidden>
                    <label class="account-field__label" for="account-email-password">
                        <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('lock') ?></span>
                        <span class="account-field__label-text">CONFIRM WITH PASSWORD</span>
                    </label>
                    <div class="input-password">
                        <input
                            class="input"
                            id="account-email-password"
                            type="password"
                            name="current_password"
                            autocomplete="current-password"
                        >
                        <?php hopia_account_eye_toggle('account-email-password', 'password'); ?>
                    </div>
                    <p class="account-field__error" data-account-error="current_password" hidden></p>
                </div>

                <p class="account-panel__error" data-account-form-error role="alert" hidden></p>

                <!-- Read-mode action. Hidden by CSS while the module is in
                     edit mode, so a stale EDIT PROFILE is never left behind. -->
                <div class="account-panel__actions" data-account-read-actions>
                    <button class="account-link account-link--sm" type="button" data-account-edit-open>
                        <span class="account-link__label">EDIT PROFILE</span>
                        <span class="account-link__arrow" aria-hidden="true"><?= hopia_account_icon('arrow-right') ?></span>
                    </button>
                </div>

                <!-- Edit-mode actions. Visibility is owned by the
                     .is-editing state, which both entering and leaving edit
                     mode controls, so CANCEL and SAVE CHANGES can never
                     survive a cancel. -->
                <div class="account-panel__actions" data-account-profile-actions>
                    <button class="account-btn account-btn--ghost" type="button" data-account-edit-cancel>CANCEL</button>
                    <button class="account-btn account-btn--primary" type="submit" data-account-save="profile">SAVE CHANGES</button>
                </div>
            </form>
        </section>

        <!-- Security: the secondary module. The stored password is never
             rendered; only a fixed mask is shown. -->
        <section class="account-panel account-panel--security" aria-labelledby="account-security-heading">
            <div class="account-panel__head">
                <span class="account-panel__icon" aria-hidden="true"><?= hopia_account_icon('lock') ?></span>
                <h2 class="account-panel__title" id="account-security-heading">SECURITY</h2>
            </div>

            <p class="account-success" data-account-success="password" role="status" aria-live="polite" hidden>
                <span class="account-success__icon" aria-hidden="true"><?= hopia_account_icon('check') ?></span>
                <span class="account-success__copy">
                    <span class="account-success__title">PASSWORD UPDATED</span>
                    <span class="account-success__detail">Your password has been changed successfully.</span>
                </span>
            </p>

            <div class="account-field account-field--security account-security-row" data-account-security-read>
                <p class="account-field__label">
                    <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('key') ?></span>
                    <span class="account-field__label-text">PASSWORD</span>
                </p>
                <div class="account-field__control account-field__control--security">
                    <p class="account-field__value account-field__value--mask" aria-label="Password hidden">••••••••••••</p>
                    <button class="account-link account-link--sm" type="button" data-account-password-open>
                        <span class="account-link__label">CHANGE PASSWORD</span>
                        <span class="account-link__arrow" aria-hidden="true"><?= hopia_account_icon('arrow-right') ?></span>
                    </button>
                </div>
            </div>

            <!-- The form stays mounted and the module expands in place via
                 the .is-password-editing state, so opening and closing never
                 rebuilds the panel. -->
            <div class="account-reveal" data-account-reveal>
                <div class="account-reveal__inner">
                    <form class="account-password" method="POST" action="account-update.php" novalidate data-account-form="password">
                        <input type="hidden" name="action" value="password">
                        <input type="hidden" name="csrf_token" value="<?= hopia_e($csrfToken) ?>" data-account-csrf>

                        <div class="account-field">
                            <label class="account-field__label" for="account-current-password">
                                <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('lock') ?></span>
                                <span class="account-field__label-text">CURRENT PASSWORD</span>
                            </label>
                            <div class="input-password">
                                <input
                                    class="input"
                                    id="account-current-password"
                                    type="password"
                                    name="current_password"
                                    autocomplete="current-password"
                                    required
                                >
                                <?php hopia_account_eye_toggle('account-current-password', 'current password'); ?>
                            </div>
                            <p class="account-field__error" data-account-error="current_password" hidden></p>
                        </div>

                        <div class="account-field">
                            <label class="account-field__label" for="account-new-password">
                                <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('key') ?></span>
                                <span class="account-field__label-text">NEW PASSWORD</span>
                            </label>
                            <div class="input-password">
                                <input
                                    class="input"
                                    id="account-new-password"
                                    type="password"
                                    name="new_password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    required
                                >
                                <?php hopia_account_eye_toggle('account-new-password', 'new password'); ?>
                            </div>
                            <p class="account-field__error" data-account-error="new_password" hidden></p>
                        </div>

                        <div class="account-field">
                            <label class="account-field__label" for="account-confirm-password">
                                <span class="account-field__icon" aria-hidden="true"><?= hopia_account_icon('key') ?></span>
                                <span class="account-field__label-text">CONFIRM NEW PASSWORD</span>
                            </label>
                            <div class="input-password">
                                <input
                                    class="input"
                                    id="account-confirm-password"
                                    type="password"
                                    name="confirm_password"
                                    autocomplete="new-password"
                                    minlength="8"
                                    required
                                >
                                <?php hopia_account_eye_toggle('account-confirm-password', 'confirmation password'); ?>
                            </div>
                            <p class="account-field__error" data-account-error="confirm_password" hidden></p>
                        </div>

                        <p class="account-panel__error" data-account-form-error role="alert" hidden></p>

                        <div class="account-panel__actions">
                            <button class="account-btn account-btn--ghost" type="button" data-account-password-cancel>CANCEL</button>
                            <button class="account-btn account-btn--primary" type="submit" data-account-save="password">
                                <span>UPDATE PASSWORD</span>
                                <span class="account-btn__arrow" aria-hidden="true"><?= hopia_account_icon('arrow-right') ?></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- Quick actions: the supporting module. Same destinations and
             order as before, presented as compact rows. -->
        <aside class="account-panel account-panel--quick" aria-labelledby="account-quick-heading">
            <div class="account-panel__head">
                <span class="account-panel__icon" aria-hidden="true"><?= hopia_account_icon('layers') ?></span>
                <h2 class="account-panel__title" id="account-quick-heading">QUICK ACTIONS</h2>
            </div>

            <div class="account-quick__list">
                <a class="account-quick__action account-quick__action--primary" href="orders.php">
                    <span class="account-quick__icon" aria-hidden="true"><?= hopia_account_icon('package') ?></span>
                    <span class="account-quick__copy">
                        <span class="account-quick__label">VIEW MY ORDERS</span>
                        <span class="account-quick__sub">Track and review your purchases</span>
                    </span>
                    <span class="account-quick__arrow" aria-hidden="true"><?= hopia_account_icon('arrow-right') ?></span>
                </a>

                <a class="account-quick__action account-quick__action--secondary" href="products.php">
                    <span class="account-quick__icon" aria-hidden="true"><?= hopia_account_icon('bag') ?></span>
                    <span class="account-quick__copy">
                        <span class="account-quick__label">CONTINUE SHOPPING</span>
                        <span class="account-quick__sub">Browse the latest finds</span>
                    </span>
                    <span class="account-quick__arrow" aria-hidden="true"><?= hopia_account_icon('arrow-right') ?></span>
                </a>
            </div>
        </aside>
    </div>

    <!-- Account exit. Kept clearly separated from the modules above so
         LOG OUT is never confused with an ordinary action. -->
    <div class="account-exit">
        <span class="account-exit__rule" aria-hidden="true"></span>
        <a class="account-exit__logout" href="logout.php" data-logout>
            <span class="account-exit__icon" aria-hidden="true"><?= hopia_account_icon('sign-out') ?></span>
            <span class="account-exit__label">LOG OUT</span>
        </a>
    </div>
</section>

<script>
    (function () {
        var root = document.querySelector('.account-center');

        if (!root) {
            return;
        }

        var editOpen = root.querySelectorAll('[data-account-edit-open]');
        var editOpenPrimary = editOpen.length ? editOpen[0] : null;
        var editCancel = root.querySelector('[data-account-edit-cancel]');
        var profileForm = root.querySelector('[data-account-form="profile"]');
        var passwordForm = root.querySelector('[data-account-form="password"]');
        var passwordOpen = root.querySelector('[data-account-password-open]');
        var passwordCancel = root.querySelector('[data-account-password-cancel]');
        var emailConfirm = root.querySelector('[data-account-email-confirm]');
        var emailPassword = document.getElementById('account-email-password');
        var emailInput = document.getElementById('account-email');
        var nameOutput = root.querySelector('[data-account-field="name"]');
        var emailOutput = root.querySelector('[data-account-field="email"]');
        var initialsOutput = root.querySelector('[data-account-initials]');
        var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

        var successTimers = {
            profile: null,
            password: null
        };

        var editing = false;
        var passwordEditing = false;

        var fields = {
            first_name: document.getElementById('account-first-name'),
            last_name: document.getElementById('account-last-name'),
            email: emailInput,
            phone: document.getElementById('account-phone')
        };

        /* Baseline values captured when the form leaves edit mode. Used to
           detect an email change and to reset the form on CANCEL. */
        var baseline = {
            first_name: fields.first_name.value,
            last_name: fields.last_name.value,
            email: fields.email.value,
            phone: fields.phone.value
        };

        /* -------------------------------------------------------------- *
         * Password visibility
         *
         * Each toggle flips its own input between type="password" and
         * type="text". Switching the type attribute preserves the entered
         * value, so nothing typed is lost. Every toggle is independent and
         * is a type="button", so clicking it never submits a form.
         * -------------------------------------------------------------- */
        root.querySelectorAll('[data-password-toggle]').forEach(function (button) {
            var input = document.getElementById(button.getAttribute('data-password-toggle'));

            if (!input) {
                return;
            }

            var eyeOpen = button.querySelectorAll('.eye-open');
            var eyeClosed = button.querySelectorAll('.eye-closed');
            var label = button.getAttribute('aria-label') || 'password';
            var shownLabel = label.replace(/^Show /, 'Hide ');

            function apply(show) {
                input.type = show ? 'text' : 'password';
                eyeOpen.forEach(function (node) {
                    node.style.display = show ? 'none' : '';
                });
                eyeClosed.forEach(function (node) {
                    node.style.display = show ? '' : 'none';
                });
                button.setAttribute('aria-label', show ? shownLabel : label);
                button.setAttribute('aria-pressed', show ? 'true' : 'false');
                button.classList.toggle('is-revealed', show);
            }

            button.addEventListener('click', function () {
                apply(input.type === 'password');
            });
        });

        /* -------------------------------------------------------------- *
         * Errors
         * -------------------------------------------------------------- */
        function clearErrors(scope) {
            (scope || root).querySelectorAll('[data-account-error]').forEach(function (node) {
                node.textContent = '';
                node.hidden = true;
            });

            (scope || root).querySelectorAll('.input.is-error').forEach(function (input) {
                input.classList.remove('is-error');
                input.removeAttribute('aria-invalid');
            });

            var formError = (scope || root).querySelector('[data-account-form-error]');

            if (formError) {
                formError.textContent = '';
                formError.hidden = true;
            }
        }

        function showError(form, field, message) {
            var scope = form || root;
            var node = scope.querySelector('[data-account-error="' + field + '"]');

            if (node) {
                node.textContent = message;
                node.hidden = false;
            }

            var input = fields[field] || scope.querySelector('[name="' + field + '"]');

            if (input && input.classList.contains('input')) {
                input.classList.add('is-error');
                input.setAttribute('aria-invalid', 'true');
            }
        }

        /* Shown only when no field owns the message, so a rejected field
           never reports itself twice (once beside the input and once
           below the form). */
        function showFormError(form, message) {
            var node = (form || root).querySelector('[data-account-form-error]');

            if (node) {
                node.textContent = message;
                node.hidden = false;
            }
        }

        /* Reports each error exactly once: field messages beside their own
           input, and the form message only for failures that belong to no
           single field. The server's message is passed through so a
           validation rejection, a rejected CSRF token, and an ended session
           stay distinguishable instead of collapsing into one generic
           "session expired" notice. */
        function reportFailure(form, data) {
            var errors = data.errors || {};
            var keys = Object.keys(errors);

            clearErrors(form);

            if (keys.length === 0) {
                showFormError(form, data.message || 'Something went wrong. Please try again.');
                return;
            }

            keys.forEach(function (field) {
                showError(form, field, errors[field]);
            });
        }

        /* -------------------------------------------------------------- *
         * Success
         *
         * One banner per module, so a profile save is never reported in the
         * Security module and vice versa. Each fades out on its own timer.
         * -------------------------------------------------------------- */
        function showSuccess(module) {
            var banner = root.querySelector('[data-account-success="' + module + '"]');

            if (!banner) {
                return;
            }

            if (successTimers[module]) {
                window.clearTimeout(successTimers[module]);
            }

            banner.hidden = false;

            /* Restart the entry transition when the banner is already
               showing, so repeated saves always fade in. */
            banner.classList.remove('is-visible');
            void banner.offsetWidth;
            banner.classList.add('is-visible');

            successTimers[module] = window.setTimeout(function () {
                banner.classList.remove('is-visible');
                banner.hidden = true;
            }, 6000);
        }

        function clearSuccess(module) {
            var banner = root.querySelector('[data-account-success="' + module + '"]');

            if (successTimers[module]) {
                window.clearTimeout(successTimers[module]);
                successTimers[module] = null;
            }

            if (banner) {
                banner.classList.remove('is-visible');
                banner.hidden = true;
            }
        }

        /* -------------------------------------------------------------- *
         * Profile values
         * -------------------------------------------------------------- */
        function applyProfile(profile) {
            if (!profile) {
                return;
            }

            if (nameOutput) {
                nameOutput.textContent = profile.full_name;
            }

            if (emailOutput) {
                emailOutput.textContent = profile.email;
            }

            if (initialsOutput) {
                initialsOutput.textContent = profile.initials;
            }

            Object.keys(fields).forEach(function (key) {
                var input = fields[key];

                if (!input || typeof profile[key] === 'undefined') {
                    return;
                }

                input.value = profile[key];
                baseline[key] = input.value;

                var value = root.querySelector('[data-account-value="' + key + '"]');

                if (!value) {
                    return;
                }

                if (profile[key] === '') {
                    value.textContent = 'Not provided';
                    value.classList.add('account-field__value--missing');
                } else {
                    value.textContent = profile[key];
                    value.classList.remove('account-field__value--missing');
                }
            });
        }

        function syncToken(data) {
            if (!data || !data.csrf_token) {
                return;
            }

            root.querySelectorAll('[data-account-csrf]').forEach(function (node) {
                node.value = data.csrf_token;
            });
        }

        function setBusy(button, busy) {
            if (!button) {
                return;
            }

            button.disabled = busy;
            button.classList.toggle('is-loading', busy);
        }

        /* Collect the listed fields of a form as a urlencoded body, which is
           what account-update.php reads. Keeping the field list declarative
           avoids reaching into individual inputs in the submit handlers. */
        function serialize(form, keys) {
            var payload = new URLSearchParams();

            keys.forEach(function (key) {
                var input = form.querySelector('[name="' + key + '"]');
                payload.append(key, input ? input.value : '');
            });

            return payload;
        }

        /* The email confirmation field is a control, not a stored value: it
           only exists while the submitted email differs from the stored one. */
        function syncEmailConfirm() {
            if (!emailConfirm || !emailInput) {
                return;
            }

            var changed = emailInput.value.trim().toLowerCase() !== baseline.email.trim().toLowerCase();

            emailConfirm.hidden = !changed || !editing;

            if (!changed && emailPassword) {
                emailPassword.value = '';
            }
        }

        /* -------------------------------------------------------------- *
         * Personal information states
         * -------------------------------------------------------------- */
        function openEdit() {
            if (editing) {
                return;
            }

            editing = true;
            clearErrors(profileForm);
            clearSuccess('profile');
            root.classList.add('is-editing');
            syncEmailConfirm();

            if (fields.first_name) {
                fields.first_name.focus();
            }
        }

        function closeEdit() {
            if (!editing) {
                return;
            }

            editing = false;
            clearErrors(profileForm);

            /* CANCEL discards the draft: the form is returned to the values
               the page was rendered with, and the database is untouched.
               Removing .is-editing also hides CANCEL and SAVE CHANGES, so
               neither control survives the cancel. */
            Object.keys(fields).forEach(function (key) {
                if (fields[key]) {
                    fields[key].value = baseline[key];
                }
            });

            if (emailPassword) {
                emailPassword.value = '';
            }

            if (emailConfirm) {
                emailConfirm.hidden = true;
            }

            root.classList.remove('is-editing');

            if (editOpenPrimary) {
                editOpenPrimary.focus();
            }
        }

        /* -------------------------------------------------------------- *
         * Security states
         * -------------------------------------------------------------- */
        function openPassword() {
            passwordEditing = true;
            clearErrors(passwordForm);
            clearSuccess('password');
            root.classList.add('is-password-editing');

            var current = document.getElementById('account-current-password');

            if (current) {
                current.focus();
            }
        }

        function closePassword() {
            passwordEditing = false;
            passwordForm.reset();
            clearErrors(passwordForm);
            root.classList.remove('is-password-editing');

            if (passwordOpen) {
                passwordOpen.focus();
            }
        }

        editOpen.forEach(function (button) {
            button.hidden = false;
            button.addEventListener('click', openEdit);
        });

        if (editCancel) {
            editCancel.addEventListener('click', closeEdit);
        }

        if (passwordOpen) {
            passwordOpen.addEventListener('click', openPassword);
        }

        if (passwordCancel) {
            passwordCancel.addEventListener('click', closePassword);
        }

        if (emailInput) {
            emailInput.addEventListener('input', syncEmailConfirm);
        }

        /* Leaving an edit state with unsaved changes is cancelled rather
           than silently discarded, matching the project's other draft
           flows. Escape never submits a form. */
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (passwordEditing) {
                    event.preventDefault();
                    closePassword();
                } else if (editing) {
                    event.preventDefault();
                    closeEdit();
                }
            }
        });

        if (profileForm) {
            profileForm.addEventListener('submit', function (event) {
                event.preventDefault();

                var button = root.querySelector('[data-account-save="profile"]');
                var payload = serialize(profileForm, [
                    'action',
                    'first_name',
                    'last_name',
                    'email',
                    'phone',
                    'current_password',
                    'csrf_token'
                ]);

                clearErrors(profileForm);
                setBusy(button, true);

                fetch(profileForm.getAttribute('action'), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: payload.toString(),
                    credentials: 'same-origin'
                })
                    .then(function (response) {
                        return response.json().catch(function () {
                            return null;
                        });
                    })
                    .then(function (data) {
                        setBusy(button, false);

                        if (!data) {
                            showFormError(profileForm, 'Something went wrong. Please try again.');
                            return;
                        }

                        syncToken(data);

                        if (data.ok) {
                            applyProfile(data.profile);

                            /* Leaving edit mode also hides CANCEL and
                               SAVE CHANGES, and the identity area has
                               already been updated in place — no reload. */
                            editing = false;
                            root.classList.remove('is-editing');

                            if (emailConfirm) {
                                emailConfirm.hidden = true;
                            }

                            if (emailPassword) {
                                emailPassword.value = '';
                            }

                            showSuccess('profile');
                            return;
                        }

                        /* A failure keeps the module in edit mode with the
                           entered values and Save/Cancel still available, so
                           a rejected save is never mistaken for success. */
                        reportFailure(profileForm, data);
                    })
                    .catch(function () {
                        setBusy(button, false);
                        showFormError(profileForm, 'Something went wrong. Please try again.');
                    });
            });
        }

        if (passwordForm) {
            passwordForm.addEventListener('submit', function (event) {
                event.preventDefault();

                var button = root.querySelector('[data-account-save="password"]');
                var payload = serialize(passwordForm, [
                    'action',
                    'current_password',
                    'new_password',
                    'confirm_password',
                    'csrf_token'
                ]);

                clearErrors(passwordForm);
                setBusy(button, true);

                fetch(passwordForm.getAttribute('action'), {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                    },
                    body: payload.toString(),
                    credentials: 'same-origin'
                })
                    .then(function (response) {
                        return response.json().catch(function () {
                            return null;
                        });
                    })
                    .then(function (data) {
                        setBusy(button, false);

                        if (!data) {
                            showFormError(passwordForm, 'Something went wrong. Please try again.');
                            return;
                        }

                        syncToken(data);

                        if (data.ok) {
                            /* Never expose or retain the entered values:
                               the form is cleared, the module collapses back
                               to the masked read state, and no validation
                               state is left behind. */
                            passwordEditing = false;
                            passwordForm.reset();
                            clearErrors(passwordForm);
                            root.classList.remove('is-password-editing');
                            showSuccess('password');

                            if (passwordOpen) {
                                passwordOpen.focus();
                            }

                            return;
                        }

                        /* The form stays open and the entered NEW PASSWORD
                           and CONFIRM PASSWORD values are preserved. A wrong
                           current password is reported once, beside its own
                           field. */
                        reportFailure(passwordForm, data);
                    })
                    .catch(function () {
                        setBusy(button, false);
                        showFormError(passwordForm, 'Something went wrong. Please try again.');
                    });
            });
        }

        /* Reduced motion is respected in CSS; this keeps the timers honest
           so a shortened banner still clears its own state. */
        reducedMotion.addEventListener('change', function () {
            if (!reducedMotion.matches) {
                return;
            }

            Object.keys(successTimers).forEach(function (module) {
                if (successTimers[module]) {
                    window.clearTimeout(successTimers[module]);
                    successTimers[module] = null;
                    clearSuccess(module);
                }
            });
        });
    })();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
