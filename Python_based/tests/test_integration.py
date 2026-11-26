import io
import json
from pathlib import Path

import numpy as np
import pytest
from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont

pytestmark = pytest.mark.integration
VARIANTS = ["original", "rotate90", "rotate180", "rotate270", "skew", "blur", "dim", "jpeg", "shadow", "small"]


def encode(image, fmt="PNG", **kwargs):
    out = io.BytesIO()
    image.save(out, format=fmt, **kwargs)
    return out.getvalue()


def variant(image, name):
    if name.startswith("rotate"):
        return image.rotate(int(name[6:]), expand=True)
    if name == "skew":
        return image.rotate(6, expand=True, fillcolor="white")
    if name == "blur":
        return image.filter(ImageFilter.GaussianBlur(.65))
    if name == "dim":
        return ImageEnhance.Contrast(ImageEnhance.Brightness(image).enhance(.65)).enhance(.75)
    if name == "shadow":
        array = np.asarray(image).astype(np.float32)
        gradient = np.linspace(.45, 1.0, image.width).reshape(1, -1, 1)
        return Image.fromarray(np.uint8(array * gradient))
    if name == "small":
        ratio = 380 / image.width
        return image.resize((380, int(image.height * ratio)), Image.Resampling.LANCZOS)
    return image


@pytest.mark.parametrize("sample,amount,currency", [("usps.jpeg", "55.00", "USD"), ("swiss.jpg", "54.50", "CHF")])
@pytest.mark.parametrize("name", VARIANTS)
def test_real_receipt_variants(engine, fixtures, sample, amount, currency, name):
    image = variant(Image.open(fixtures / sample).convert("RGB"), name)
    data = encode(image, "JPEG", quality=35) if name == "jpeg" else encode(image)
    result = engine.process(data, sample, include_preview=False)
    page = result.pages[0]
    # Persist evidence before asserting, so failures are part of the benchmark record.
    output = Path(__file__).parents[1] / "artifacts" / "benchmark"
    output.mkdir(parents=True, exist_ok=True)
    record = {"sample": sample, "variant": name, "expected": {"amount": amount, "currency": currency},
              "result": result.model_dump()}
    (output / f"{Path(sample).stem}-{name}.json").write_text(json.dumps(record, indent=2), encoding="utf-8")
    assert page.total is not None, page.raw_text
    assert page.total.amount == amount, page.raw_text
    assert page.total.currency == currency, page.raw_text
    if sample == "swiss.jpg" and name == "original":
        assert page.tax.amount == "3.85"
        assert page.checks["line_item_sum"] == "54.50"
        assert any(c.role == "exchange" and c.amount == "36.33" and c.currency == "EUR" for c in page.candidates)


def font(size):
    paths = ["/System/Library/Fonts/Supplemental/Arial.ttf",
             "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"]
    for path in paths:
        if Path(path).exists():
            return ImageFont.truetype(path, size)
    return ImageFont.load_default(size=size)


def synthetic(lines):
    image = Image.new("RGB", (1000, max(650, 150 + len(lines) * 72)), "white")
    draw = ImageDraw.Draw(image)
    for i, line in enumerate(lines):
        draw.text((55, 45 + i * 72), line, font=font(37), fill="black")
    return image


@pytest.mark.parametrize("lines,expected,currency", [
    (["BAY CAFE", "Subtotal USD 18.40", "Tax USD 1.60", "TOTAL USD 20.00", "Cash USD 50.00", "Change USD 30.00"], "20.00", "USD"),
    (["CAFE PARIS", "Sous-total EUR 100,00", "TVA EUR 20,00", "Net a payer EUR 120,00"], "120.00", "EUR"),
    (["ALPEN SHOP", "Total CHF 1'234.50", "Exchange equivalent EUR 1100.00"], "1234.50", "CHF"),
    (["MUMBAI CAFE", "Subtotal INR 1000.00", "CGST INR 25.00", "SGST INR 25.00", "Grand Total INR 1050.00"], "1050.00", "INR"),
    (["REFUND RECEIPT", "Total USD -25.50"], "-25.50", "USD"),
])
def test_unseen_synthetic_totals(engine, lines, expected, currency):
    page = engine.process(encode(synthetic(lines)), include_preview=False).pages[0]
    assert page.total is not None, page.raw_text
    assert page.total.amount == expected, page.raw_text
    assert page.total.currency == currency


def test_blank_and_nonreceipt_abstain(engine):
    for image in [Image.new("RGB", (650, 800), "white"),
                  synthetic(["This is a letter", "Please call 555 123 4567", "Date 30.07.2007"])]:
        page = engine.process(encode(image), include_preview=False).pages[0]
        assert page.total is None
        assert page.needs_review


def test_two_page_pdf(engine):
    first = synthetic(["FIRST SHOP", "Total USD 12.75"])
    second = synthetic(["SECOND SHOP", "Total CHF 83.20"])
    result = engine.process(encode(first, "PDF", save_all=True, append_images=[second]), include_preview=False)
    assert len(result.pages) == 2
    assert [p.total.amount for p in result.pages] == ["12.75", "83.20"]
    assert [p.total.currency for p in result.pages] == ["USD", "CHF"]


def test_two_page_tiff(engine):
    first = synthetic(["FIRST SHOP", "Total USD 9.25"])
    second = synthetic(["SECOND SHOP", "Total CHF 61.10"])
    result = engine.process(encode(first, "TIFF", save_all=True, append_images=[second]), include_preview=False)
    assert [p.total.amount for p in result.pages] == ["9.25", "61.10"]
