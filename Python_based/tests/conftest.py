from pathlib import Path

import pytest

from receipt_ocr.engine import ReceiptEngine


@pytest.fixture(scope="session")
def engine():
    return ReceiptEngine()


@pytest.fixture(scope="session")
def fixtures():
    return Path(__file__).parent / "fixtures"

