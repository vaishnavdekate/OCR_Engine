# Laravel conversion verification

Verified on 26 November 2026 on macOS arm64. The workspace is `/Users/vaishnavdekate/Projects/OCR_Engine` with exactly these application folders:

```text
Receipt/
Python_based/
Laravel_based/
```

## Preservation and runtime

The existing Python project, virtual environment, tests, caches, build files, and previous artifacts were moved into `Python_based`. SHA-256 comparison verified all 6,865 original project files at the move. Subsequently the Python README launch path and the installed editable package entry points were updated for the new location. Python source logic was preserved. Original receipt inputs were retained separately at the root; the final 20-file receipt snapshot records their hashes.

The Laravel implementation requires PHP and native tools only: Laravel routes/controllers/middleware, PHP monetary parsing and selection, PHP image orchestration and review logic, ImageMagick, Poppler, and a small native C++ OCR process. It neither invokes a Python interpreter nor calls the Python application. The original UI JavaScript and CSS are reused with Blade. HTTP response fields, the upload/review/export workflow, and CLI extraction remain available.

The OCR executable uses the same PP-OCRv6 small weights through RapidOCRLite's native CPU interpreter at commit `9c8769008c07e13867c173c786fdc66c7fb314b4`. Recognition and image preprocessing can differ from the Python ONNX Runtime/OpenCV stack; byte-identical OCR text/confidences are not claimed. Model hashes and licenses are documented in `MODELS.md`; the native source and license are supplied under `native/rapidocrlite`.

## Results

| Check | Result | Evidence |
| --- | --- | --- |
| Python suite after move | 124 passed; no failures/errors/skips | `artifacts/reorganized-python-tests.xml` |
| Laravel complete suite | 87 passed; 539 assertions; no failures/errors/skips | `artifacts/laravel-tests-final.xml` |
| Static monetary/parser parity | 15 money cases and 28 captured parser cases pass | `tests/Fixtures/parser-parity.json`, `tests/Unit/ReceiptParityTest.php` |
| Live Laravel HTTP uploads | 20/20 expected totals and declared field checks pass | `artifacts/live-receipts/results.json`, `artifacts/receipt-results.csv` |
| Input integrity | All 20 tested inputs match their frozen SHA-256 hashes | `artifacts/receipt-snapshot.json` |

The native tests cover all receipt inputs, rotated table receipts, blank-image abstention, multi-page PDF/TIFF handling, and page-count limits. HTTP tests cover the UI/schema, authentication, host/origin checks, invalid/missing/oversized uploads, currency hints, and busy responses. A runtime-boundary test checks that application paths contain no Python program, Python-project reference, or call to the Python HTTP port.

The live API check took 71.5 seconds in total for the frozen 20 inputs. Its result files contain each expected value, observed response, pass/fail checks, input hash, and HTTP status. Expected totals were manually read from the receipt images; optional fields are checked only where declared in `tests/Fixtures/expected.json`. This is a finite regression set, not an estimate of general OCR accuracy. Merchant names, all line items, and every currency are not certified by a passing total check. Ambiguous currency and repaired text continue to require review.

## Corrections discovered during conversion

The added receipts exposed repeated tax summaries, an intermediate balance before a final payable total, plural discount labels, and OCR confusion in total labels/decimal separators. The PHP parser now avoids double-counting an arithmetically verified tax summary and prioritizes a final payable total. Conservative text repairs are flagged for review. Dedicated tests cover these cases and prevent an ordinary “Hotel” label from being treated as a repaired “Total.” These improvements were made in Laravel; the original Python parser remains preserved.

## Reproduce

```sh
cd /Users/vaishnavdekate/Projects/OCR_Engine/Laravel_based
sh scripts/setup.sh
php vendor/bin/phpunit
sh run.sh
```

With the server running, use another terminal:

```sh
php scripts/verify-receipts.php --snapshot=artifacts/receipt-snapshot.json
php artisan receipt:extract ../Receipt/ReceiptSwiss.jpg
```

The local Laravel server uses port 8766 and the independent Python app uses 8765. Both health endpoints were verified after the move. The live HTTP tests exercise the actual PHP server; a new automated visual/browser interaction pass was unavailable because the local browser-control helper could not start. Docker/Linux packaging is included but untested here. No external server deployment was performed.

## Source delivery

`artifacts/ocr-engine-reorganized-source.zip` contains application sources for both projects, the shared receipts, PHP dependency locks, native sources/models, and this verification evidence. Python virtual environments, Composer vendor files, private `.env` files, runtime caches/logs, temporary uploads, prior generated archives, Git metadata, and local workflow/tracking instructions are excluded. Install dependency versions from the supplied manifests; rebuild the native executable for another operating system/architecture. `artifacts/source-manifest.json` records the included file hashes.
