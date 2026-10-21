// A real WooCommerce product CSV import through WooCommerce's own importer screens. The
// importer processes the file in admin-ajax batches (woocommerce_do_ajax_product_import),
// which a running Traffic collection records. Closes the browser on every path.
const { session, site, output } = require("../browser/fleet-context.cjs");
const csv = process.argv[2];
if (!csv) throw Error("usage: node woocommerce-import.cjs <csv>");
(async () => {
  const { browser, page } = await session();
  try {
    await page.goto(site + "/wp-admin/edit.php?post_type=product&page=product_importer");
    await page.locator("#upload").setInputFiles(csv);
    await Promise.all([page.waitForNavigation({ timeout: 120000 }), page.locator('button[name="save_step"]').click()]);
    await Promise.all([page.waitForNavigation({ timeout: 120000 }), page.locator('button[name="save_step"]').click()]);
    await page.waitForURL(/step=done/, { timeout: 600000 });
    const text = await page.locator(".woocommerce-importer-done").innerText();
    await page.screenshot({ path: output + "/woocommerce-import-done.png", timeout: 10000 }).catch(() => {});
    if (!/imported/i.test(text)) throw Error("WooCommerce did not report imported products: " + text);
    console.log("PASS: WooCommerce imported the CSV through its own importer: " + text.replace(/\s+/g, " "));
  } finally {
    await browser.close();
  }
})().catch((error) => { console.error("FAIL: " + error.message); process.exit(1); });
