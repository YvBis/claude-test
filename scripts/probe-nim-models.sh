#!/usr/bin/env bash
# Probes NVIDIA NIM chat models for availability and latency.
#
# Usage: NVIDIA_API_KEY=... scripts/probe-nim-models.sh [model ...]
#
# Prints one markdown table row per model and exits non-zero when no model
# answered. The list defaults to the chat-capable candidates that were in the
# catalog when the fallback model had to be replaced (task 10.19).
set -euo pipefail

API_URL="${NIM_API_URL:-https://integrate.api.nvidia.com/v1/chat/completions}"
TIMEOUT="${NIM_PROBE_TIMEOUT:-120}"
ATTEMPTS="${NIM_PROBE_ATTEMPTS:-2}"

DEFAULT_MODELS=(
  "z-ai/glm-5.3-flash"
  "minimaxai/minimax-m3.1-flash"
  "meta/muse-glimmer-30b"
  "moonshotai/kimi-k3.1-pro"
  "mistralai/mistral-large-4-1t-a52b"
  "nvidia/nemotron-3-ultra-550b-a55b"
  "nvidia/nemotron-3.5-lightning-30b-a3b"
  "google/gemma-4-31b-it"
)

if [[ $# -gt 0 ]]; then
  MODELS=("$@")
else
  MODELS=("${DEFAULT_MODELS[@]}")
fi

if [[ -z "${NVIDIA_API_KEY:-}" ]]; then
  echo "NVIDIA_API_KEY is not set" >&2
  exit 2
fi

payload() {
  local model="$1"
  printf '{"model":"%s","messages":[{"role":"user","content":"Reply with the single word OK."}],"max_tokens":16,"temperature":0}' "$model"
}

echo "| model | attempts | ok | fastest s | slowest s | last error |"
echo "|---|---|---|---|---|---|"

any_ok=0

for model in "${MODELS[@]}"; do
  ok=0
  fastest=""
  slowest=""
  last_error=""

  for ((attempt = 1; attempt <= ATTEMPTS; attempt++)); do
    body_file="$(mktemp)"
    code_file="$(mktemp)"
    seconds="$(curl -sS --max-time "$TIMEOUT" -o "$body_file" -w '%{time_total}' \
      -H "Authorization: Bearer ${NVIDIA_API_KEY}" \
      -H 'Content-Type: application/json' \
      -d "$(payload "$model")" \
      "$API_URL" 2>"$code_file" || true)"
    curl_error="$(tr -d '\n' <"$code_file")"
    content="$(jq -r '.choices[0].message.content // empty' "$body_file" 2>/dev/null || true)"
    status="$(jq -r '.error.message // empty' "$body_file" 2>/dev/null || true)"

    if [[ -n "$seconds" && -n "$content" ]]; then
      ok=$((ok + 1))
      fastest="$(printf '%s\n%s\n' "$fastest" "$seconds" | sed '/^$/d' | sort -n | head -1)"
      slowest="$(printf '%s\n%s\n' "$slowest" "$seconds" | sed '/^$/d' | sort -n | tail -1)"
    else
      last_error="${curl_error:-${status:-no content}}"
      last_error="${last_error:0:120}"
    fi

    rm -f "$body_file" "$code_file"
  done

  [[ "$ok" -gt 0 ]] && any_ok=1
  printf '| %s | %s | %s | %s | %s | %s |\n' \
    "$model" "$ATTEMPTS" "$ok" "${fastest:-—}" "${slowest:-—}" "${last_error:-—}"
done

if [[ "$any_ok" -eq 0 ]]; then
  echo "No model answered within ${TIMEOUT}s" >&2
  exit 1
fi
