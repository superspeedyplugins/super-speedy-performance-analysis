#!/usr/bin/env bash
# Regression: a suite run that ends - passing, failing, terminated or killed - leaves no local SMTP
# mail sink running for its scenario, and keeps the mail it captured.
#
#   SSPA_SCENARIO=<dedicated scenario> bash .tests/harness/mail-sink-lifecycle.sh
#
# Drives the real entry points (.tests/run-tests.sh, .tests/setup-site.sh) and the real
# .tests/local-services.sh + .tests/fixtures/mail-sink.py. Only sinks serving THIS checkout's
# scenario output directory are inspected or stopped; other checkouts' sinks are never touched.
# The scenario's site and its captured mail are left in place for inspection.
set -uo pipefail
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SCENARIO="${SSPA_SCENARIO:?set SSPA_SCENARIO to a dedicated scenario}"
export SSPA_SCENARIO="$SCENARIO"
OUT="$PLUGIN_DIR/.data/local-services/$SCENARIO"
EVIDENCE="$PLUGIN_DIR/.data/mail-sink-lifecycle"
mkdir -p "$EVIDENCE"
FAILS=0
pass() { echo "PASS: $*"; }
fail() { echo "FAIL: $*"; FAILS=$((FAILS + 1)); }

# PIDs of mail sinks whose output directory is exactly this scenario's.
sinks() {
    ps -eo pid=,args= | awk -v d="$OUT" '
        $2 ~ /python/ && index($0, "fixtures/mail-sink.py ") && index($0, " " d) {
            rest = substr($0, index($0, " " d) + length(d) + 1)
            if (rest == "" || rest ~ /^ [0-9]+$/) print $1
        }'
}
# Wait up to $1 seconds for this scenario to have no sink; prints what is left.
no_sink_within() {
    local deadline=$(( $(date +%s) + $1 ))
    while [ -n "$(sinks)" ] && [ "$(date +%s)" -lt "$deadline" ]; do sleep 0.5; done
    sinks | tr '\n' ' '
}
# Wait up to $1 seconds for this scenario to have a sink; prints its PID.
sink_within() {
    local deadline=$(( $(date +%s) + $1 ))
    while [ -z "$(sinks)" ] && [ "$(date +%s)" -lt "$deadline" ]; do sleep 0.2; done
    sinks | head -1
}
# A minimal owner shell: the real env + local-services, then idle until signalled (at most 120 s,
# so an interrupted test cannot leave it behind).
owner() {
    local ready="$EVIDENCE/owner-ready.$RANDOM$RANDOM"
    bash -c 'source "$1/.tests/env.sh" && sspa_require_site >/dev/null && source "$1/.tests/local-services.sh" || exit 1
             touch "$2"
             for i in $(seq 1 600); do sleep 0.2; done' _ "$PLUGIN_DIR" "$ready" >> "$EVIDENCE/owner.log" 2>&1 &
    local pid=$! i
    # Ready once local-services.sh has returned (sink started AND its port published), so a
    # nested run does not race the owner's own option write.
    for i in $(seq 1 100); do [ -f "$ready" ] && break; kill -0 "$pid" 2>/dev/null || break; sleep 0.2; done
    rm -f "$ready"
    echo "$pid"
}
# Bounded wait for a PID we started to exit.
reap() {
    local deadline=$(( $(date +%s) + $2 ))
    while kill -0 "$1" 2>/dev/null && [ "$(date +%s)" -lt "$deadline" ]; do sleep 0.2; done
    kill -0 "$1" 2>/dev/null && { kill -KILL "$1" 2>/dev/null; echo "still running after $2 s, killed"; }
    wait "$1" 2>/dev/null
}

echo "scenario $SCENARIO, sink output $OUT"
# Clear on the way in: a sink left by an earlier run of THIS checkout's scenario.
for pid in $(sinks); do kill "$pid" 2>/dev/null; done
[ -z "$(no_sink_within 10)" ] || { echo "FAIL: could not clear this scenario's previous sink"; exit 1; }
rm -f "$OUT/mail.jsonl"

# 1. A passing run that sends real mail (case 106) stops its sink and keeps the captured mail.
bash "$PLUGIN_DIR/.tests/run-tests.sh" 106-mail > "$EVIDENCE/pass-run.log" 2>&1
rc=$?
[ "$rc" -eq 0 ] && pass "passing run exits 0" || fail "passing run exits 0 (rc $rc, see $EVIDENCE/pass-run.log)"
left=$(no_sink_within 10)
[ -z "$left" ] && pass "a passing run leaves no mail sink" || fail "a passing run leaves no mail sink (still running: $left)"
[ -s "$OUT/mail.jsonl" ] && pass "the captured mail survives the run ($(wc -l < "$OUT/mail.jsonl") messages)" || fail "the captured mail survives the run"

# 2. A failing run (no case matches the filter) stops its sink too.
bash "$PLUGIN_DIR/.tests/run-tests.sh" zz-no-such-case > "$EVIDENCE/fail-run.log" 2>&1
rc=$?
[ "$rc" -ne 0 ] && pass "failing run exits non-zero (rc $rc)" || fail "failing run exits non-zero"
left=$(no_sink_within 10)
[ -z "$left" ] && pass "a failing run leaves no mail sink" || fail "a failing run leaves no mail sink (still running: $left)"

# 3. setup-site.sh (idempotent top-up) stops the sink it started.
bash "$PLUGIN_DIR/.tests/setup-site.sh" > "$EVIDENCE/setup.log" 2>&1
rc=$?
[ "$rc" -eq 0 ] && pass "setup-site.sh exits 0" || fail "setup-site.sh exits 0 (rc $rc)"
left=$(no_sink_within 10)
[ -z "$left" ] && pass "setup-site.sh leaves no mail sink" || fail "setup-site.sh leaves no mail sink (still running: $left)"

# 4. A runner terminated by SIGTERM (as timeout(1) does) stops its sink.
o=$(owner); s=$(sink_within 20)
[ -n "$s" ] && pass "an owner shell starts a sink (PID $s)" || fail "an owner shell starts a sink"
kill -TERM "$o"; r=$(reap "$o" 20)
left=$(no_sink_within 10)
[ -z "$left" ] && pass "SIGTERM to the runner leaves no mail sink$r" || fail "SIGTERM to the runner leaves no mail sink (still running: $left)$r"

# 5. A runner killed outright (no trap can run) is backstopped by the sink watching its owner.
o=$(owner); s=$(sink_within 20)
kill -KILL "$o"; r=$(reap "$o" 5)
left=$(no_sink_within 15)
[ -n "$s" ] && [ -z "$left" ] && pass "SIGKILL to the runner: the sink exits once its owner is gone" \
    || fail "SIGKILL to the runner: the sink exits once its owner is gone (sink ${s:-none}, still running: $left)"

# 6. A nested run on the same scenario reuses the outer sink and must not stop it
#    (run-regressions.sh sources local-services.sh, then calls run-tests.sh).
o=$(owner); s=$(sink_within 20)
bash "$PLUGIN_DIR/.tests/run-tests.sh" 02-token > "$EVIDENCE/nested-run.log" 2>&1
rc=$?
[ "$rc" -eq 0 ] && grep -q '1 case file(s) run, 0 failed' "$EVIDENCE/nested-run.log" && pass "the nested run reuses the outer sink and passes" \
    || fail "the nested run reuses the outer sink and passes (rc $rc, see $EVIDENCE/nested-run.log)"
[ -n "$s" ] && kill -0 "$s" 2>/dev/null && pass "a nested run leaves the outer run's sink running" \
    || fail "a nested run leaves the outer run's sink running (sink ${s:-none})"
kill -TERM "$o"; r=$(reap "$o" 20)
left=$(no_sink_within 10)
[ -z "$left" ] && pass "the outer run then stops its own sink$r" || fail "the outer run then stops its own sink (still running: $left)$r"

# 7. The idle timeout stops a sink that receives nothing, even while its owner lives.
o=$(SSPA_MAIL_SINK_IDLE_SECONDS=3 owner); s=$(sink_within 20)
left=$(no_sink_within 15)
[ -n "$s" ] && [ -z "$left" ] && pass "an idle sink exits after SSPA_MAIL_SINK_IDLE_SECONDS" \
    || fail "an idle sink exits after SSPA_MAIL_SINK_IDLE_SECONDS (sink ${s:-none}, still running: $left)"
kill -TERM "$o"; r=$(reap "$o" 20)
kill -0 "$o" 2>/dev/null && fail "the owner exits after the sink already stopped$r" || pass "the owner exits cleanly after the sink already stopped$r"

# Never leave a sink of ours behind, whatever failed above.
for pid in $(sinks); do kill "$pid" 2>/dev/null; done
echo "Mail sink lifecycle: $FAILS failed"
[ "$FAILS" -eq 0 ]
