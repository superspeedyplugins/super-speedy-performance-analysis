/** Navigate a warmup or measured request through the runner's document boundary. */
export async function navigateDocument(page, url, options, beforeRequest = () => {}) {
  // Chromium can treat repeated fragment URLs as same-document navigation, which
  // sends no HTTP request. Start from a neutral document without changing the URL,
  // browser context or cookies; the caller starts its timer after this reset.
  await page.goto('about:blank', options);
  beforeRequest();
  return page.goto(url, options);
}
