#!/usr/bin/env python3
"""Check every published quotation in rules/rules-snapshot.json against its live source page.

Usage: python3 -I .tests/lead-gen/verify-published-quotes.py [download-dir]

Every offer quote, figure and cache education text carries a source_url. This fetches each
source once and checks the text appears on the page verbatim (whitespace collapsed; inline
markup such as <strong> or <code> may split the words). A changed page prints FAIL with the
quote, so the bundled copy can be updated with its new source_checked date.

Needs network access. Not part of the WordPress suite, which runs with external HTTP blocked.
"""
import html
import json
import os
import re
import sys
import tempfile
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SNAPSHOT = os.path.join(ROOT, 'rules', 'rules-snapshot.json')


def items(node, path=''):
    if isinstance(node, dict):
        if isinstance(node.get('text'), str) and isinstance(node.get('source_url'), str):
            yield path, node
        for key, value in node.items():
            yield from items(value, path + '/' + str(key))
    elif isinstance(node, list):
        for index, value in enumerate(node):
            yield from items(value, path + '/' + str(index))


def flatten(raw):
    raw = re.sub(r'<(script|style|noscript)\b.*?</\1>', ' ', raw, flags=re.S | re.I)
    spaced = html.unescape(re.sub(r'<[^>]+>', ' ', raw))
    joined = html.unescape(re.sub(r'<[^>]+>', '', raw))
    collapse = lambda text: re.sub(r'\s+', ' ', text)
    # Inline elements leave a stray space before punctuation in the spaced form.
    return collapse(spaced), re.sub(r' ([,.;:!?)])', r'\1', collapse(spaced)), collapse(joined)


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else tempfile.mkdtemp(prefix='sspa-quotes-')
    os.makedirs(out, exist_ok=True)
    snapshot = json.load(open(SNAPSHOT, encoding='utf-8'))
    pages = {}
    failures = 0
    checked = 0
    for path, item in items(snapshot):
        url = item['source_url']
        if url not in pages:
            request = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0 sspa-quote-check'})
            with urllib.request.urlopen(request, timeout=60) as response:
                raw = response.read().decode('utf-8', 'replace')
            with open(os.path.join(out, re.sub(r'[^a-z0-9]+', '_', url.lower()) + '.html'), 'w', encoding='utf-8') as handle:
                handle.write(raw)
            pages[url] = flatten(raw)
        text = re.sub(r'\s+', ' ', item['text'])
        checked += 1
        if any(text in form for form in pages[url]):
            print('PASS: ' + path + ' verbatim on ' + url)
        else:
            failures += 1
            print('FAIL: ' + path + ' not found verbatim on ' + url + '\n      ' + text)
    print('%d quotations checked, %d not found; pages saved in %s' % (checked, failures, out))
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
