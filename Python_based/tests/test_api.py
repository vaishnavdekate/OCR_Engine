import pytest
from fastapi.testclient import TestClient

from receipt_ocr.api import app
from receipt_ocr.imaging import MAX_BYTES


@pytest.fixture
def client(monkeypatch):
    monkeypatch.delenv("RECEIPT_API_TOKEN", raising=False)
    with TestClient(app, base_url="http://localhost") as client:
        yield client


def test_home_health_and_schema(client):
    assert client.get("/").status_code == 200
    assert client.get("/docs").status_code == 200
    assert "Download the OpenAPI schema" in client.get("/docs").text
    assert client.get("/static/app.js").status_code == 200
    assert client.get("/api/health").json()["status"] == "ok"
    assert "/api/receipts" in client.get("/openapi.json").json()["paths"]
    assert "frame-ancestors 'none'" in client.get("/").headers["content-security-policy"]


def test_invalid_upload(client):
    response = client.post("/api/receipts", files={"file": ("bad.jpg", b"bad", "image/jpeg")})
    assert response.status_code == 422
    assert not app.state.busy


def test_no_upload(client):
    assert client.post("/api/receipts").status_code == 422


def test_oversized_upload(client):
    response = client.post("/api/receipts", files={"file": ("large.jpg", b"x" * (MAX_BYTES + 1))})
    assert response.status_code == 413
    assert not app.state.busy


def test_currency_validation(client):
    response = client.post("/api/receipts", files={"file": ("x", b"x")}, data={"currency": "USD<script>"})
    assert response.status_code == 422


def test_busy_backpressure(client):
    app.state.busy = True
    response = client.post("/api/receipts", files={"file": ("x", b"x")})
    assert response.status_code == 503
    assert response.headers["retry-after"] == "5"
    app.state.busy = False


def test_cross_origin_rejected(client):
    assert client.post("/api/receipts", files={"file": ("x", b"x")},
                       headers={"Origin": "https://attacker.example"}).status_code == 403


def test_token_authentication(monkeypatch):
    monkeypatch.setenv("RECEIPT_API_TOKEN", "test-only-token")
    with TestClient(app, base_url="http://localhost") as client:
        assert client.get("/api/health").json()["authentication_required"]
        assert client.post("/api/receipts", files={"file": ("x", b"x")}).status_code == 401
        response = client.post("/api/receipts", files={"file": ("x", b"x")},
                               headers={"Authorization": "Bearer test-only-token"})
        assert response.status_code == 422  # Auth accepted; document rejected.


def test_host_validation(client):
    assert client.get("/api/health", headers={"Host": "attacker.example"}).status_code == 400


def test_request_size_rejected_before_parsing(client):
    response = client.post("/api/receipts", content=b"tiny", headers={"Content-Length": str(MAX_BYTES + 100_000)})
    assert response.status_code == 413


def test_concurrent_upload_is_rejected_until_worker_finishes(client):
    import threading
    from concurrent.futures import ThreadPoolExecutor

    from receipt_ocr.models import DocumentResult

    started, release = threading.Event(), threading.Event()

    class SlowEngine:
        def process(self, *args):
            started.set()
            assert release.wait(5)
            return DocumentResult(filename="x", sha256="0" * 64, engine="test", processing_ms=1, pages=[])

    app.state.engine = SlowEngine()
    with ThreadPoolExecutor(max_workers=1) as pool:
        first = pool.submit(client.post, "/api/receipts", files={"file": ("x", b"x")})
        assert started.wait(5)
        try:
            assert client.post("/api/receipts", files={"file": ("x", b"x")}).status_code == 503
        finally:
            release.set()
        assert first.result(timeout=5).status_code == 200
    assert not app.state.busy


@pytest.mark.integration
def test_real_multipart_upload(client, engine, fixtures):
    app.state.engine = engine
    response = client.post("/api/receipts", files={"file": ("../../receipt.jpg", (fixtures / "usps.jpeg").read_bytes(), "image/jpeg")}, data={"preview": "false"})
    assert response.status_code == 200
    body = response.json()
    assert body["filename"] == "receipt.jpg"
    assert body["pages"][0]["total"] == {"amount": "55.00", "currency": "USD"}
    assert body["pages"][0]["preview"] is None
    assert len(body["sha256"]) == 64

