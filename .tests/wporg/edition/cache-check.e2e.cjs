// wordpress.org edition: the Cache performance check pressed in a real browser by the
// administrator. Run by .tests/wporg/edition/run.sh on tests-wporg-edition.
//
// Proves: the plan shown before the button counts five server requests per page (no logged-in
// customer requests); the check completes; the browser still walks every server leg in its fixed
// order, but the progress never exceeds the declared total; the saved customer rows carry
// customer_not_measured and no response; the result shows the reason on the customer row; and
// nothing overflows at 390px.
//
// It deliberately does not use ../../browser/fleet-context.cjs: that refuses a site whose plugin is
// not this checkout, and this site runs the extracted wp.org zip on purpose.
const {chromium, expect} = require('@playwright/test');
const {execFileSync} = require('node:child_process');
const site = process.env.SSPA_E2E_URL;
const output = process.env.SSPA_FLEET_OUTPUT;
const wpPath = process.env.SSPA_TEST_SITE_DIR;
if (!site || !output || !wpPath) throw Error('SSPA_E2E_URL, SSPA_FLEET_OUTPUT and SSPA_TEST_SITE_DIR are required');
const json = code => JSON.parse(execFileSync(process.env.SSPA_TEST_REAL_WP || 'wp', ['eval', code, '--path=' + wpPath, '--url=' + site], {encoding: 'utf8'}).trim().split('\n').pop());
async function session() {
  const browser = await chromium.launch();
  const context = await browser.newContext({viewport: {width: 1280, height: 900}, reducedMotion: 'reduce'});
  const page = await context.newPage();
  await page.goto(site + '/wp-login.php');
  await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
  await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
  await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
  return {browser, page};
}
const admin = (page, tab) => page.goto(site + '/wp-admin/admin.php?page=sspa#' + tab);
async function closeBrowser(browser) {
  await Promise.race([browser.close(), new Promise(resolve => setTimeout(resolve, 30000))]);
  const proc = browser.process && browser.process();
  if (proc && proc.exitCode === null) proc.kill('SIGKILL');
}

(async () => {
  const {browser, page} = await session();
  try {
    await admin(page, 'cache');
    const panel = page.locator('#sspa_main .tab-contents[data-tab="cache"]');
    await expect(panel).toHaveAttribute('data-sspa-tab-loaded', '1', {timeout: 30000});
    const plan = await panel.locator('.sspa-cache-request-plan').innerText();
    expect(plan, 'the plan says customers are not requested').toContain('Logged-in customers are not requested');
    expect(plan).not.toContain('test customer');
    const declared = Number((plan.match(/(\d+) sequential GET requests/) || [])[1]);
    const targets = await panel.locator('.sspa-cache-delivery-targets li').count();
    // Two browser GETs plus five server GETs per page, plus two returning-visitor GETs per page at most.
    expect([targets * 7, targets * 9], 'the request count shown before the button').toContain(declared);

    const progress = [];
    await page.exposeFunction('sspaProgress', text => progress.push(text));
    await page.evaluate(() => {
      const status = document.querySelector('#sspa_main .tab-contents[data-tab="cache"] .sspa-cache-delivery-status');
      new MutationObserver(() => window.sspaProgress(status.textContent)).observe(status, {childList: true, characterData: true, subtree: true});
    });
    const completed = page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('action=sspa_cache_delivery_complete'), {timeout: 300000});
    await panel.locator('.sspa-cache-delivery-start').click();
    const completion = await (await completed).json();
    expect(completion.success, 'the check completed: ' + JSON.stringify(completion.data)).toBe(true);
    await expect(panel.locator('.sspa-cache-audiences')).toBeVisible({timeout: 60000});

    expect(progress.some(t => t.includes('Logged-in customers are not measured')), 'progress names the skipped audience').toBe(true);
    const counts = progress.map(t => (t.match(/\((\d+)\/(\d+)\)/) || []).slice(1).map(Number)).filter(p => p.length === 2);
    expect(counts.every(([done, total]) => done <= total), 'progress never passes the declared total: ' + JSON.stringify(counts.slice(-3))).toBe(true);

    const saved = json('echo wp_json_encode(SSPA_Cache_Delivery::latest()["delivery_path_observations"]["server"]["requests"]);');
    const customer = saved.filter(r => r.audience === 'customer');
    expect(customer.length, 'two customer legs per page are recorded').toBe(targets * 2);
    expect(customer.every(r => r.error_class === 'customer_not_measured' && !r.status), 'customer legs carry customer_not_measured and no response').toBe(true);
    const row = panel.locator('.sspa-cache-audience[data-audience="customer"]');
    await expect(row).toBeVisible();
    await page.screenshot({path: output + '/wporg-cache-1280.png', fullPage: true});
    const text = await panel.innerText();
    expect(text, 'the result shows why customers were not measured').toContain('Logged-in customers are not measured');

    await page.setViewportSize({width: 390, height: 844});
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow, 'no horizontal overflow at 390px').toBeLessThanOrEqual(0);
    await page.screenshot({path: output + '/wporg-cache-390.png', fullPage: true});
    console.log('PASS: cache check in the browser: ' + targets + ' pages, declared ' + declared + ', customer legs not requested and explained');
  } catch (error) {
    await page.screenshot({path: output + '/wporg-cache-failure.png', fullPage: true}).catch(() => {});
    console.log('FAIL: cache check in the browser: ' + (error && error.message ? error.message.split('\n')[0] : error));
    process.exitCode = 1;
  } finally {
    await closeBrowser(browser);
  }
})();
