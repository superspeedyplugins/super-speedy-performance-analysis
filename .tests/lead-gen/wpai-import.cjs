// A real WP All Import run through its own wp-admin wizard: upload the CSV, map title and
// content, choose the unique identifier, confirm and run. Waits for WP All Import to record
// the finished run in its own history. Closes the browser on every path.
const { session, site, wp, output } = require("../browser/fleet-context.cjs");
const csv = process.argv[2];
if (!csv) throw Error("usage: node wpai-import.cjs <csv>");
(async () => {
  const { browser, page } = await session();
  try {
    await page.goto(site + "/wp-admin/admin.php?page=pmxi-admin-import");
    await page.click("#file_upload_type");
    await page.locator("input[type=file]").first().setInputFiles(csv);
    await page.waitForFunction(() => document.querySelector("input[name=filepath]").value !== "", null, { timeout: 60000 });
    await page.waitForTimeout(1500);
    // WP All Import animates its preview, so its controls never settle for Playwright's
    // actionability checks; the wizard's own buttons are pressed through the DOM.
    const press = (selector) => Promise.all([page.waitForNavigation({ timeout: 120000 }), page.evaluate((s) => document.querySelector(s).click(), selector)]);
    await press("#advanced_upload");
    await page.fill("#wpallimport-title", "{title[1]}");
    await page.evaluate(() => { const v = "{content[1]}"; document.querySelector("textarea#content").value = v; if (window.tinymce && tinymce.get("content")) tinymce.get("content").setContent(v); });
    await press('input[value="Continue to Import Settings"]');
    await page.evaluate(() => { document.querySelector("input[name=unique_key]").value = "{ref[1]}"; });
    await press('input[type=submit][value="Continue"]');
    await page.evaluate(() => { [...document.querySelectorAll("input[type=submit]")].find((b) => /Confirm/.test(b.value)).click(); });
    const started = Date.now();
    let done = false;
    while (!done && Date.now() - started < 600000) {
      await page.waitForTimeout(3000);
      done = "1" === wp("eval", "global $wpdb; $i = $wpdb->get_row(\"SELECT * FROM {$wpdb->prefix}pmxi_imports ORDER BY id DESC LIMIT 1\"); $h = $i ? $wpdb->get_row($wpdb->prepare(\"SELECT * FROM {$wpdb->prefix}pmxi_history WHERE import_id = %d ORDER BY id DESC LIMIT 1\", $i->id)) : null; echo ($i && !$i->processing && !$i->executing && (int) $i->imported === (int) $i->count && $h && is_numeric($h->time_run) && (int) $h->time_run > 0) ? 1 : 0;");
    }
    if (!done) throw Error("WP All Import did not record a finished run within 10 minutes");
    await page.screenshot({ path: output + "/wpai-import-finished.png", timeout: 10000 }).catch(() => {});
    console.log("PASS: WP All Import finished a real import through its own wizard");
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error("FAIL: " + error.message); process.exit(1); });
