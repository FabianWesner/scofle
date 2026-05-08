#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENV="$ROOT/storage/app/python-venv"

TARGETS=("$ROOT/python")

if [[ -d "$VENV/lib" ]]; then
  while IFS= read -r package_path; do
    TARGETS+=("$package_path")
  done < <(find "$VENV/lib" -type d -path '*/site-packages/px_image2pptx' -prune -print)
fi

if grep -R -I -n -E 'gemini|googleapis|generativeai' "${TARGETS[@]}"; then
  echo "Banned Gemini-related literal found." >&2
  exit 1
fi
