(function () {
    'use strict';

    var root = document.getElementById('bundle-identification');
    if (!root) { return; }

    var config = {
        lookupUrl: root.getAttribute('data-lookup-url') || 'api/bundle-barcode-lookup.php',
        csrf: root.getAttribute('data-csrf') || ''
    };

    var form = document.getElementById('bundle-barcode-form');
    var input = document.getElementById('bundle-barcode-input');
    var submit = document.getElementById('bundle-barcode-submit');
    var result = document.getElementById('bundle-identification-result');
    var resultTitle = document.getElementById('bundle-result-title');
    var resultMessage = document.getElementById('bundle-result-message');
    var startButton = document.getElementById('bundle-camera-start');
    var stopButton = document.getElementById('bundle-camera-stop');
    var torchButton = document.getElementById('bundle-camera-torch');
    var zoomControl = document.getElementById('bundle-camera-zoom-control');
    var zoomInput = document.getElementById('bundle-camera-zoom');
    var readerOff = document.getElementById('bundle-reader-off');
    var readerWrap = document.getElementById('bundle-reader-wrap');
    var cameraState = document.getElementById('bundle-camera-state');

    var lookupSerial = 0;
    var activeRequest = null;
    var pendingBarcode = '';
    var cameraScanner = null;
    var cameraRunning = false;
    var cameraStarting = false;
    var cameraStopping = false;
    var cameraEpoch = 0;
    var lastScan = { code: '', at: 0 };
    var torchSupported = false;
    var torchOn = false;
    var zoomCaps = null;
    var stateTimer = 0;

    function showNotification(message, kind) {
        var popup = document.getElementById('admin-success');
        if (!popup || !window.hopiaAdminSuccess) { return; }

        var isError = kind === 'error';
        var isWarning = kind === 'warning';
        var messageBox = popup.querySelector('[data-admin-success-message]');
        var close = popup.querySelector('[data-admin-success-close]');
        var iconPath = popup.querySelector('.admin-success__icon path');

        popup.setAttribute('data-bundle-kind', kind);
        messageBox.setAttribute('role', isError ? 'alert' : 'status');
        messageBox.setAttribute('aria-live', isError ? 'assertive' : 'polite');
        close.setAttribute('aria-label', 'Dismiss ' + (isError ? 'error' : (isWarning ? 'warning' : 'success')) + ' message');
        iconPath.setAttribute('d', isError || isWarning ? 'M12 8v4m0 4h.01' : 'm8 12 2.5 2.5L16 9');
        window.hopiaAdminSuccess.show(message);
    }

    function setCameraState(message, kind) {
        window.clearTimeout(stateTimer);
        cameraState.textContent = message;
        cameraState.setAttribute('data-kind', kind || 'ready');
    }

    function resetCameraStateSoon() {
        var run = cameraEpoch;
        window.clearTimeout(stateTimer);
        stateTimer = window.setTimeout(function () {
            if (run === cameraEpoch && cameraRunning) {
                setCameraState('Point camera at next barcode', 'ready');
            }
        }, 2500);
    }

    function setLookupBusy(isBusy) {
        submit.disabled = isBusy;
        submit.textContent = isBusy ? 'Finding Item...' : 'Find Item';
        form.setAttribute('aria-busy', isBusy ? 'true' : 'false');
    }

    function clearHighlight() {
        var highlighted = document.querySelector('.bp-card.is-barcode-match');
        if (highlighted) { highlighted.classList.remove('is-barcode-match'); }
    }

    function hideResultRows() {
        Array.prototype.forEach.call(result.querySelectorAll('[data-result-row]'), function (row) {
            row.hidden = true;
        });
    }

    function setResultField(name, value) {
        var row = result.querySelector('[data-result-row="' + name + '"]');
        var field = result.querySelector('[data-result-field="' + name + '"]');
        if (!row || !field) { return; }
        var text = value == null ? '' : String(value);
        row.hidden = text === '';
        field.textContent = text;
    }

    function productStatus(product) {
        var status = product.status || 'Not recorded';
        return product.retired ? status + ' \u00b7 Retired' : status;
    }

    function formatPurchaseDate(value) {
        if (!value) { return ''; }
        var date = new Date(value + 'T00:00:00');
        if (isNaN(date.getTime())) { return value; }
        return date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderItem(data, source) {
        var product = data.product || {};
        var bundle = data.bundle || null;
        var card = null;

        hideResultRows();
        result.hidden = false;
        result.setAttribute('data-result-state', bundle ? 'success' : 'warning');
        resultTitle.textContent = product.name || 'Registered item';
        resultMessage.textContent = data.message || '';
        setResultField('barcode', product.barcode);
        setResultField('product-category', product.category);
        setResultField('product-status', productStatus(product));
        setResultField('description', product.description);
        setResultField('item-number', product.bundle_item_number);

        if (!bundle) {
            setResultField('bundle-code', 'No assigned bundle');
            return;
        }

        setResultField('bundle-code', bundle.code);
        setResultField('bundle-category', bundle.category);
        setResultField('bundle-capital', '\u20b1' + bundle.capital);
        setResultField('bundle-quantity', bundle.original_quantity);
        setResultField('purchase-date', formatPurchaseDate(bundle.purchase_date));

        card = document.querySelector('.bp-card[data-bundle-id="' + bundle.id + '"]');
        if (!card) {
            resultMessage.textContent += ' The matching bundle is outside the current filtered list.';
            return;
        }

        card.classList.add('is-barcode-match');
        var expandButton = card.querySelector('.bp-card__expand');
        if (expandButton && expandButton.getAttribute('aria-expanded') !== 'true') {
            expandButton.click();
        }
        if (source === 'manual') {
            window.setTimeout(function () {
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }, 100);
        }
    }

    function renderFailure(message) {
        hideResultRows();
        result.hidden = false;
        result.setAttribute('data-result-state', 'error');
        resultTitle.textContent = 'Item not identified';
        resultMessage.textContent = message;
    }

    function identifyBarcode(rawBarcode, source) {
        var barcode = String(rawBarcode == null ? '' : rawBarcode).trim();
        var requestNumber;
        var body;

        if (barcode === '') {
            lookupSerial += 1;
            if (activeRequest) { activeRequest.abort(); }
            activeRequest = null;
            pendingBarcode = '';
            setLookupBusy(false);
            clearHighlight();
            renderFailure('Enter or scan a barcode to identify an item.');
            showNotification('Enter or scan a barcode to identify an item.', 'error');
            input.focus();
            return;
        }

        if (pendingBarcode === barcode) { return; }

        requestNumber = ++lookupSerial;
        if (activeRequest) { activeRequest.abort(); }
        activeRequest = typeof AbortController !== 'undefined' ? new AbortController() : null;
        pendingBarcode = barcode;
        clearHighlight();
        setLookupBusy(true);

        if (source === 'camera') {
            input.value = barcode;
            setCameraState('Identifying ' + barcode + '...', 'starting');
        }

        body = new URLSearchParams();
        body.set('csrf_token', config.csrf);
        body.set('barcode', barcode);

        fetch(config.lookupUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json'
            },
            body: body.toString(),
            signal: activeRequest ? activeRequest.signal : undefined
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, reason: 'error', message: 'The server returned an invalid response.' };
            });
        }).then(function (data) {
            if (requestNumber !== lookupSerial) { return; }

            if (data.ok && data.product && data.bundle) {
                renderItem(data, source);
                showNotification(data.message, 'success');
                if (source === 'camera') {
                    setCameraState('Identified: ' + data.product.name, 'ok');
                    resetCameraStateSoon();
                    if (navigator.vibrate) { navigator.vibrate(80); }
                }
                return;
            }

            if (data.reason === 'no_bundle' && data.product) {
                renderItem(data, source);
                showNotification(data.message || 'Item found, but it does not have an assigned bundle.', 'warning');
            } else {
                renderFailure(data.message || 'The item could not be identified.');
                showNotification(data.message || 'The item could not be identified.', 'error');
            }

            if (source === 'camera') {
                setCameraState(data.message || 'Item not identified', data.reason === 'no_bundle' ? 'warn' : 'error');
                resetCameraStateSoon();
            }
        }).catch(function (error) {
            if (error && error.name === 'AbortError') { return; }
            if (requestNumber !== lookupSerial) { return; }
            renderFailure('Could not reach the server. Please check your connection and try again.');
            showNotification('Could not reach the server. Please check your connection and try again.', 'error');
            if (source === 'camera') {
                setCameraState('Server connection failed', 'error');
                resetCameraStateSoon();
            }
        }).finally(function () {
            if (requestNumber !== lookupSerial) { return; }
            pendingBarcode = '';
            activeRequest = null;
            setLookupBusy(false);
            if (source === 'manual') {
                input.focus();
                input.select();
            }
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        identifyBarcode(input.value, 'manual');
    });

    function cameraSupported() {
        return typeof HopiaDynamsoftScanner !== 'undefined'
            && HopiaDynamsoftScanner.isSupported()
            && navigator.mediaDevices
            && typeof navigator.mediaDevices.getUserMedia === 'function';
    }

    function secureContextOk() {
        if (window.isSecureContext) { return true; }
        var host = window.location.hostname;
        return host === 'localhost' || host === '127.0.0.1' || host === '::1';
    }

    function showCameraFailure(error) {
        var name = error && error.name ? error.name : '';
        var detail = error && error.message ? error.message : String(error || '');
        var message;

        if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || /NotAllowedError|PermissionDeniedError/i.test(detail)) {
            message = 'Camera permission was denied. Allow camera access in your browser settings, or enter the barcode manually.';
        } else if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || /NotFoundError|DevicesNotFoundError/i.test(detail)) {
            message = 'No camera was found on this device. Manual barcode entry still works.';
        } else if (name === 'NotReadableError' || name === 'TrackStartError' || /NotReadableError|TrackStartError/i.test(detail)) {
            message = 'The camera is already in use by another app. Close it and try again, or enter the barcode manually.';
        } else if (name === 'OverconstrainedError' || /OverconstrainedError/i.test(detail)) {
            message = 'The requested camera settings are not supported. Manual barcode entry still works.';
        } else {
            message = 'The camera scanner could not be started. Manual barcode entry still works.';
        }

        showNotification(message, 'error');
    }

    function probeCameraCapabilities(scanner) {
        try {
            var caps = scanner.getRunningTrackCapabilities();
            var settings = scanner.getRunningTrackSettings();

            if (caps && caps.torch) {
                torchSupported = true;
                torchButton.hidden = false;
            }

            if (caps && caps.zoom && caps.zoom.max > caps.zoom.min) {
                zoomCaps = caps.zoom;
                zoomInput.min = caps.zoom.min;
                zoomInput.max = caps.zoom.max;
                zoomInput.step = caps.zoom.step || 0.1;
                zoomInput.value = settings && typeof settings.zoom === 'number' ? settings.zoom : caps.zoom.min;
                zoomControl.hidden = false;
            }
        } catch (error) { /* Optional camera controls stay hidden. */ }
    }

    function setCameraOffUi() {
        startButton.hidden = false;
        startButton.disabled = cameraStopping;
        stopButton.hidden = true;
        readerOff.hidden = false;
        readerWrap.hidden = true;
        torchButton.hidden = true;
        torchButton.disabled = false;
        torchButton.textContent = 'Flashlight: Off';
        torchButton.setAttribute('aria-pressed', 'false');
        zoomControl.hidden = true;
        readerWrap.classList.remove('is-flash');
        setCameraState('', 'ready');
    }

    function startCamera() {
        var run;
        var scanner;

        if (cameraStarting || cameraRunning || cameraStopping) { return; }
        if (!cameraSupported()) {
            showNotification('Camera scanning is not supported on this device or browser. Manual barcode entry still works.', 'error');
            return;
        }
        if (!secureContextOk()) {
            showNotification('Camera scanning needs HTTPS or localhost. Manual barcode entry still works.', 'error');
            return;
        }

        run = ++cameraEpoch;
        cameraStarting = true;
        startButton.hidden = true;
        stopButton.hidden = false;
        readerOff.hidden = true;
        readerWrap.hidden = false;
        setCameraState('Starting camera...', 'starting');

        try {
            scanner = HopiaDynamsoftScanner.create('bundle-barcode-reader');
            cameraScanner = scanner;
        } catch (error) {
            cameraStarting = false;
            cameraScanner = null;
            setCameraOffUi();
            showNotification('The camera scanner could not be initialized. Manual barcode entry still works.', 'error');
            return;
        }

        scanner.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: { width: 280, height: 160 } },
            function (decodedText) {
                var now = Date.now();
                var code = String(decodedText == null ? '' : decodedText).trim();
                if (!code || run !== cameraEpoch) { return; }
                if (code === lastScan.code) {
                    lastScan.at = now;
                    return;
                }
                lastScan = { code: code, at: now };
                identifyBarcode(code, 'camera');
            },
            function () {
                if (Date.now() - lastScan.at > 1200) {
                    lastScan = { code: '', at: 0 };
                }
            }
        ).then(function () {
            if (run !== cameraEpoch) {
                scanner.stop().catch(function () {}).then(function () {
                    try { scanner.clear(); } catch (error) { /* Already cleared. */ }
                    cameraStopping = false;
                    startButton.disabled = false;
                });
                return;
            }
            cameraStarting = false;
            cameraRunning = true;
            setCameraState('Point camera at barcode', 'ready');
            probeCameraCapabilities(scanner);
        }).catch(function (error) {
            if (run !== cameraEpoch) {
                try { scanner.clear(); } catch (clearError) { /* Already released. */ }
                cameraStopping = false;
                startButton.disabled = false;
                return;
            }
            cameraStarting = false;
            cameraRunning = false;
            cameraScanner = null;
            try { scanner.clear(); } catch (clearError) { /* Not started. */ }
            setCameraOffUi();
            showCameraFailure(error);
        });
    }

    function stopCamera() {
        var scanner = cameraScanner;
        cameraEpoch += 1;
        window.clearTimeout(stateTimer);
        cameraScanner = null;
        cameraRunning = false;
        cameraStarting = false;
        lastScan = { code: '', at: 0 };
        torchSupported = false;
        torchOn = false;
        zoomCaps = null;
        cameraStopping = Boolean(scanner);
        setCameraOffUi();

        if (!scanner) { return; }
        scanner.stop().catch(function () {}).then(function () {
            try { scanner.clear(); } catch (error) { /* Already released. */ }
            cameraStopping = false;
            startButton.disabled = false;
        });
    }

    torchButton.addEventListener('click', function () {
        if (!torchSupported || !cameraScanner || !cameraRunning) { return; }
        torchOn = !torchOn;
        torchButton.disabled = true;
        var request;
        try {
            request = cameraScanner.applyVideoConstraints({ advanced: [{ torch: torchOn }] });
        } catch (error) {
            request = Promise.reject(error);
        }
        Promise.resolve(request)
            .then(function () {
                torchButton.textContent = 'Flashlight: ' + (torchOn ? 'On' : 'Off');
                torchButton.setAttribute('aria-pressed', torchOn ? 'true' : 'false');
                readerWrap.classList.toggle('is-flash', torchOn);
            }).catch(function () {
                torchSupported = false;
                torchOn = false;
                torchButton.hidden = true;
                readerWrap.classList.remove('is-flash');
            }).finally(function () {
                torchButton.disabled = false;
            });
    });

    zoomInput.addEventListener('input', function () {
        if (!zoomCaps || !cameraScanner || !cameraRunning) { return; }
        var value = Math.min(zoomCaps.max, Math.max(zoomCaps.min, Number(zoomInput.value)));
        var request;
        try {
            request = cameraScanner.applyVideoConstraints({ advanced: [{ zoom: value }] });
        } catch (error) {
            request = Promise.reject(error);
        }
        Promise.resolve(request).catch(function () {
            zoomCaps = null;
            zoomControl.hidden = true;
        });
    });

    startButton.addEventListener('click', startCamera);
    stopButton.addEventListener('click', stopCamera);
    window.addEventListener('pagehide', stopCamera);

}());
