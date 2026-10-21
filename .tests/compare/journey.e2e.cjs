// Compare and the share panel, as an administrator uses them, from the WordPress admin menu.
//
//   node journey.e2e.cjs
//
// Run by .tests/compare/run.sh, which clears the state on the way in (sharing off, no prompt
// choices, superspeedy.org answered from the build's own documents by the observer fixture).
// Screenshots go to SSPA_FLEET_OUTPUT. The browser is closed on every path.
const { session, site, output, expect, wp, closeBrowser } = require("../browser/fleet-context.cjs");
const shot = (page, name) => page.screenshot({ path: output + "/" + name + ".png", fullPage: true, timeout: 30000 });
const pass = (label) => console.log("PASS: " + label);
const php = (code) => wp("eval", code);
const apiRequests = () => JSON.parse(php("echo wp_json_encode(array_values(array_filter((array) get_option('sspa_test_ssorg_log', array()), function ($e) { return false !== strpos($e['url'], '/api/v1/'); })));"));
const ajax = (page, action) => page.waitForResponse((r) => r.url().includes("admin-ajax.php") && (r.request().postData() || "").includes("action=" + action));

async function openFromMenu(page) {
  await page.goto(site + "/wp-admin/index.php");
  const menu = page.locator('#adminmenu a[href$="page=sspa"]').first();
  await expect(menu).toBeAttached();
  const parent = page.locator("#adminmenu li.menu-top").filter({ has: page.locator('a[href$="page=sspa"]') }).first();
  await parent.hover();
  await Promise.all([page.waitForURL(/[?&]page=sspa(&|#|$)/, { timeout: 60000 }), menu.click()]);
  await expect(page.locator("#sspa_main")).toBeVisible();
}
const tab = (page, slug) => page.locator('#sspa_main .tab-contents[data-tab="' + slug + '"]');
async function selected(page, slug) {
  await expect(page.locator("#sspa_main .nav-tab-active")).toHaveAttribute("data-tab", slug);
  await expect(tab(page, slug)).toBeVisible();
}

(async () => {
  const { browser, page } = await session();
  try {
    await openFromMenu(page);
    await expect(page.locator('#sspa_main .nav-tab[data-tab="compare"]')).toHaveText("Compare");
    await expect(page.locator('#sspa_main .nav-tab[data-tab="history"]')).toHaveCount(0);
    pass("the PA screen opened from the admin menu has a Compare tab and no History tab");

    // A real analysis from the PA screen; its result view then carries the panel.
    const runsBefore = Number(php("global $wpdb; echo (int) $wpdb->get_var(\"SELECT MAX(id) FROM {$wpdb->prefix}sspa_runs\");"));
    await page.locator("#sspa-run-analysis").click();
    await expect(page.locator("#sspa-runner")).toBeVisible({ timeout: 60000 });
    await expect(page.locator("#sspa-runner")).toBeHidden({ timeout: 1800000 });
    const run = JSON.parse(php("global $wpdb; echo wp_json_encode($wpdb->get_row(\"SELECT id, run_type, status FROM {$wpdb->prefix}sspa_runs ORDER BY id DESC LIMIT 1\"));"));
    if (!(Number(run.id) > runsBefore && run.status === "done")) throw Error("the analysis did not complete: " + JSON.stringify(run));
    const overviewPanel = tab(page, "overview").locator(".sspa-share-prompt");
    await expect(overviewPanel).toBeVisible({ timeout: 60000 });
    const first = await tab(page, "overview").evaluate((node) => node.firstElementChild && node.firstElementChild.classList.contains("sspa-share-prompt"));
    if (!first) throw Error("the share panel is not at the top of the Overview result");
    await expect(overviewPanel.locator("button")).toHaveCount(2);
    await expect(overviewPanel.locator("input[type=checkbox]")).toHaveCount(0);
    await shot(page, "01-overview-after-analysis");
    pass("after analysis " + run.id + " the Overview result opens with the share panel at the top");

    // An old #history bookmark opens Compare, and the fragment becomes #compare.
    await page.goto(site + "/wp-admin/admin.php?page=sspa#history");
    await selected(page, "compare");
    await expect(page).toHaveURL(/#compare$/);
    await page.goto(site + "/wp-admin/admin.php?page=sspa&tab=history");
    await selected(page, "compare");
    pass("#history and ?tab=history both open Compare");

    const compare = tab(page, "compare");
    await expect(compare.locator(".sspa-share-prompt")).toBeVisible({ timeout: 60000 });
    await expect(compare.locator(".sspa-history-compare-picker")).toBeVisible();
    await expect(compare.locator("#sspa-community-figures-load")).toBeVisible();
    await shot(page, "02-compare-before-figures");
    let requests = apiRequests();
    if (requests.length) throw Error("superspeedy.org was contacted before the click: " + JSON.stringify(requests));
    pass("Compare shows the panel, the own-run comparison and a Load community figures button, with no request yet");

    await Promise.all([ajax(page, "sspa_community_figures"), compare.locator("#sspa-community-figures-load").click()]);
    const figures = compare.locator(".sspa-community-figures-table").first();
    await expect(figures).toBeVisible({ timeout: 60000 });
    const home = compare.locator('tr[data-page-class="home"][data-rung]');
    await expect(home).toContainText("612.4");
    await expect(home).toContainText("1 installation");
    await expect(home).toContainText("Early data");
    await expect(compare.locator('tr[data-page-class="product-single"][data-rung]')).toContainText("Ranking");
    await expect(compare.locator('tr[data-plugin="woocommerce"]').first()).toBeVisible();
    await expect(compare.locator('tr[data-plugin="akismet"]')).toHaveCount(0);
    requests = apiRequests().map((r) => r.method + " " + r.url);
    if (JSON.stringify(requests) !== JSON.stringify(["GET https://superspeedy.org/api/v1/cohorts", "GET https://superspeedy.org/api/v1/plugin-impact"])) throw Error("unexpected requests: " + JSON.stringify(requests));
    await figures.scrollIntoViewIfNeeded();
    await shot(page, "03-compare-community-figures");
    pass("one click loads both documents once each and shows figures with n and their confidence label");

    // Snooze: gone everywhere for 24 hours, by the stored time.
    const before = Math.floor(Date.now() / 1000);
    await Promise.all([ajax(page, "sspa_share_prompt_snooze"), compare.locator(".sspa-share-prompt-snooze").click()]);
    await expect(page.locator(".sspa-share-prompt:visible")).toHaveCount(0);
    const until = Number(php("echo (int) get_user_meta(1, 'sspa_share_prompt_snoozed_until', true);"));
    if (!(until >= before + 86400 - 5 && until <= Math.floor(Date.now() / 1000) + 86400 + 5)) throw Error("snooze stored " + until + " for a click at " + before);
    await page.reload();
    await selected(page, "compare");
    await expect(compare.locator("#sspa-community-figures-load")).toBeVisible({ timeout: 60000 });
    await expect(page.locator(".sspa-share-prompt")).toHaveCount(0);
    await shot(page, "04-compare-snoozed");
    pass("Don't share for 24 hours hides the panel, stores now + 24 hours, and it stays hidden after a reload");
    php("update_user_meta(1, 'sspa_share_prompt_snoozed_until', " + (until - 86400 - 1) + ");");
    await page.reload();
    await selected(page, "compare");
    await expect(compare.locator(".sspa-share-prompt")).toBeVisible({ timeout: 60000 });
    pass("once the stored time has passed, the panel is back");

    // Share automatically: the same option and consent record as the Share tab's checkbox.
    await Promise.all([ajax(page, "sspa_share_optin"), compare.locator(".sspa-share-prompt-share").click()]);
    await expect(page.locator(".sspa-share-prompt:visible")).toHaveCount(0);
    const consent = JSON.parse(php("echo wp_json_encode(array(get_option('sspa_share_optin'), get_option('sspa_share_consent_version'), get_option('sspa_share_consented_at'), SSPA_Community_Schema::CONSENT_VERSION));"));
    if (!(String(consent[0]) === "1" && Number(consent[1]) === consent[3] && Math.abs(Date.parse(consent[2].replace(" ", "T") + "Z") / 1000 - Date.now() / 1000) < 120)) throw Error("consent record: " + JSON.stringify(consent));
    await page.locator('#sspa_main .nav-tab[data-tab="share"]').click();
    await selected(page, "share");
    await expect(page.locator("#sspa-share-optin")).toBeChecked({ timeout: 60000 });
    await page.reload();
    await expect(page.locator(".sspa-share-prompt")).toHaveCount(0);
    await shot(page, "05-share-tab-after-sharing");
    pass("Share anonymised results automatically turns on sharing with consent version " + consent[1] + " at " + consent[2] + " UTC, ticks the Share tab, and the panel is gone");

    // Sharing off again on the Share tab, then the quiet permanent opt-out and its undo.
    await selected(page, "share");
    await Promise.all([ajax(page, "sspa_share_optin"), page.locator("#sspa-share-optin").uncheck()]);
    const never = page.locator("#sspa-share-prompt-never");
    await expect(never).not.toBeChecked({ timeout: 60000 });
    await Promise.all([ajax(page, "sspa_share_prompt_never"), never.check()]);
    await shot(page, "06-share-tab-dont-ask");
    await page.goto(site + "/wp-admin/admin.php?page=sspa#compare");
    await selected(page, "compare");
    await expect(compare.locator("#sspa-community-figures-load")).toBeVisible({ timeout: 60000 });
    await expect(page.locator(".sspa-share-prompt")).toHaveCount(0);
    pass("with sharing off, Don't ask me again on the Share tab keeps the panel away");
    await page.locator('#sspa_main .nav-tab[data-tab="share"]').click();
    await expect(page.locator("#sspa-share-prompt-never")).toBeChecked({ timeout: 60000 });
    await Promise.all([ajax(page, "sspa_share_prompt_never"), page.locator("#sspa-share-prompt-never").uncheck()]);
    await page.goto(site + "/wp-admin/admin.php?page=sspa#compare");
    await selected(page, "compare");
    await expect(compare.locator(".sspa-share-prompt")).toBeVisible({ timeout: 60000 });
    await shot(page, "07-compare-asked-again");
    pass("unticking it brings the panel back");

    // Never on other admin screens.
    for (const screen of ["index.php", "plugins.php", "edit.php?post_type=product"]) {
      await page.goto(site + "/wp-admin/" + screen);
      await expect(page.locator("#wpadminbar")).toBeVisible();
      await expect(page.locator(".sspa-share-prompt")).toHaveCount(0);
    }
    pass("no share panel on the Dashboard, Plugins or Products screens");
  } finally {
    await closeBrowser(browser);
  }
})().then(() => process.exit(0), (error) => { console.error("FAIL: " + error.message); process.exit(1); });
