const {
  fs,
  wp,
  json,
  output,
  session,
  admin,
  expect,
} = require("./fleet-context.cjs");
(async () => {
  const { browser, context, page } = await session();
  try {
    await admin(page, "pages");
    const row = page
      .locator(".sspa-page-row")
      .filter({ hasText: "Home" })
      .first();
    await expect(row).toBeVisible();
    const id = Number(await row.getAttribute("data-profile-id"));
    const saved = json(
      `echo wp_json_encode(SSPA_Profile_Panel::profile_row(${id}));`,
    );
    await row.click();
    const panel = page.locator("#sspa-adhoc-pop");
    await expect(panel).toBeVisible();
    if (process.env.SSPA_NEGATIVE === "panel-hidden")
      await page.addStyleTag({
        content: "#sspa-adhoc-pop{display:none!important}",
      });
    await expect(panel).toBeVisible();
    await expect(panel).toContainText("Stored result");
    await expect(panel).toContainText(" / " + saved.sql_count);
    await panel.locator('.sspa-adhoc-attrib-btn[data-mode="caller"]').click();
    await expect(
      panel.locator('.sspa-adhoc-attrib-table[data-mode="caller"]'),
    ).toBeVisible();
    await expect(
      panel.locator('.sspa-adhoc-attrib-table[data-mode="code_owner"]'),
    ).toBeHidden();
    const phase = panel.locator(".sspa-adhoc-phase").first();
    await expect(phase).toBeVisible();
    const key = await phase.getAttribute("data-phase");
    const disclosure = phase.getByRole("button");
    await expect(disclosure).toHaveAttribute("aria-expanded", "false");
    await disclosure.focus();
    await page.keyboard.press("Tab");
    await page.keyboard.press("Shift+Tab");
    await expect(disclosure).toBeFocused();
    await disclosure.press("Enter");
    await expect(disclosure).toHaveAttribute("aria-expanded", "true");
    await expect(
      panel.locator(`.sspa-adhoc-sub[data-parent="${key}"]`).first(),
    ).toBeVisible();
    await disclosure.press("Space");
    await expect(disclosure).toHaveAttribute("aria-expanded", "false");
    await expect(
      panel.locator(`.sspa-adhoc-sub[data-parent="${key}"]`).first(),
    ).toBeHidden();
    const query = panel.locator(".sspa-adhoc-qrow").first();
    await query.scrollIntoViewIfNeeded();
    const sql = await query.getAttribute("data-sql");
    const copyQuery = query.getByRole("button", { name: "Copy query" });
    await copyQuery.focus();
    await copyQuery.press("Enter");
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(sql);
    // EXPLAIN is calculated when opening the real panel; no separate EXPLAIN button exists.
    const explained = panel.locator(".sspa-adhoc-explain").first();
    await expect(explained).toBeVisible();
    await expect(explained).toContainText("EXPLAIN:");
    await panel.locator(".sspa-markdown-copy").click();
    await expect(panel.locator(".sspa-markdown-status")).toContainText(
      "Copied",
    );
    const markdown = await page.evaluate(() => navigator.clipboard.readText());
    expect(markdown).toContain("Performance");
    expect(markdown).not.toContain("Synthetic PA browser");
    const download = page.waitForEvent("download");
    await panel.locator(".sspa-markdown-download").click();
    await (await download).saveAs(output + "/page-report.md");
    // The copy and the download are two generations of the same report, a moment apart; only
    // the "Generated:" timestamp may differ between them, and it may cross a second boundary.
    const withoutGenerated = (text) => text.replace(/^- Generated: .*$/m, "- Generated: <time>");
    expect(withoutGenerated(fs.readFileSync(output + "/page-report.md", "utf8"))).toBe(withoutGenerated(markdown));
    const diagnostic = page.waitForEvent("download");
    await panel.locator(".sspa-adhoc-export").click();
    await (await diagnostic).saveAs(output + "/page-diagnostic.json");
    const data = JSON.parse(
      fs.readFileSync(output + "/page-diagnostic.json", "utf8"),
    );
    expect(data.schema).toBe("sspa/page-diagnostic-export@1");
    expect(Number(data.profile.id)).toBe(id);
    expect(Number(data.profile.sql_count)).toBe(Number(saved.sql_count));
    expect(data.capture.sql.queries.length).toBeGreaterThan(0);
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(panel).toBeVisible();
    const box = await panel.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(391);
    await page.screenshot({
      path: output + "/report-mobile.png",
      fullPage: true,
    });
    const functions = panel.locator(".sspa-excimer-missing");
    if (await functions.count()) {
      await expect(functions).toBeVisible();
      await expect(functions).toContainText("not available");
      console.log(
        "UNVERIFIED Excimer function expansion: actual capture reports sampling unavailable",
      );
    }
    await panel.locator(".sspa-adhoc-close").click();
    await page.setViewportSize({ width: 1280, height: 900 });
    await admin(page, "tools");
    const toggle = page.locator(".sspa-steps-toggle").first();
    await expect(toggle).toBeVisible();
    const steps = page.locator(
      "#" + (await toggle.getAttribute("data-target")),
    );
    await toggle.click();
    await expect(steps).toBeVisible();
    const block = steps.locator(".sspa-code-block").first();
    const command = await block.locator("code").innerText();
    await block.locator(".sspa-copy").click();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(
      command,
    );
    await toggle.click();
    await expect(steps).toBeHidden();
    await page.screenshot({
      path: output + "/tools-capabilities.png",
      fullPage: true,
    });
    console.log(
      "PASS stored report values, attribution, phase expansion, actual EXPLAIN, clipboard, exact Markdown/JSON export and mobile geometry",
    );
  } finally {
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
