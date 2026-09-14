// Issue 19. The parts of the administrator's journey no other retained journey walks: the
// admin bar's first submenu action opening the panel from a real front-end page, an admin-bar
// cache control proved by its effect, switching the profile panel's attribution mode by its
// real buttons, a stored result becoming a fresh one after Re-run, and closing the panel with
// Escape with focus going back to what opened it. Browser assertions throughout; nothing
// calls a server handler directly.
const {
  wp,
  json,
  output,
  state,
  session,
  admin,
  response,
  expect,
} = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  try {
    const site = process.env.SSPA_TEST_SITE_URL;

    // 1. Admin bar, first submenu action, from a real front-end page: Analyse this page.
    await page.goto(site + "/?sspa_admin_journey=1");
    const analyse = page.locator("#wp-admin-bar-sspa-adhoc > a");
    await expect(analyse).toBeAttached();
    await page.$eval("#wp-admin-bar-sspa-adhoc > a", (a) => a.click());
    const panel = page.locator("#sspa-adhoc-pop");
    await expect(panel).toBeVisible();
    // Opening the menu shows the stored result for this page (Home has one on the retained
    // site); measuring again is the person's explicit choice through Re-run. The panel's
    // other primary button, "Measure plugin impact", starts a different, longer analysis.
    const trigger = panel.locator(".sspa-adhoc-rerun");
    await expect(trigger).toBeVisible();
    const started = response(page, "sspa_adhoc_start");
    await trigger.click();
    const reply = await (await started).json();
    expect(reply.success).toBe(true);
    await expect(panel).toContainText("Fresh result", { timeout: 180000 });
    await expect(panel).toContainText("profiled as");
    console.log("PASS admin-bar Analyse this page opens the panel on the front end with a fresh result");

    // 2. Admin bar cache control: flush the object cache, proved by a key that disappears and
    //    by the bar's own notice.
    wp("eval", "wp_cache_set('sspa_journey_probe', 'present', 'sspa_journey', 3600); echo 'set';");
    expect(json(`echo wp_json_encode(wp_cache_get('sspa_journey_probe', 'sspa_journey'));`)).toBe("present");
    const flush = page.locator("#wp-admin-bar-sspa-flush-object-cache > a");
    await expect(flush).toBeAttached();
    // The action runs at admin-post.php and redirects back to the page it was used from; wait
    // for that page to be fully back before going anywhere else.
    await Promise.all([
      page.waitForURL((url) => url.searchParams.get("sspa_admin_journey") === "1", { waitUntil: "load" }),
      page.$eval("#wp-admin-bar-sspa-flush-object-cache > a", (a) => a.click()),
    ]);
    await page.waitForLoadState("networkidle");
    expect(json(`echo wp_json_encode(wp_cache_get('sspa_journey_probe', 'sspa_journey'));`)).toBe(false);
    expect(json(`echo wp_json_encode(get_transient('sspa_bar_notice_1'));`)).toBe("Flushed the object cache.");
    console.log("PASS admin-bar cache control flushed the object cache and reported it");

    // 3. The profile panel from the Pages tab: attribution switching, stored then fresh,
    //    Escape, focus restoration. In a second tab of the same session: the front-end tab
    //    reloads itself once its measurement finishes, which would interrupt a navigation.
    const front = page;
    const page2 = await front.context().newPage();
    page2.on("pageerror", (e) => errors.push(e.message));
    const adminTab = page2;
    await admin(adminTab, "pages");
    const row = adminTab.locator("#sspa_main .sspa-page-row").first();
    await expect(row).toBeVisible();
    await row.focus();
    const openerId = await row.evaluate((el) => {
      if (!el.id) el.id = "sspa-journey-opener";
      return el.id;
    });
    await row.click();
    const panel2 = adminTab.locator("#sspa-adhoc-pop");
    await expect(panel2).toBeVisible();
    await expect(panel2.locator(".sspa-adhoc-badge.is-cached")).toBeVisible();
    await expect(panel2.locator(".sspa-adhoc-badge.is-cached")).toContainText("Stored result");
    const modes = panel2.locator(".sspa-adhoc-attrib-btn");
    await expect(modes).toHaveCount(2);
    const pressedBefore = await modes.evaluateAll((els) => els.map((e) => e.getAttribute("aria-pressed")));
    expect(pressedBefore.filter((v) => v === "true").length).toBe(1);
    const other = panel2.locator('.sspa-adhoc-attrib-btn[aria-pressed="false"]').first();
    const otherMode = await other.getAttribute("data-mode");
    await other.click();
    await expect(panel2.locator(`.sspa-adhoc-attrib-btn[data-mode="${otherMode}"]`)).toHaveAttribute("aria-pressed", "true");
    await expect(panel2.locator(`.sspa-adhoc-attrib-table[data-mode="${otherMode}"]`)).toBeVisible();
    const visibleTables = await panel2.locator(".sspa-adhoc-attrib-table:visible").count();
    expect(visibleTables).toBe(1);
    console.log("PASS attribution mode switches by its real buttons, showing exactly one table");

    const rerun = panel2.locator(".sspa-adhoc-rerun");
    await expect(rerun).toBeVisible();
    await rerun.click();
    await expect(panel2.locator(".sspa-adhoc-badge.is-fresh")).toContainText("Fresh result", { timeout: 180000 });
    console.log("PASS Re-run turns a stored result into a fresh one");

    await adminTab.keyboard.press("Escape");
    await expect(panel2).toBeHidden();
    const focused = await adminTab.evaluate(() => document.activeElement && document.activeElement.id);
    expect(focused).toBe(openerId);
    console.log("PASS Escape closes the panel and focus returns to the row that opened it");

    await page.screenshot({ path: output + "/admin-journey-final.png", fullPage: true });
    expect(errors).toEqual([]);
    console.log("PASS administrator journey: admin-bar action, cache control, attribution switching, fresh result, Escape and focus restoration");
  } finally {
    await page.screenshot({ path: output + "/admin-journey-end.png", fullPage: true });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
