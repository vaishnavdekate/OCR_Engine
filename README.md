# Receipt OCR Engine

Receipt amount extraction with independent Python and Python-free Laravel implementations.

```text
Receipt/         shared test receipt images
Python_based/    original Python implementation
Laravel_based/   PHP application with native C++ OCR inference
```

Both implementations use PP-OCRv6 small detection and recognition models. They expose receipt uploads, amount candidates and source evidence, manual review, JSON/CSV export, and command-line extraction. Inputs include JPEG, PNG, WebP, TIFF, AVIF, and scanned PDF.

## Laravel application

The Laravel version uses RapidOCRLite's native C++ CPU interpreter. PHP handles amount parsing, currencies, arithmetic validation, orientation selection, and review logic. It does not require Python or the Python service.

Requirements: PHP >=8.4.1 with `intl` and normal Laravel extensions, Composer, ImageMagick with AVIF support, and Poppler. Building the native executable requires CMake >=3.20 and a C++20 compiler. Model files and native source are included. Rebuild the executable for your OS/architecture; the supplied binary is macOS ARM64.

```sh
cd Laravel_based
sh scripts/setup.sh
sh run.sh
```

Open <http://127.0.0.1:8766>. See [Laravel setup and operation](Laravel_based/README.md), [model provenance](Laravel_based/MODELS.md), and [conversion verification](Laravel_based/CONVERSION_REPORT.md).

## Python application

```sh
cd Python_based
python3 -m venv .venv
.venv/bin/python -m pip install -e '.[dev]'
PORT=8765 sh run.sh
```

Open <http://127.0.0.1:8765>. See [Python documentation](Python_based/README.md) and [dependency notices](Python_based/THIRD_PARTY.md).

## Verification

The conversion was verified with 87 Laravel tests (539 assertions), the preserved Python suite's 124 tests, and expected totals for all 20 shared receipts, including AVIF. These results describe the regression inputs, not general accuracy for unseen receipts or every extracted field. Ambiguous results remain subject to review.

```sh
cd Laravel_based
php vendor/bin/phpunit
# With the Laravel server running:
php scripts/verify-receipts.php
```

Run the Python suite from `Python_based` with `.venv/bin/python -m pytest`.

Private `.env` files, installed dependencies, virtual environments, runtime caches/logs, build directories, and generated local reports/archives are not versioned. Tests, fixtures, models, native sources, dependency manifests, and setup scripts are included. Setup and verification commands regenerate local dependencies and artifacts. A GitHub push does not deploy an application server.
