from typing import Literal

from pydantic import BaseModel, Field


class TextLine(BaseModel):
    text: str
    confidence: float
    box: list[float]  # x0, y0, x1, y1 in the returned, oriented preview


class Money(BaseModel):
    amount: str  # exact decimal, never a JSON floating-point monetary value
    currency: str | None = None


class Candidate(Money):
    id: str
    role: str
    score: float
    ocr_confidence: float
    source_text: str
    box: list[float]
    reasons: list[str] = Field(default_factory=list)
    observations: int = 1


class LineItem(BaseModel):
    description: str
    quantity: str | None = None
    unit_price: str | None = None
    amount: str
    currency: str | None = None
    source_text: str


class ReceiptResult(BaseModel):
    page: int = 1
    status: Literal["ok", "needs_review", "no_total"]
    total: Money | None
    subtotal: Money | None = None
    tax: Money | None = None
    tip: Money | None = None
    merchant: str | None = None
    confidence: float = 0
    needs_review: bool = True
    warnings: list[str] = Field(default_factory=list)
    checks: dict[str, str] = Field(default_factory=dict)
    line_items: list[LineItem] = Field(default_factory=list)
    candidates: list[Candidate] = Field(default_factory=list)
    lines: list[TextLine] = Field(default_factory=list)
    raw_text: str = ""
    rotation: int = 0
    passes: int = 0
    width: int = 0
    height: int = 0
    preview: str | None = None


class DocumentResult(BaseModel):
    filename: str
    sha256: str
    engine: str
    processing_ms: int
    pages: list[ReceiptResult]

