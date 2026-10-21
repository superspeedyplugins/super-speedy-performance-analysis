// The administrator's journey in a real browser, from the WordPress admin menu.
//
//   node journey.e2e.cjs full          # on the slow store: run an analysis from the PA screen,
//                                      # read the finding, its free fix and the offer, dismiss
//                                      # it, check the export, open the Imports tab
//   node journey.e2e.cjs imports <tag> # open the Imports tab and keep a screenshot
//
// Screenshots go to SSPA_FLEET_OUTPUT. The browser is closed on every path.
const { session, site, output, expect, fs, wp, closeBrowser } = require("../browser/fleet-context.cjs");
const mode = process.argv[2] || "full";
const tag = process.argv[3] || "imports";
const shot = (page, name) => page.screenshot({ path: output + "/" + name + ".png", fullPage: true, timeout: 30000 });

async function openFromMenu(page) {
  await page.goto(site + "/wp-admin/index.php");
  // PA sits in the shared Super Speedy menu's flyout (or is its own top-level menu without
  // it): hover the parent as an administrator would, then choose Performance Analysis.
  const menu = page.locator('#adminmenu a[href$="page=sspa"]').first();
  await expect(menu).toBeAttached();
  const parent = page.locator("#adminmenu li.menu-top").filter({ has: page.locator('a[href$="page=sspa"]') }).first();
  await parent.hover();
  await Promise.all([page.waitForURL(/[?&]page=sspa(&|#|$)/, { timeout: 60000 }), menu.click()]);
  await expect(page.locator("#sspa_main")).toBeVisible();
}

async function imports(page, name) {
  await page.locator('.nav-tab[data-tab="imports"]').click();
  const tab = page.locator('.tab-contents[data-tab="imports"]');
  await expect(tab.locator(".sspa-imports-tab")).toBeVisible({ timeout: 60000 });
  await shot(page, name);
  return tab;
}

(async () => {
  const { browser, page } = await session();
  try {
    await openFromMenu(page);
    if (mode === "imports") {
      await imports(page, "imports-tab-" + tag);
      console.log("PASS: Imports tab opened from the PA menu (" + tag + ")");
      return;
    }
    // Run an analysis from the PA screen. When it finishes, the floating monitor refreshes
    // the tabs in place and closes.
    const runsBefore = Number(wp("eval", "global $wpdb; echo (int) $wpdb->get_var(\"SELECT MAX(id) FROM {$wpdb->prefix}sspa_runs\");"));
    await page.locator("#sspa-run-analysis").click();
    await expect(page.locator("#sspa-runner")).toBeVisible({ timeout: 60000 });
    await expect(page.locator("#sspa-runner")).toBeHidden({ timeout: 1800000 });
    const run = JSON.parse(wp("eval", "global $wpdb; echo wp_json_encode($wpdb->get_row(\"SELECT id, run_type, status FROM {$wpdb->prefix}sspa_runs ORDER BY id DESC LIMIT 1\"));"));
    if (!(Number(run.id) > runsBefore && run.status === "done")) throw Error("the analysis started from the PA screen did not complete: " + JSON.stringify(run));
    console.log("PASS: Run Analysis from the PA screen completed analysis " + run.id + " (" + run.run_type + ")");
    const overview = page.locator('.tab-contents[data-tab="overview"]');
    const offers = overview.locator(".sspa-offer");
    await expect(offers.first()).toBeVisible({ timeout: 60000 });
    const count = await offers.count();
    if (count < 1 || count > 2) throw Error("Overview shows " + count + " offers");
    // Finding, free fix, "If you would rather not do this by hand:", offer - in that order.
    const first = offers.first();
    const insight = first.locator("xpath=ancestor::li[contains(@class,'sspa-insight')]");
    if (await insight.count()) {
      const order = await insight.evaluate((li) => {
        const text = li.innerText;
        const rec = li.querySelector(".sspa-insight-rec");
        return [text.indexOf(li.querySelector("strong").innerText), rec ? text.indexOf(rec.innerText) : -1, text.indexOf("If you would rather not do this by hand:"), text.indexOf(li.querySelector(".sspa-offer-link").innerText)];
      });
      if (!(order[0] >= 0 && order[0] < order[1] && order[1] < order[2] && order[2] < order[3])) throw Error("finding, free fix, lead and offer are out of order: " + JSON.stringify(order));
    }
    await first.scrollIntoViewIfNeeded();
    await shot(page, "overview-offers");
    const dismissed = await first.getAttribute("data-offer");
    const link = await first.locator(".sspa-offer-link").first().getAttribute("href");
    if (!/utm_source=performance-analysis&utm_medium=plugin&utm_campaign=[a-z_]+$/.test(link.replace(/&amp;/g, "&"))) throw Error("offer link is not statically tagged: " + link);
    console.log("PASS: the Overview shows " + count + " offer(s) after the finding and its free fix; primary " + dismissed);

    // Dismiss it; it stays gone after a reload and leaves the export.
    await Promise.all([
      page.waitForResponse((r) => r.url().includes("admin-ajax.php") && (r.request().postData() || "").includes("action=sspa_dismiss_offer")),
      first.locator(".sspa-offer-dismiss").click(),
    ]);
    await expect(overview.locator('.sspa-offer[data-offer="' + dismissed + '"]')).toHaveCount(0);
    await page.reload();
    await expect(page.locator('.tab-contents[data-tab="overview"] .sspa-offer[data-offer="' + dismissed + '"]')).toHaveCount(0);
    await shot(page, "overview-after-dismiss");
    console.log("PASS: Don't show this again removes the " + dismissed + " offer, and it stays gone after a reload");
    await page.locator('.sspa-markdown-copy[data-kind="run"]').click();
    await expect(page.locator(".sspa-markdown-status")).toContainText(/copied/i, { timeout: 60000 });
    const markdown = await page.evaluate(() => navigator.clipboard.readText());
    fs.writeFileSync(output + "/run-export.md", markdown);
    if (markdown.includes("utm_campaign=" + dismissed + "_export")) throw Error("the dismissed offer is still in the export");
    console.log("PASS: the copied run export no longer carries the dismissed offer");

    await imports(page, "imports-tab-slow-store");
    console.log("PASS: the Imports tab opens from the PA navigation");
  } finally {
    await closeBrowser(browser);
  }
})().then(() => process.exit(0), (error) => { console.error("FAIL: " + error.message); process.exit(1); });
