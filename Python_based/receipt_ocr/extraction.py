"""Layout-aware extraction. Receipt text is data; it is never executed or treated as instructions."""

import re
import unicodedata
from collections import Counter
from decimal import Decimal, InvalidOperation

from .models import Candidate, LineItem, Money, ReceiptResult, TextLine

# Accept one or two decimal digits; many receipts print 16.5 rather than 16.50.
# Whole numbers still require a monetary label or an explicit currency.
NUMBER = re.compile(
    r"(?<![\w.,:/])(?P<n>[-−]?\(?\d{1,3}(?:[ '\u2019.,]\d{3})+[.,]\d{1,2}\)?"
    r"|[-−]?\(?\d+[.,]\d{1,2}\)?)(?![\w\d.,:/]|\s*%)"
)
INTEGER = re.compile(r"(?<![\w.,:/])(?P<n>-?(?:\d{1,3}(?:[ '\u2019.,]\d{3})+|\d{1,9}))(?![\w.,:/]|\s*%)")
CURRENCY = re.compile(r"\b(?:USD|CHF|EUR|INR|GBP|CAD|AUD|NZD|JPY|CNY|AED)\b|US\$|[€£₹$¥]", re.I)
TOTAL = re.compile(r"\b(?:grand\s*total|total(?:\s+(?:due|amount|ttc))?|amount\s*(?:due|payable)|balance\s*due|net\s*a\s*payer|gesamt(?:betrag)?|summe|endbetrag|montant\s*du)\b", re.I)
SUBTOTAL = re.compile(r"\b(?:sub[ -]?total|subtotal|sous[ -]?total|zwischensumme)\b", re.I)
TAX = re.compile(r"\b(?:tax|vat|mwst|tva|gst|cgst|sgst|igst|sales\s*tax)\b", re.I)
TIP = re.compile(r"\b(?:tip|gratuity|pourboire)\b", re.I)
PAYMENT = re.compile(r"\b(?:cash|tendered|paid|payment|remitted|debit|credit|visa|mastercard|purchase)\b", re.I)
EXCHANGE = re.compile(r"\b(?:equivalent|entspricht|exchange|converted|conversion|in\s+euro)\b", re.I)
EXCLUDE = re.compile(r"\b(?:tel|fax|phone|account|approval|transaction|receipt\s*#|rech\.?\s*nr|tax\s*id|mwst\s*nr|date|time|aid|pin|table|tisch)(?!\w)", re.I)
CHANGE = re.compile(r"\b(?:change|rendu|ruckgeld)\b", re.I)
DISCOUNT = re.compile(r"\b(?:savings|saved|discount|coupon|rabatt|remise)\b", re.I)


def normalized(text: str) -> str:
    return "".join(c for c in unicodedata.normalize("NFKD", text) if not unicodedata.combining(c))


def decimal_amount(text: str) -> str | None:
    s = text.strip().replace("−", "-").replace("’", "'")
    negative = s.startswith("(") and s.endswith(")")
    s = s.strip("()").replace(" ", "").replace("'", "")
    if "," in s and "." in s:
        decimal = "," if s.rfind(",") > s.rfind(".") else "."
        s = s.replace("." if decimal == "," else ",", "").replace(decimal, ".")
    elif "," in s:
        s = s.replace(",", ".") if len(s.rsplit(",", 1)[1]) in {1, 2} else s.replace(",", "")
    elif s.count(".") > 1:
        a, b = s.rsplit(".", 1)
        s = a.replace(".", "") + "." + b
    elif "." in s and len(s.rsplit(".", 1)[1]) == 3:
        s = s.replace(".", "")
    try:
        amount = Decimal(s) * (-1 if negative else 1)
        if not amount.is_finite() or abs(amount) > Decimal("999999999.99"):
            return None
        return f"{amount:.2f}"
    except InvalidOperation:
        return None


def _currency(text: str, position: int, fallback: str | None) -> str | None:
    matches = list(CURRENCY.finditer(text))
    if not matches:
        return fallback
    token = min(matches, key=lambda m: min(abs(m.end() - position), abs(m.start() - position))).group().upper()
    return {"€": "EUR", "£": "GBP", "₹": "INR", "US$": "USD",
            "$": fallback if fallback in {"USD", "CAD", "AUD", "NZD"} else None,
            "¥": fallback if fallback in {"JPY", "CNY"} else None}.get(token, token)


def _document_currency(lines: list[TextLine], hint: str | None) -> str | None:
    if hint:
        return hint
    text = "\n".join(line.text for line in lines)
    codes = Counter(m.group().upper() for m in CURRENCY.finditer(text)
                    if len(m.group()) == 3 and m.group().isalpha())
    if codes:
        return codes.most_common(1)[0][0]
    if re.search(r"UNITED\s*STATES|USPS|\b[A-Z]{2}\s+\d{5}(?:-\d{4})?\b", text, re.I):
        return "USD"
    symbols = [m.group() for m in CURRENCY.finditer(text)]
    for symbol, code in [("€", "EUR"), ("£", "GBP"), ("₹", "INR")]:
        if symbol in symbols:
            return code
    return None


def group_lines(detections: list[TextLine], skew_degrees: float = 0) -> list[TextLine]:
    """Group in the receipt's baseline coordinates; keep evidence in image coordinates."""
    from math import radians, tan

    slope = tan(radians(skew_degrees))

    def baseline(box: TextLine) -> float:
        return (box.box[1] + box.box[3]) / 2 - slope * (box.box[0] + box.box[2]) / 2

    rows: list[list[TextLine]] = []
    for box in sorted(detections, key=baseline):
        cy = baseline(box)
        height = box.box[3] - box.box[1]
        for row in reversed(rows[-3:]):
            ry = sum(baseline(r) for r in row) / len(row)
            rh = sum(r.box[3] - r.box[1] for r in row) / len(row)
            if abs(cy - ry) <= 0.52 * min(height, rh):
                row.append(box)
                break
        else:
            rows.append([box])
    result = []
    for row in rows:
        row.sort(key=lambda b: b.box[0])
        result.append(TextLine(
            text=" ".join(b.text for b in row),
            confidence=sum(b.confidence * len(b.text) for b in row) / max(1, sum(len(b.text) for b in row)),
            box=[min(b.box[0] for b in row), min(b.box[1] for b in row),
                 max(b.box[2] for b in row), max(b.box[3] for b in row)],
        ))
    return result


def extract(lines: list[TextLine], width: int, height: int,
            currency_hint: str | None = None) -> ReceiptResult:
    currency = _document_currency(lines, currency_hint)
    candidates: list[Candidate] = []
    items: list[LineItem] = []
    for row, line in enumerate(lines):
        text = normalized(line.text)
        text = re.sub(r"\b(USD|CHF|EUR|INR|GBP|CAD|AUD|NZD|JPY|CNY|AED)(?=[-\d(])", r"\1 ", text, flags=re.I)
        text = re.sub(r"(?<=\d)(USD|CHF|EUR|INR|GBP|CAD|AUD|NZD|JPY|CNY|AED)\b", r" \1", text, flags=re.I)
        # Only repair glyphs within an otherwise numeric decimal token.
        repaired = re.sub(r"(?<=\d)([.,])([0-9OoIlS]{2})(?!\w)",
                          lambda m: m[1] + m[2].translate(str.maketrans("OoIlS", "00115")), text)
        matches = list(NUMBER.finditer(repaired))
        role = "amount"
        if EXCLUDE.search(text):
            continue
        if EXCHANGE.search(text):
            role = "exchange"
        elif SUBTOTAL.search(text):
            role = "subtotal"
        elif CHANGE.search(text):
            role = "change"
        elif DISCOUNT.search(text):
            role = "discount"
        elif TAX.search(text):
            role = "tax"
        elif TIP.search(text):
            role = "tip"
        elif TOTAL.search(text) and not re.search(r"\btotal\s*(?:items|qty|quantity|points)\b", text, re.I):
            role = "total"
        elif PAYMENT.search(text):
            role = "payment"
        # A label may be in the row immediately above a standalone amount.
        if role == "amount" and row and not re.search(r"[a-zA-Z]{4}", CURRENCY.sub("", text)):
            prior = normalized(lines[row - 1].text)
            if TOTAL.search(prior) and not SUBTOTAL.search(prior) and not NUMBER.search(prior):
                if line.box[1] - lines[row - 1].box[3] < 2.5 * (line.box[3] - line.box[1]):
                    role = "total"
        if not matches and (role in {"total", "subtotal", "tax", "tip", "payment"} or CURRENCY.search(text)):
            matches = list(INTEGER.finditer(repaired))
        for index, match in enumerate(matches):
            value = decimal_amount(match["n"])
            if value is None:
                continue
            current_role = role
            if role in {"total", "subtotal", "tax", "tip", "payment", "change"} and index != len(matches) - 1:
                current_role = "tax_base" if role == "tax" else "amount"
            score = {"total": 80, "subtotal": 20, "tax": 12, "tip": 10,
                     "payment": 28, "exchange": -20, "change": -20, "tax_base": -5}.get(current_role, 8)
            reasons = [f"Classified as {current_role} from the receipt row."]
            if repaired != text:
                reasons.append("Ambiguous OCR glyphs were repaired within a decimal amount.")
            if current_role == "total":
                if re.search(r"grand|due|payable|endbetrag", text, re.I):
                    score += 6
                score += min(5, 5 * line.box[1] / max(height, 1))
                reasons.append("Explicit payable-total label.")
            score += line.confidence * 8
            candidates.append(Candidate(
                id=f"r{row}-a{index}", amount=value,
                currency=_currency(repaired, match.start(), currency), role=current_role,
                score=round(score, 2), ocr_confidence=round(line.confidence, 4),
                source_text=line.text, box=line.box, reasons=reasons,
            ))
        if role == "amount" and matches:
            prefix = text[:matches[0].start()].strip()
            if re.search(r"[a-zA-Z]{2}", prefix) and not re.search(r"@|https?", prefix):
                quantity = re.match(r"\s*(\d+(?:[.,]\d+)?)\s*[xX×]\s*", prefix)
                if quantity or len(matches) >= 2:
                    amounts = [decimal_amount(m["n"]) for m in matches]
                    if amounts[-1] is not None:
                        items.append(LineItem(
                            description=re.sub(r"\s+a\s*$", "", CURRENCY.sub("", prefix[quantity.end():] if quantity else prefix)).strip(),
                            quantity=quantity[1].replace(",", ".") if quantity else None,
                            unit_price=amounts[-2] if len(amounts) >= 2 else None,
                            amount=amounts[-1], currency=_currency(text, matches[-1].start(), currency),
                            source_text=line.text,
                        ))
    candidates.sort(key=lambda c: c.score, reverse=True)
    totals = [c for c in candidates if c.role == "total"]
    best = totals[0] if totals else None
    warnings = []
    checks = {}
    confidence = 0.0
    if best:
        confidence = min(0.96, 0.70 + 0.25 * best.ocr_confidence)
        if len({(c.amount, c.currency) for c in totals}) > 1:
            warnings.append("Conflicting printed totals were found; confirm the payable amount.")
            confidence = min(confidence, 0.64)
        if best.currency is None:
            warnings.append("Currency is ambiguous or absent. Select the receipt currency.")
            confidence = min(confidence, 0.74)
        if best.ocr_confidence < 0.85:
            warnings.append("The total row has low OCR confidence.")
            confidence = min(confidence, 0.70)
        if any("glyphs" in reason for reason in best.reasons):
            warnings.append("Ambiguous characters in the total were repaired. Confirm the printed digits.")
            confidence = min(confidence, 0.75)
        comparable_items = [i for i in items if i.currency == best.currency]
        if comparable_items:
            item_sum = sum((Decimal(i.amount) for i in comparable_items), Decimal("0"))
            checks["line_item_sum"] = f"{item_sum:.2f}"
            checks["line_items_match_total"] = str(item_sum == Decimal(best.amount)).lower()
    else:
        warnings.append("No explicit total could be read. Candidate amounts require human review.")
    def field(role: str) -> Money | None:
        found = [c for c in candidates if c.role == role and (not best or c.currency == best.currency)]
        if role == "tax" and len(found) > 1:
            explicit = [c for c in found if re.search(r"total\s*(?:tax|gst|vat)", c.source_text, re.I)]
            if explicit:
                return Money(amount=explicit[0].amount, currency=explicit[0].currency)
            total_tax = sum((Decimal(c.amount) for c in found), Decimal("0"))
            return Money(amount=f"{total_tax:.2f}", currency=found[0].currency)
        return Money(amount=found[0].amount, currency=found[0].currency) if found else None
    subtotal, tax, tip = field("subtotal"), field("tax"), field("tip")
    if best and subtotal and tax:
        expected = Decimal(subtotal.amount) + Decimal(tax.amount) + Decimal(tip.amount if tip else "0")
        checks["subtotal_plus_tax_and_tip"] = f"{expected:.2f}"
        if expected == Decimal(best.amount):
            checks["arithmetic"] = "matched"
        else:
            checks["arithmetic"] = "mismatch"
            warnings.append("Subtotal + tax + tip does not match the total; review discounts or other charges.")
            confidence = min(confidence, 0.70)
    merchant = next((line.text for line in lines[:5] if re.search(r"[A-Za-z]{3}", line.text)
                     and not EXCLUDE.search(normalized(line.text))), None)
    review = best is None or confidence < 0.85 or bool(warnings)
    return ReceiptResult(
        status="no_total" if best is None else "needs_review" if review else "ok",
        total=Money(amount=best.amount, currency=best.currency) if best else None,
        subtotal=subtotal, tax=tax, tip=tip, merchant=merchant,
        confidence=round(confidence, 4), needs_review=review, warnings=warnings, checks=checks,
        line_items=items, candidates=candidates, lines=lines,
        raw_text="\n".join(line.text for line in lines), width=width, height=height,
    )
