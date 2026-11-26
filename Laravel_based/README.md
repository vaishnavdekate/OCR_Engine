# Receipt Lens — Laravel, without Python

This is the Laravel/PHP conversion of the receipt application. The workspace has this layout:

```text
OCR_Engine/
├── Receipt/          original receipt inputs, shared by tests
├── Python_based/     complete preserved Python project, environment, and artifacts
└── Laravel_based/    this standalone Laravel application
```

The Laravel application runs without the Python project, a Python interpreter, Python packages, or the Python HTTP service. Web/API/CLI logic, monetary extraction, currency handling, row grouping, orientation selection, arithmetic, warnings, and consensus are PHP. Text detection/recognition runs in a native C++ executable using the same Apache-2.0 PP-OCRv6 small model files. ImageMagick handles images and Poppler handles scanned PDFs. No paid API, GPU, database, frontend build, or external OCR service is required.

## Run on this computer

Dependencies, the native executable, and model files are already installed:

```sh
cd /Users/vaishnavdekate/Projects/OCR_Engine/Laravel_based
sh run.sh
```

Open <http://127.0.0.1:8766>. Keep the terminal open; Ctrl+C stops the Laravel development server. The launcher uses two PHP workers so concurrent requests can receive the same busy response as the Python application. Change the port with `PORT=8770 sh run.sh`.

The preserved Python application runs independently at <http://127.0.0.1:8765>:

```sh
cd /Users/vaishnavdekate/Projects/OCR_Engine/Python_based
PORT=8765 sh run.sh
```

## New-machine setup

Tested on macOS arm64 with PHP 8.5.10, Laravel 13.44.1, ImageMagick 7.1.2-36, Poppler 26.11.0, CMake 4.5.0, and Apple Clang 21. Composer's lock file requires PHP 8.4.1 or newer and the `intl` extension, plus normal Laravel extensions. A C++20 compiler and CMake >=3.20 are required to build the native engine.

On macOS, install the tools with Homebrew:

```sh
brew install php composer cmake imagemagick poppler
```

Then, inside this directory:

```sh
sh scripts/setup.sh
sh run.sh
```

The setup script installs Composer dependencies, creates a local `.env`, generates an application key, and builds the native executable. A verified project-local Composer PHAR is also included under `tools/`. Model files and the character dictionary are provided under `storage/app/models/`; their hashes and provenance are in `MODELS.md`. Model downloads and a Python installation are not part of setup. Rebuild the native executable for each operating system/architecture; the supplied local binary is macOS arm64. Linux/Docker packaging is supplied but has not been executed here.

## Preserved application behavior

- Same receipt workspace, session list, source-row highlighting, manual correction/confirmation, and JSON/CSV exports. Confirmed values remain separate from original OCR values.
- Same `POST /api/receipts`, `GET /api/health`, `/docs`, and `/openapi.json` locations and receipt result fields.
- Exact monetary decimal strings. Arithmetic uses integer cents. Supports grouped numbers, decimal comma/point, one- or two-digit fractions, whole contextual amounts, negatives, and parentheses.
- Total/subtotal/tax/tip extraction; payment/change/exchange separation; best-effort line items and merchant names; source text and bounding boxes.
- Four page orientations, geometric row alignment, a second image pass, agreement checks, low-certainty warnings, and abstention when an explicit total is unreadable.
- JPEG, PNG, WebP, TIFF, AVIF, and scanned PDF. Limits remain 20 MiB, 25 megapixels per page, and five pages. Pages are independent.
- Optional bearer authentication, allowed-host and origin checks, request/file limits, and a cross-process lock returning 503 with `Retry-After: 5` when OCR is busy.

OCR text and confidence can differ between the native C++ interpreter/ImageMagick preprocessing and Python's ONNX Runtime/OpenCV implementation. The public workflow and monetary rules are preserved; recognition is verified against the receipt folder rather than assumed byte-identical. Scores are heuristic, and two passes can share errors. Ambiguous currency still requires review.

## HTTP API

```sh
curl -X POST http://127.0.0.1:8766/api/receipts \
  -F 'file=@../Receipt/ReceiptSwiss.jpg' \
  -F 'preview=false'
```

Optional fields: `currency=CHF`, `preview=false`. A hint resolves missing/ambiguous currency without replacing an explicit printed code. Return values include `filename`, input `sha256`, `engine`, `processing_ms`, and per-page `total`, `subtotal`, `tax`, `tip`, `candidates`, `lines`, `raw_text`, `checks`, `warnings`, `needs_review`, preview, dimensions, rotation, and pass count. Amounts are decimal strings. A null total is not zero. The API reference and schema are local assets.

## Artisan CLI

```sh
php artisan receipt:extract ../Receipt/ReceiptSwiss.jpg
php artisan receipt:extract ../Receipt/receipt.jpeg ../Receipt/348s.jpg --output results.json
php artisan receipt:extract /absolute/path/receipt.avif --currency CHF
```

The CLI omits image previews, supports multiple files, uses the same PHP service as HTTP uploads, and exits with code 2 for invalid input or busy OCR.

## Configuration and operation

`config/receipt.php` contains limits and native-tool settings. Environment variables:

| Variable | Purpose |
| --- | --- |
| `RECEIPT_API_TOKEN` | Optional bearer token. Enter it in the UI or send `Authorization: Bearer ...`. |
| `RECEIPT_ALLOWED_HOSTS` | Comma-separated hostnames; default `127.0.0.1,localhost`. |
| `OCR_NATIVE_BINARY` | Native recognizer path; default `native/bin/receipt-ocr`. |
| `OCR_MODEL_DIR` | Default `storage/app/models`. |
| `IMAGEMAGICK_BINARY` | Default `bin/image-tool`, supporting ImageMagick 7 and 6 command forms. AVIF needs a capable ImageMagick codec build. |
| `PDFINFO_BINARY`, `PDFTOPPM_BINARY` | Poppler tools. macOS defaults use Homebrew paths; Linux defaults use PATH. |
| `OCR_PROCESS_TIMEOUT` | Per-native-command timeout, default 45 seconds. |

The app uses private temporary directories for processing and removes them in `finally` blocks. PHP may spool uploads to temporary files. There is no receipt database or server-side receipt history. `storage/app/receipt.lock` is a stable lock file, not a saved receipt. The UI holds results in memory until reload. Logs and temporary processing directories must remain private and writable. After an OS/process crash, an administrator can remove abandoned `storage/app/ocr-tmp/` contents once no job is active.

For another server, configure authenticated HTTPS, allowed hosts, reverse-proxy body limits/timeouts, and a persistent `APP_KEY`. Serve only `public/`. Keep native code, models, temporary files, `.env`, tests, credentials, and artifacts outside the web root. A local single-worker lock is not a distributed queue. No external deployment has been performed.

An optional Dockerfile builds the native engine without Python. It excludes `.env`, tests, generated results, caches, dependency folders, and prior binaries. The container generates an ephemeral application key if `APP_KEY` is absent; production should supply a persistent key. Docker has not been tested on this host.

## Tests and evidence

```sh
php vendor/bin/phpunit
php vendor/bin/phpunit --exclude-group integration
php vendor/bin/phpunit --group integration
```

Integration tests read `../Receipt` by default. Override with `RECEIPT_TEST_DIR=/absolute/path/to/Receipt`. Static parser vectors in `tests/Fixtures/parser-parity.json` compare the PHP implementation against captured outputs from the original parser; the test runner does not invoke Python. Native tests cover every shared receipt, rotated table images, blank-image abstention, PDF/TIFF pages and page limits. `CONVERSION_REPORT.md` and `artifacts/` contain exact results.

The final conversion check passed 87 PHP tests (539 assertions), and the running HTTP endpoint matched the manually checked totals for all 20 receipts in the saved input snapshot, including AVIF. Additional checks cover known subtotal/tax/tip/currency fields. These results describe this test set; they do not establish accuracy for unseen receipts or every extracted field. The preserved Python project passed its existing 124 tests after relocation.

Repeat the live receipt check while the app is running:

```sh
php scripts/verify-receipts.php
```

The Laravel conversion also handles repeated tax summaries, a final “total to pay” following an intermediate balance, plural discount labels, and conservative repairs of OCR total labels and currency-prefixed decimal hyphens. Repaired OCR amounts remain flagged for review. These cases have dedicated regression tests; the preserved Python parser has not been changed.

## Source map

`app/Receipt/AmountExtractor.php` and `Money.php` implement amount extraction. `ReceiptEngine.php` handles orientations and consensus; `DocumentImages.php` handles native decoding/previews; `NativeRecognizer.php` translates native polygons into PHP text rows. `NativeProcess.php` launches argument arrays without a shell. `ReceiptLock.php` bounds concurrency. The HTTP controller and middleware live under `app/Http`; the Artisan command is under `app/Console/Commands`. Blade views, static assets, and the preserved schema live under `resources/` and `public/static/`. `native/receipt_ocr.cpp` is the small recognition-only adapter; the pinned native dependency is under `native/rapidocrlite/`.
