// Drives the real admin UI of the installed wordpress.org archive and writes the listing
// screenshots. Run through capture-screenshots.sh, which prepares the site.
// Only environment chrome is hidden (admin-bar extras, core and WooCommerce notices); the
// plugin's own screens are captured as the archive renders them.
// Shot order and content must match == Screenshots == in .wporg/readme-<version>.txt.
const { chromium } = require('playwright');
const path = require('path');
const OUT = process.env.OUT, BASE = process.env.BASE;
const VIEWPORT = { width: 1400, height: 900 };
const CHROME_CSS = `
  #wpadminbar .ab-top-menu > li:not(#wp-admin-bar-wp-logo):not(#wp-admin-bar-site-name):not(#wp-admin-bar-my-account) { display: none !important; }
  .update-nag, .notice:not(.inline), .woocommerce-layout__header-tasks-reminder-bar, .woocommerce-store-alerts,
  #wp-admin-bar-wc-coming-soon { display: none !important; }
`;
let n = 0;
const shot = async (page, name) => {
  n += 1;
  await page.addStyleTag({ content: CHROME_CSS });
  await page.waitForTimeout(600);
  const file = path.join(OUT, `screenshot-${n}.png`);
  await page.screenshot({ path: file });
  console.log(`wrote ${file} (${name})`);
};
const tab = async (page, t) => {
  // A hash-only change does not reload, so an open profile dialog would stay on screen.
  await page.goto(BASE + '/wp-admin/admin.php?page=sspa#' + t);
  await page.reload();
  await page.locator(`#sspa_main .tab-contents[data-tab="${t}"][data-sspa-tab-loaded="1"], #sspa_main .tab-contents[data-tab="${t}"]:not([data-sspa-tab-lazy])`).first().waitFor({ timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(1500);
};
// Put an element just under the admin bar.
const scrollTo = (page, selector, offset = 50) => page.evaluate(([sel, off]) => {
  const el = document.querySelector(sel);
  if (!el) throw new Error('missing ' + sel);
  window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - off);
}, [selector, offset]);

async function runAnalysis(page) {
  await tab(page, 'overview');
  // The screen polls sspa_process_batch until the run reports it is done.
  const done = page.waitForResponse(async r => {
    if (!r.url().includes('admin-ajax.php') || !/action=sspa_process_batch/.test(r.request().postData() || '')) return false;
    const body = await r.json().catch(() => null);
    return !!(body && body.data && ['done', 'failed', 'cancelled'].includes(body.data.status));
  }, { timeout: 600000 });
  done.catch(() => {});
  await page.getByRole('button', { name: 'Run Analysis', exact: true }).click();
  const r = await done;
  const status = (await r.json()).data.status;
  if (status !== 'done') throw new Error('analysis ended ' + status);
  await page.waitForTimeout(3000);
}

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: VIEWPORT, deviceScaleFactor: 2 });
  try {
    await page.goto(BASE + '/wp-login.php');
    await page.fill('#user_login', process.env.PD_ADMIN_USER);
    await page.fill('#user_pass', process.env.PD_ADMIN_PASS);
    await Promise.all([page.waitForURL(u => !u.pathname.includes('wp-login')), page.click('#wp-submit')]);

    // Two analyses, so Compare has two points in time. The first installs the profiler (0.68.5).
    await runAnalysis(page);
    await runAnalysis(page);
    // The share invitation is answered the way a user answers it.
    await tab(page, 'overview');
    const later = page.getByRole('button', { name: "Don't share for 24 hours" });
    if (await later.count()) { await later.first().click(); await page.waitForTimeout(1500); }

    // 1. Overview: site score and top insights.
    await tab(page, 'overview');
    await scrollTo(page, '.sspa-score, .sspa-site-score, .sspa-insights', 60).catch(() => {});
    await shot(page, 'overview');

    // 2. Pages: every measured page.
    await tab(page, 'pages');
    await scrollTo(page, 'tr.sspa-page-row', 140);
    await shot(page, 'pages');

    // 3. One page's full profile (the busiest admin screen).
    await page.locator('tr.sspa-page-row', { hasText: 'admin-products' }).first().click();
    await page.waitForTimeout(4000);
    await scrollTo(page, '.sspa-profile-panel, .sspa-page-profile, #sspa-profile-panel', 40).catch(() => {});
    await shot(page, 'page profile');

    // 4. Plugins: cost per plugin.
    await tab(page, 'plugins');
    await scrollTo(page, 'table', 120).catch(() => {});
    await shot(page, 'plugins');

    // 5. Cache performance, after pressing the check.
    await tab(page, 'cache');
    const panel = page.locator('#sspa_main .tab-contents[data-tab="cache"]');
    const completed = page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('action=sspa_cache_delivery_complete'), { timeout: 300000 });
    completed.catch(() => {});
    await panel.locator('.sspa-cache-delivery-start').click();
    await completed;
    await panel.locator('.sspa-cache-audiences').waitFor({ timeout: 60000 });
    await scrollTo(page, '.sspa-cache-audiences', 120);
    await shot(page, 'cache');

    // 6. Compare: two points in time.
    await tab(page, 'compare');
    await scrollTo(page, '.sspa-history-compare-picker', 50);
    await shot(page, 'compare');

    // 7. Tools: the profiler and the server tools.
    await tab(page, 'tools');
    await shot(page, 'tools');
  } catch (e) {
    await page.screenshot({ path: path.join(OUT, 'capture-failure.png'), fullPage: true }).catch(() => {});
    console.error('FAIL: ' + (e && e.message ? e.message.split('\n')[0] : e));
    process.exitCode = 1;
  } finally {
    await Promise.race([browser.close(), new Promise(r => setTimeout(r, 30000))]);
  }
})();
