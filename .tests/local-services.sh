#!/usr/bin/env bash
# Sourced after the native target and plugin realpath have been checked.
SSPA_LOCAL_OUTPUT="$PLUGIN_DIR/.data/local-services/$SSPA_SCENARIO"
mkdir -p "$SSPA_LOCAL_OUTPUT"
if [ ! -f "$SSPA_LOCAL_OUTPUT/smtp-pid" ] || ! kill -0 "$(cat "$SSPA_LOCAL_OUTPUT/smtp-pid")" 2>/dev/null; then
    rm -f "$SSPA_LOCAL_OUTPUT/smtp-port"
    nohup python3 "$PLUGIN_DIR/.tests/fixtures/mail-sink.py" "$SSPA_LOCAL_OUTPUT" > "$SSPA_LOCAL_OUTPUT/smtp.log" 2>&1 </dev/null &
    echo "$!" > "$SSPA_LOCAL_OUTPUT/smtp-pid"
    for attempt in $(seq 1 30); do [ -s "$SSPA_LOCAL_OUTPUT/smtp-port" ] && break; sleep .1; done
fi
[ -s "$SSPA_LOCAL_OUTPUT/smtp-port" ] || { echo 'Local SMTP sink failed to start' >&2; return 1; }
cli option update sspa_regression_smtp_port "$(cat "$SSPA_LOCAL_OUTPUT/smtp-port")" --quiet || return 1
