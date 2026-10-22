"""Loopback-only SMTP transport for synthetic WordPress regression mail.

Usage: mail-sink.py <output dir> [<owner pid>]

Captured mail is appended to <output dir>/mail.jsonl and kept. The sink belongs to the test run
that started it (.tests/local-services.sh), which stops it on exit. As a backstop it also exits
when the owner PID has gone (an owner killed outright cannot run its trap), and after
SSPA_MAIL_SINK_IDLE_SECONDS (default 14400) without a connection.
"""
import json
import os
import pathlib
import socketserver
import sys
import threading
import time

output = pathlib.Path(sys.argv[1])
output.mkdir(parents=True, exist_ok=True)
owner = int(sys.argv[2]) if len(sys.argv) > 2 else None
idle_seconds = float(os.environ.get('SSPA_MAIL_SINK_IDLE_SECONDS', '14400'))
lock = threading.Lock()
last_activity = time.monotonic()


def owner_alive():
    if owner is None:
        return True
    try:
        os.kill(owner, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def watchdog(server):
    while True:
        time.sleep(min(2.0, max(0.5, idle_seconds / 4)))
        if not owner_alive():
            reason = 'owner %d exited' % owner
        elif time.monotonic() - last_activity > idle_seconds:
            reason = 'idle for %g s' % idle_seconds
        else:
            continue
        print('mail sink stopping: ' + reason, file=sys.stderr, flush=True)
        server.shutdown()
        return

class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
        global last_activity
        last_activity = time.monotonic()
        self.wfile.write(b'220 localhost synthetic regression sink\r\n')
        envelope = []
        while line := self.rfile.readline(1048576):
            command = line.decode('utf-8', 'replace').strip()
            verb = command.split(' ', 1)[0].upper()
            if verb in ('EHLO', 'HELO'):
                self.wfile.write(b'250 localhost\r\n')
            elif verb == 'DATA':
                self.wfile.write(b'354 End with a dot\r\n')
                body = []
                while data := self.rfile.readline(1048576):
                    if data == b'.\r\n':
                        break
                    body.append(data.decode('utf-8', 'replace'))
                with lock, (output / 'mail.jsonl').open('a') as stream:
                    stream.write(json.dumps({'at': time.time(), 'envelope': envelope, 'body': ''.join(body)}) + '\n')
                self.wfile.write(b'250 Captured locally\r\n')
            elif verb == 'QUIT':
                self.wfile.write(b'221 Bye\r\n')
                break
            else:
                envelope.append(command)
                self.wfile.write(b'250 OK\r\n')

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

with Server(('127.0.0.1', 0), SMTP) as server:
    (output / 'smtp-port').write_text(str(server.server_address[1]))
    threading.Thread(target=watchdog, args=(server,), daemon=True).start()
    server.serve_forever()
