# Receipt Lens

A complete local receipt OCR service with a browser workspace, HTTP API, CLI, source evidence, human review, and reproducible tests. No paid service, API key, GPU, or cloud OCR is required.

## Run

The project virtual environment is already installed on this computer. Port 8000 was occupied during development; use 8765:

```sh
cd /Users/vaishnavdekate/Projects/OCR_Engine/Python_based
PORT=8765 sh run.sh
```

Open <http://127.0.0.1:8765>. Upload a receipt, inspect the total and its source row, confirm or correct the amount, and export JSON or CSV. Reviews stay separate from original OCR values. Session results live in browser memory and clear on reload. Downloads remain on your computer.

On a new machine (Python 3.11+ with compatible native wheels; tested on Python 3.14/macOS arm64):

```sh
python3 -m venv .venv
.venv/bin/python -m pip install '.[dev]'
PORT=8765 sh run.sh
```

`requirements-lock.txt` records the complete tested environment. For exact reproduction on this platform, install it with `pip install -r requirements-lock.txt`. The pinned RapidOCR wheel bundles the default models; the tested OCR path works without network access after installation. Installation needs internet access. The default processing pipeline does not request external URLs or call an LLM.

## What it extracts

- Payable total, subtotal, tax (including multiple tax rows), tip, and identifiable line items.
- All readable monetary candidates, classified into total, tax, tax base, payment, change, exchange equivalent, discount, or general amount.
- Currency codes, OCR text, source-row bounding boxes, evidence scores, warnings, and arithmetic checks.
- JPEG, PNG, WebP, TIFF, AVIF, and scanned PDF; up to 20 MiB, 25 megapixels per page, and 5 pages per document. Each page is processed separately. AVIF uses the decoder included in the pinned Pillow wheel.

Amounts are exact decimal strings, never binary floating point. Parsing supports one or two fractional digits (for example, `16.5` becomes `16.50`), decimal commas, decimal points, apostrophe/space thousands separators, negatives, parentheses, and context-labelled whole amounts. The parser recognizes common English, German, and French total/tax labels. Explicit currency detection covers USD, CHF, EUR, INR, GBP, CAD, AUD, NZD, JPY, CNY, AED and their common symbols. Other three-letter currency hints can be supplied for an unlabelled receipt.

## HTTP API

Read <http://127.0.0.1:8765/docs> for an entirely local API reference and <http://127.0.0.1:8765/openapi.json> for the machine-readable schema.

```sh
curl -X POST http://127.0.0.1:8765/api/receipts \
  -F 'file=@/absolute/path/receipt.jpg' \
  -F 'preview=false'
```

Optional form fields: `currency=CHF` to resolve a missing or ambiguous currency; `preview=false` to omit the inline preview image. A hint never replaces an explicit printed currency. The response contains `pages`, each with `total`, `tax`, `subtotal`, `tip`, `candidates`, `lines`, `checks`, `warnings`, `status`, and `needs_review`. `GET /api/health` reports availability and busy/authentication state. Models initialize on first extraction.

For another local application, post a multipart file, read `pages[n].total.amount` as a decimal string, and route `needs_review=true` or a null total to human review. Never turn a null total into zero. Preserve the original response with any reviewed value. The service does not write to the Bar Assistant application or its database.

## CLI

```sh
.venv/bin/python -m receipt_ocr.cli tests/fixtures/usps.jpeg tests/fixtures/swiss.jpg --output results.json
.venv/bin/python -m receipt_ocr.cli /absolute/path/receipt.png --currency INR
```

The CLI supports multiple documents, writes JSON, and omits preview images. After installing the project, `receipt-lens` is also available as a console command.

## Accuracy and review

1. Safely decode content, normalize EXIF orientation, composite transparency, and resize for recognition.
2. Run the real PP-OCRv6 small detector/recognizer at four page orientations. Select the orientation using horizontal text geometry, recognition confidence, total labels, and available arithmetic. This prevents individually rotated text crops from making a sideways page look correct.
3. Run a second recognition pass with illumination normalization and local contrast enhancement. Compare its total with the selected original pass.
4. Estimate the receipt's text baseline from detection polygons and group labels/prices in those coordinates. Keep source boxes in the original preview coordinates. Parse exact values, score explicit monetary labels, and separate tax bases and exchange equivalents. Validate available subtotal/tax/tip arithmetic and line-item sums.
5. Flag ambiguous currency, missing/conflicting totals, repaired digits, low OCR confidence, disagreement between passes, and arithmetic discrepancies. Abstain when no explicit total is found.

The displayed evidence score is heuristic and **not** a calibrated probability. Both preprocessing passes use the same model and can share errors. Passing the supplied examples and their variants does not establish general production accuracy. See `TEST_REPORT.md` for measured results and limits. Handwriting, severe folds/glare/blur, unfamiliar scripts, missing receipt sections, complex invoices, and uncommon layouts need further evaluation. Line-item and merchant extraction are best effort; a line-item sum may cover only the readable rows. There is no trained receipt-specific semantic model, perspective rectifier, or multi-page invoice reconciliation.

Receipt text is treated only as data. Text printed in an upload cannot issue commands, invoke tools, or change the extraction workflow.

## Operations

The launcher binds to loopback. Uploads are decoded in memory; the multipart framework may spool large request bodies to temporary files, closed after the request. No upload/result database or server-side receipt archive is created. One OCR worker processes one document at a time; overlapping requests receive `503` with `Retry-After: 5`. The busy state remains until the real worker finishes, including if a client disconnects. CPU thread counts are bounded.

Before exposing the service to other computers, configure HTTPS and authentication:

- Set `RECEIPT_API_TOKEN` to a strong secret; clients send `Authorization: Bearer ...`. The local UI supports token entry in session memory.
- Set `RECEIPT_ALLOWED_HOSTS` to a comma-separated list of intended hostnames. Defaults: `127.0.0.1,localhost`. Cross-origin uploads are rejected. Same-origin reverse proxying is recommended.
- Use an external reverse proxy for TLS, rate limits, and request timeouts. Allow at least 20 MiB plus multipart overhead. Use one Uvicorn process to preserve the single-worker bound; every additional process adds a model and its own concurrency slot.
- Allocate roughly 2 GB RAM for the largest permitted multi-page inputs and evaluate real workload latency. No hard CPU execution deadline is implemented. Horizontal scaling and durable job queues are future work.

Optional container packaging (Docker was unavailable here, so the image build has not been exercised):

```sh
docker build -t receipt-lens .
docker run --rm -p 127.0.0.1:8765:8000 -e RECEIPT_API_TOKEN receipt-lens
```

The Docker allowlist includes only application source and packaging metadata. Sample receipts, tests, generated artifacts, credentials, caches, and local configuration are excluded. No server deployment was performed.

## Tests

```sh
.venv/bin/python -m pytest -q --junitxml=artifacts/tests.xml
.venv/bin/python -m pytest -m 'not integration' -q
.venv/bin/python -m pytest -m integration -q
```

Real OCR tests run the actual bundled ONNX models, with no mocked receipt predictions. Tests include the two user-supplied images, degraded variants, newly rendered receipts with different totals, multi-page PDF/TIFF, nonreceipt/blank images, decimal parsing, arithmetic discrepancies, identifiers, ambiguous currencies, upload/auth/host limits, and overlapping requests. Benchmark JSON retains recognized text and actual outputs under `artifacts/benchmark/`. These are local test artifacts and are excluded from the container.

`tests/test_receipt_folder.py` also exercises all ten original files from the user's `Receipt` folder through the upload endpoint, including the original AVIF, and tests rotated table receipts. Test images are copied under `tests/fixtures/receipt-folder/`; `expected.json` contains manually read ground truth and is used only by tests. Application code never reads this manifest. The earlier failing baseline remains under `artifacts/receipt-folder-2026-11-26/` for comparison; the corrected live-server retest is under `artifacts/receipt-folder-fixed-2026-11-26/`.

## Source map

`receipt_ocr/engine.py` orchestrates OCR and consensus; `imaging.py` validates and prepares uploads; `extraction.py` performs layout and monetary reasoning; `models.py` defines responses; `api.py` serves the HTTP service; `cli.py` exposes batch use. `receipt_ocr/static/` contains the complete dependency-free browser UI and API reference. `tests/` contains regression and real OCR tests with the attached receipt fixtures. `Dockerfile`, `run.sh`, and `pyproject.toml` provide packaging and launch configuration.

Open-source dependencies and model fingerprints are documented in `THIRD_PARTY.md`.

