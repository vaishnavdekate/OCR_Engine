# Total-extraction fixes and AVIF support — 26 November 2026

The release archive includes every complete application file, tests, fixtures, and documentation. These are the files changed or added for this update:

| File | Change |
| --- | --- |
| `receipt_ocr/engine.py` | Select page orientation using text-box geometry and recognition confidence; estimate receipt tilt from detection polygons; preserve geometry checks when applying currency hints. |
| `receipt_ocr/extraction.py` | Group labels and prices along the receipt baseline; accept one- and two-digit decimal fractions while retaining exact decimal values. |
| `receipt_ocr/imaging.py` | Accept native AVIF decoding with the same upload, pixel, frame, EXIF, and transparency handling. |
| `receipt_ocr/static/index.html` | Include AVIF in the file picker and displayed formats. |
| `receipt_ocr/static/api.html` | Document AVIF upload support. |
| `README.md` | Explain the fixes, AVIF support, tests, and current project path. |
| `SOURCE_FILES.md` | Extend the source inventory. |
| `TEST_REPORT.md` | Record the updated regression and live folder-retest results. |
| `tests/test_extraction.py` | Add decimal and tilted-row regressions. |
| `tests/test_imaging.py` | Add native AVIF and AVIF pixel-limit tests. |
| `tests/test_receipt_folder.py` | New real OCR/upload regressions for every folder input and rotated table receipts. |
| `tests/fixtures/receipt-folder/expected.json` | New manually read expected totals/subtotals/taxes, used only by tests. |
| `tests/fixtures/receipt-folder/*` | Byte-identical copies of all ten original user-provided receipt inputs. |
| `CHANGED_FILES.md` | This inventory. |

Application extraction code does not read the fixture manifest or branch on sample filenames. Currency ambiguity remains a review condition. The original failing test evidence is retained separately from the new results.

No existing input receipts were edited. Tests, fixtures, dependency environments, and generated evidence remain outside application container packaging.
