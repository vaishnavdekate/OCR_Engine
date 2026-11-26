#!/bin/sh
set -eu
cd "$(dirname "$0")"
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-2}"
exec php -d upload_max_filesize=20M -d post_max_size=21M -d memory_limit=768M -d max_execution_time=300 -S "127.0.0.1:${PORT:-8766}" -t public public/router.php
