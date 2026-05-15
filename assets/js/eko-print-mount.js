/**

 * Standalone print page: mounts canvas via EkoCanvasRenderer.runRenderPipeline.

 */

(function () {

    'use strict';



    function ready(fn) {

        if (document.readyState === 'loading') {

            document.addEventListener('DOMContentLoaded', fn);

        } else {

            fn();

        }

    }



    ready(function () {
        if (window.ekoSampaRender && window.ekoSampaRender.debug) {
            window.EKO_RENDER_DEBUG = true;
        }

        const raw = window.ekoSampaPrintPayload;

        const mount = document.getElementById('eko-sampa-print-mount');

        const R = window.EkoCanvasRenderer;

        if (!mount || !R || !raw) {

            document.body.classList.add('eko-sampa-print-error');

            return;

        }



        const payload = typeof R.normalizePayload === 'function' ? R.normalizePayload(raw) : raw;

        if (!Array.isArray(payload.elements)) {

            document.body.classList.add('eko-sampa-print-error');

            return;

        }



        const status = document.getElementById('eko-sampa-print-status');

        if (status) {

            status.textContent = 'Loading assets…';

        }



        const onPaint = function (ev) {

            document.body.classList.add('eko-sampa-print-ready');

            if (status) {

                status.textContent = '';

                status.style.display = 'none';

            }

            const auto = mount.getAttribute('data-auto-print');

            if (auto === '1' || auto === 'true') {

                window.setTimeout(function () {

                    window.print();

                }, 120);

            }

            document.removeEventListener(R.RenderLifecycle.PAINT_READY, onPaint);

        };



        if (R.RenderLifecycle && R.RenderLifecycle.PAINT_READY) {

            document.addEventListener(R.RenderLifecycle.PAINT_READY, onPaint);

        }



        const pipeline =

            typeof R.runRenderPipeline === 'function'

                ? R.runRenderPipeline(mount, payload, {

                      forPrint: true,

                      target: R.RenderTargets && R.RenderTargets.PRINT,

                  })

                : R.mountInto(mount, payload, { forPrint: true });



        pipeline

            .then(function (report) {

                if (!R.RenderLifecycle || !R.RenderLifecycle.PAINT_READY) {

                    onPaint({ detail: { report: report } });

                }

            })

            .catch(function () {

                document.body.classList.add('eko-sampa-print-ready');

                document.body.classList.add('eko-sampa-print-error');

                if (status) {

                    status.textContent = '';

                }

            });

    });

})();

