// Copy Markdown survives a slow export, and every failure says what actually failed (0.70.6).
//
// A customer's page capture took longer than Chromium's five-second user-activation window to
// export. The click posted sspa_markdown_export and only called navigator.clipboard.writeText()
// when the answer arrived, by which time the activation had expired: writeText rejected and the
// screen said "The Markdown report could not be exported." although the export had succeeded.
//
// Environment: the test-only MU plugin .tests/fixtures/slow-markdown-export.php sleeps seven
// seconds before the REAL export handler (option sspa_test_slow_markdown_export = delay); the
// Markdown is the real handler's. The browser context grants only clipboard-write while
// copying, which is what a normal Chrome user has: writes allowed, gated by user activation.
// Granting clipboard-read as well lifts that gate in Chromium (measured; see the plan
// 2026-11-24-copy-markdown-after-slow-export.md), so it is granted only AFTER each copy, to read
// the clipboard back. With no grant at all headless Chromium refuses every write, which is the
// "browser refused" case below rather than an artefact in the main cases.
//
// Clipboard calls are observed (not replaced) by an init script that records, for each
// writeText/write call, whether user activation was still active and how the call settled.
const { chromium } = require("@playwright/test");
const { fs, site, wp, json, output, login, expect, closeBrowser } = require("./fleet-context.cjs");

const fixtures = JSON.parse(fs.readFileSync(process.env.SSPA_COPY_FIXTURES, "utf8"));
const results = [];
function check(ok, label) {
  results.push({ ok, label });
  console.log((ok ? "PASS: " : "FAIL: ") + label);
}
const generic = "The Markdown report could not be exported.";
const normalise = (md) => String(md || "").replace(/^- Generated: .*$/m, "- Generated: <time>");
const mode = (value) => wp("option", "update", "sspa_test_slow_markdown_export", value);

async function observeClipboard(context) {
  await context.addInitScript(() => {
    window.__sspaClipboardCalls = [];
    const clip = navigator.clipboard;
    if (!clip) return;
    for (const name of ["writeText", "write"]) {
      const original = clip[name] && clip[name].bind(clip);
      if (!original) continue;
      clip[name] = function () {
        const call = { name, activeAtCall: navigator.userActivation ? navigator.userActivation.isActive : null, at: Date.now() };
        window.__sspaClipboardCalls.push(call);
        const settled = original.apply(null, arguments);
        settled.then(() => { call.result = "ok"; }, (e) => { call.result = e.name + ": " + e.message; });
        return settled;
      };
    }
  });
}

async function newSession(browser, permissions) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, reducedMotion: "reduce", permissions });
  await observeClipboard(context);
  const page = await login(context);
  page.__dialogs = [];
  page.on("dialog", (dialog) => { page.__dialogs.push(dialog.message()); dialog.dismiss().catch(() => {}); });
  return { context, page };
}

// Leave a known value on the clipboard, then return to the normal-user grant.
async function seedClipboard(context, page, value) {
  await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await page.evaluate((v) => navigator.clipboard.writeText(v), value);
  await context.clearPermissions();
  await context.grantPermissions(["clipboard-write"]);
}
async function readClipboard(context, page) {
  await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  const text = await page.evaluate(() => navigator.clipboard.readText());
  await context.clearPermissions();
  await context.grantPermissions(["clipboard-write"]);
  return text;
}
// A fresh document every time: a hash-only goto would keep the previous panel open.
const screen = (page, tab) => page.goto(site + "/wp-admin/admin.php?page=sspa&sspa_test_load=" + Date.now() + "#" + tab);
const exportResponse = (page) =>
  page.waitForResponse((r) => r.url().includes("admin-ajax.php") && (r.request().postData() || "").includes("action=sspa_markdown_export"), { timeout: 60000 });

// Open the screen holding the button and return a locator scoped to the result's actions.
const targets = [
  { name: "page profile panel", kind: "page", id: fixtures.profile, open: async (page) => {
      await screen(page, "pages");
      await page.locator('.sspa-page-row[data-profile-id="' + fixtures.profile + '"]').first().click();
      const scope = page.locator("#sspa-adhoc-pop");
      await expect(scope.locator('.sspa-markdown-copy[data-kind="page"]')).toBeVisible({ timeout: 30000 });
      return scope;
    } },
  { name: "Overview run", kind: "run", id: fixtures.run, open: async (page) => {
      await screen(page, "overview");
      const scope = page.locator("#sspa-run-panel");
      await expect(scope.locator('.sspa-markdown-copy[data-kind="run"]')).toBeVisible();
      return scope;
    } },
  { name: "Compare row", kind: "run", id: fixtures.run, open: async (page) => {
      await screen(page, "compare");
      const button = page.locator('#sspa_main .tab-contents[data-tab="compare"] .sspa-markdown-copy[data-kind="run"][data-id="' + fixtures.run + '"]');
      await expect(button).toBeVisible({ timeout: 30000 });
      return button.locator("xpath=..");
    } },
  { name: "checkout panel", kind: "checkout", id: fixtures.checkout_run, open: async (page) => {
      await screen(page, "workflows");
      await page.locator(".sspa-ck-open").first().click();
      const scope = page.locator("#sspa-adhoc-pop");
      await expect(scope.locator('.sspa-markdown-copy[data-kind="checkout"]')).toBeVisible({ timeout: 30000 });
      return scope;
    } },
];

async function slowCopy(context, page, target) {
  const scope = await target.open(page);
  const copyButton = scope.locator('.sspa-markdown-copy[data-kind="' + target.kind + '"]').first();
  const sentinel = "sspa-sentinel-" + Date.now();
  await seedClipboard(context, page, sentinel);
  page.__dialogs.length = 0;
  await page.evaluate(() => { window.__sspaClipboardCalls.length = 0; });
  const started = Date.now();
  const answered = exportResponse(page);
  await copyButton.click();
  const response = await answered;
  const elapsed = Date.now() - started;
  const body = await response.json().catch(() => null);
  check(elapsed > 5500 && body && body.success && body.data && body.data.markdown, `${target.name}: the real export succeeded and took ${elapsed} ms, past the 5 s activation window`);
  // Settle: the status says Copied, a dialog opens, or an alert fires.
  await expect.poll(async () => page.__dialogs.length + (await scope.locator(".sspa-markdown-status").first().innerText().catch(() => "")).length
    + (await page.locator("#sspa-copy-manual").count()), { timeout: 20000 }).toBeGreaterThan(0);
  await page.waitForTimeout(300);
  const calls = await page.evaluate(() => window.__sspaClipboardCalls);
  const clipboard = await readClipboard(context, page);
  check(page.__dialogs.length === 0, `${target.name}: no alert (${JSON.stringify(page.__dialogs)}); clipboard calls ${JSON.stringify(calls)}`);
  check(await page.locator("#sspa-copy-manual").count() === 0, `${target.name}: no manual-copy dialog was needed`);
  check(normalise(clipboard) === normalise(body && body.data && body.data.markdown), `${target.name}: the clipboard holds the exported Markdown (${clipboard.length} chars, sentinel ${clipboard === sentinel ? "still there" : "replaced"})`);
  check(clipboard.includes("- Export type: `" + target.kind + "`") && clipboard.includes("- Result ID: `" + target.id + "`"), `${target.name}: the copied report is the ${target.kind} export of result ${target.id}`);
  const status = await scope.locator(".sspa-markdown-status").first().innerText().catch(() => "");
  check(status.includes("Copied"), `${target.name}: the status says ${JSON.stringify(status)}`);
  // Download after the same slow export still saves the file (anchor click after AJAX).
  const download = page.waitForEvent("download", { timeout: 60000 });
  await scope.locator('.sspa-markdown-download[data-kind="' + target.kind + '"]').first().click();
  const saved = output + "/" + target.name.replace(/\W+/g, "-") + ".md";
  await (await download).saveAs(saved);
  check(normalise(fs.readFileSync(saved, "utf8")) === normalise(body && body.data && body.data.markdown), `${target.name}: Download Markdown after the same 7 s export saves the same document`);
}

async function failure(page, label, prepare, expectText) {
  const scope = await targets[1].open(page);
  page.__dialogs.length = 0;
  await page.evaluate(() => { window.__sspaClipboardCalls.length = 0; });
  await prepare(page, scope);
  const answered = exportResponse(page);
  await scope.locator(".sspa-markdown-copy").first().click();
  const response = await answered;
  await expect.poll(() => page.__dialogs.length, { timeout: 15000 }).toBeGreaterThan(0);
  const message = page.__dialogs[0];
  check(message !== generic && expectText.every((t) => message.includes(t)), `${label}: HTTP ${response.status()} is reported as ${JSON.stringify(message)}`);
}

(async () => {
  const browser = await chromium.launch();
  try {
    mode("delay");
    {
      const { context, page } = await newSession(browser, ["clipboard-write"]);
      for (const target of targets) await slowCopy(context, page, target);
      await context.close();
    }

    mode("");
    {
      const { page } = await newSession(browser, ["clipboard-write"]);
      // A stale nonce: check_ajax_referer() answers -1 with HTTP 403.
      await failure(page, "expired security token", (p) => p.evaluate(() => { window.sspa_adhoc.nonce = "stale-nonce"; }), ["403", "Reload"]);
      // The server's own refusal (success:false) is shown as the server wrote it.
      const missing = json("$e = SSPA_Markdown_Export::build('page', 999999999); echo wp_json_encode(is_wp_error($e) ? $e->get_error_message() : '');");
      await failure(page, "server refusal", (p, scope) => scope.locator(".sspa-markdown-copy").first().evaluate((b) => { b.setAttribute("data-kind", "page"); b.setAttribute("data-id", "999999999"); }), [missing]);
      mode("fatal");
      await failure(page, "server crash", () => {}, ["500"]);
      mode("");
      await page.context().close();
    }

    // A browser that refuses every clipboard write: no clipboard-write grant (headless then
    // refuses both async writes) and a slow export (so the execCommand('copy') fallback runs
    // after the activation has expired and is refused too). The Markdown is shown to copy by hand.
    mode("delay");
    {
      const { context, page } = await newSession(browser, []);
      const scope = await targets[1].open(page);
      const answered = exportResponse(page);
      await scope.locator(".sspa-markdown-copy").first().click();
      const body = await (await answered).json();
      const manual = page.locator("#sspa-copy-manual");
      await expect.poll(async () => page.__dialogs.length + (await manual.count()), { timeout: 20000 }).toBeGreaterThan(0);
      const refusedCalls = await page.evaluate(() => window.__sspaClipboardCalls);
      check(page.__dialogs.length === 0, `clipboard refused: no alert (${JSON.stringify(page.__dialogs)}); clipboard calls ${JSON.stringify(refusedCalls)}`);
      const refusedStatus = await scope.locator(".sspa-markdown-status").first().innerText().catch(() => "");
      check(refusedStatus.includes("copy by hand"), `clipboard refused: the status says ${JSON.stringify(refusedStatus)}`);
      const shown = (await manual.count()) ? await manual.locator("textarea").inputValue() : "";
      check(normalise(shown) === normalise(body.data.markdown), `clipboard refused: the dialog shows the exported Markdown (${shown.length} chars)`);
      check((await manual.count()) > 0 && /Ctrl\+C/.test(await manual.innerText()), "clipboard refused: the dialog says to press Ctrl+C (Cmd+C)");
      if (await manual.count()) {
        const selected = await manual.locator("textarea").evaluate((t) => t.selectionEnd - t.selectionStart);
        check(selected === shown.length, `clipboard refused: the whole report is selected (${selected} of ${shown.length})`);
        await page.screenshot({ path: output + "/clipboard-refused-dialog.png" });
        await context.grantPermissions(["clipboard-write"]);
        await manual.getByRole("button", { name: "Copy", exact: true }).click();
        await expect(manual).toContainText("Copied");
        await context.grantPermissions(["clipboard-read", "clipboard-write"]);
        check(normalise(await page.evaluate(() => navigator.clipboard.readText())) === normalise(body.data.markdown), "clipboard refused: the dialog's own Copy button copies the report once the browser allows it");
        await manual.getByRole("button", { name: "Close" }).click();
        check((await manual.count()) === 0 || !(await manual.isVisible()), "clipboard refused: Close dismisses the dialog");
      }
      await context.close();
    }
  } catch (error) {
    check(false, "journey error: " + (error && error.stack || error));
  } finally {
    try { mode(""); } catch (e) { console.error("could not clear the slow-export option: " + e.message); }
    await closeBrowser(browser);
  }
  const failed = results.filter((r) => !r.ok).length;
  console.log(`Copy Markdown journey: ${results.length - failed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})();
