(function (global) {
    'use strict';

    var sdkReady;
    var requiredCameraLabel = 'HP HD Camera';

    function canceled() {
        var error = new Error('Camera start canceled.');
        error.name = 'AbortError';
        return error;
    }

    function requiredCameraUnavailable(message) {
        var error = new Error(message);
        error.name = 'RequiredCameraUnavailableError';
        return error;
    }

    function stopStream(stream) {
        if (!stream || typeof stream.getTracks !== 'function') { return; }
        stream.getTracks().forEach(function (track) { track.stop(); });
    }

    function normalizeCameraLabel(label) {
        return String(label || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function isRequiredCamera(device) {
        var label = normalizeCameraLabel(device && device.label);
        var required = normalizeCameraLabel(requiredCameraLabel);
        return label === required || label.indexOf(required + ' (') === 0;
    }

    async function enumerateVideoInputs() {
        var devices = await global.navigator.mediaDevices.enumerateDevices();
        return devices.filter(function (device) { return device.kind === 'videoinput'; });
    }

    async function findRequiredCamera() {
        var cameras = await enumerateVideoInputs();
        var camera = cameras.find(isRequiredCamera);
        if (camera) { return camera; }

        // Device labels may be hidden until this origin has opened a camera once.
        // The temporary stream is always released before Dynamsoft is initialized.
        var labelsHidden = cameras.length === 0 || cameras.some(function (device) { return !device.label; });
        if (labelsHidden) {
            var permissionStream = null;
            try {
                permissionStream = await global.navigator.mediaDevices.getUserMedia({ audio: false, video: true });
            } finally {
                stopStream(permissionStream);
            }
            cameras = await enumerateVideoInputs();
            camera = cameras.find(isRequiredCamera);
            if (camera) { return camera; }
        }

        throw requiredCameraUnavailable(
            'HP HD Camera is unavailable. DroidCam Video was not selected; connect or enable the HP camera and try again.'
        );
    }

    async function isRequiredCameraActive(cameraSettings) {
        if (!cameraSettings || !cameraSettings.deviceId) { return false; }
        var cameras = await enumerateVideoInputs();
        return cameras.some(function (camera) {
            return camera.deviceId === cameraSettings.deviceId && isRequiredCamera(camera);
        });
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
        if (this.starting) { return; }
        if (!this.container) { throw new Error('Scanner preview is unavailable.'); }

        // Release any stream owned by this scanner before selecting a new one.
        if (this.running || this.router || this.enhancer || this.view) { await this.stop(); }

        var generation = ++this.generation;
        this.starting = true;
        try {
            var requiredCamera = await findRequiredCamera();
            if (generation !== this.generation) { throw canceled(); }
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
            this.enhancer.ifSaveLastUsedCamera = false;
            this.enhancer.ifSkipCameraInspection = true;

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

            await this.enhancer.selectCamera(requiredCamera.deviceId);
            await this.enhancer.open();
            var cameraSettings = this.enhancer.getCameraSettings();
            if (!(await isRequiredCameraActive(cameraSettings))) {
                throw requiredCameraUnavailable(
                    'HP HD Camera could not be activated. The scanner was stopped instead of using DroidCam Video.'
                );
            }
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
            return Boolean(
                global.Dynamsoft && Dynamsoft.CVR && Dynamsoft.DCE
                && global.navigator && global.navigator.mediaDevices
                && typeof global.navigator.mediaDevices.getUserMedia === 'function'
                && typeof global.navigator.mediaDevices.enumerateDevices === 'function'
            );
        },
        create: function (containerId) { return new Scanner(containerId); }
    };
}(window));
