#!/usr/bin/env bash
# Probes NVIDIA NIM chat models for availability and latency.
#
# Usage: NVIDIA_API_KEY=... scripts/probe-nim-models.sh [model ...]
#
# Env:
#   NIM_PROBE_MAX_TOKENS  output cap (default 16); >32 switches to a review-like
#                         prompt so latency reflects real review generation
#   NIM_PROBE_ATTEMPTS    attempts per model (default 2)
#   NIM_PROBE_TIMEOUT     curl --max-time seconds (default 120)
#   NIM_PROBE_REASONING_EFFORT  sent as reasoning_effort (default medium) because
#                         OpenRabbit's groq transport always sends it; a model that
#                         rejects the parameter must fail here, not in the gate
#   NIM_API_URL           endpoint (default NVIDIA NIM chat/completions)
#
# Prints a markdown table: successes, fastest/slowest seconds, HTTP status of
# the last failure and a short error snippet. Exits non-zero only when no model
# answered at all.
set -euo pipefail

API_URL="${NIM_API_URL:-https://integrate.api.nvidia.com/v1/chat/completions}"
TIMEOUT="${NIM_PROBE_TIMEOUT:-120}"
ATTEMPTS="${NIM_PROBE_ATTEMPTS:-2}"
MAX_TOKENS="${NIM_PROBE_MAX_TOKENS:-16}"

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

prompt() {
  if [[ "$MAX_TOKENS" -gt 32 ]]; then
    cat <<'EOF'
Review this diff and answer with five short bullet points: one correctness risk, one
security concern, one performance note, one test gap, one readability nit.

--- a/src/Service/PaymentService.php
+++ b/src/Service/PaymentService.php
@@
-    public function charge(int $userId, int $amount): void
-    {
-        $this->gateway->charge($userId, $amount);
-    }
+    public function charge(string $userId, int $amount): void
+    {
+        $user = $this->users->find($userId);
+        $this->gateway->charge($user->id, $amount);
+        $this->logger->info('charged ' . $user->email);
+    }
EOF
  else
    echo 'Reply with the single word OK.'
  fi
}

payload() {
  local model="$1"
  jq -n --arg model "$model" --arg prompt "$(prompt)" --argjson max "$MAX_TOKENS" \
    --arg effort "${NIM_PROBE_REASONING_EFFORT:-medium}" \
    '{model: $model, messages: [{role: "user", content: $prompt}], max_tokens: $max, temperature: 1, reasoning_effort: $effort}'
}

echo "| model | attempts | ok | fastest s | slowest s | last HTTP | last error |"
echo "|---|---|---|---|---|---|---|"

any_ok=0

for model in "${MODELS[@]}"; do
  ok=0
  fastest=""
  slowest=""
  last_http="—"
  last_error=""

  for ((attempt = 1; attempt <= ATTEMPTS; attempt++)); do
    body_file="$(mktemp)"
    meta_file="$(mktemp)"
    meta="$(curl -sS --max-time "$TIMEOUT" -o "$body_file" -w '%{http_code} %{time_total}' \
      -H "Authorization: Bearer ${NVIDIA_API_KEY}" \
      -H 'Content-Type: application/json' \
      -d "$(payload "$model")" \
      "$API_URL" 2>"$meta_file" || true)"
    curl_error="$(tr -d '\n' <"$meta_file")"
    http_code="${meta%% *}"
    seconds="${meta##* }"
    content="$(jq -r '.choices[0].message.content // empty' "$body_file" 2>/dev/null || true)"

    if [[ "$http_code" == "200" && -n "$content" ]]; then
      ok=$((ok + 1))
      fastest="$(printf '%s\n%s\n' "$fastest" "$seconds" | sed '/^$/d' | sort -n | head -1)"
      slowest="$(printf '%s\n%s\n' "$slowest" "$seconds" | sed '/^$/d' | sort -n | tail -1)"
    else
      last_http="${http_code:-—}"
      snippet="$(jq -c '.error // .' "$body_file" 2>/dev/null | head -c 160 || true)"
      last_error="${curl_error:-${snippet:-empty body}}"
      last_error="${last_error:0:160}"
    fi

    rm -f "$body_file" "$meta_file"
  done

  [[ "$ok" -gt 0 ]] && any_ok=1
  printf '| %s | %s | %s | %s | %s | %s | %s |\n' \
    "$model" "$ATTEMPTS" "$ok" "${fastest:-—}" "${slowest:-—}" "$last_http" "${last_error:-—}"
done

if [[ "$any_ok" -eq 0 ]]; then
  echo "No model answered within ${TIMEOUT}s" >&2
  exit 1
fi
