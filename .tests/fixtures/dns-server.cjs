// A loopback UDP DNS server for case 165.
//   node dns-server.cjs <port> ok     answers every query
//   node dns-server.cjs <port> drop   leaves queries 2, 5, 7, 10 and 12 of every 12 unanswered
// Names starting "sspa-dns-check-" get NXDOMAIN, every other name one A record. Exits by itself
// after 120 seconds so a test that dies cannot leave it running.
const dgram = require('dgram');
const port = parseInt(process.argv[2], 10);
const mode = process.argv[3] || 'ok';
const DROP = new Set([1, 4, 6, 9, 11]);
let n = 0;
const server = dgram.createSocket('udp4');
server.on('message', (msg, rinfo) => {
	const index = n++ % 12;
	if (mode === 'drop' && DROP.has(index)) { return; }
	if (msg.length < 17) { return; }
	let end = 12;
	const labels = [];
	while (end < msg.length && msg[end] !== 0) { labels.push(msg.slice(end + 1, end + 1 + msg[end]).toString('ascii')); end += msg[end] + 1; }
	const question = msg.slice(12, end + 5);
	const missing = labels.length && labels[0].indexOf('sspa-dns-check-') === 0;
	const header = Buffer.alloc(12);
	msg.copy(header, 0, 0, 2);
	header.writeUInt16BE(missing ? 0x8183 : 0x8180, 2);
	header.writeUInt16BE(1, 4);
	header.writeUInt16BE(missing ? 0 : 1, 6);
	const parts = [header, question];
	if (!missing) { parts.push(Buffer.from([0xc0, 0x0c, 0, 1, 0, 1, 0, 0, 0, 60, 0, 4, 192, 0, 2, 1])); }
	server.send(Buffer.concat(parts), rinfo.port, rinfo.address);
});
server.bind(port, '127.0.0.1', () => { console.log('listening ' + port + ' ' + mode); });
setTimeout(() => process.exit(0), 120000);
