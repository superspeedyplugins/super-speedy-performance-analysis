const {
  fs,
  wp,
  json,
  output,
  session,
  admin,
  response,
  expect,
} = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  try {
    fs.writeFileSync(
      output + "/prior-outbox.json",
      JSON.stringify(
        json(
          "global $wpdb;$rows=$wpdb->get_results('SELECT * FROM '.SSPA_Schema::table('submission_outbox'),ARRAY_A);foreach($rows as &$row){$row['payload_gzip_base64']=base64_encode($row['payload_gzip']);unset($row['payload_gzip']);}echo wp_json_encode($rows);",
        ),
      ),
    );
    wp(
      "eval",
      `update_option('sspa_share_optin',0);update_option('sspa_fleet_receiver',1);update_option('sspa_fleet_receiver_log',array());global $wpdb;if(false===$wpdb->query('DELETE FROM '.SSPA_Schema::table('submission_outbox')))WP_CLI::error($wpdb->last_error);`,
    );
    const run = json("echo wp_json_encode(SSPA_Report::latest_done_run_id());");
    const refused = json(
      `$r=SSPA_Community_Outbox::queue_run(${run});echo wp_json_encode(is_wp_error($r)?$r->get_error_code():$r);`,
    );
    expect(refused).toBe("sspa_not_opted_in");
    await admin(page, "share");
    const panel = page.locator('.tab-contents[data-tab="share"]');
    await expect(panel.locator("#sspa-share-optin")).not.toBeChecked();
    await panel.locator('.sspa-preview-outbox[data-outbox-id="0"]').click();
    const pre = panel.locator(".sspa-payload-preview");
    await expect(pre).toBeVisible();
    await expect.poll(() => pre.innerText()).toMatch(/^\s*\{/);
    const shown = await pre.innerText();
    expect(JSON.parse(shown).run.run_uuid).toBe(
      json(
        `echo wp_json_encode(SSPA_Run_Controller::run_row(${run})['run_uuid']);`,
      ),
    );
    expect(shown).not.toMatch(/billing_email|request_body|session_token/);
    const download = page.waitForEvent("download");
    await panel.locator(".sspa-download-payload").click();
    await (await download).saveAs(output + "/sharing-preview.json");
    expect(fs.readFileSync(output + "/sharing-preview.json", "utf8")).toBe(
      shown,
    );
    expect(
      json(
        'echo wp_json_encode(get_option("sspa_fleet_receiver_log",array()));',
      ),
    ).toEqual([]);
    const consent = response(page, "sspa_share_optin");
    await panel.locator("#sspa-share-optin").check();
    expect((await (await consent).json()).success).toBe(true);
    await page.reload();
    await page.waitForLoadState("networkidle");
    await expect(panel.locator("#sspa-share-optin")).toBeChecked();
    const queued = json(
      `echo wp_json_encode(SSPA_Community_Outbox::queue_run(${run}));`,
    );
    expect(Number(queued.run_id)).toBe(run);
    const revoke = response(page, "sspa_share_optin");
    await panel.locator("#sspa-share-optin").uncheck();
    expect((await (await revoke).json()).success).toBe(true);
    await page.reload();
    await expect(panel.locator("#sspa-share-optin")).not.toBeChecked();
    wp("eval", "SSPA_Community_Worker::run();");
    expect(
      json(
        'echo wp_json_encode(get_option("sspa_fleet_receiver_log",array()));',
      ),
    ).toEqual([]);
    const allow = response(page, "sspa_share_optin");
    await panel.locator("#sspa-share-optin").check();
    expect((await (await allow).json()).success).toBe(true);
    await page.reload();
    await expect(panel.locator("#sspa-share-optin")).toBeChecked();
    wp("eval", "SSPA_Community_Worker::run();");
    const requests = json(
      'echo wp_json_encode(get_option("sspa_fleet_receiver_log",array()));',
    );
    const reservation = requests.find((x) => x.payload?.run_uuid);
    expect(reservation.payload.run_uuid).toBe(queued.run_uuid);
    expect(reservation.payload.payload_sha256).toBe(queued.payload_sha256);
    expect(requests.filter((x) => x.payload?.run_uuid)).toHaveLength(1);
    const stored = json(
      `$r=SSPA_Community_Outbox::get(${queued.id});echo wp_json_encode(array('state'=>$r['state'],'http'=>(int)$r['last_http_status'],'sha'=>hash('sha256',$r['payload_gzip']),'attempts'=>(int)$r['attempts']));`,
    );
    expect(stored.state).toBe("retry");
    expect(stored.http).toBe(503);
    expect(stored.sha).toBe(queued.payload_sha256);
    expect(stored.attempts).toBe(1);
    const revokeAgain = response(page, "sspa_share_optin");
    await panel.locator("#sspa-share-optin").uncheck();
    expect((await (await revokeAgain).json()).success).toBe(true);
    await page.reload();
    await expect(panel.locator("#sspa-share-optin")).not.toBeChecked();
    wp(
      "eval",
      `global $wpdb;$wpdb->update(SSPA_Schema::table('submission_outbox'),array('next_attempt'=>gmdate('Y-m-d H:i:s')),array('id'=>${queued.id}));SSPA_Community_Worker::run();`,
    );
    expect(
      json(
        'echo wp_json_encode(get_option("sspa_fleet_receiver_log",array()));',
      ),
    ).toEqual(requests);
    await page.screenshot({
      path: output + "/sharing-revoked.png",
      fullPage: true,
    });
    console.log(
      "PASS exact preview/download, zero opted-out queue, real consent/revocation, approved local reservation and retained refused delivery",
    );
  } finally {
    wp("option", "update", "sspa_share_optin", "0");
    await page.screenshot({
      path: output + "/sharing-final.png",
      fullPage: true,
    });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
