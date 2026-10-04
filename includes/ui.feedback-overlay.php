<?php

/*
 * Customer Feedback overlay.
 *
 * Rendered by ui.footer.php when a page sets $ui_feedback_overlay = true.
 * Submissions are sent to customer/feedback-submit.php, which enforces
 * every business rule server-side and returns JSON. A successful response
 * transitions the overlay into the FEEDBACK SUBMITTED state; an error
 * response is shown inline in the form, never via alert().
 *
 * The photo is optional. It previews in the browser and, when present, is
 * uploaded with the form as multipart/form-data; customer/feedback-submit.php
 * validates and stores it server-side and the browser never learns the
 * stored path.
 *
 * The same overlay serves both modes via data-feedback-mode:
 *   leave - "LEAVE FEEDBACK" / "SUBMIT FEEDBACK" / "FEEDBACK SUBMITTED"
 *   edit  - "EDIT YOUR FEEDBACK" / "SAVE CHANGES" / "FEEDBACK UPDATED"
 * In edit mode the form is prefilled from the customer's stored row and the
 * photo block shows the current photo with CHANGE PHOTO / REMOVE PHOTO.
 * Edit submissions are sent to customer/feedback-update.php, which
 * re-validates ownership and the delivered order, updates the existing
 * row in place, and returns JSON; a successful response transitions the
 * overlay into the FEEDBACK UPDATED state.
 */

require_once __DIR__ . '/ui.php';

?>
<div class="feedback-overlay" id="feedback-overlay" role="dialog" aria-modal="true" aria-labelledby="feedback-overlay-title" hidden>
    <div class="feedback-overlay__backdrop" data-feedback-close></div>

    <div class="feedback-overlay__panel" role="document">
        <button class="feedback-overlay__close" type="button" data-feedback-close aria-label="Close feedback form">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false">
                <line x1="6" y1="6" x2="18" y2="18"></line>
                <line x1="18" y1="6" x2="6" y2="18"></line>
            </svg>
        </button>

        <div class="feedback-overlay__pane" id="feedback-overlay-form-view">
            <p class="feedback-overlay__eyebrow">CUSTOMER FEEDBACK</p>
            <h2 class="feedback-overlay__title" id="feedback-overlay-title">LEAVE FEEDBACK</h2>

            <div class="feedback-overlay__product">
                <div class="feedback-overlay__thumb">
                    <img src="" alt="" hidden>
                    <div class="feedback-overlay__thumb-placeholder" aria-hidden="true">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="3" width="18" height="18" rx="2"></rect>
                            <circle cx="8.5" cy="8.5" r="1.5"></circle>
                            <polyline points="21 15 16 10 5 21"></polyline>
                        </svg>
                    </div>
                </div>
                <div class="feedback-overlay__product-info">
                    <p class="feedback-overlay__product-name" id="feedback-product-name"></p>
                    <p class="feedback-overlay__product-meta" id="feedback-product-meta"></p>
                </div>
            </div>

            <form class="feedback-overlay__form" id="feedback-form" enctype="multipart/form-data" novalidate>
                <div class="feedback-overlay__field">
                    <p class="feedback-overlay__question" id="feedback-rating-question">How was your Hopia Fits find?</p>
                    <div class="feedback-stars" role="radiogroup" aria-labelledby="feedback-rating-question" aria-describedby="feedback-rating-error">
                        <button class="feedback-star" type="button" role="radio" aria-checked="false" aria-label="1 star" data-feedback-star="1" tabindex="0">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                        </button>
                        <button class="feedback-star" type="button" role="radio" aria-checked="false" aria-label="2 stars" data-feedback-star="2" tabindex="-1">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                        </button>
                        <button class="feedback-star" type="button" role="radio" aria-checked="false" aria-label="3 stars" data-feedback-star="3" tabindex="-1">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                        </button>
                        <button class="feedback-star" type="button" role="radio" aria-checked="false" aria-label="4 stars" data-feedback-star="4" tabindex="-1">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                        </button>
                        <button class="feedback-star" type="button" role="radio" aria-checked="false" aria-label="5 stars" data-feedback-star="5" tabindex="-1">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                        </button>
                    </div>
                    <p class="feedback-overlay__error" id="feedback-rating-error" hidden></p>
                </div>

                <div class="feedback-overlay__field">
                    <label class="feedback-overlay__label" for="feedback-text">YOUR FEEDBACK</label>
                    <textarea class="feedback-overlay__textarea" id="feedback-text" name="feedback" rows="4" placeholder="Share your experience..." aria-describedby="feedback-text-error"></textarea>
                    <p class="feedback-overlay__error" id="feedback-text-error" hidden></p>
                </div>

                <div class="feedback-overlay__field feedback-overlay__field--photo">
                    <div class="feedback-overlay__photo-head">
                        <span class="feedback-overlay__label" id="feedback-photo-label">ADD A PHOTO</span>
                        <span class="feedback-overlay__optional">Optional</span>
                    </div>
                    <label class="feedback-upload" for="feedback-photo" id="feedback-upload-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                            <path d="M12 16V4"></path>
                            <path d="m7 9 5-5 5 5"></path>
                            <path d="M5 20h14"></path>
                        </svg>
                        <span>Upload Photo</span>
                        <input class="sr-only" type="file" id="feedback-photo" name="photo" accept="image/*">
                    </label>

                    <div class="feedback-photo-preview" id="feedback-photo-preview" hidden>
                        <div class="feedback-photo-preview__thumb">
                            <img src="" alt="" id="feedback-photo-preview-img">
                        </div>
                        <div class="feedback-photo-preview__meta">
                            <p class="feedback-photo-preview__name" id="feedback-photo-preview-name"></p>
                            <div class="feedback-photo-preview__actions">
                                <button class="feedback-photo-preview__change" type="button" data-feedback-photo-change>CHANGE PHOTO</button>
                                <button class="feedback-photo-preview__remove" type="button" data-feedback-photo-remove>REMOVE PHOTO</button>
                            </div>
                        </div>
                    </div>
                </div>

                <p class="feedback-overlay__error" id="feedback-form-error" hidden></p>

                <div class="feedback-overlay__actions">
                    <button class="feedback-overlay__cancel" type="button" data-feedback-cancel>CANCEL</button>
                    <button class="feedback-overlay__submit" type="submit" id="feedback-submit-action">SUBMIT FEEDBACK</button>
                </div>
            </form>
        </div>

        <div class="feedback-overlay__pane feedback-overlay__success" id="feedback-overlay-success-view" hidden>
            <p class="feedback-overlay__eyebrow">CUSTOMER FEEDBACK</p>
            <div class="feedback-overlay__success-rule" aria-hidden="true"></div>
            <h2 class="feedback-overlay__success-title" id="feedback-success-title">FEEDBACK SUBMITTED</h2>
            <p class="feedback-overlay__success-copy" id="feedback-success-copy">Thank you for sharing<br>your Hopia Fits experience.</p>
            <p class="feedback-overlay__success-note" id="feedback-success-note">Your feedback has been submitted successfully.</p>
            <div class="feedback-overlay__success-actions">
                <button class="feedback-overlay__submit" type="button" data-feedback-continue>CONTINUE SHOPPING</button>
                <button class="feedback-overlay__view" type="button" data-feedback-view data-feedback-route="feedback.php" data-feedback-route-ready="true">VIEW CUSTOMER'S FEEDBACK</button>
                <p class="feedback-overlay__view-note" id="feedback-view-note" hidden></p>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        var overlay = document.getElementById('feedback-overlay');
        if (!overlay) {
            return;
        }

        var formView = document.getElementById('feedback-overlay-form-view');
        var successView = document.getElementById('feedback-overlay-success-view');
        var titleEl = document.getElementById('feedback-overlay-title');
        var nameEl = document.getElementById('feedback-product-name');
        var metaEl = document.getElementById('feedback-product-meta');
        var thumbImg = overlay.querySelector('.feedback-overlay__thumb img');
        var thumbPlaceholder = overlay.querySelector('.feedback-overlay__thumb-placeholder');
        var stars = Array.prototype.slice.call(overlay.querySelectorAll('[data-feedback-star]'));
        var starsGroup = overlay.querySelector('.feedback-stars');
        var form = document.getElementById('feedback-form');
        var textarea = document.getElementById('feedback-text');
        var photoInput = document.getElementById('feedback-photo');
        var photoPreview = document.getElementById('feedback-photo-preview');
        var photoPreviewImg = document.getElementById('feedback-photo-preview-img');
        var photoPreviewName = document.getElementById('feedback-photo-preview-name');
        var uploadLabel = document.getElementById('feedback-upload-label');
        var submitBtn = document.getElementById('feedback-submit-action');
        var successTitleEl = document.getElementById('feedback-success-title');
        var successCopyEl = document.getElementById('feedback-success-copy');
        var successNoteEl = document.getElementById('feedback-success-note');
        var ratingError = document.getElementById('feedback-rating-error');
        var textError = document.getElementById('feedback-text-error');
        var formError = document.getElementById('feedback-form-error');
        var continueBtn = overlay.querySelector('[data-feedback-continue]');
        var viewBtn = overlay.querySelector('[data-feedback-view]');
        var viewNote = document.getElementById('feedback-view-note');
        var lastTrigger = null;
        var currentRating = 0;
        var currentPhoto = null;
        var currentKey = '';
        var currentOrderId = '';
        var currentOrderItemId = '';
        var photoRemoved = false;
        var draft = null;
        var mode = 'leave';

        /* Titles, submit labels, and success copy are keyed by mode so
           EDIT YOUR FEEDBACK reuses this overlay unchanged. */
        var MODE_TITLES = {
            leave: 'LEAVE FEEDBACK',
            edit: 'EDIT YOUR FEEDBACK'
        };

        var MODE_SUBMIT_LABELS = {
            leave: 'SUBMIT FEEDBACK',
            edit: 'SAVE CHANGES'
        };

        var MODE_SUCCESS_TITLES = {
            leave: 'FEEDBACK SUBMITTED',
            edit: 'FEEDBACK UPDATED'
        };

        var MODE_SUCCESS_COPY = {
            leave: 'Thank you for sharing<br>your Hopia Fits experience.',
            edit: 'Thank you for updating<br>your Hopia Fits experience.'
        };

        var MODE_SUCCESS_NOTES = {
            leave: 'Your feedback has been submitted successfully.',
            edit: 'Your feedback has been updated successfully.'
        };

        function setRating(value) {
            currentRating = value;
            stars.forEach(function (star) {
                var starValue = parseInt(star.getAttribute('data-feedback-star'), 10);
                star.classList.toggle('is-active', starValue <= value);
                star.classList.remove('is-preview');
                star.setAttribute('aria-checked', starValue === value ? 'true' : 'false');
                star.setAttribute('tabindex', starValue === value || (value === 0 && starValue === 1) ? '0' : '-1');
            });
        }

        function previewRating(value) {
            stars.forEach(function (star) {
                var starValue = parseInt(star.getAttribute('data-feedback-star'), 10);
                star.classList.toggle('is-preview', starValue <= value);
            });
        }

        function clearPreview() {
            stars.forEach(function (star) {
                star.classList.remove('is-preview');
            });
        }

        function showError(el, message) {
            if (!el) {
                return;
            }
            el.textContent = message;
            el.hidden = false;
        }

        function showFormError(message) {
            showError(formError, message);
        }

        function hideError(el) {
            if (!el) {
                return;
            }
            el.textContent = '';
            el.hidden = true;
        }

        function clearErrors() {
            hideError(ratingError);
            hideError(textError);
            hideError(formError);
            if (textarea) {
                textarea.removeAttribute('aria-invalid');
            }
            if (starsGroup) {
                starsGroup.removeAttribute('aria-invalid');
            }
        }

        function renderPhotoPreview(photo) {
            if (!photoPreview || !photoPreviewImg || !photoPreviewName) {
                return;
            }
            photoPreviewImg.setAttribute('src', photo.dataUrl);
            photoPreviewName.textContent = photo.name;
            photoPreview.hidden = false;
            syncPhotoControls();
        }

        function hidePhotoPreview() {
            if (photoPreview) {
                photoPreview.hidden = true;
            }
            if (photoPreviewImg) {
                photoPreviewImg.removeAttribute('src');
            }
            if (photoPreviewName) {
                photoPreviewName.textContent = '';
            }
            syncPhotoControls();
        }

        /* While a photo is shown (picked or prefilled) the upload label steps
           aside so CHANGE PHOTO is the only way to replace it. */
        function syncPhotoControls() {
            if (uploadLabel) {
                uploadLabel.hidden = photoPreview ? !photoPreview.hidden : false;
            }
        }

        function setPhoto(file) {
            if (!file) {
                currentPhoto = null;
                hidePhotoPreview();
                return;
            }
            photoRemoved = false;
            var reader = new FileReader();
            reader.onload = function (event) {
                currentPhoto = {
                    name: file.name,
                    dataUrl: event.target.result,
                    file: file
                };
                renderPhotoPreview(currentPhoto);
            };
            reader.readAsDataURL(file);
        }

        function removePhoto() {
            currentPhoto = null;
            photoRemoved = true;
            if (photoInput) {
                photoInput.value = '';
            }
            hidePhotoPreview();
        }

        function resetFields() {
            setRating(0);
            clearPreview();
            if (textarea) {
                textarea.value = '';
            }
            removePhoto();
            clearErrors();
            if (viewNote) {
                viewNote.hidden = true;
                viewNote.textContent = '';
            }
        }

        function showFormView() {
            if (successView) {
                successView.hidden = true;
            }
            if (formView) {
                formView.hidden = false;
            }
            overlay.setAttribute('aria-labelledby', 'feedback-overlay-title');
        }

        function showSuccessView() {
            if (formView) {
                formView.hidden = true;
            }
            if (successView) {
                successView.hidden = false;
            }
            overlay.setAttribute('aria-labelledby', 'feedback-success-title');
        }

        function validate() {
            var valid = true;

            if (currentRating < 1) {
                showError(ratingError, 'Please choose a rating from 1 to 5 stars.');
                if (starsGroup) {
                    starsGroup.setAttribute('aria-invalid', 'true');
                }
                valid = false;
            } else {
                hideError(ratingError);
                if (starsGroup) {
                    starsGroup.removeAttribute('aria-invalid');
                }
            }

            var text = textarea ? textarea.value.trim() : '';
            if (text === '') {
                showError(textError, 'Please write a few words about your find.');
                if (textarea) {
                    textarea.setAttribute('aria-invalid', 'true');
                }
                valid = false;
            } else {
                hideError(textError);
                if (textarea) {
                    textarea.removeAttribute('aria-invalid');
                }
            }

            return valid;
        }

        function triggerKey(trigger) {
            return [
                trigger.getAttribute('data-feedback-order') || '',
                trigger.getAttribute('data-feedback-name') || '',
                trigger.getAttribute('data-feedback-size') || '',
                trigger.getAttribute('data-feedback-color') || ''
            ].join('|');
        }

        function saveDraft() {
            if (currentKey === '') {
                return;
            }
            draft = {
                key: currentKey,
                rating: currentRating,
                text: textarea ? textarea.value : '',
                photo: currentPhoto,
                photoRemoved: photoRemoved
            };
        }

        function restoreDraft(saved) {
            setRating(saved.rating || 0);
            if (textarea) {
                textarea.value = saved.text || '';
            }
            photoRemoved = saved.photoRemoved === true && !saved.photo;
            if (saved.photo) {
                currentPhoto = saved.photo;
                renderPhotoPreview(saved.photo);
            } else {
                currentPhoto = null;
                hidePhotoPreview();
            }
            clearErrors();
        }

        function applyPrefill(trigger) {
            var rating = parseInt(trigger.getAttribute('data-feedback-rating') || '0', 10);
            if (rating >= 1 && rating <= 5) {
                setRating(rating);
            }
            if (textarea) {
                textarea.value = trigger.getAttribute('data-feedback-text') || '';
            }
            var photo = trigger.getAttribute('data-feedback-photo') || '';
            if (photo !== '') {
                currentPhoto = {
                    name: trigger.getAttribute('data-feedback-photo-name') || 'Current photo',
                    dataUrl: photo
                };
                photoRemoved = false;
                renderPhotoPreview(currentPhoto);
            }
        }

        function openOverlay(trigger) {
            lastTrigger = trigger || null;
            currentKey = triggerKey(trigger);
            currentOrderId = trigger.getAttribute('data-feedback-order') || '';
            currentOrderItemId = trigger.getAttribute('data-feedback-order-item') || '';
            mode = trigger.getAttribute('data-feedback-mode') || 'leave';
            photoRemoved = false;

            if (titleEl) {
                titleEl.textContent = MODE_TITLES[mode] || MODE_TITLES.leave;
            }
            if (submitBtn) {
                submitBtn.textContent = MODE_SUBMIT_LABELS[mode] || MODE_SUBMIT_LABELS.leave;
            }

            var name = trigger.getAttribute('data-feedback-name') || '';
            var size = trigger.getAttribute('data-feedback-size') || '';
            var color = trigger.getAttribute('data-feedback-color') || '';
            var image = trigger.getAttribute('data-feedback-image') || '';

            if (nameEl) {
                nameEl.textContent = name !== '' ? name : 'Your item';
            }

            var metaParts = [];
            if (size !== '') {
                metaParts.push('Size ' + size);
            }
            if (color !== '') {
                metaParts.push(color);
            }
            if (metaEl) {
                metaEl.textContent = metaParts.join(' | ');
                metaEl.hidden = metaParts.length === 0;
            }

            if (thumbImg && thumbPlaceholder) {
                if (image !== '') {
                    thumbImg.setAttribute('src', image);
                    thumbImg.hidden = false;
                    thumbPlaceholder.hidden = true;
                } else {
                    thumbImg.removeAttribute('src');
                    thumbImg.hidden = true;
                    thumbPlaceholder.hidden = false;
                }
            }

            showFormView();

            if (draft && draft.key === currentKey) {
                restoreDraft(draft);
            } else {
                resetFields();
                if (mode === 'edit') {
                    applyPrefill(trigger);
                }
            }

            overlay.hidden = false;
            document.body.classList.add('is-modal-open');

            if (stars.length > 0) {
                stars[0].focus();
            } else {
                var closeBtn = overlay.querySelector('.feedback-overlay__close');
                if (closeBtn) {
                    closeBtn.focus();
                }
            }
        }

        function closeOverlay() {
            overlay.hidden = true;
            document.body.classList.remove('is-modal-open');
            if (lastTrigger && document.contains(lastTrigger)) {
                lastTrigger.focus();
            }
            lastTrigger = null;
        }

        /* Dismissing (close icon, backdrop, Escape) keeps the in-progress draft. */
        function dismissOverlay() {
            saveDraft();
            closeOverlay();
        }

        /* Explicit cancel discards the draft and never shows success. */
        function cancelOverlay() {
            draft = null;
            resetFields();
            closeOverlay();
        }

        function submitSuccess() {
            draft = null;
            resetFields();
            if (successTitleEl) {
                successTitleEl.textContent = MODE_SUCCESS_TITLES[mode] || MODE_SUCCESS_TITLES.leave;
            }
            if (successCopyEl) {
                successCopyEl.innerHTML = MODE_SUCCESS_COPY[mode] || MODE_SUCCESS_COPY.leave;
            }
            if (successNoteEl) {
                successNoteEl.textContent = MODE_SUCCESS_NOTES[mode] || MODE_SUCCESS_NOTES.leave;
            }
            showSuccessView();
            if (continueBtn) {
                continueBtn.focus();
            }
        }

        document.addEventListener('click', function (event) {
            var trigger = event.target.closest('[data-feedback-open]');
            if (!trigger) {
                return;
            }
            event.preventDefault();
            openOverlay(trigger);
        });

        overlay.addEventListener('click', function (event) {
            if (event.target.closest('[data-feedback-cancel]')) {
                event.preventDefault();
                cancelOverlay();
                return;
            }

            if (event.target.closest('[data-feedback-close]')) {
                event.preventDefault();
                if (successView && !successView.hidden) {
                    closeOverlay();
                } else {
                    dismissOverlay();
                }
            }
        });

        document.addEventListener('keydown', function (event) {
            if (overlay.hidden) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                if (successView && !successView.hidden) {
                    closeOverlay();
                } else {
                    dismissOverlay();
                }
            }
        });

        stars.forEach(function (star) {
            var value = parseInt(star.getAttribute('data-feedback-star'), 10);

            star.addEventListener('click', function () {
                setRating(value);
                hideError(ratingError);
                if (starsGroup) {
                    starsGroup.removeAttribute('aria-invalid');
                }
            });

            star.addEventListener('mouseenter', function () {
                previewRating(value);
            });

            star.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight' || event.key === 'ArrowUp') {
                    next = Math.min(5, value + 1);
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') {
                    next = Math.max(1, value - 1);
                } else if (event.key === 'Home') {
                    next = 1;
                } else if (event.key === 'End') {
                    next = 5;
                }

                if (next !== null) {
                    event.preventDefault();
                    setRating(next);
                    hideError(ratingError);
                    var target = stars[next - 1];
                    if (target) {
                        target.focus();
                    }
                }
            });
        });

        if (starsGroup) {
            starsGroup.addEventListener('mouseleave', clearPreview);
        }

        if (textarea) {
            textarea.addEventListener('input', function () {
                if (textarea.value.trim() !== '') {
                    hideError(textError);
                    textarea.removeAttribute('aria-invalid');
                }
            });
        }

        if (photoInput) {
            photoInput.addEventListener('change', function () {
                var file = photoInput.files && photoInput.files[0];
                setPhoto(file || null);
            });
        }

        overlay.querySelectorAll('[data-feedback-photo-remove]').forEach(function (button) {
            button.addEventListener('click', function () {
                removePhoto();
            });
        });

        overlay.querySelectorAll('[data-feedback-photo-change]').forEach(function (button) {
            button.addEventListener('click', function () {
                if (photoInput) {
                    photoInput.click();
                }
            });
        });

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();

                /* Front-end validation first; the server re-checks everything. */
                if (!validate()) {
                    if (currentRating < 1 && stars.length > 0) {
                        stars[0].focus();
                    } else if (textarea) {
                        textarea.focus();
                    }
                    return;
                }

                if (currentOrderId === '' || currentOrderItemId === '') {
                    showFormError('Please choose an item to leave feedback for.');
                    return;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                }

                /* FormData lets the optional photo ride along as a real file
                   upload; the browser sets the multipart boundary itself. In
                   edit mode photo_action tells the update endpoint whether
                   the stored photo is kept, replaced, or removed. */
                var body = new FormData();
                body.append('order_id', currentOrderId);
                body.append('order_item_id', currentOrderItemId);
                body.append('rating', String(currentRating));
                body.append('feedback_text', textarea ? textarea.value : '');
                if (currentPhoto && currentPhoto.file) {
                    body.append('photo', currentPhoto.file);
                    body.append('photo_action', 'replace');
                } else if (photoRemoved) {
                    body.append('photo_action', 'remove');
                } else {
                    body.append('photo_action', 'keep');
                }

                fetch(mode === 'edit' ? 'feedback-update.php' : 'feedback-submit.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: body
                })
                    .then(function (response) {
                        return response.json().catch(function () {
                            return null;
                        });
                    })
                    .then(function (data) {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }

                        if (data && data.ok) {
                            submitSuccess();
                            return;
                        }

                        showFormError(data && data.message
                            ? data.message
                            : 'Something went wrong. Please try again.');
                    })
                    .catch(function () {
                        if (submitBtn) {
                            submitBtn.disabled = false;
                        }
                        showFormError('Something went wrong. Please try again.');
                    });
            });
        }

        if (continueBtn) {
            continueBtn.addEventListener('click', function () {
                closeOverlay();
            });
        }

        if (viewBtn) {
            viewBtn.addEventListener('click', function () {
                var ready = viewBtn.getAttribute('data-feedback-route-ready') === 'true';
                var route = viewBtn.getAttribute('data-feedback-route') || '';
                if (ready && route !== '') {
                    window.location.href = route;
                    return;
                }
                if (viewNote) {
                    viewNote.textContent = 'This page is coming soon.';
                    viewNote.hidden = false;
                }
            });
        }
    })();
</script>
