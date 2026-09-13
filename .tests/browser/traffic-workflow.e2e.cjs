const browserRequest = (page, url, form) =>
  page.evaluate(
    async ({ url, form }) => {
      const response = await fetch(
        url,
        form
          ? {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: new URLSearchParams(form),
            }
          : {},
      );
      return { status: response.status, data: await response.json() };
    },
    { url, form },
  );
const {
  fs,
  wp,
  json,
  site,
  output,
  login,
  session,
  admin,
  response,
  expect,
} = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  const guest = await browser.newContext();
  const visitor = await guest.newPage();
  try {
    wp(
      "eval",
      `$active=SSPA_Traffic_Collection::active();if($active)SSPA_Traffic_Collection::stop($active['id'],true);update_option('sspa_share_optin',0);update_option('sspa_fleet_receiver',1);update_option('sspa_fleet_receiver_log',array());global $wpdb;$wpdb->query("UPDATE ".SSPA_Schema::table('submission_outbox')." SET state='paused' WHERE state IN ('pending','retry')");`,
    );
    await admin(page, "traffic");
    const count = () =>
      json(
        `global $wpdb;echo wp_json_encode((int)$wpdb->get_var('SELECT COUNT(*) FROM '.SSPA_Schema::table('traffic_collections')));`,
      );
    const before = count();
    page.once("dialog", (d) => d.accept());
    await page.locator("#sspa-traffic-start").click();
    expect(count()).toBe(before);
    await page.locator("#sspa-traffic-confirm").check();
    await page.locator("#sspa-traffic-duration").selectOption("1h");
    const start = response(page, "sspa_traffic_start");
    await page.locator("#sspa-traffic-start").click();
    expect((await (await start).json()).success).toBe(true);
    const active = json(
      "echo wp_json_encode(SSPA_Traffic_Collection::active());",
    );
    expect(Number(active.id)).toBeGreaterThan(0);
    await page.waitForTimeout(3000);
    const product = json(
      `$ids=wc_get_products(array('limit'=>1,'return'=>'ids','status'=>'publish'));echo wp_json_encode(array('id'=>$ids[0],'url'=>get_permalink($ids[0])));`,
    );
    await visitor.goto(product.url);
    await visitor.goto(site + "/?add-to-cart=" + product.id);
    const cart = await visitor.evaluate(async () => {
      const r = await fetch("/?wc-ajax=get_refreshed_fragments", {
        method: "POST",
      });
      return { code: r.status, data: await r.json() };
    });
    expect(cart.code).toBe(200);
    expect(cart.data.fragments).toBeTruthy();
    const rest = await browserRequest(visitor, "/wp-json/wc/store/v1/cart");
    expect(rest.status).toBe(200);
    expect(rest.data.items.length).toBeGreaterThan(0);
    const memberContext = await browser.newContext();
    const member = await login(
      memberContext,
      "sspa-browser-customer",
      "Synthetic-local-customer-76!",
    );
    await member.goto(product.url);
    await member.goto(site + "/?add-to-cart=" + product.id);
    const memberCart = await browserRequest(
      member,
      "/wp-json/wc/store/v1/cart",
    );
    expect(memberCart.status).toBe(200);
    expect(memberCart.data.items.length).toBeGreaterThan(0);
    page.once("dialog", (d) => d.accept());
    const stopped = response(page, "sspa_traffic_stop");
    await page.locator("#sspa-traffic-emergency-stop").click();
    expect((await (await stopped).json()).success).toBe(true);
    await page.waitForTimeout(3000);
    const events = () =>
      json(
        `global $wpdb;echo wp_json_encode((int)$wpdb->get_var('SELECT COUNT(*) FROM '.SSPA_Schema::table('traffic_events').' WHERE collection_id=${active.id}'));`,
      );
    const recorded = events();
    expect(recorded).toBeGreaterThanOrEqual(3);
    await visitor.goto(product.url);
    expect(events()).toBe(recorded);
    const download = page.waitForEvent("download");
    await page.locator("#sspa-traffic-observations").click();
    await (await download).saveAs(output + "/traffic-observations.json");
    const text = fs.readFileSync(output + "/traffic-observations.json", "utf8");
    const observations = JSON.parse(text);
    expect(observations.schema).toBeTruthy();
    expect(text).not.toMatch(
      /user_email|billing_email|session_token|request_body/,
    );
    await page.screenshot({
      path: output + "/traffic-stopped.png",
      fullPage: true,
    });
    const windows = [];
    for (const keepSlow of [true, false]) {
      wp(
        "eval",
        `activate_plugin('zz-ajax-owner/fixture.php');activate_plugin('zz-ajax-slow/fixture.php');$o=(array)get_option('wpiperf_settings');$o['unload_feature_enabled']=1;$o['ajax_unloads_beta_enabled']=1;$o['ajax_unloads_enabled']=1;update_option('wpiperf_settings',$o);$r=SPRO_Fast_Ajax::save_choices(array(array('transport'=>'admin_ajax','endpoint'=>'zz_ajax_workflow_fixture','method'=>'POST','context'=>'anon','enabled'=>true,'keep_plugins'=>array('zz-ajax-owner/fixture.php'${keepSlow ? ",'zz-ajax-slow/fixture.php'" : ""}),'review_selection'=>true,'acknowledge_write'=>true)));if(is_wp_error($r))WP_CLI::error($r->get_error_message());SPRO_Unload::write_policy();`,
      );
      await admin(page, "ajax");
      await page
        .locator(".sspa-ajax-start [name=label]")
        .fill(keepSlow ? "Browser before" : "Browser after");
      await page
        .locator(".sspa-ajax-start [name=scenario]")
        .fill("Synthetic cart workflow");
      await Promise.all([
        page.waitForNavigation(),
        page.locator(".sspa-ajax-start button").click(),
      ]);
      const window = json(
        "$windows=SSPA_Ajax_Profile::windows();echo wp_json_encode(end($windows));",
      );
      windows.push(window.uuid);
      await page.waitForTimeout(3000);
      for (let i = 0; i < 3; i++) {
        const r = await browserRequest(visitor, "/wp-admin/admin-ajax.php", {
          action: "zz_ajax_workflow_fixture",
        });
        expect(r.status).toBe(200);
        expect(r.data.data.observed).toBe(true);
      }
      await browserRequest(visitor, "/wp-json/wc/store/v1/cart");
      await Promise.all([
        page.waitForNavigation(),
        page.locator(`.sspa-ajax-stop[data-uuid="${window.uuid}"]`).click(),
      ]);
    }
    await page
      .locator(".sspa-ajax-compare [name=before]")
      .selectOption(windows[0]);
    await page
      .locator(".sspa-ajax-compare [name=after]")
      .selectOption(windows[1]);
    await page.locator(".sspa-ajax-compare button").click();
    await expect(page.locator(".sspa-ajax-chart canvas").first()).toBeVisible();
    const compared = json(
      `echo wp_json_encode(SSPA_Ajax_Profile::compare('${windows[0]}','${windows[1]}'));`,
    );
    const endpoint = compared.pages.find((p) =>
      p.label.includes("zz_ajax_workflow_fixture"),
    );
    expect(endpoint.previous.samples).toBe(3);
    expect(endpoint.current.samples).toBe(3);
    expect(endpoint.delta.absolute).toBeLessThan(-80);
    expect(
      endpoint.previous.points[0].evidence.setup.spro.policy_fingerprint,
    ).not.toBe(
      endpoint.current.points[0].evidence.setup.spro.policy_fingerprint,
    );
    expect(
      json(
        'echo wp_json_encode(get_option("sspa_fleet_receiver_log",array()));',
      ),
    ).toEqual([]);
    await page.screenshot({
      path: output + "/ajax-browser-comparison.png",
      fullPage: true,
    });
    console.log(
      "PASS real collection consent/window, guest basket+AJAX/REST, retained privacy export and browser-recorded SPro configuration comparison",
    );
  } finally {
    await page.screenshot({
      path: output + "/traffic-workflow-final.png",
      fullPage: true,
    });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
