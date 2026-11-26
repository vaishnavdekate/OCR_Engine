<?php

return [
    'token' => env('RECEIPT_API_TOKEN', ''),
    'allowed_hosts' => explode(',', env('RECEIPT_ALLOWED_HOSTS', '127.0.0.1,localhost')),
    'native_binary' => env('OCR_NATIVE_BINARY',base_path('native/bin/receipt-ocr')),
    'models' => env('OCR_MODEL_DIR',storage_path('app/models')),
    'magick' => env('IMAGEMAGICK_BINARY',base_path('bin/image-tool')),
    'pdfinfo' => env('PDFINFO_BINARY', PHP_OS_FAMILY === 'Darwin' ? '/opt/homebrew/bin/pdfinfo' : 'pdfinfo'),
    'pdftoppm' => env('PDFTOPPM_BINARY', PHP_OS_FAMILY === 'Darwin' ? '/opt/homebrew/bin/pdftoppm' : 'pdftoppm'),
    'timeout' => (int) env('OCR_PROCESS_TIMEOUT', 45),
    'max_bytes' => 20 * 1024 * 1024,
    'max_pixels' => 25_000_000,
    'max_pages' => 5,
];
