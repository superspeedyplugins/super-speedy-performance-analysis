// "Request a fix quote" as an administrator uses it, from the WordPress admin menu.
//
//   node journey.e2e.cjs
//
// Run by .tests/quote-request/run.sh after assert-quote.php (which leaves a home page analysis
// behind). Opens Performance Analysis from the menu, analyses the home page again from its
// Pages row, opens the dialog, reads the preview, presses Send without consent (refused, nothing
// requested), ticks consent, sends, and reads the reference. The receiver is the real Super
// Speedy Contact route on the receiver site. Screenshots go to SSPA_FLEET_OUTPUT. The browser is
// closed on every path.
const { execFileSync } = require("node:child_process");
const { session, site, output, expect, fs, wp, closeBrowser } = require("../browser/fleet-context.cjs");
const shot = (page, name) => page.screenshot({ path: output + "/" + name + ".png", fullPage: false, timeout: 30000 });
const pass = (label) => console.log("PASS: " + label);
const receiver = (code) =>
  execFileSync(process.env.SSPA_TEST_REAL_WP || "wp", ["eval", code, "--path=" + process.env.SSPA_QUOTE_RECEIVER_DIR, "--url=" + process.env.SSPA_QUOTE_RECEIVER_URL], { encoding: "utf8", maxBuffer: 64e6 }).trim();
const quoteRequests = () => JSON.parse(wp("eval", "echo wp_json_encode(array_values((array) get_option('sspa_test_quote_log', array())));")).length;

async function openFromMenu(page) {
  await page.goto(site + "/wp-admin/index.php");
  const menu = page.locator('#adminmenu a[href$="page=sspa"]').first();
  await expect(menu).toBeAttached();
  const parent = page.locator("#adminmenu li.menu-top").filter({ has: page.locator('a[href$="page=sspa"]') }).first();
  await parent.hover();
  await Promise.all([page.waitForURL(/[?&]page=sspa(&|#|$)/, { timeout: 60000 }), menu.click()]);
  await expect(page.locator("#sspa_main")).toBeVisible();
}

(async () => {
  if (!process.env.SSPA_QUOTE_RECEIVER_DIR || !process.env.SSPA_QUOTE_RECEIVER_URL) throw Error("run.sh provides the receiver site");
  const { browser, page } = await session();
  try {
    await page.setViewportSize({ width: 1440, height: 1000 });
    await openFromMenu(page);
    pass("Performance Analysis opened from the admin menu");

    // Analyse a page: the Pages row opens its stored profile; Re-run measures it again now.
    await page.locator('#sspa_main .nav-tab[data-tab="pages"]').click();
    const row = page.locator("#sspa_main .sspa-page-row").first();
    await expect(row).toBeVisible({ timeout: 60000 });
    await row.click();
    const panel = page.locator("#sspa-adhoc-pop");
    await expect(panel).toBeVisible();
    await panel.locator(".sspa-adhoc-rerun").click();
    await expect(panel.locator(".sspa-adhoc-badge.is-fresh")).toContainText("Fresh result", { timeout: 300000 });
    pass("the page was analysed again from its Pages row");

    // The dialog, opened from the action bar beside Copy Markdown.
    const opener = panel.locator('.sspa-adhoc-topbar .sspa-quote-open[data-source="page-analysis"]');
    await expect(opener).toBeVisible();
    await opener.click();
    const dialog = page.locator(".sspa-quote-dialog");
    await expect(dialog).toBeVisible({ timeout: 60000 });
    await expect(dialog).toHaveAttribute("role", "dialog");
    const preview = dialog.locator("#sspa-quote-markdown");
    const markdown = await preview.inputValue();
    fs.writeFileSync(output + "/previewed.md", markdown);
    if (!markdown.startsWith("<!-- sspa/llm-report@") || !markdown.includes("# Page performance analysis")) throw Error("the preview is not the page Markdown report");
    await expect(preview).toHaveAttribute("readonly", "");
    for (const name of ["ongoing_care", "marketing_opt_in", "consent"]) {
      await expect(dialog.locator('input[name="' + name + '"]')).not.toBeChecked();
    }
    await expect(dialog.locator(".sspa-quote-consent a").first()).toHaveAttribute("href", "https://www.superspeedyplugins.com/privacy-policy/#performance-review-requests");
    await expect(dialog.locator('input[name="email"]')).not.toHaveValue("");
    await shot(page, "01-dialog-preview");
    pass("the dialog shows the full " + markdown.length + "-character page Markdown read-only, with every box unticked");

    // Send without consent: refused in the dialog, and nothing is requested from the receiver.
    const before = quoteRequests();
    await dialog.locator('textarea[name="message"]').fill("Journey: please quote for the home page.");
    await dialog.locator(".sspa-quote-send").click();
    await expect(dialog.locator(".sspa-quote-status")).toContainText("Tick the consent box");
    await expect(dialog.locator('input[name="consent"]')).toBeFocused();
    await page.waitForTimeout(1500);
    if (quoteRequests() !== before) throw Error("a request was made without the consent tick");
    await shot(page, "02-send-without-consent");
    pass("Send without the consent tick is refused and nothing is requested");

    // Tick, send, read the reference.
    await dialog.locator('input[name="consent"]').check();
    await dialog.locator(".sspa-quote-send").click();
    await expect(dialog.locator(".sspa-quote-status")).toContainText(/Sent\. Your reference is SSPQ-[A-Z0-9]+/, { timeout: 120000 });
    const reference = await dialog.getAttribute("data-reference");
    if (quoteRequests() !== before + 1) throw Error("expected exactly one request to the receiver");
    await shot(page, "03-sent-reference");
    pass("with consent one request is sent and the dialog shows the reference " + reference);

    // What the receiver stored is what the dialog showed, byte for byte.
    const stored = Buffer.from(
      receiver('foreach (get_posts(array("post_type" => "ssp_contact_lead", "post_status" => "any", "numberposts" => 1, "meta_key" => "_sspc_reference", "meta_value" => ' + JSON.stringify(reference) + ')) as $p) { echo base64_encode(get_post_meta($p->ID, "_sspc_markdown", true)); }'),
      "base64",
    ).toString("utf8");
    fs.writeFileSync(output + "/received.md", stored);
    if (stored !== markdown) throw Error("the receiver stored " + stored.length + " characters, not the " + markdown.length + " previewed");
    const source = receiver('foreach (get_posts(array("post_type" => "ssp_contact_lead", "post_status" => "any", "numberposts" => 1, "meta_key" => "_sspc_reference", "meta_value" => ' + JSON.stringify(reference) + ')) as $p) { echo get_post_meta($p->ID, "_sspc_source", true) . " " . get_post_meta($p->ID, "_sspc_message", true); }');
    if (source !== "page-analysis Journey: please quote for the home page.") throw Error("unexpected stored source and message: " + source);
    pass("the receiver stored exactly the previewed Markdown under " + reference + ", source page-analysis");

    // Escape closes the dialog only; the page analysis stays open underneath.
    await dialog.locator(".sspa-quote-close").focus();
    await page.keyboard.press("Escape");
    await expect(dialog).toHaveCount(0);
    await expect(panel).toBeVisible();
    pass("Escape closes the dialog and leaves the page analysis open");

    // At phone width the dialog fits, and Cancel sends nothing.
    await page.setViewportSize({ width: 390, height: 844 });
    const beforeCancel = quoteRequests();
    await panel.locator('.sspa-adhoc-topbar .sspa-quote-open[data-source="page-analysis"]').click();
    await expect(dialog).toBeVisible({ timeout: 60000 });
    const box = await dialog.boundingBox();
    if (!box || box.x < 0 || box.x + box.width > 390) throw Error("the dialog does not fit a 390 px screen: " + JSON.stringify(box));
    await shot(page, "04-dialog-390");
    await dialog.locator(".sspa-quote-close").click();
    await expect(dialog).toHaveCount(0);
    if (quoteRequests() !== beforeCancel) throw Error("Cancel sent a request");
    pass("at 390 px the dialog fits the screen, and Cancel sends nothing");
  } finally {
    await closeBrowser(browser);
  }
})().then(() => process.exit(0), (error) => { console.error("FAIL: " + error.message); process.exit(1); });
