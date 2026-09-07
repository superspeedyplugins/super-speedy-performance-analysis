import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { chromium } from 'playwright';
import { navigateDocument } from '../src/navigation.js';

test('warmups and repeated fragment targets each issue their own correlated document request', async () => {
  const requests = [];
  const server = createServer((request, response) => {
    if (request.url.startsWith('/analysis')) requests.push({ url: request.url, cookie: request.headers.cookie, sample: request.headers['x-sample'] });
    response.writeHead(200, { 'Content-Type': 'text/html', 'Set-Cookie': 'session=retained; Path=/', 'X-Sample': request.headers['x-sample'] || 'warmup' });
    response.end('<!doctype html><title>Navigation probe</title><main>Retained page</main>');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  const browser = await chromium.launch({ headless: true });
  try {
    const page = await browser.newPage();
    const url = `http://127.0.0.1:${server.address().port}/analysis?page=sspa#history`;
    const options = { waitUntil: 'domcontentloaded', timeout: 5000 };
    await navigateDocument(page, url, options);
    await navigateDocument(page, url, options);
    assert.equal(requests.length, 2, 'both warmups must reach the server, including an identical fragment URL');
    for (let sequence = 1; sequence <= 3; sequence++) {
      const sample = `sample-${sequence}`;
      await page.setExtraHTTPHeaders({ 'X-Sample': sample });
      let requestsAtStart;
      const response = await navigateDocument(page, url, options, () => { requestsAtStart = requests.length; });
      assert.ok(response, 'every recorded repetition needs a real HTTP response after warmup');
      assert.equal(response.headers()['x-sample'], sample, 'the response belongs to this repetition');
      assert.equal(requestsAtStart, sequence + 1, 'timing starts before the next target request');
      assert.equal(page.url(), url, 'the requested route and fragment stay intact');
      assert.equal(requests.at(-1).cookie, 'session=retained', 'resetting the document preserves authentication cookies');
      assert.equal(requests.at(-1).url, '/analysis?page=sspa', 'measurement adds no cache-busting query parameters');
    }
    assert.deepEqual(requests.slice(2).map(request => request.sample), ['sample-1', 'sample-2', 'sample-3']);
    await new Promise(resolve => server.close(resolve));
    await assert.rejects(navigateDocument(page, url, options), /ERR_CONNECTION_REFUSED/, 'a real transport failure must remain a failure');
  } finally {
    await browser.close();
    if (server.listening) await new Promise(resolve => server.close(resolve));
  }
});
