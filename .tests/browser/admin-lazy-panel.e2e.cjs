// "Analyse this page" on wp-admin screens other than Performance Analysis's own. 0.43.16 hid
// the item there to keep plugin assets off core screens (wp.org L2), which left no way to
// profile a slow admin page such as another plugin's settings screen. The panel now loads on
// first click: the screen carries none of the plugin's files until then, the click opens the
// panel and profiles that admin URL, and ?sspa_open=page opens it the same way.
const { output, session, expect } = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  // The panel's own files. super-speedy-settings' update UI on plugins.php is a separate
  // submodule with its own screens and is not what this journey is about.
  const ours = () =>
    page.$$eval('script[src], link[rel="stylesheet"][href]', (els) =>
      els
        .map((el) => el.getAttribute("src") || el.getAttribute("href"))
        .filter((src) => /\/plugins\/super-speedy-performance-analysis[^/]*\/includes\/admin\//.test(src)),
    );
  try {
    const site = process.env.SSPA_TEST_SITE_URL;

    // 1. The core screens wp.org's asset-scope check loads: the item is there on each, and no
    //    panel file is on the page before the click.
    for (const core of ["index.php", "edit.php", "upload.php", "options-general.php", "users.php"]) {
      await page.goto(site + "/wp-admin/" + core);
      await expect(page.locator("#wp-admin-bar-sspa-adhoc > a")).toBeAttached();
      expect(await ours()).toEqual([]);
    }
    console.log("PASS Analyse this page is on five core screens with no panel file loaded");
    await page.goto(site + "/wp-admin/plugins.php?sspa_lazy_panel=1");
    const analyse = page.locator("#wp-admin-bar-sspa-adhoc > a");
    await expect(analyse).toBeAttached();
    expect(await ours()).toEqual([]);
    console.log("PASS Analyse this page is in the admin bar on plugins.php with no panel file loaded");

    // 2. Clicking it loads the panel and profiles this admin URL (nothing is stored for it, so
    //    opening the panel measures it).
    await page.$eval("#wp-admin-bar-sspa-adhoc > a", (a) => a.click());
    const panel = page.locator("#sspa-adhoc-pop");
    await expect(panel).toBeVisible();
    await expect(panel).toContainText("profiled as", { timeout: 180000 });
    expect((await ours()).some((src) => src.includes("sspa-adhoc.js"))).toBe(true);
    console.log("PASS the first click loads the panel and profiles the admin page");

    // 3. The deep link opens the panel on another core screen without a click.
    await page.goto(site + "/wp-admin/users.php?sspa_open=page");
    await expect(page.locator("#sspa-adhoc-pop")).toBeVisible();
    console.log("PASS ?sspa_open=page opens the lazily loaded panel");

    // 4. An editor screen, where Analyse update/save needs the panel at once: it is enqueued
    //    there as before, and the lazy loader stays off.
    await page.goto(site + "/wp-admin/post-new.php");
    expect((await ours()).some((src) => src.includes("sspa-adhoc.js"))).toBe(true);
    await expect(page.locator("#sspa-adhoc-lazy-js-after")).toHaveCount(0);
    console.log("PASS the editor keeps its eager panel and no lazy loader");

    expect(errors).toEqual([]);
    console.log("PASS no page errors");
  } finally {
    await page.screenshot({ path: output + "/admin-lazy-panel.png", fullPage: true }).catch(() => {});
    await browser.close();
  }
})().catch((e) => {
  console.error("FAIL", e.message);
  process.exit(1);
});
