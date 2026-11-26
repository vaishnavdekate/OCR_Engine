import io
import warnings
from dataclasses import dataclass

import cv2
import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError

MAX_BYTES = 20 * 1024 * 1024
MAX_PIXELS = 25_000_000
MAX_PAGES = 5
Image.MAX_IMAGE_PIXELS = MAX_PIXELS


class InvalidDocument(ValueError):
    pass


@dataclass
class PageImage:
    rgb: np.ndarray
    page: int


def _rgb(image: Image.Image) -> np.ndarray:
    image = ImageOps.exif_transpose(image)
    if image.width * image.height > MAX_PIXELS:
        raise InvalidDocument("Image exceeds the 25 megapixel limit.")
    if image.width < 40 or image.height < 40:
        raise InvalidDocument("Image is too small to read (minimum 40 × 40 pixels).")
    if image.mode in ("RGBA", "LA") or "transparency" in image.info:
        rgba = image.convert("RGBA")
        canvas = Image.new("RGBA", rgba.size, "white")
        canvas.alpha_composite(rgba)
        image = canvas
    return np.array(image.convert("RGB"))


def decode_document(data: bytes) -> list[PageImage]:
    if not data:
        raise InvalidDocument("The uploaded file is empty.")
    if len(data) > MAX_BYTES:
        raise InvalidDocument("File exceeds the 20 MiB limit.")
    if data.startswith(b"%PDF-"):
        return _decode_pdf(data)
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(data)) as image:
                if image.format not in {"JPEG", "PNG", "WEBP", "TIFF", "AVIF"}:
                    raise InvalidDocument("Use JPEG, PNG, WebP, TIFF, AVIF, or PDF.")
                if getattr(image, "n_frames", 1) > MAX_PAGES:
                    raise InvalidDocument(f"Documents may contain at most {MAX_PAGES} pages.")
                pages = []
                for i in range(getattr(image, "n_frames", 1)):
                    image.seek(i)
                    pages.append(PageImage(_rgb(image), i + 1))
                return pages
    except InvalidDocument:
        raise
    except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError,
            Image.DecompressionBombWarning) as exc:
        raise InvalidDocument("Cannot decode this image. It may be corrupt or too large.") from exc


def _decode_pdf(data: bytes) -> list[PageImage]:
    import pypdfium2 as pdfium

    try:
        with pdfium.PdfDocument(data) as document:
            if not 1 <= len(document) <= MAX_PAGES:
                raise InvalidDocument(f"PDFs must contain 1–{MAX_PAGES} pages.")
            pages = []
            for i in range(len(document)):
                page = document[i]
                try:
                    w, h = page.get_size()
                    if min(w, h) <= 0 or w * h * 4 > MAX_PIXELS:
                        raise InvalidDocument("PDF page exceeds the render size limit.")
                    bitmap = page.render(scale=2)
                    try:
                        pages.append(PageImage(_rgb(bitmap.to_pil()), i + 1))
                    finally:
                        bitmap.close()
                finally:
                    page.close()
            return pages
    except InvalidDocument:
        raise
    except Exception as exc:
        raise InvalidDocument("Cannot read this PDF. Encrypted or corrupt PDFs are unsupported.") from exc


def prepare(rgb: np.ndarray) -> np.ndarray:
    h, w = rgb.shape[:2]
    scale = min(2200 / max(h, w), max(1.0, 1000 / min(h, w)))
    if scale != 1:
        rgb = cv2.resize(rgb, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)
    return rgb


def enhance(rgb: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    # Normalize uneven illumination while preserving decimal dots and thin thermal print.
    background = cv2.GaussianBlur(gray, (0, 0), 21)
    normalized = cv2.divide(gray, background, scale=255)
    normalized = cv2.createCLAHE(clipLimit=1.5, tileGridSize=(8, 8)).apply(normalized)
    return cv2.cvtColor(normalized, cv2.COLOR_GRAY2RGB)


def preview_data(rgb: np.ndarray) -> str:
    import base64

    image = Image.fromarray(rgb)
    image.thumbnail((1400, 1800))
    out = io.BytesIO()
    image.save(out, format="JPEG", quality=85)
    return "data:image/jpeg;base64," + base64.b64encode(out.getvalue()).decode("ascii")

