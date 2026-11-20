const viewer = document.querySelector('[data-material-pdf]');

if (viewer) {
    showPdf(viewer).catch(() => {
        viewer.querySelector('[data-pdf-status]').textContent = 'PDF tidak bisa ditampilkan di sini. Buka PDF di tab baru.';
    });
}

async function showPdf(viewer) {
    const canvas = viewer.querySelector('canvas');
    const frame = canvas.parentElement;
    const controls = viewer.querySelector('[data-pdf-controls]');
    const previous = controls.querySelector('[data-pdf-prev]');
    const next = controls.querySelector('[data-pdf-next]');
    const status = viewer.querySelector('[data-pdf-status]');

    status.textContent = 'Memuat PDF…';

    const assets = new URL('../vendor/pdfjs-6.5.438/', import.meta.url);
    const { getDocument, GlobalWorkerOptions } = await import(new URL('pdf.min.mjs', assets).href);
    GlobalWorkerOptions.workerSrc = new URL('pdf.worker.min.mjs', assets).href;
    const pdf = await getDocument({
        url: viewer.dataset.materialPdf,
        cMapUrl: new URL('cmaps/', assets).href,
        standardFontDataUrl: new URL('standard_fonts/', assets).href,
        iccUrl: new URL('iccs/', assets).href,
        wasmUrl: new URL('wasm/', assets).href,
        isEvalSupported: false,
    }).promise;

    let pageNumber = 1;
    let renderTask;
    let renderId = 0;
    let previousWidth = 0;

    async function renderPage() {
        const width = frame.clientWidth;
        if (!width) return;

        const id = ++renderId;
        const number = pageNumber;
        previous.disabled = number === 1;
        next.disabled = number === pdf.numPages;

        if (renderTask) {
            const active = renderTask;
            active.cancel();
            // The next task must wait until PDF.js releases this canvas.
            await active.promise.catch(() => {});
        }

        try {
            const page = await pdf.getPage(number);
            if (id !== renderId) return;

            const natural = page.getViewport({ scale: 1 });
            const scale = width / natural.width;
            const height = natural.height * scale;
            const density = Math.min(window.devicePixelRatio || 1, 2, 4096 / Math.max(width, height));
            const viewport = page.getViewport({ scale: scale * density });
            canvas.width = Math.max(1, Math.floor(viewport.width));
            canvas.height = Math.max(1, Math.floor(viewport.height));
            canvas.hidden = true;
            status.textContent = `Memuat halaman ${number}…`;

            renderTask = page.render({ canvas, viewport });
            await renderTask.promise;
            if (id !== renderId) return;

            canvas.hidden = false;
            status.textContent = `Halaman ${number} dari ${pdf.numPages}`;
            canvas.setAttribute('aria-label', status.textContent);
        } catch (error) {
            if (id !== renderId || error.name === 'RenderingCancelledException') return;

            canvas.hidden = true;
            status.textContent = 'Halaman ini tidak bisa ditampilkan. Buka PDF di tab baru.';
        } finally {
            if (id === renderId) renderTask = undefined;
        }
    }

    previous.addEventListener('click', () => {
        if (pageNumber > 1) {
            pageNumber--;
            renderPage();
        }
    });
    next.addEventListener('click', () => {
        if (pageNumber < pdf.numPages) {
            pageNumber++;
            renderPage();
        }
    });
    controls.hidden = pdf.numPages < 2;

    new ResizeObserver(() => {
        const width = frame.clientWidth;
        if (width === previousWidth) return;
        previousWidth = width;
        renderPage();
    }).observe(frame);
}
