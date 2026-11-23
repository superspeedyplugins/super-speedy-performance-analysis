// Drives the real admin UI of the installed wordpress.org archive and writes the listing
// screenshots. Run through capture-screenshots.sh, which prepares the site.
// Only environment chrome is hidden (admin-bar extras, core and WooCommerce notices); the
// plugin's own screens are captured as the archive renders them.
// Shot order and content must match == Screenshots == in .wporg/readme-<version>.txt.
//
// Shots 1 and 2 are animations: each is recorded as a Playwright video in its own browser
// context (sharing the login held in memory) and written as <OUT>/video-N.webm plus a
// video-N.json of event times (seconds from the start of the recording), which
// capture-screenshots.sh turns into screenshot-N.gif with ffmpeg.
const { chromium } = require('playwright');
const fs = require('fs');
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
const tab = async (page, t, query = '') => {
  // A hash-only change does not reload, so an open profile dialog would stay on screen.
  await page.goto(BASE + '/wp-admin/admin.php?page=sspa' + query + '#' + t);
  await page.reload();
  await page.locator(`#sspa_main .tab-contents[data-tab="${t}"][data-sspa-tab-loaded="1"], #sspa_main .tab-contents[data-tab="${t}"]:not([data-sspa-tab-lazy])`).first().waitFor({ timeout: 30000 }).catch(() => {});
  await page.addStyleTag({ content: CHROME_CSS });
  await page.waitForTimeout(1500);
};
// Put an element just under the admin bar.
const scrollTo = (page, selector, offset = 50) => page.evaluate(([sel, off]) => {
  const el = document.querySelector(sel);
  if (!el) throw new Error('missing ' + sel);
  window.scrollTo(0, el.getBoundingClientRect().top + window.scrollY - off);
}, [selector, offset]);
// A smooth scroll a viewer can follow, in small wheel steps.
const glide = async (page, px, ms) => {
  const steps = Math.max(1, Math.round(ms / 40));
  for (let i = 0; i < steps; i++) { await page.mouse.wheel(0, px / steps); await page.waitForTimeout(40); }
};
// Waits for the run's final batch response; returns a promise of its status.
const runDone = page => page.waitForResponse(async r => {
  if (!r.url().includes('admin-ajax.php') || !/action=sspa_process_batch/.test(r.request().postData() || '')) return false;
  const body = await r.json().catch(() => null);
  return !!(body && body.data && ['done', 'failed', 'cancelled'].includes(body.data.status));
}, { timeout: 600000 }).then(async r => (await r.json()).data.status);

async function runAnalysis(page) {
  await tab(page, 'overview');
  const done = runDone(page);
  done.catch(() => {});
  await page.getByRole('button', { name: 'Run Analysis', exact: true }).click();
  const status = await done;
  if (status !== 'done') throw new Error('analysis ended ' + status);
  await page.waitForTimeout(3000);
}

// Records one animation. body(page, mark) drives the screen; mark(name) notes an event time.
async function record(browser, state, num, body) {
  const dir = fs.mkdtempSync(path.join(OUT, '.rec-'));
  const context = await browser.newContext({ viewport: VIEWPORT, storageState: state, recordVideo: { dir, size: VIEWPORT } });
  const t0 = Date.now();
  const marks = {};
  const mark = name => { marks[name] = (Date.now() - t0) / 1000; console.log(`video-${num}: ${name} at ${marks[name].toFixed(1)}s`); };
  const page = await context.newPage();
  try {
    await body(page, mark);
    mark('end');
  } finally {
    const video = page.video();
    await context.close();
    if (video) {
      const target = path.join(OUT, `video-${num}.webm`);
      await video.saveAs(target);
      await video.delete().catch(() => {});
      fs.writeFileSync(path.join(OUT, `video-${num}.json`), JSON.stringify(marks, null, 2));
      console.log(`wrote ${target}`);
    }
    fs.rmSync(dir, { recursive: true, force: true });
  }
  n = Math.max(n, num);
}

(async () => {
  const browser = await chromium.launch();
  const context = await browser.newContext({ viewport: VIEWPORT, deviceScaleFactor: 2 });
  const page = await context.newPage();
  try {
    await page.goto(BASE + '/wp-login.php');
    await page.fill('#user_login', process.env.PD_ADMIN_USER);
    await page.fill('#user_pass', process.env.PD_ADMIN_PASS);
    await Promise.all([page.waitForURL(u => !u.pathname.includes('wp-login')), page.click('#wp-submit')]);
    // The login session, held in memory only, so the recording contexts start signed in.
    const state = await context.storageState();

    // The first analysis installs the profiler (0.68.5). The share invitation it raises is
    // answered the way a user answers it, before anything is recorded.
    await runAnalysis(page);
    await tab(page, 'overview');
    const later = page.getByRole('button', { name: "Don't share for 24 hours" });
    if (await later.count()) { await later.first().click(); await page.waitForTimeout(1500); }

    // 1. Animation: press Run Analysis, watch the progress, read the findings. The second
    //    analysis also gives Compare two points in time.
    await record(browser, state, 1, async (p, mark) => {
      await tab(p, 'overview');
      await p.mouse.move(700, 450);
      mark('ready');
      await p.waitForTimeout(1200);
      const done = runDone(p);
      done.catch(() => {});
      const button = p.getByRole('button', { name: 'Run Analysis', exact: true });
      await button.hover();
      await p.waitForTimeout(500);
      mark('click');
      await button.click();
      const status = await done;
      if (status !== 'done') throw new Error('analysis ended ' + status);
      mark('done');
      await p.locator('#sspa-runner').waitFor({ state: 'hidden', timeout: 60000 }).catch(() => {});
      await p.addStyleTag({ content: CHROME_CSS });
      await p.waitForTimeout(800);
      mark('results');
      // Bring "Top insights" up under the admin bar.
      const top = await p.evaluate(() => {
        const el = [...document.querySelectorAll('#sspa_main .tab-contents[data-tab="overview"] h2, #sspa_main .tab-contents[data-tab="overview"] h3')]
          .find(h => /Top insights/i.test(h.textContent));
        return el ? el.getBoundingClientRect().top - 70 : 0;
      });
      if (top > 0) await glide(p, top, 1500);
      await p.waitForTimeout(2500);
    });

    // 2. Animation: open the top finding's page analysis and scroll through the profile.
    await record(browser, state, 2, async (p, mark) => {
      await tab(p, 'overview');
      await scrollTo(p, '.sspa-insight-profile', 260);
      await p.mouse.move(700, 450);
      mark('ready');
      await p.waitForTimeout(1000);
      const link = p.locator('.sspa-insight-profile').first();
      await link.hover();
      await p.waitForTimeout(600);
      mark('click');
      await link.click();
      await p.waitForTimeout(2500);
      mark('open');
      // Scroll the dialog, not the page behind it.
      const box = await p.evaluate(() => {
        const els = [...document.querySelectorAll('*')].filter(e => {
          const s = getComputedStyle(e);
          return /(auto|scroll)/.test(s.overflowY) && e.scrollHeight > e.clientHeight + 50 && e.getBoundingClientRect().height > 300 && e.offsetParent !== null && e.closest('#sspa_main') === null;
        });
        const el = els.sort((a, b) => b.getBoundingClientRect().width - a.getBoundingClientRect().width)[0];
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { x: r.left + r.width / 2, y: r.top + r.height / 2 };
      });
      if (box) await p.mouse.move(box.x, box.y);
      await glide(p, 650, 2000);
      await p.waitForTimeout(1000);
      await glide(p, 700, 2000);
      await p.waitForTimeout(2000);
    });

    // 3. Pages: every measured page.
    await tab(page, 'pages');
    await scrollTo(page, 'tr.sspa-page-row', 140);
    await shot(page, 'pages');

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
