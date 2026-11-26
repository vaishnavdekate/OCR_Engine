#!/bin/sh
set -eu
if [ -z "${APP_KEY:-}" ]; then
    APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    export APP_KEY
fi
exec php -d upload_max_filesize=20M -d post_max_size=21M -d memory_limit=768M -d max_execution_time=300 -S 0.0.0.0:8000 -t public public/router.php
