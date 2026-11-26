import hashlib
import logging
import math
import statistics
import threading
import time
from pathlib import Path

import cv2
import numpy as np

from .extraction import extract, group_lines
from .imaging import decode_document, enhance, prepare, preview_data
from .models import DocumentResult, ReceiptResult, TextLine

ENGINE_NAME = "RapidOCR 3.10.0 / PP-OCRv6 small / ONNX Runtime CPU"
logger = logging.getLogger(__name__)


class ReceiptEngine:
    def __init__(self):
        self._ocr = None
        self._lock = threading.Lock()

    def _load(self):
        if self._ocr is None:
            import onnxruntime as ort
            from rapidocr import RapidOCR

            ort.disable_telemetry_events()
            self._ocr = RapidOCR(params={
                "Global.log_level": "error",
                "Global.max_side_len": 2200,
                "EngineConfig.onnxruntime.intra_op_num_threads": 2,
                "EngineConfig.onnxruntime.inter_op_num_threads": 1,
                "Det.limit_side_len": 960,
            })

    def _read(self, rgb: np.ndarray, currency: str | None = None) -> ReceiptResult:
        self._load()
        # NumPy input is BGR in RapidOCR. Disable per-line 180° correction so page
        # orientation can be chosen consistently for both reading order and preview.
        output = self._ocr(cv2.cvtColor(rgb, cv2.COLOR_RGB2BGR), use_cls=False)
        detections = []
        horizontal_chars = 0
        total_chars = 0
        confidence_sum = 0.0
        angles = []
        if output.txts is not None and output.boxes is not None:
            for text, score, polygon in zip(output.txts, output.scores, output.boxes):
                if not text.strip():
                    continue
                points = np.asarray(polygon)
                width = float(np.ptp(points[:, 0]))
                height = float(np.ptp(points[:, 1]))
                chars = len(text.strip())
                total_chars += chars
                confidence_sum += chars * float(score)
                if width > height:
                    horizontal_chars += chars
                # Only long, near-horizontal text supplies a reliable receipt baseline.
                edge = points[1] - points[0]
                angle = math.degrees(math.atan2(float(edge[1]), float(edge[0])))
                if width > 2 * height and chars >= 4 and abs(angle) <= 20:
                    angles.append(angle)
                detections.append(TextLine(
                    text=text, confidence=float(score),
                    box=[float(points[:, 0].min()), float(points[:, 1].min()),
                         float(points[:, 0].max()), float(points[:, 1].max())],
                ))
        h, w = rgb.shape[:2]
        skew = statistics.median(angles) if angles else 0.0
        result = extract(group_lines(detections, skew), w, h, currency)
        result.checks["horizontal_text_fraction"] = f"{horizontal_chars / max(total_chars, 1):.4f}"
        result.checks["mean_text_confidence"] = f"{confidence_sum / max(total_chars, 1):.4f}"
        result.checks["line_skew_degrees"] = f"{skew:.2f}"
        return result

    @staticmethod
    def _orientation_score(result: ReceiptResult) -> float:
        # The recognizer can rotate individual vertical crops and still read their text.
        # Such crops must not win page orientation and then merge whole columns into rows.
        horizontal = float(result.checks.get("horizontal_text_fraction", "1"))
        mean_confidence = float(result.checks.get("mean_text_confidence", "0"))
        legible = sum(len(line.text) * line.confidence for line in result.lines)
        arithmetic = 4 if result.checks.get("arithmetic") == "matched" else 0
        return (50 * horizontal + 25 * mean_confidence + min(legible, 1000) / 200
                + (8 if result.total else 0) + arithmetic)

    def process(self, data: bytes, filename: str = "receipt", currency: str | None = None,
                include_preview: bool = True) -> DocumentResult:
        start = time.monotonic()
        pages = decode_document(data)
        results = []
        # ONNX sessions are shared and bounded to one active document per process.
        with self._lock:
            for page in pages:
                rgb = prepare(page.rgb)
                trials = []
                for turns in range(4):
                    rotated = np.ascontiguousarray(np.rot90(rgb, turns))
                    result = self._read(rotated, currency)
                    trials.append((result, rotated, turns * 90))
                result, oriented, rotation = max(trials, key=lambda t: self._orientation_score(t[0]))
                second = self._read(enhance(oriented), currency)
                selected = self._consensus(result, second)
                selected.rotation = rotation
                selected.passes = 5
                selected.page = page.page
                if include_preview:
                    selected.preview = preview_data(oriented)
                if len(selected.raw_text.strip()) < 20:
                    selected.warnings.append("Very little text was readable. Upload a clearer, complete receipt.")
                    selected.needs_review = True
                    selected.status = "needs_review" if selected.total else "no_total"
                results.append(selected)
        return DocumentResult(
            filename=Path(filename.replace("\\", "/")).name[:255] or "receipt",
            sha256=hashlib.sha256(data).hexdigest(), engine=ENGINE_NAME,
            processing_ms=round((time.monotonic() - start) * 1000), pages=results,
        )

    @staticmethod
    def _consensus(first: ReceiptResult, second: ReceiptResult) -> ReceiptResult:
        if first.total is None and second.total is not None:
            first, second = second, first
        if first.total:
            if second.total == first.total:
                first.checks["preprocessing_consensus"] = "matched"
                first.confidence = min(0.98, first.confidence + 0.025)
            elif second.total:
                first.checks["preprocessing_consensus"] = "disagreement"
                first.warnings.append("OCR passes disagree on the total. Compare the image and candidate amounts.")
                first.confidence = min(first.confidence, 0.60)
            else:
                first.checks["preprocessing_consensus"] = "single_pass_only"
                first.warnings.append("Only one preprocessing pass found the total. Confirm the amount.")
                first.confidence = min(first.confidence, 0.80)
        for candidate in first.candidates:
            if any((c.amount, c.currency, c.role) == (candidate.amount, candidate.currency, candidate.role)
                   for c in second.candidates):
                candidate.observations = 2
                candidate.reasons.append("Observed in both image preprocessing passes.")
        known = {(c.amount, c.currency, c.role) for c in first.candidates}
        for candidate in second.candidates:
            if (candidate.amount, candidate.currency, candidate.role) not in known:
                candidate.id = "enhanced-" + candidate.id
                candidate.reasons.append("Found in the illumination-normalized pass.")
                first.candidates.append(candidate)
        first.needs_review = not first.total or bool(first.warnings) or first.confidence < 0.85
        first.status = "no_total" if not first.total else "needs_review" if first.needs_review else "ok"
        return first

