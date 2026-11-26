# Open-source components

No commercial OCR API is used. Installed dependencies retain their own licenses and notices. The source archive does not redistribute installed dependency wheels or model binaries.

| Component | Purpose | License / upstream |
| --- | --- | --- |
| RapidOCR 3.10.0 | Detection and recognition pipeline | Apache-2.0; https://github.com/RapidAI/RapidOCR |
| PP-OCRv6 small models | Receipt text detection and recognition | Apache-2.0; Baidu/PaddleOCR rights holders; https://rapidai.github.io/RapidOCRDocs/main/en/model_licenses/ |
| ONNX Runtime 1.32.0 | CPU inference | MIT; https://github.com/microsoft/onnxruntime |
| OpenCV headless | Image preparation | Apache-2.0; https://github.com/opencv/opencv-python |
| Pillow | Safe image decoding and previews | MIT-CMU; https://python-pillow.org |
| FastAPI / Starlette / Uvicorn | HTTP service | MIT / BSD-3-Clause / BSD-3-Clause; upstream package notices |
| pypdfium2 / PDFium | Scanned PDF rendering | BSD-3-Clause / Apache-2.0 wrapper options, plus bundled PDFium dependency notices; https://github.com/pypdfium2-team/pypdfium2 |

Default model fingerprints from the tested RapidOCR wheel:

```text
PP-OCRv6_det_small.onnx
090f04abcd9d9a7498bc4ebf677e4cb9bdce1fe4197ddb7e529f1ef44e1ff94f

PP-OCRv6_rec_small.onnx
6f327246b50388f3c176ae304bd95767ea6dc0c9ae92153ef8cbe210b3c14884

ch_ppocr_mobile_v2.0_cls_mobile.onnx
e47acedf663230f8863ff1ab0e64dd2d82b838fceb5957146dab185a89d6215c
```

The classifier is bundled but disabled in this app: whole-page orientations are evaluated explicitly. Source-row geometry stays aligned with the oriented preview.

Quality guidance consulted: https://tesseract-ocr.github.io/tessdoc/ImproveQuality.html and the official RapidOCR usage documentation at https://rapidai.github.io/RapidOCRDocs/main/en/install_usage/rapidocr/usage/ . This implementation uses RapidOCR, not Tesseract.

`tests/fixtures/usps.jpeg` and `tests/fixtures/swiss.jpg` are copies of the user's supplied test receipts. They are local testing inputs, not a licensed public receipt corpus, and are excluded from application container packaging.
