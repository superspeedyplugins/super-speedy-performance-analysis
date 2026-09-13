const { output, session, admin, expect } = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  try {
    await page.setViewportSize({ width: 390, height: 844 });
    await admin(page, "pages");
    await page
      .locator(".sspa-page-row")
      .filter({ hasText: "Home" })
      .first()
      .click();
    const panel = page.locator("#sspa-adhoc-pop");
    await expect(panel.locator(".sspa-markdown-copy")).toBeVisible();
    await page.screenshot({
      path: output + "/report-mobile-controls.png",
      fullPage: true,
    });
    const bounds = await panel.boundingBox();
    expect(bounds.x).toBeGreaterThanOrEqual(0);
    expect(bounds.x + bounds.width).toBeLessThanOrEqual(390);
    for (const selector of [
      ".sspa-adhoc-rerun",
      ".sspa-adhoc-export",
      ".sspa-markdown-download",
      ".sspa-markdown-copy",
    ]) {
      const button = panel.locator(selector);
      await expect(button).toBeVisible();
      const box = await button.boundingBox();
      expect(box.x, selector + " left edge").toBeGreaterThanOrEqual(bounds.x);
      expect(
        box.x + box.width,
        selector + " right edge must remain inside the visible panel",
      ).toBeLessThanOrEqual(bounds.x + bounds.width);
    }
    console.log(
      "PASS every report action remains visible within the mobile panel",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
