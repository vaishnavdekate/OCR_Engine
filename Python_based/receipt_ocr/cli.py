import argparse
import json
import sys
from pathlib import Path

from .engine import ReceiptEngine
from .imaging import MAX_BYTES, InvalidDocument


def main():
    parser = argparse.ArgumentParser(description="Read receipt amounts entirely on your computer.")
    parser.add_argument("files", type=Path, nargs="+")
    parser.add_argument("--currency", help="Optional three-letter currency hint")
    parser.add_argument("--output", type=Path, help="Write JSON to a file instead of stdout")
    args = parser.parse_args()
    currency = args.currency.upper() if args.currency else None
    if currency and (len(currency) != 3 or not currency.isascii() or not currency.isalpha()):
        parser.error("--currency must be a three-letter currency code")
    engine = ReceiptEngine()
    results = []
    try:
        for path in args.files:
            with path.open("rb") as stream:
                data = stream.read(MAX_BYTES + 1)
            results.append(engine.process(data, path.name, currency, include_preview=False).model_dump())
    except (OSError, InvalidDocument) as exc:
        print(str(exc), file=sys.stderr)
        raise SystemExit(2) from exc
    text = json.dumps(results[0] if len(results) == 1 else results, indent=2, ensure_ascii=False)
    if args.output:
        args.output.write_text(text + "\n", encoding="utf-8")
    else:
        print(text)


if __name__ == "__main__":
    main()

