<?php

/*
 * Hopia Fits - Phase A walk-in scan-to-sell page.
 *
 * Authenticated admin page. Lets a cashier build a DRAFT sale by scanning
 * (phone/web camera) or typing barcodes, review the items and running total,
 * then explicitly press "Complete Sale". Scanning only looks items up and
 * adds them to the browser draft; nothing is marked SOLD and no sale exists
 * until the cashier confirms and the server commits.
 *
 * Camera scanning, manual entry, and a future USB/Bluetooth keyboard-wedge
 * scanner all funnel through the SAME browser function (addBarcodeToDraft)
 * and the SAME server endpoints, so server-side sale logic never changes with
 * the input method.
 */

session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/dynamsoft_scanner.php';

// The guarded test router sets this only after verifying its isolated database.
$walkinScannerTest = defined('HOPIA_WALKIN_ZXING_TEST') && HOPIA_WALKIN_ZXING_TEST === true;

/*
 * Stable per-session CSRF token for the walk-in endpoints. It is intentionally
 * separate from $_SESSION['csrf_token'] (used by the one-shot admin forms) so
 * that repeated barcode lookups during a draft never clobber those tokens, and
 * so an idempotent sale retry can still present a valid token.
 */
if (empty($_SESSION['walkin_csrf'])) {
    $_SESSION['walkin_csrf'] = bin2hex(random_bytes(32));
}
$walkinCsrf = $_SESSION['walkin_csrf'];

$page_title = "Scan & Sell - Admin - Hopia's Ukay-Ukay";
$page_description = "Record a walk-in sale by scanning item barcodes.";
$ui_section = 'admin';
$ui_active = 'scan.php';
$body_class = 'admin-scan-page';

require __DIR__ . '/../includes/ui.head.php';
require __DIR__ . '/../includes/ui.header.php';
?>

<style>
/* Scoped layout for the walk-in scan page (keeps css/styles.css untouched). */
.scan-grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
@media (min-width: 900px) { .scan-grid { grid-template-columns: 1fr 1fr; align-items: start; } }

/* Camera off-state placeholder (same size class as the live preview). */
.scan-reader-frame { width: 100%; max-width: 640px; min-height: 240px; margin: 0 auto; display: grid; place-items: center; border: 1px dashed var(--admin-border-strong, #11100e33); border-radius: 12px; color: var(--admin-text-muted, #11100e99); font-size: .9rem; padding: 1rem; text-align: center; }
.scan-reader-frame[hidden] { display: none; }

/* Camera preview: fills the phone screen width (capped at 640px) and stays
   responsive on rotation/resize. The wrapper hosts the library's video and the
   overlay hint/state, which live OUTSIDE #scan-reader so the library can own
   that element's children. */
.scan-reader-wrap { position: relative; width: 100%; max-width: 640px; margin: 0 auto; border-radius: 12px; overflow: hidden; background: #11100e; transition: box-shadow .15s ease; }
.scan-reader-wrap[hidden] { display: none; }
.scan-reader-wrap.is-flash { box-shadow: 0 0 0 4px var(--admin-success, #1a7f37); }
.scan-reader { width: 100%; }
.scan-reader video { display: block; width: 100% !important; height: auto; background: #11100e; }
.scan-reader__hint { position: absolute; top: 8px; left: 8px; right: 8px; margin: 0; padding: 6px 10px; border-radius: 8px; background: rgba(17,16,14,.75); color: #fff; font-size: .82rem; line-height: 1.4; text-align: center; pointer-events: none; }
.scan-reader__state { position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); margin: 0; padding: 5px 14px; border-radius: 999px; background: rgba(17,16,14,.85); color: #fff; font-size: .85rem; font-weight: 600; max-width: 92%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; text-align: center; pointer-events: none; }
.scan-reader__state[data-kind="starting"] { background: rgba(138,90,0,.92); }
.scan-reader__state[data-kind="ready"] { background: rgba(29,122,60,.92); }
.scan-reader__state[data-kind="ok"] { background: #1a7f37; }
.scan-reader__state[data-kind="warn"] { background: rgba(138,90,0,.92); }
.scan-reader__state[data-kind="error"], .scan-reader__state[data-kind="offline"] { background: rgba(180,35,24,.92); }
.scan-controls { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; align-items: center; }
.scan-controls .btn[hidden] { display: none; }
.scan-zoom { display: inline-flex; align-items: center; gap: .45rem; font-size: .85rem; color: var(--admin-text, #11100e); }
.scan-zoom[hidden] { display: none; }
.scan-zoom input[type="range"] { width: 120px; }
.scan-manual { display: flex; gap: .5rem; flex-wrap: wrap; align-items: flex-end; }
.scan-manual .field { flex: 1 1 220px; margin: 0; }
.scan-draft-total { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; margin: 1rem 0; padding-top: 1rem; border-top: 1px solid var(--admin-border, #11100e22); }
.scan-draft-total__value { font-size: 1.5rem; font-weight: 700; }
.scan-status { margin: .75rem 0; }
.scan-remove { background: none; border: none; color: var(--admin-danger, #b42318); cursor: pointer; text-decoration: underline; font: inherit; }
.scan-draft-empty { color: var(--admin-text-muted, #11100e99); padding: 1rem 0; }
<?php if ($walkinScannerTest): ?>
/* Reuse the admin popup; errors use the existing alert-error palette. */
.ui-admin #admin-success[data-scan-kind="error"] { --admin-accent-soft: var(--color-danger-soft); --admin-accent-strong: var(--admin-danger, var(--color-danger-dark)); background: var(--color-danger-soft); color: var(--admin-danger, var(--color-danger-dark)); border-color: var(--admin-danger-border, #f0cfcd); border-left-color: var(--color-danger); }
/* Keep the test camera preview and its controls in separate responsive rows. */
.scan-grid > .card { min-width: 0; }
.scan-reader-wrap, .scan-reader-frame { max-width: none; margin-top: .75rem; scroll-margin-top: 5rem; }
.scan-reader-frame { min-height: clamp(320px, 52vh, 420px); min-height: clamp(320px, 52svh, 420px); }
.scan-camera-preview { width: 100% !important; height: clamp(320px, 52vh, 420px) !important; height: clamp(320px, 52svh, 420px) !important; }
.scan-reader video { height: 100% !important; object-fit: contain; }
.scan-camera-settings { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; padding: .875rem; background: var(--admin-surface, #fff); color: var(--admin-text, #11100e); border-bottom: 1px solid var(--admin-border, #11100e22); }
.scan-camera-setting { display: grid; gap: .35rem; min-width: 0; font-size: .85rem; font-weight: 600; }
.scan-camera-setting select { width: 100%; max-width: 100%; min-width: 0; min-height: 44px; margin: 0 !important; padding: .5rem; font: inherit; color: inherit; background: var(--admin-surface, #fff); border: 1px solid var(--admin-border-strong, #11100e33); border-radius: 6px; text-overflow: ellipsis; }
.scan-camera-setting[hidden] { display: none; }
.scan-camera-preview { position: relative; }
.scan-camera-guide { position: absolute; left: 5%; top: 25%; width: 90%; height: 50%; border: 2px solid #fe8e14; pointer-events: none; }
.scan-camera-resolution { padding: .5rem 0; min-height: 44px; display: flex; align-items: center; }
.scan-camera-capabilities { grid-column: 1 / -1; margin: 0; font-size: .8rem; font-weight: 400; }
.scan-reader__hint { position: static; margin: 0; padding: .75rem .875rem; border-radius: 0; }
.scan-reader__state { position: static; transform: none; width: fit-content; max-width: calc(100% - 1.75rem); margin: 0 auto .875rem; white-space: normal; overflow-wrap: anywhere; }
@media (max-width: 480px) { .scan-camera-settings { grid-template-columns: minmax(0, 1fr); } }
@media (max-height: 500px) and (orientation: landscape) { .scan-camera-preview { height: clamp(240px, 60vh, 320px) !important; height: clamp(240px, 60svh, 320px) !important; } }
<?php endif; ?>
</style>

<div class="admin-products admin-scan">
    <header class="admin-page-header">
        <div>
            <p class="admin-page-header__eyebrow">Point of sale</p>
            <h1>SCAN &amp; SELL</h1>
            <p class="admin-page-header__copy">
                Scan or type each item's barcode to build the sale, review the list and total,
                then press <strong>Complete Sale</strong>. Scanning never sells an item on its own.
            </p>
        </div>
        <a class="btn btn-ghost" href="sales.php">View Sales</a>
    </header>

    <div id="scan-status" class="scan-status" role="status" aria-live="polite"></div>

    <div class="scan-grid">
        <section class="card" aria-labelledby="scan-input-title">
            <div class="card-body">
                <div class="admin-section-heading">
                    <div>
                        <p class="admin-section-heading__eyebrow">Add items</p>
                        <h2 id="scan-input-title">Scan or enter a barcode</h2>
                    </div>
                </div>

                <form id="scan-manual-form" class="scan-manual" autocomplete="off">
                    <div class="field">
                        <label for="scan-barcode-input">Barcode</label>
                        <input
                            type="text"
                            id="scan-barcode-input"
                            name="barcode"
                            class="input"
                            inputmode="text"
                            autocapitalize="characters"
                            placeholder="e.g. HOPEBUNDLE-000005-01"
                            aria-describedby="scan-input-hint"
                        >
                    </div>
                    <button type="submit" class="btn btn-primary">Add Item</button>
                </form>
                <p id="scan-input-hint" class="hint">
                    Works with a USB/Bluetooth scanner (it types the code and presses Enter),
                    the camera below, or manual typing.
                </p>

                <div class="scan-controls">
                    <button type="button" id="scan-camera-start" class="btn btn-secondary btn-sm">Start Camera</button>
                    <button type="button" id="scan-camera-stop" class="btn btn-ghost btn-sm" hidden>Stop Camera</button>
                    <button type="button" id="scan-torch" class="btn btn-secondary btn-sm" hidden aria-pressed="false">Flashlight: Off</button>
                    <label id="scan-zoom-control" class="scan-zoom" hidden>
                        <span>Zoom</span>
                        <input type="range" id="scan-zoom" aria-label="Camera zoom">
                    </label>
                </div>

                <div id="scan-reader-off" class="scan-reader-frame">
                    Camera is off. Press <strong>&nbsp;Start Camera&nbsp;</strong> to scan with your device camera,
                    or keep using manual entry.
                </div>

                <div id="scan-reader-wrap" class="scan-reader-wrap" hidden>
<?php if ($walkinScannerTest): ?>
                    <div class="scan-camera-settings" role="group" aria-label="Camera settings">
                        <label class="scan-camera-setting">
                            <span>Camera</span>
                            <select id="scan-camera-select" disabled aria-label="Camera"></select>
                        </label>
                        <div class="scan-camera-setting">
                            <span id="scan-resolution-label">Resolution</span>
                            <output id="scan-camera-resolution" class="scan-camera-resolution" aria-labelledby="scan-resolution-label">Camera off</output>
                        </div>
                        <p id="scan-camera-capabilities" class="scan-camera-capabilities"></p>
                    </div>
<?php endif; ?>
                    <div id="scan-reader" class="scan-reader"></div>
                    <p class="scan-reader__hint">
                        Hold steady, 10&ndash;15&nbsp;cm away. Fit the <strong>entire</strong> barcode inside the
                        frame, including its outer bars.
                    </p>
                    <p class="scan-reader__state" id="scan-camera-state" role="status" data-kind="starting">Starting camera&hellip;</p>
                </div>
            </div>
        </section>

        <section class="card" aria-labelledby="scan-draft-title">
            <div class="card-body">
                <div class="admin-section-heading">
                    <div>
                        <p class="admin-section-heading__eyebrow">Current sale</p>
                        <h2 id="scan-draft-title">Draft (<span id="scan-draft-count">0</span>)</h2>
                    </div>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th scope="col">Item</th>
                                <th scope="col">Barcode</th>
                                <th scope="col">Price</th>
                                <th scope="col"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody id="scan-draft-body">
                            <tr id="scan-draft-empty-row">
                                <td colspan="4" class="scan-draft-empty">No items yet. Scan or enter a barcode to begin.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="scan-draft-total">
                    <span>Total</span>
                    <span class="scan-draft-total__value">&#8369;<span id="scan-draft-total">0.00</span></span>
                </div>

                <button type="button" id="scan-complete" class="btn btn-primary btn-block" disabled>
                    Complete Sale
                </button>
            </div>
        </section>
    </div>
</div>

<?php if ($walkinScannerTest): ?>
<script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.23.0/umd/index.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@zxing/browser@0.2.1/umd/zxing-browser.min.js"></script>
<?php else: ?>
<?php hopia_dynamsoft_assets(); ?>
<?php endif; ?>
<script>
(function () {
    'use strict';

    var CONFIG = {
        csrf: <?= json_encode($walkinCsrf) ?>,
        lookupUrl: 'api/barcode-lookup.php',
        saleUrl: 'api/walkin-sale.php',
    };

    var draft = [];          // [{id, name, category, size, color, barcode, price}]
    var inFlight = {};       // barcodes with a lookup currently running
    var requestKey = newRequestKey();
    var saleInProgress = false;

    var elInput = document.getElementById('scan-barcode-input');
    var elForm = document.getElementById('scan-manual-form');
    var elBody = document.getElementById('scan-draft-body');
    var elEmptyRow = document.getElementById('scan-draft-empty-row');
    var elCount = document.getElementById('scan-draft-count');
    var elTotal = document.getElementById('scan-draft-total');
    var elComplete = document.getElementById('scan-complete');
    var elStatus = document.getElementById('scan-status');
    var elStart = document.getElementById('scan-camera-start');
    var elStop = document.getElementById('scan-camera-stop');
    var elReaderOff = document.getElementById('scan-reader-off');
    var elReader = document.getElementById('scan-reader');
    var elReaderWrap = document.getElementById('scan-reader-wrap');
    var elCameraState = document.getElementById('scan-camera-state');
    var elTorch = document.getElementById('scan-torch');
    var elZoomControl = document.getElementById('scan-zoom-control');
    var elZoom = document.getElementById('scan-zoom');

<?php if ($walkinScannerTest): ?>
    var cameraControls = null;
    var cameraStream = null;
    var cameraVideo = null;
    var cameraEpoch = 0;
    var zoomRequest = 0;
    var elCameraSelect = document.getElementById('scan-camera-select');
    var elResolution = document.getElementById('scan-camera-resolution');
    var elCapabilities = document.getElementById('scan-camera-capabilities');
<?php else: ?>
    var cameraScanner = null;
<?php endif; ?>
    var cameraRunning = false;
    var cameraStarting = false;
    var lastScan = { code: '', at: 0 };
    var torchSupported = false;
    var torchOn = false;
    var zoomCaps = null;
    var audioCtx = null;     // reused Web Audio context (created on Start Camera tap)

    function newRequestKey() {
        try {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            if (window.crypto && window.crypto.getRandomValues) {
                var buf = new Uint8Array(16);
                window.crypto.getRandomValues(buf);
                return Array.prototype.map.call(buf, function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
            }
        } catch (e) { /* fall through */ }
        return 'k' + Date.now().toString(16) + Math.random().toString(16).slice(2, 10);
    }

    function priceToCents(priceStr) {
        var n = parseFloat(priceStr);
        if (!isFinite(n) || n < 0) { return 0; }
        return Math.round(n * 100);
    }

    function centsToString(cents) {
        return (cents / 100).toFixed(2);
    }

    function totalCents() {
        return draft.reduce(function (sum, item) { return sum + priceToCents(item.price); }, 0);
    }

    /* Initialize (or resume a suspended) Web Audio context.
       Returns null if the browser does not support Web Audio — callers
       must check and skip silently. */
    function getAudioCtx() {
        if (audioCtx) { return audioCtx; }
        try {
            var Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) { return null; }
            audioCtx = new Ctor();
        } catch (e) { audioCtx = null; }
        return audioCtx;
    }

    /* Play one crisp electronic beep using a short gain-faded oscillator.
       This mimics a handheld barcode scanner: ~800 Hz square wave, 80 ms. */
    function playSuccessBeep() {
        var ctx = getAudioCtx();
        if (!ctx) { return; }
        try {
            if (ctx.state === 'suspended') { ctx.resume(); }
            var osc = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'square';
            osc.frequency.setValueAtTime(800, ctx.currentTime);
            gain.gain.setValueAtTime(0, ctx.currentTime);
            gain.gain.linearRampToValueAtTime(0.20, ctx.currentTime + 0.005);
            gain.gain.setValueAtTime(0.20, ctx.currentTime + 0.070);
            gain.gain.linearRampToValueAtTime(0, ctx.currentTime + 0.090);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.100);
        } catch (e) { /* browser blocked playback */ }
    }

    function setStatus(message, kind) {
        elStatus.textContent = '';
        if (!message) { return; }
        var box = document.createElement('div');
        box.className = 'alert ' + (kind === 'error' ? 'alert-error' : (kind === 'success' ? 'alert-success' : 'alert-info'));
        box.setAttribute('role', kind === 'error' ? 'alert' : 'status');
        box.textContent = message;
        elStatus.appendChild(box);
    }

    function setLookupStatus(message, kind) {
        setStatus(message, kind);
<?php if ($walkinScannerTest): ?>
        // One shared popup replaces the previous result; decoded frames alone never call it.
        var popup = document.getElementById('admin-success');
        if (!popup || !window.hopiaAdminSuccess) { return; }
        var isError = kind === 'error';
        popup.setAttribute('data-scan-kind', kind);
        var popupMessage = popup.querySelector('[data-admin-success-message]');
        popupMessage.setAttribute('role', isError ? 'alert' : 'status');
        popupMessage.setAttribute('aria-live', isError ? 'assertive' : 'polite');
        popup.querySelector('[data-admin-success-close]').setAttribute('aria-label', 'Dismiss ' + (isError ? 'error' : 'success') + ' message');
        popup.querySelector('.admin-success__icon path').setAttribute('d', isError ? 'M12 8v4m0 4h.01' : 'm8 12 2.5 2.5L16 9');
        window.hopiaAdminSuccess.show(message);
<?php endif; ?>
    }

    /* Camera overlay state bar — does NOT replace the page-level setStatus
       (which carries CSRF/network error messages). */
    function setCameraState(text, kind) {
        if (!elCameraState) { return; }
        elCameraState.textContent = text;
        elCameraState.setAttribute('data-kind', kind);
    }

    function escapeText(value) {
        return String(value == null ? '' : value);
    }

    function render() {
        // Remove existing item rows (keep the empty-row element reference).
        Array.prototype.slice.call(elBody.querySelectorAll('tr[data-barcode]')).forEach(function (tr) {
            tr.parentNode.removeChild(tr);
        });

        if (draft.length === 0) {
            elEmptyRow.hidden = false;
        } else {
            elEmptyRow.hidden = true;
            draft.forEach(function (item) {
                var tr = document.createElement('tr');
                tr.setAttribute('data-barcode', item.barcode);

                var nameTd = document.createElement('td');
                var strong = document.createElement('strong');
                strong.textContent = escapeText(item.name);
                nameTd.appendChild(strong);
                var meta = [];
                if (item.category) { meta.push(item.category); }
                if (item.size) { meta.push('Size ' + item.size); }
                if (item.color) { meta.push(item.color); }
                if (meta.length) {
                    var small = document.createElement('div');
                    small.className = 'text-small muted';
                    small.textContent = meta.join(' \u00b7 ');
                    nameTd.appendChild(small);
                }

                var codeTd = document.createElement('td');
                var code = document.createElement('code');
                code.textContent = escapeText(item.barcode);
                codeTd.appendChild(code);

                var priceTd = document.createElement('td');
                priceTd.textContent = '\u20b1' + centsToString(priceToCents(item.price));

                var removeTd = document.createElement('td');
                var removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.className = 'scan-remove';
                removeBtn.textContent = 'Remove';
                removeBtn.addEventListener('click', function () { removeItem(item.barcode); });
                removeTd.appendChild(removeBtn);

                tr.appendChild(nameTd);
                tr.appendChild(codeTd);
                tr.appendChild(priceTd);
                tr.appendChild(removeTd);
                elBody.appendChild(tr);
            });
        }

        elCount.textContent = String(draft.length);
        elTotal.textContent = centsToString(totalCents());
        elComplete.disabled = (draft.length === 0 || saleInProgress);
    }

    function inDraft(barcode) {
        return draft.some(function (item) { return item.barcode === barcode; });
    }

    // Any change to the draft composition starts a NEW idempotency key, so a
    // completed sale's key is bound to its exact item set.
    function resetRequestKey() { requestKey = newRequestKey(); }

    function removeItem(barcode) {
        draft = draft.filter(function (item) { return item.barcode !== barcode; });
        resetRequestKey();
        render();
    }

    // The single shared entry point for camera scans, manual entry, and a
    // future USB/Bluetooth wedge scanner.
    function addBarcodeToDraft(rawCode) {
        var barcode = String(rawCode == null ? '' : rawCode).trim();
        if (barcode === '') { return; }

        if (inDraft(barcode)) {
            setStatus('That item is already in the current sale.', 'info');
            return;
        }
        if (inFlight[barcode]) { return; }
        inFlight[barcode] = true;

        var body = new URLSearchParams();
        body.set('csrf_token', CONFIG.csrf);
        body.set('barcode', barcode);
        draft.forEach(function (item) { body.append('draft[]', item.barcode); });

        fetch(CONFIG.lookupUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (data) { return { status: res.status, data: data }; });
        }).then(function (result) {
            delete inFlight[barcode];
            var data = result.data || {};
            if (data.ok && data.product) {
                if (inDraft(data.product.barcode)) {
                    setStatus('That item is already in the current sale.', 'info');
                    return;
                }
                draft.push(data.product);
                resetRequestKey();
                render();
                setLookupStatus(<?= $walkinScannerTest ? "'Added to sale draft: '" : "'Added: '" ?> + data.product.name + ' (\u20b1' + data.product.price + ').', 'success');
                setCameraState('\u2713 ' + data.product.name, 'ok');
                setTimeout(function () { setCameraState('Point camera at next barcode', 'ready'); }, 3000);
                if (navigator.vibrate) { navigator.vibrate(80); }
                // One beep per accepted scan: fires only after the lookup
                // succeeded AND the product was actually added to the draft.
                playSuccessBeep();
            } else {
                setLookupStatus(data.message || 'That item could not be added.', 'error');
            }
        }).catch(function () {
            delete inFlight[barcode];
            setLookupStatus('Could not reach the server. Please check your connection and try again.', 'error');
        });
    }

    // ---- Manual entry + USB wedge (same handler) ----
    elForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var value = elInput.value;
        elInput.value = '';
        elInput.focus();
        addBarcodeToDraft(value);
    });

    // ---- Complete Sale ----
    elComplete.addEventListener('click', function () {
        if (saleInProgress || draft.length === 0) { return; }
        saleInProgress = true;
        elComplete.disabled = true;
        elComplete.textContent = 'Completing\u2026';

        var body = new URLSearchParams();
        body.set('csrf_token', CONFIG.csrf);
        body.set('request_key', requestKey);
        body.set('expected_total', centsToString(totalCents()));
        draft.forEach(function (item) {
            body.append('barcodes[]', item.barcode);
            body.append('prices[]', item.price);   // reviewed preview price for per-item staleness check
        });

        fetch(CONFIG.saleUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' },
            body: body.toString()
        }).then(function (res) {
            return res.json().then(function (data) { return { status: res.status, data: data }; });
        }).then(function (result) {
            var data = result.data || {};
            if (data.ok && data.redirect) {
                // Durable receipt reflects the committed transaction.
                window.location.href = data.redirect;
                return;
            }

            saleInProgress = false;
            elComplete.textContent = 'Complete Sale';

            if (data.reason === 'price_changed' && Array.isArray(data.items)) {
                var priceByBarcode = {};
                data.items.forEach(function (it) { priceByBarcode[it.barcode] = it.price; });
                draft.forEach(function (item) {
                    if (priceByBarcode.hasOwnProperty(item.barcode)) { item.price = priceByBarcode[item.barcode]; }
                });
                resetRequestKey();
                render();
                setStatus(data.message || 'Prices changed. Please review the updated total, then complete the sale again.', 'error');
                return;
            }

            // key_conflict: same key used with a different item set — the draft is
            // now stale; give the cashier a clean slate rather than silently returning
            // an unrelated old receipt or creating a second transaction.
            if (data.reason === 'key_conflict') {
                draft = [];
                resetRequestKey();
                render();
                setStatus(data.message || 'The request was reused with different items. A fresh draft has been started.', 'error');
                return;
            }

            // Remove any specifically-named problem items so the cashier can retry.
            if (Array.isArray(data.barcodes) && data.barcodes.length) {
                draft = draft.filter(function (item) { return data.barcodes.indexOf(item.barcode) === -1; });
                resetRequestKey();
            }
            render();
            setStatus(data.message || 'The sale could not be completed.', 'error');
        }).catch(function () {
            saleInProgress = false;
            elComplete.textContent = 'Complete Sale';
            render();
            setStatus('Could not reach the server. The sale was not completed. Please try again.', 'error');
        });
    });

    // ---- Camera ----
    function cameraSupported() {
<?php if ($walkinScannerTest): ?>
        return typeof ZXingBrowser !== 'undefined' && ZXingBrowser.BrowserMultiFormatReader
            && typeof ZXing !== 'undefined' && ZXing.DecodeHintType && ZXing.BarcodeFormat
<?php else: ?>
        return typeof HopiaDynamsoftScanner !== 'undefined' && HopiaDynamsoftScanner.isSupported()
<?php endif; ?>
            && navigator.mediaDevices
            && typeof navigator.mediaDevices.getUserMedia === 'function';
    }

    function secureContextOk() {
        if (window.isSecureContext) { return true; }
        var host = window.location.hostname;
        return host === 'localhost' || host === '127.0.0.1' || host === '::1';
    }

    function onScanSuccess(decodedText) {
        var now = Date.now();
<?php if ($walkinScannerTest): ?>
        // Keep the code latched until it leaves view; even failed lookups notify only once.
        if (decodedText === lastScan.code) { lastScan.at = now; return; }
<?php else: ?>
        if (decodedText === lastScan.code && (now - lastScan.at) < 1500) { return; }
<?php endif; ?>
        lastScan = { code: decodedText, at: now };
        addBarcodeToDraft(decodedText);
    }

<?php if ($walkinScannerTest): ?>
    function updateCapabilityMessage() {
        var unavailable = [];
        if (!torchSupported) { unavailable.push('Flashlight'); }
        if (!zoomCaps) { unavailable.push('Zoom'); }
        elCapabilities.textContent = unavailable.length ? unavailable.join(' and ') + ' unavailable on this camera/browser.' : '';
    }

    function probeCameraCaps(track) {
        try {
            var caps = track.getCapabilities();
            var settings = track.getSettings();
            torchSupported = caps.torch === true && typeof cameraControls.switchTorch === 'function';
            elTorch.hidden = !torchSupported;
            if (caps.zoom && caps.zoom.max > caps.zoom.min && typeof settings.zoom === 'number') {
                zoomCaps = caps.zoom;
                elZoom.min = caps.zoom.min;
                elZoom.max = caps.zoom.max;
                elZoom.step = caps.zoom.step > 0 ? caps.zoom.step : 0.1;
                elZoom.value = settings.zoom;
                elZoomControl.hidden = false;
            }
        } catch (error) { /* Missing capability APIs mean these controls stay hidden. */ }
        updateCapabilityMessage();
    }

    async function applyZoom(rawValue) {
        if (!zoomCaps || !cameraStream) { return; }
        var track = cameraStream.getVideoTracks()[0];
        var value = Math.min(zoomCaps.max, Math.max(zoomCaps.min, Number(rawValue)));
        if (!isFinite(value)) { return; }
        var run = cameraEpoch;
        var request = ++zoomRequest;
        try {
            await track.applyConstraints({ advanced: [{ zoom: value }] });
            if (run !== cameraEpoch || request !== zoomRequest) { return; }
            var actual = track.getSettings().zoom;
            if (typeof actual !== 'number' || Math.abs(actual - value) > Number(elZoom.step)) { throw new Error('Zoom not applied'); }
            elZoom.value = actual;
        } catch (error) {
            if (run !== cameraEpoch || request !== zoomRequest) { return; }
            zoomCaps = null;
            elZoomControl.hidden = true;
            updateCapabilityMessage();
        }
    }

    async function toggleTorch() {
        if (!torchSupported || !cameraControls) { return; }
        var run = cameraEpoch;
        var nextState = !torchOn;
        elTorch.disabled = true;
        try {
            await cameraControls.switchTorch(nextState);
            if (run !== cameraEpoch) { return; }
            var actual = cameraStream.getVideoTracks()[0].getSettings().torch;
            if (typeof actual === 'boolean' && actual !== nextState) { throw new Error('Torch not applied'); }
            torchOn = nextState;
            elTorch.textContent = 'Flashlight: ' + (torchOn ? 'On' : 'Off');
            elTorch.setAttribute('aria-pressed', torchOn ? 'true' : 'false');
            elReaderWrap.classList.toggle('is-flash', torchOn);
        } catch (error) {
            if (run !== cameraEpoch) { return; }
            torchSupported = false;
            torchOn = false;
            elTorch.hidden = true;
            elReaderWrap.classList.remove('is-flash');
            updateCapabilityMessage();
        } finally {
            if (run === cameraEpoch) { elTorch.disabled = false; }
        }
    }

    function releaseCamera() {
        // Invalidate pending permission/start promises and callbacks before releasing anything.
        cameraEpoch += 1;
        if (cameraControls) {
            try { Promise.resolve(cameraControls.stop()).catch(function () {}); } catch (error) { /* already stopped */ }
        }
        if (cameraStream) { cameraStream.getTracks().forEach(function (track) { track.stop(); }); }
        if (cameraVideo) { cameraVideo.pause(); cameraVideo.srcObject = null; }
        cameraControls = null;
        cameraStream = null;
        cameraVideo = null;
        cameraRunning = false;
        cameraStarting = false;
        torchSupported = false;
        torchOn = false;
        zoomCaps = null;
        lastScan = { code: '', at: 0 };
        elReader.textContent = '';
    }

    function stopCamera() {
        releaseCamera();
        elStart.hidden = false;
        elStop.hidden = true;
        elReaderOff.hidden = false;
        elReaderWrap.hidden = true;
        elTorch.hidden = true;
        elTorch.disabled = false;
        elTorch.textContent = 'Flashlight: Off';
        elTorch.setAttribute('aria-pressed', 'false');
        elZoomControl.hidden = true;
        elCameraSelect.disabled = true;
        elResolution.textContent = 'Camera off';
        elReaderWrap.classList.remove('is-flash');
        setCameraState('', '');
    }

    function showCameraFailure(error) {
        var name = error && error.name || '';
        if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
            setStatus('Camera permission was denied. Allow camera access in your browser settings, or use manual entry.', 'error');
        } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
            setStatus('No camera was found on this device. Manual entry still works.', 'error');
        } else if (name === 'NotReadableError' || name === 'TrackStartError') {
            setStatus('The camera is already in use by another app. Close it and try again, or use manual entry.', 'error');
        } else if (name === 'OverconstrainedError') {
            setStatus('The selected camera settings are not supported. Choose another camera or use manual entry.', 'error');
        } else {
            setStatus('The ZXing camera could not be started. Manual entry still works.', 'error');
        }
    }

    async function startCamera(deviceId) {
        if (cameraStarting || cameraRunning) { return; }
        getAudioCtx();
        if (!cameraSupported() || !secureContextOk()) {
            setStatus('ZXing camera scanning needs HTTPS and a supported browser. Manual entry still works.', 'error');
            return;
        }
        deviceId = typeof deviceId === 'string' ? deviceId : elCameraSelect.value;
        var run = ++cameraEpoch;
        var stream = null;
        cameraStarting = true;
        elStart.hidden = true;
        elStop.hidden = false;
        elReaderOff.hidden = true;
        elReaderWrap.hidden = false;
        elCameraSelect.disabled = true;
        elCapabilities.textContent = '';
        setCameraState('Starting ZXing camera\u2026', 'starting');
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                audio: false,
                video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } }
            });
            if (run !== cameraEpoch) { stream.getTracks().forEach(function (track) { track.stop(); }); return; }
            cameraStream = stream;
            var video = document.createElement('video');
            video.autoplay = true;
            video.muted = true;
            video.playsInline = true;
            cameraVideo = video;
            var preview = document.createElement('div');
            preview.className = 'scan-camera-preview';
            preview.appendChild(video);
            var guide = document.createElement('div');
            guide.className = 'scan-camera-guide';
            guide.setAttribute('aria-hidden', 'true');
            preview.appendChild(guide);
            elReader.appendChild(preview);
            var hints = new Map();
            hints.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.CODE_128]);
            hints.set(ZXing.DecodeHintType.TRY_HARDER, true);
            var reader = new ZXingBrowser.BrowserMultiFormatReader(hints, {
                delayBetweenScanAttempts: 100,
                delayBetweenScanSuccess: 200
            });
            var controls = await reader.decodeFromStream(stream, video, function (result, error) {
                if (run !== cameraEpoch) { return; }
                if (result && result.getBarcodeFormat() === ZXing.BarcodeFormat.CODE_128) {
                    onScanSuccess(result.getText());
                } else {
                    if (Date.now() - lastScan.at > 1200) { lastScan = { code: '', at: 0 }; }
                    // ZXing's minified bundle shortens error.name; getKind() is its stable discriminator.
                    var errorKind = error && typeof error.getKind === 'function' ? error.getKind() : error && error.name;
                    if (error && ['NotFoundException', 'ChecksumException', 'FormatException'].indexOf(errorKind) === -1) {
                        stopCamera();
                        showCameraFailure(error);
                    }
                }
            });
            if (run !== cameraEpoch) {
                Promise.resolve(controls.stop()).catch(function () {});
                return;
            }
            cameraControls = controls;
            cameraStarting = false;
            cameraRunning = true;
            var track = stream.getVideoTracks()[0];
            function updateResolution() {
                if (run === cameraEpoch) { elResolution.textContent = video.videoWidth + ' x ' + video.videoHeight; }
            }
            video.addEventListener('resize', updateResolution);
            updateResolution();
            probeCameraCaps(track);
            track.addEventListener('ended', function () {
                if (run === cameraEpoch) { stopCamera(); setStatus('Camera disconnected. Start it again or use manual entry.', 'error'); }
            });
            setCameraState('Point camera at barcode', 'ready');
            setStatus('ZXing camera started. Point it at a Code 128 barcode.', 'info');
            try {
                var cameras = await ZXingBrowser.BrowserCodeReader.listVideoInputDevices();
                if (run !== cameraEpoch) { return; }
                elCameraSelect.textContent = '';
                cameras.forEach(function (camera, index) {
                    var option = document.createElement('option');
                    option.value = camera.deviceId;
                    option.textContent = camera.label || 'Camera ' + (index + 1);
                    elCameraSelect.appendChild(option);
                });
                elCameraSelect.value = track.getSettings().deviceId || deviceId;
                elCameraSelect.disabled = cameras.length < 2;
            } catch (error) { /* Scanning still works if device enumeration is unavailable. */ }
        } catch (error) {
            if (stream) { stream.getTracks().forEach(function (track) { track.stop(); }); }
            if (run !== cameraEpoch) { return; }
            stopCamera();
            showCameraFailure(error);
        }
    }

    elCameraSelect.addEventListener('change', function () {
        var deviceId = this.value;
        stopCamera();
        startCamera(deviceId);
    });
    elTorch.addEventListener('click', toggleTorch);
    elZoom.addEventListener('input', function () { applyZoom(this.value); });
    elZoom.addEventListener('change', function () { applyZoom(this.value); });
    window.addEventListener('pagehide', stopCamera);
<?php else: ?>
    /* Keep the shipped camera adapter unchanged outside the isolated test router. */
    function probeCameraCaps(scanner) {
        if (torchSupported || !scanner) { return; }
        try {
            var caps = scanner.getRunningTrackCapabilities();
            if (caps && typeof caps.torch !== 'undefined') {
                torchSupported = true;
                elTorch.hidden = false;
            }
            if (caps && caps.zoom && typeof caps.zoom.min === 'number' && typeof caps.zoom.max === 'number' && caps.zoom.max > caps.zoom.min) {
                zoomCaps = { min: caps.zoom.min, max: caps.zoom.max, current: 1.0 };
                var currentZoom = 1.0;
                try {
                    var settings = scanner.getRunningTrackSettings();
                    if (settings && typeof settings.zoom === 'number') { currentZoom = settings.zoom; }
                } catch (e2) { /* keep default */ }
                elZoom.min = zoomCaps.min;
                elZoom.max = zoomCaps.max;
                elZoom.step = Math.max(0.1, (zoomCaps.max - zoomCaps.min) / 20);
                elZoom.value = Math.min(zoomCaps.max, Math.max(zoomCaps.min, currentZoom));
                elZoomControl.hidden = false;
            }
        } catch (e) { /* Device does not expose getRunningTrackCapabilities */ }
    }

    function applyZoom(rawValue) {
        if (!zoomCaps || !cameraScanner) { return; }
        var val = parseFloat(rawValue);
        if (!isFinite(val)) { return; }
        zoomCaps.current = val;
        try {
            cameraScanner.applyVideoConstraints({ advanced: [{ zoom: val }] });
        } catch (e) { /* Constraint not supported; silently ignore */ }
    }

    function toggleTorch() {
        if (!torchSupported || !cameraScanner) { return; }
        torchOn = !torchOn;
        try {
            cameraScanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] });
        } catch (e) { /* Torch not supported on this device; remove UI */ }
        elTorch.textContent = 'Flashlight: ' + (torchOn ? 'On' : 'Off');
        elTorch.setAttribute('aria-pressed', torchOn ? 'true' : 'false');
        if (torchOn) { elReaderWrap.classList.add('is-flash'); }
        else { elReaderWrap.classList.remove('is-flash'); }
    }

    elTorch.addEventListener('click', toggleTorch);
    elZoom.addEventListener('input', function () { applyZoom(this.value); });
    elZoom.addEventListener('change', function () { applyZoom(this.value); });

    function startCamera() {
        if (cameraStarting || cameraRunning) { return; }
        getAudioCtx();
        if (!cameraSupported()) {
            setStatus('Camera scanning is not supported on this device or browser. You can still enter barcodes manually.', 'error');
            return;
        }
        if (!secureContextOk()) {
            setStatus('The camera needs a secure connection (HTTPS or localhost). This page is served from an insecure origin, so camera scanning is disabled. Manual entry still works.', 'error');
            return;
        }
        elReaderOff.hidden = true;
        elReaderWrap.hidden = false;
        setCameraState('Starting camera\u2026', 'starting');
        elTorch.hidden = true;
        elZoomControl.hidden = true;
        try {
            cameraScanner = HopiaDynamsoftScanner.create('scan-reader');
        } catch (e) {
            cameraScanner = null;
            cameraStarting = false;
            setCameraState('Scanner init failed', 'error');
            setStatus('The scanner could not be initialized. Manual entry still works.', 'error');
            return;
        }
        cameraStarting = true;
        cameraScanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 280, height: 160 } },
            onScanSuccess,
            function () { /* per-frame no-decode: ignore to avoid noise */ }
        ).then(function () {
            cameraStarting = false;
            cameraRunning = true;
            elStart.hidden = true;
            elStop.hidden = false;
            setCameraState('Point camera at barcode', 'ready');
            setStatus('Camera started. Point it at a Code 128 barcode.', 'info');
            probeCameraCaps(cameraScanner);
        }).catch(function (err) {
            if (err && err.name === 'AbortError') { return; }
            cameraStarting = false;
            cameraRunning = false;
            if (cameraScanner) {
                try { cameraScanner.clear(); } catch (e2) { /* noop */ }
                cameraScanner = null;
            }
            elReaderOff.hidden = false;
            elReaderWrap.hidden = true;
            elStart.hidden = false;
            elStop.hidden = true;
            var name = (err && err.name) ? err.name : '';
            var detail = (err && err.message) ? err.message : (typeof err === 'string' ? err : '');
            if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
                setStatus('Camera permission was denied. Allow camera access in your browser settings, or use manual entry.', 'error');
            } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
                setStatus('No camera was found on this device. Manual entry still works.', 'error');
            } else if (name === 'NotReadableError' || name === 'TrackStartError') {
                setStatus('The camera is already in use by another app. Close it and try again, or use manual entry.', 'error');
            } else if (name === 'OverconstrainedError') {
                setStatus('The requested camera settings are not supported by this device (' + name + '). Manual entry still works.', 'error');
            } else {
                setStatus('The camera could not be started' + (detail ? ' (' + detail + ')' : '') + '. Manual entry still works.', 'error');
            }
        });
    }

    function stopCamera() {
        if (!cameraScanner) {
            elStart.hidden = false;
            elStop.hidden = true;
            elReaderOff.hidden = false;
            elReaderWrap.hidden = true;
            return;
        }
        torchSupported = false;
        torchOn = false;
        zoomCaps = null;
        cameraScanner.stop().then(function () {
            cameraRunning = false;
            cameraStarting = false;
            try { cameraScanner.clear(); } catch (e) { /* noop */ }
            cameraScanner = null;
            elStart.hidden = false;
            elStop.hidden = true;
            elReaderOff.hidden = false;
            elReaderWrap.hidden = true;
            elTorch.hidden = true;
            elZoomControl.hidden = true;
            setCameraState('', '');
        }).catch(function () {
            elStart.hidden = false;
            elStop.hidden = true;
            elReaderOff.hidden = false;
            elReaderWrap.hidden = true;
        });
    }
<?php endif; ?>

    elStart.addEventListener('click', startCamera);
    elStop.addEventListener('click', stopCamera);

    if (!secureContextOk()) {
        elStart.disabled = true;
        setStatus('Camera scanning needs HTTPS or localhost. Manual and USB-scanner entry still work on this page.', 'info');
    }

    window.addEventListener('beforeunload', function () {
<?php if ($walkinScannerTest): ?>
        releaseCamera();
<?php else: ?>
        if (cameraScanner && cameraRunning) {
            try { cameraScanner.stop(); } catch (e) { /* noop */ }
        }
<?php endif; ?>
    });

    elInput.focus();
    render();
})();
</script>

<?php require __DIR__ . '/../includes/ui.footer.php'; ?>
