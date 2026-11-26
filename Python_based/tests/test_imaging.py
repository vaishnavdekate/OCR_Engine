import io

import numpy as np
import pytest
from PIL import Image

from receipt_ocr.imaging import MAX_BYTES, InvalidDocument, decode_document


def encoded(image, fmt="PNG", **kwargs):
    out = io.BytesIO()
    image.save(out, format=fmt, **kwargs)
    return out.getvalue()


@pytest.mark.parametrize("data", [b"", b"not an image", b"%PDF-broken", b"<svg onload='alert(1)'></svg>"])
def test_bad_uploads(data):
    with pytest.raises(InvalidDocument):
        decode_document(data)


def test_oversized_file():
    with pytest.raises(InvalidDocument, match="20 MiB"):
        decode_document(b"x" * (MAX_BYTES + 1))


def test_oversized_pixels():
    with pytest.raises(InvalidDocument):
        decode_document(encoded(Image.new("L", (5100, 5100), 255)))


def test_too_small():
    with pytest.raises(InvalidDocument, match="too small"):
        decode_document(encoded(Image.new("RGB", (20, 20))))


def test_transparency_composited_on_white():
    page = decode_document(encoded(Image.new("RGBA", (80, 80), (0, 0, 0, 0))))[0]
    assert np.all(page.rgb == 255)


def test_exif_orientation():
    image = Image.new("RGB", (80, 100), "white")
    exif = Image.Exif()
    exif[274] = 6
    page = decode_document(encoded(image, "JPEG", exif=exif))[0]
    assert page.rgb.shape[:2] == (80, 100)


def test_tiff_pages():
    image = Image.new("RGB", (80, 100), "white")
    data = encoded(image, "TIFF", save_all=True, append_images=[image])
    assert len(decode_document(data)) == 2


def test_too_many_tiff_pages():
    image = Image.new("RGB", (80, 100), "white")
    with pytest.raises(InvalidDocument, match="at most 5"):
        decode_document(encoded(image, "TIFF", save_all=True, append_images=[image] * 5))


def test_pdf_page_limit():
    image = Image.new("RGB", (80, 100), "white")
    with pytest.raises(InvalidDocument, match="1–5 pages"):
        decode_document(encoded(image, "PDF", save_all=True, append_images=[image] * 5))


def test_pdf_render():
    image = Image.new("RGB", (200, 300), "white")
    pages = decode_document(encoded(image, "PDF"))
    assert len(pages) == 1
    assert pages[0].rgb.shape[:2] == (600, 400)


def test_avif_decode_by_content_not_extension():
    image = Image.new("RGB", (120, 180), "white")
    data = encoded(image, "AVIF")
    page = decode_document(data)[0]
    assert page.rgb.shape == (180, 120, 3)


def test_avif_keeps_pixel_limit():
    with pytest.raises(InvalidDocument):
        decode_document(encoded(Image.new("RGB", (5100, 5100), "white"), "AVIF"))

