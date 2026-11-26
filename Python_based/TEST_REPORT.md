# Receipt Lens verification

## Latest verification — 26 November 2026

**124 tests passed**, with zero failures or skips, in **197.12 seconds**: 79 parsing/imaging/API tests and 45 real OCR integration cases. The original 20 receipt transformation regressions still pass. New cases cover all ten inputs from the user's `Receipt` folder, their expected printed totals, available subtotal/tax values, direct AVIF uploads, AVIF size limits, skewed text rows, one-digit decimal fractions, and rotated versions of both table receipts.

After applying the tested files and restarting the actual localhost service, every original file in `/Users/vaishnavdekate/Projects/OCR_Engine/Receipt` was uploaded directly to `POST /api/receipts`, with no currency hint or manual format conversion. **10/10 returned HTTP 200 and the correct printed total.** Declared subtotal/tax/currency assertions also passed. The AVIF response includes a valid inline preview. Regression fixture hashes match the original folder inputs byte-for-byte.

| Previously failing input | Before | After | Printed total |
| --- | ---: | ---: | ---: |
| `348s.jpg` | 0.00 | 141.38 | 141.38 |
| `receipt-type-1.png` | 19.90 | 79.40 | 79.40 |
| `receipt-type-3.png` | 19.90 | 79.40 | 79.40 |
| `realistic-receipt-template_23-2147938550.avif` | Rejected | 16.50 | 16.50 |

Changes: page orientation now uses horizontal text geometry and recognition confidence; labels and prices are grouped along the estimated receipt baseline; one- and two-digit decimal fractions are accepted; AVIF is enabled in decoding, the browser file picker, and API documentation. These are general rules: application code does not read expected test values or branch on sample filenames.

Live request times were 2.593–5.978 seconds (median 3.833 seconds), including first-document model initialization. This is a local sequential observation, not a production latency guarantee. Health was `ok`, `busy=false` after the run.

Eight receipts still have ambiguous/absent currency and are marked for review; the USPS and Swiss examples resolve USD and CHF. **10/10 is total-amount matching, not complete receipt accuracy or a generalization guarantee.** Merchant names and structured line items remain best effort. Both preprocessing passes share a model and can share errors.

The updated package was installed locally so the CLI and service use the same fixes. Full source fingerprints, raw live responses, per-file comparisons, and the JUnit test report are retained in `artifacts/receipt-folder-fixed-2026-11-26/`. The previous failing baseline remains in `artifacts/receipt-folder-2026-11-26/`.

Browser file-picker automation was unavailable because the browser helper could not start in this chat. The current HTML's AVIF accept list, real upload endpoint, preview generation, and complete response fields were verified; this update does not claim a new browser interaction test. Docker and external-server deployment remain untested/not performed. One existing upstream Starlette TestClient deprecation warning remains.

## Initial verification — 25 November 2026

Verified locally on **25 November 2026**, using CPython 3.14.7 on macOS arm64 and the real, bundled RapidOCR 3.10.0 PP-OCRv6 small models with ONNX Runtime 1.32.0 CPU inference. No mocked OCR predictions or paid/cloud OCR calls were used.

## Results

**96 tests passed** in the full run (129.85 seconds): **67 parsing, imaging, and API tests**, plus **29 integration test cases**. Integration cases process 30 documents / 32 pages, including blank and nonreceipt negatives. Machine-readable evidence: `artifacts/tests.xml`, with per-variant OCR text and predictions in `artifacts/benchmark/*.json`.

| Original receipt | Expected | Actual | Additional checks |
| --- | --- | --- | --- |
| User's `receipt.jpeg` | USD 55.00 | USD 55.00 | Original and enhanced passes agree; item row adds up to 55.00 |
| User's `ReceiptSwiss.jpg` | CHF 54.50 | CHF 54.50 | Tax CHF 3.85; 4 item totals add up to 54.50; EUR 36.33 is an exchange equivalent |

**20/20 exact total-and-currency matches** across the following transformations of both real receipt images:

| Variant | USPS | Swiss |
| --- | --- | --- |
| Original | Pass | Pass |
| 90° rotation | Pass | Pass |
| 180° rotation | Pass | Pass |
| 270° rotation | Pass | Pass |
| 6° skew | Pass | Pass |
| Gaussian blur, radius 0.65 | Pass | Pass |
| Brightness 0.65, contrast 0.75 | Pass | Pass |
| JPEG quality 35 | Pass | Pass |
| Left-to-right shadow, brightness 0.45–1.0 | Pass | Pass |
| Resize to 380 pixels wide | Pass | Pass |

Processing time for these 20 cases was **3.797–6.694 seconds per document**, with a **4.949 second median** in the recorded full run. This is a local sequential benchmark, not a load or service latency guarantee. The browser upload of the full-size Swiss sample took about 7.4 seconds with concurrent development tests running.

Five newly rendered, different receipts also passed exact total/currency assertions: USD 20.00 with cash/change, EUR 120.00 with comma decimals, CHF 1'234.50 with an alternate EUR amount, INR 1050.00 with two tax components, and a USD -25.50 refund. Two-page PDF and TIFF receipts passed with distinct currencies/totals per page. Blank images and a nonreceipt letter abstained with no total.

## Behavior verified

- Exact decimal handling, localized separators, whole-number totals, negative/parenthesized amounts, and common OCR glyph repairs.
- English/German/French total labels; split label/value rows; wide receipt columns; totals distinguished from subtotal, tax basis, exchange equivalents, savings, cash and change.
- Low certainty and repaired digits require review. Conflicting totals and arithmetic discrepancies require review. Missing totals are not guessed from the largest amount.
- Currency hints resolve ambiguous symbols without replacing explicit currency codes.
- Dates, percentages, phone numbers, payment identifiers, and receipt numbers are excluded from monetary evidence.
- Content-based decoding, EXIF orientation, transparency, image pixel limit, file size limit, page count, corrupt/unsupported inputs, scanned PDF rendering, and TIFF pages.
- Real multipart receipt upload, sanitized display filename, preview omission, response schema, authentication, host validation, cross-origin rejection, request-size rejection before multipart parsing, and overlap backpressure while an actual test worker is blocked.
- Receipt text that resembles an instruction remains data.

Manual browser checks used the actual local app: successful Swiss receipt upload; CHF 54.50 total and CHF 3.85 tax displayed; matching item sum; review confirmation; edit/correct flow; source-row highlighting; JSON and CSV downloads. The downloaded files were read back to verify exact amounts, separate reviewed/original values, and omitted image previews. No browser console errors or warnings were observed in this flow. The API reference uses local assets and works without a documentation CDN.

## Fixes made during testing

The initial run exposed incorrect context-manager use for a PDF page and a word-boundary edge case after `Receipt #`. Both were corrected and the final full suite passed. The PDF render and two-page PDF tests now exercise resource cleanup. The default CDN-based API documentation was replaced with a local reference compatible with the app's content security policy.

## Limits of this evidence

These are **two independent real receipts**, plus deterministic transformations and synthetic examples. Transformations are correlated with their source images. A 20/20 result on this set does not establish 100% accuracy on new receipts. No independent large labelled receipt benchmark, handwriting benchmark, perspective/glare benchmark, mobile-device browser session, sustained load test, or calibrated confidence study was performed.

Both preprocessing passes share the same model and can agree on the same mistake. Some line items and merchant names may be imperfect even when the total is right. Severe damage, handwritten totals, unsupported languages, absent totals, multiple receipts in one image, and multi-page invoice aggregation require further work or review. The UI keeps human review available for every result.

The test runner emits one upstream Starlette warning about its legacy `httpx` TestClient adapter; this does not fail the tests. Docker was unavailable, so container build/start was not verified. No production, test, client-domain, or other external server deployment was performed. The pre-existing Bar Assistant application was not modified.
