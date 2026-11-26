"""Real-image regressions from the user's Receipt folder; values never enter app code."""

import io
import json
from pathlib import Path

import pytest
from fastapi.testclient import TestClient
from PIL import Image

from receipt_ocr.api import app

FIXTURES = Path(__file__).parent / "fixtures" / "receipt-folder"
EXPECTED = json.loads((FIXTURES / "expected.json").read_text())


@pytest.mark.integration
@pytest.mark.parametrize("name", sorted(EXPECTED))
def test_every_receipt_through_upload_endpoint(engine, name, monkeypatch):
    monkeypatch.delenv("RECEIPT_API_TOKEN", raising=False)
    with TestClient(app, base_url="http://localhost") as client:
        app.state.engine = engine
        response = client.post("/api/receipts", files={"file": (name, (FIXTURES / name).read_bytes())},
                               data={"preview": "true"})
    assert response.status_code == 200, response.text
    result = response.json()
    page = result["pages"][0]
    for field in ("total", "subtotal", "tax"):
        if field in EXPECTED[name]:
            assert page[field] is not None, page["raw_text"]
            assert page[field]["amount"] == EXPECTED[name][field], page["raw_text"]
    if "currency" in EXPECTED[name]:
        assert page["total"]["currency"] == EXPECTED[name]["currency"]
    else:
        assert page["needs_review"], "Currency ambiguity must still require review."
    assert page["preview"].startswith("data:image/jpeg;base64,")
    assert 0 <= page["rotation"] <= 270


@pytest.mark.integration
@pytest.mark.parametrize("name", ["receipt-type-1.png", "receipt-type-3.png"])
@pytest.mark.parametrize("angle", [90, 180, 270])
def test_table_receipts_in_rotated_images(engine, name, angle):
    image = Image.open(FIXTURES / name).convert("RGB").rotate(angle, expand=True)
    buffer = io.BytesIO()
    image.save(buffer, "PNG")
    page = engine.process(buffer.getvalue(), "arbitrary-name.png", include_preview=False).pages[0]
    assert page.total.amount == "79.40", page.raw_text
    assert page.tax.amount == "10.00"
    assert page.subtotal.amount == "69.40"
    assert page.checks["arithmetic"] == "matched"


def test_fixture_manifest_covers_every_input():
    assert {p.name for p in FIXTURES.iterdir() if p.suffix != ".json"} == set(EXPECTED)
