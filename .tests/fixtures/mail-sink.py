"""Retained loopback-only SMTP transport for synthetic WordPress regression mail."""
import json
import pathlib
import socketserver
import sys
import threading
import time

output = pathlib.Path(sys.argv[1])
output.mkdir(parents=True, exist_ok=True)
lock = threading.Lock()

class SMTP(socketserver.StreamRequestHandler):
    def handle(self):
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
    server.serve_forever()
