# Model and native dependency provenance

The Laravel runtime uses the original PP-OCRv6 small detector and recognizer assets, without Python or ONNX Runtime. The C++ interpreter is RapidAI/RapidOCRLite at commit `9c8769008c07e13867c173c786fdc66c7fb314b4`.

| Asset | SHA-256 |
| --- | --- |
| `PP-OCRv6_det_small.onnx` | `090f04abcd9d9a7498bc4ebf677e4cb9bdce1fe4197ddb7e529f1ef44e1ff94f` |
| `PP-OCRv6_rec_small.onnx` | `6f327246b50388f3c176ae304bd95767ea6dc0c9ae92153ef8cbe210b3c14884` |

These files were copied unchanged from the already verified RapidOCR 3.10.0 package. `dict.txt` is the character metadata extracted once from the recognizer during conversion and distributed as a static asset. No runtime extraction or Python dependency is needed. `storage/app/models/manifest.json` records every asset's actual hash.

Official provenance and licensing: https://rapidai.github.io/RapidOCRDocs/main/en/model_licenses/ . PP-OCRv6 weights are copyright Baidu and/or the relevant PaddleOCR rights holders and licensed under Apache-2.0. Native conversion/packaging does not transfer model ownership. The full Apache-2.0 license is retained in `native/rapidocrlite/LICENSE`. This application does not use or include the experimental Indra model weights.

Laravel is MIT licensed; Composer dependencies retain their licenses under `vendor/`. RapidOCRLite source is Apache-2.0, with its source notices retained. ImageMagick and Poppler are separately installed native dependencies with their own licenses. No proprietary OCR API or paid service is used.
