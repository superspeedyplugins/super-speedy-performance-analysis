#!/usr/bin/env bash
# The "full" edition is the Pro edition since 0.66.0. Kept so old commands still work.
#   .build/build-full.sh <dest_dir> [--smoke]
exec "$(cd "$(dirname "$0")" && pwd)/build-pro.sh" "$@"
