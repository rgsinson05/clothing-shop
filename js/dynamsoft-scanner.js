(function (global) {
    'use strict';

    var sdkReady;

    function canceled() {
        var error = new Error('Camera start canceled.');
        error.name = 'AbortError';
        return error;
    }

    function ensureSdk() {
        if (sdkReady) { return sdkReady; }
        if (!global.HOPIA_DYNAMSOFT_LICENSE) {
            return Promise.reject(new Error('Dynamsoft license is not configured.'));
        }
        if (!global.Dynamsoft || !Dynamsoft.License || !Dynamsoft.CVR || !Dynamsoft.DCE || !Dynamsoft.DBR) {
            return Promise.reject(new Error('Dynamsoft SDK is unavailable.'));
        }

        Dynamsoft.Core.CoreModule.engineResourcePaths.rootDirectory = 'https://cdn.jsdelivr.net/npm/';
        sdkReady = Dynamsoft.License.LicenseManager.initLicense(global.HOPIA_DYNAMSOFT_LICENSE)
            .then(function () { return Dynamsoft.Core.CoreModule.loadWasm(); })
            .catch(function (error) {
                sdkReady = null;
                throw error;
            });
        return sdkReady;
    }

    function Scanner(containerId) {
        this.container = document.getElementById(containerId);
        this.router = null;
        this.view = null;
        this.enhancer = null;
        this.running = false;
        this.starting = false;
        this.generation = 0;
    }

    Scanner.prototype.start = async function (_camera, _options, onDecode) {
        if (this.starting || this.running) { return; }
        if (!this.container) { throw new Error('Scanner preview is unavailable.'); }

        var generation = ++this.generation;
        this.starting = true;
        try {
            await ensureSdk();
            if (generation !== this.generation) { throw canceled(); }
            this.router = await Dynamsoft.CVR.CaptureVisionRouter.createInstance();
            this.view = await Dynamsoft.DCE.CameraView.createInstance();
            this.enhancer = await Dynamsoft.DCE.CameraEnhancer.createInstance(this.view);
            if (generation !== this.generation) { await this.stop(); throw canceled(); }

            this.container.textContent = '';
            this.container.classList.add('hopia-dynamsoft-reader');
            this.container.appendChild(this.view.getUIElement());
            this.router.setInput(this.enhancer);
            this.enhancer.setScanRegion({ x: 5, y: 25, width: 90, height: 50, isMeasuredInPercentage: true });

            await this.router.addResultReceiver({
                onDecodedBarcodesReceived: function (result) {
                    if (!result || !result.barcodeResultItems) { return; }
                    for (var i = 0; i < result.barcodeResultItems.length; i += 1) {
                        var item = result.barcodeResultItems[i];
                        if (item && item.text) { onDecode(item.text); break; }
                    }
                }
            });

            var filter = new Dynamsoft.Utility.MultiFrameResultCrossFilter();
            filter.enableResultCrossVerification('barcode', true);
            filter.enableResultDeduplication('barcode', true);
            filter.setDuplicateForgetTime('barcode', 3000);
            await this.router.addResultFilter(filter);

            var settings = await this.router.getSimplifiedSettings('ReadSingleBarcode');
            settings.barcodeSettings.barcodeFormatIds = Dynamsoft.DBR.EnumBarcodeFormat.BF_CODE_128;
            settings.barcodeSettings.expectedBarcodesCount = 1;
            settings.minImageCaptureInterval = 100;
            var result = await this.router.updateSettings('ReadSingleBarcode', settings);
            if (result && result.errorCode) { throw new Error('Code 128 settings were rejected.'); }

            if (typeof this.enhancer.requestCamera === 'function') {
                await this.enhancer.requestCamera('back');
            }
            await this.enhancer.open();
            await this.router.startCapturing('ReadSingleBarcode');
            if (generation !== this.generation) { await this.stop(); throw canceled(); }
            this.starting = false;
            this.running = true;
        } catch (error) {
            await this.stop();
            throw error;
        }
    };

    Scanner.prototype.stop = async function () {
        this.generation += 1;
        if (this.router) {
            try { this.router.stopCapturing(); } catch (error) { /* Already stopped. */ }
        }
        if (this.enhancer) {
            try { await this.enhancer.close(); } catch (error) { /* Already closed. */ }
        }
        if (this.router) { try { this.router.dispose(); } catch (error) { /* Already disposed. */ } }
        if (this.enhancer) { try { this.enhancer.dispose(); } catch (error) { /* Already disposed. */ } }
        if (this.view) { try { this.view.dispose(); } catch (error) { /* Already disposed. */ } }
        this.router = null;
        this.enhancer = null;
        this.view = null;
        this.running = false;
        this.starting = false;
        if (this.container) {
            this.container.textContent = '';
            this.container.classList.remove('hopia-dynamsoft-reader');
        }
    };

    Scanner.prototype.clear = function () {
        if (this.container) { this.container.textContent = ''; }
    };

    Scanner.prototype.getRunningTrackCapabilities = function () {
        return this.enhancer ? this.enhancer.getCapabilities() : {};
    };

    Scanner.prototype.getRunningTrackSettings = function () {
        return this.enhancer ? this.enhancer.getCameraSettings() : {};
    };

    Scanner.prototype.applyVideoConstraints = function (constraints) {
        var advanced = constraints && constraints.advanced && constraints.advanced[0] || {};
        if (!this.enhancer) { return Promise.reject(new Error('Camera is not running.')); }
        if (typeof advanced.torch === 'boolean') {
            return advanced.torch ? this.enhancer.turnOnTorch() : this.enhancer.turnOffTorch();
        }
        if (typeof advanced.zoom === 'number') {
            return this.enhancer.setZoom({ factor: advanced.zoom });
        }
        return Promise.resolve();
    };

    global.HopiaDynamsoftScanner = {
        isSupported: function () {
            return Boolean(global.Dynamsoft && Dynamsoft.CVR && Dynamsoft.DCE && navigator.mediaDevices);
        },
        create: function (containerId) { return new Scanner(containerId); }
    };
}(window));
