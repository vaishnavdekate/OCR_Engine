import asyncio
import logging
import os
import re
import secrets
from concurrent.futures import ThreadPoolExecutor
from contextlib import asynccontextmanager
from pathlib import Path

from fastapi import Depends, FastAPI, File, Form, HTTPException, Request, UploadFile
from fastapi.responses import FileResponse, JSONResponse
from fastapi.staticfiles import StaticFiles
from starlette.middleware.trustedhost import TrustedHostMiddleware

from .engine import ENGINE_NAME, ReceiptEngine
from .imaging import MAX_BYTES, InvalidDocument
from .models import DocumentResult

logger = logging.getLogger(__name__)
STATIC = Path(__file__).parent / "static"


class UploadLimitMiddleware:
    """Bound the HTTP body before the multipart parser can spool an unbounded upload."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope["type"] != "http" or scope["method"] != "POST":
            return await self.app(scope, receive, send)
        limit = MAX_BYTES + 64 * 1024  # bounded multipart metadata in addition to the file
        headers = dict(scope["headers"])
        try:
            length = int(headers.get(b"content-length", b"0"))
        except ValueError:
            return await JSONResponse({"detail": "Invalid Content-Length."}, status_code=400)(scope, receive, send)
        if length > limit:
            return await JSONResponse({"detail": "Upload exceeds the request size limit."}, status_code=413)(scope, receive, send)
        received = 0

        async def bounded_receive():
            nonlocal received
            message = await receive()
            if message["type"] == "http.request":
                received += len(message.get("body", b""))
                if received > limit:
                    raise HTTPException(413, "Upload exceeds the request size limit.")
            return message

        await self.app(scope, bounded_receive, send)


@asynccontextmanager
async def lifespan(app: FastAPI):
    app.state.engine = ReceiptEngine()
    app.state.pool = ThreadPoolExecutor(max_workers=1, thread_name_prefix="ocr")
    app.state.busy = False
    app.state.token = os.environ.get("RECEIPT_API_TOKEN", "")
    try:
        yield
    finally:
        app.state.pool.shutdown(wait=True, cancel_futures=True)


app = FastAPI(title="Receipt Lens", version="1.0.0", lifespan=lifespan, docs_url=None, redoc_url=None,
              description="Local receipt OCR. Monetary values are exact decimal strings.")
app.add_middleware(UploadLimitMiddleware)
app.add_middleware(TrustedHostMiddleware, allowed_hosts=[
    host.strip() for host in os.environ.get("RECEIPT_ALLOWED_HOSTS", "127.0.0.1,localhost").split(",")
])


@app.middleware("http")
async def headers(request: Request, call_next):
    response = await call_next(request)
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Referrer-Policy"] = "no-referrer"
    response.headers["Cache-Control"] = "no-store"
    response.headers["Content-Security-Policy"] = (
        "default-src 'self'; script-src 'self'; style-src 'self'; "
        "img-src 'self' data: blob:; object-src 'none'; frame-ancestors 'none'; base-uri 'none'"
    )
    return response


async def authorize(request: Request):
    token = request.app.state.token
    supplied = request.headers.get("Authorization", "")
    if token and not secrets.compare_digest(supplied.encode(), ("Bearer " + token).encode()):
        raise HTTPException(401, "A valid API token is required.", headers={"WWW-Authenticate": "Bearer"})
    # Block cross-site form submissions on the local, unauthenticated upload endpoint.
    origin = request.headers.get("origin")
    if origin and origin != str(request.base_url).rstrip("/"):
        raise HTTPException(403, "Cross-origin uploads are not allowed.")


@app.get("/", include_in_schema=False)
def home():
    return FileResponse(STATIC / "index.html")


@app.get("/docs", include_in_schema=False)
def docs():
    return FileResponse(STATIC / "api.html")


@app.get("/api/health")
def health(request: Request):
    return {"status": "ok", "engine": ENGINE_NAME, "busy": request.app.state.busy,
            "authentication_required": bool(request.app.state.token)}


@app.post("/api/receipts", response_model=DocumentResult, dependencies=[Depends(authorize)])
async def upload(request: Request, file: UploadFile = File(...),
                 currency: str | None = Form(None), preview: bool = Form(True)):
    if currency:
        currency = currency.strip().upper()
        if not re.fullmatch(r"[A-Z]{3}", currency):
            raise HTTPException(422, "Currency must be a three-letter ISO code, such as CHF.")
    if request.app.state.busy:
        await file.close()
        raise HTTPException(503, "OCR is busy. Try again shortly.", headers={"Retry-After": "5"})
    request.app.state.busy = True
    handed_off = False
    try:
        chunks = []
        size = 0
        while chunk := await file.read(1024 * 1024):
            size += len(chunk)
            if size > MAX_BYTES:
                raise HTTPException(413, "File exceeds the 20 MiB limit.")
            chunks.append(chunk)
        data = b"".join(chunks)
        loop = asyncio.get_running_loop()
        # Busy stays set until the worker really finishes, even if the HTTP client disconnects.
        def run():
            try:
                return request.app.state.engine.process(data, file.filename or "receipt", currency, preview)
            finally:
                loop.call_soon_threadsafe(setattr, request.app.state, "busy", False)
        future = loop.run_in_executor(request.app.state.pool, run)
        handed_off = True
        return await asyncio.shield(future)
    except InvalidDocument as exc:
        raise HTTPException(422, str(exc)) from exc
    except HTTPException:
        raise
    except Exception:
        logger.exception("Receipt OCR failed")
        return JSONResponse(status_code=500, content={"detail": "OCR could not finish. Check server logs and retry."})
    finally:
        await file.close()
        if not handed_off:
            request.app.state.busy = False


app.mount("/static", StaticFiles(directory=STATIC), name="static")

