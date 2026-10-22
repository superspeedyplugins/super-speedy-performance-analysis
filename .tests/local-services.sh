#!/usr/bin/env bash
# Sourced after the native target and plugin realpath have been checked.
#
# Starts the loopback SMTP mail sink for this scenario, or reuses one a parent run on the same
# scenario already started (run-regressions.sh sources this and then calls run-tests.sh). The
# shell that STARTS the sink owns it and stops it when it exits, on every path: success, failure,
# `exit` from a case, SIGTERM/SIGINT/SIGHUP. The captured mail (mail.jsonl) and smtp.log stay on
# disk as evidence. If the owner is killed outright, mail-sink.py exits once the owner PID is
# gone, and after SSPA_MAIL_SINK_IDLE_SECONDS without a connection.
SSPA_LOCAL_OUTPUT="$PLUGIN_DIR/.data/local-services/$SSPA_SCENARIO"
mkdir -p "$SSPA_LOCAL_OUTPUT"

sspa_local_services_stop() {
    local pid="${SSPA_LOCAL_SINK_PID:-}"
    [ -n "$pid" ] || return 0
    SSPA_LOCAL_SINK_PID=""
    # Only our own sink: the PID must still be mail-sink.py (it may have exited and been reused).
    if ps -p "$pid" -o args= 2>/dev/null | grep -q 'fixtures/mail-sink.py'; then
        kill "$pid" 2>/dev/null
        local i
        for i in $(seq 1 50); do kill -0 "$pid" 2>/dev/null || break; sleep 0.1; done
    fi
    if [ "$(cat "$SSPA_LOCAL_OUTPUT/smtp-pid" 2>/dev/null)" = "$pid" ]; then
        rm -f "$SSPA_LOCAL_OUTPUT/smtp-pid" "$SSPA_LOCAL_OUTPUT/smtp-port"
    fi
    return 0
}

if [ ! -f "$SSPA_LOCAL_OUTPUT/smtp-pid" ] || ! kill -0 "$(cat "$SSPA_LOCAL_OUTPUT/smtp-pid")" 2>/dev/null; then
    rm -f "$SSPA_LOCAL_OUTPUT/smtp-port"
    nohup python3 "$PLUGIN_DIR/.tests/fixtures/mail-sink.py" "$SSPA_LOCAL_OUTPUT" "$$" >> "$SSPA_LOCAL_OUTPUT/smtp.log" 2>&1 </dev/null &
    SSPA_LOCAL_SINK_PID=$!
    echo "$SSPA_LOCAL_SINK_PID" > "$SSPA_LOCAL_OUTPUT/smtp-pid"
    trap sspa_local_services_stop EXIT
    # Bash runs the EXIT trap for `exit`, not for an untrapped fatal signal.
    trap 'exit 129' HUP
    trap 'exit 130' INT
    trap 'exit 143' TERM
    for attempt in $(seq 1 30); do [ -s "$SSPA_LOCAL_OUTPUT/smtp-port" ] && break; sleep .1; done
fi
[ -s "$SSPA_LOCAL_OUTPUT/smtp-port" ] || { echo 'Local SMTP sink failed to start' >&2; return 1; }
cli option update sspa_regression_smtp_port "$(cat "$SSPA_LOCAL_OUTPUT/smtp-port")" --quiet || return 1
