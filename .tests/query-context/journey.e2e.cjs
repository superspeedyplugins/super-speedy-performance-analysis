// Where each slow query came from, in the page panel (plan .docs/2026-11-24-slowest-query-context.md).
// After assert-query-context.php on tests-query-context: the newest Home measurement is opened from
// the Pages tab as an administrator does, the footer query's row is expanded, and Analyse rows is
// clicked for real. Evidence (text of the expanded row, screenshots) goes to SSPA_FLEET_OUTPUT.
const { fs, json, output, session, admin, response, expect, closeBrowser } = require("../browser/fleet-context.cjs");

(async () => {
  const fixture = json(`
    $id = SSPA_Profile_Panel::newest_profile_id_for_page('home');
    $capture = SSPA_Profile_Panel::capture(SSPA_Profile_Panel::profile_row($id));
    $events = array();
    foreach ((array) $capture['sql']['queries'] as $q) {
      foreach (array('footer' => 'SLEEP(0.15)', 'write' => 'SLEEP(0.06)') as $name => $marker) {
        if (!isset($events[$name]) && false !== strpos((string) $q['sql'], $marker)) { $events[$name] = $q['event_id']; }
      }
    }
    $fixture = WPMU_PLUGIN_DIR . '/sspa-qc-fixture.php';
    $line = 0;
    foreach (file($fixture) as $n => $text) { if (false !== strpos($text, '$posts = new WP_Query(array(')) { $line = $n + 1; break; } }
    echo wp_json_encode(array('profile' => $id, 'events' => $events, 'line' => $line));
  `);
  let failed = false;
  const { browser, page } = await session();
  const errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  try {
    if (!fixture.events.footer) throw Error("no retained footer query in profile " + fixture.profile + "; run the case first");
    await admin(page, "pages");
    const pagesRow = page.locator(`.sspa-page-row[data-profile-id="${fixture.profile}"]`).first();
    await expect(pagesRow).toBeVisible({ timeout: 30000 });
    await pagesRow.click();
    const panel = page.locator("#sspa-adhoc-pop");
    await expect(panel).toBeVisible();
    const row = panel.locator(`tr.sspa-adhoc-qrow[data-event="${fixture.events.footer}"]`);
    await row.scrollIntoViewIfNeeded();
    const details = row.locator("details.sspa-adhoc-qctx");
    await expect(details.locator("ul")).toBeHidden();
    console.log("PASS the footer query row is collapsed until asked");
    // Opening the block reads it; it must not copy the query the way a row click does.
    await page.evaluate(() => navigator.clipboard.writeText("clipboard sentinel"));
    await details.locator("summary").click();
    await expect(details.locator("ul")).toBeVisible();
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe("clipboard sentinel");
    console.log("PASS Where it came from expands, and expanding it copies nothing");
    for (const text of [
      "Shortcode: [sspa_qc_footer_posts] registered by mu:sspa-qc-fixture",
      "Hook path: get_footer > [sspa_qc_footer_posts] (innermost meaningful hook: get_footer)",
      "Template and area: wp-content/themes/sspa-qc-theme/footer.php (footer)",
      "posts_per_page 10 (set by posts_per_page)",
      "tax_query category by slug IN (1 term)",
      "SQL_CALC_FOUND_ROWS: yes, total",
      "spro_fixture_flag = set-by-fixture",
      `wp-content/mu-plugins/sspa-qc-fixture.php:${fixture.line} WP_Query::__construct`,
      "wp-content/themes/sspa-qc-theme/footer.php:5 do_shortcode",
    ]) {
      await expect(details).toContainText(text);
    }
    console.log("PASS the expanded row shows shortcode, hook path, template and area, WP_Query facts, found rows, spro vars and frames with lines");
    fs.writeFileSync(output + "/expanded-footer-row.txt", await row.innerText());
    await row.screenshot({ path: output + "/expanded-footer-row.png" });

    const writeRow = panel.locator(`tr.sspa-adhoc-qrow[data-event="${fixture.events.write}"]`);
    await expect(writeRow).toHaveCount(1);
    await expect(writeRow.locator(".sspa-adhoc-analyse")).toHaveCount(0);
    console.log("PASS the retained UPDATE offers no Analyse rows button");

    const analysed = response(page, "sspa_analyse_rows");
    await details.getByRole("button", { name: "Analyse rows" }).click();
    const reply = await (await analysed).json();
    expect(reply.success).toBe(true);
    const result = details.locator(".sspa-adhoc-analyse-result");
    await expect(result).toContainText(reply.data.method + ": the query took");
    await expect(result.locator(".sspa-adhoc-analyse-table")).toContainText("wp_posts");
    await expect(result.locator(".sspa-adhoc-analyse-table")).toContainText("wp_term_relationships");
    console.log("PASS Analyse rows ran " + reply.data.method + " (" + reply.data.total_ms + " ms) and shows rows read per table");
    fs.writeFileSync(output + "/analyse-rows-click.json", JSON.stringify(reply, null, 2));
    await row.screenshot({ path: output + "/analysed-footer-row.png" });
    fs.writeFileSync(output + "/analysed-footer-row.txt", await row.innerText());
    expect(errors).toEqual([]);
    console.log("PASS no JavaScript errors");
  } catch (error) {
    failed = true;
    console.log("FAIL " + error.message);
    await page.screenshot({ path: output + "/journey-failure.png", fullPage: true }).catch(() => {});
  } finally {
    await closeBrowser(browser);
  }
  process.exit(failed ? 1 : 0);
})();
