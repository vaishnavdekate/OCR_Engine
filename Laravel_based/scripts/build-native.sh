#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
cmake -S native -B native/build -DCMAKE_BUILD_TYPE=Release
cmake --build native/build --target receipt-ocr -j "${BUILD_JOBS:-4}"
