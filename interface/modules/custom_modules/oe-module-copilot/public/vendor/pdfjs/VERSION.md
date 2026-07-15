# Vendored PDF.js

- **Package:** `pdfjs-dist`
- **Version:** `3.11.174`
- **Source:** `npm pack pdfjs-dist@3.11.174` (registry tarball:
  `https://registry.npmjs.org/pdfjs-dist/-/pdfjs-dist-3.11.174.tgz`)
- **Files vendored (legacy UMD build, global `pdfjsLib`):**
  - `pdf.min.js` — from the tarball's `legacy/build/pdf.min.js`
  - `pdf.worker.min.js` — from the tarball's `legacy/build/pdf.worker.min.js`
- **License:** Apache License, Version 2.0 (Mozilla Foundation). See
  `https://github.com/mozilla/pdf.js/blob/v3.11.174/LICENSE` for the full
  license text; the license header is also embedded verbatim at the top of
  each vendored file.

Vendored (TRO-44) so the panel is self-contained — no CDN is reachable from
`public/panel.html` at runtime. The "legacy" build targets older browser
JS-engine baselines than the default build; it is the build PDF.js itself
recommends for environments without guaranteed modern-JS support, and it
still exposes the same global `pdfjsLib.getDocument()` API this panel uses.

To upgrade: re-run `npm pack pdfjs-dist@<new-version>` in a scratch
directory, replace both files here with `legacy/build/pdf.min.js` and
`legacy/build/pdf.worker.min.js` from the new tarball, and update this file's
version/source lines.
