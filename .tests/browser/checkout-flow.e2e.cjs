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
  try {
    for (const kind of ["classic", "block"]) {
      wp(
        "eval",
        `wp_update_post(array('ID'=>(int)get_option('woocommerce_checkout_page_id'),'post_content'=>${JSON.stringify(kind === "block" ? "<!-- wp:woocommerce/checkout /-->" : "[woocommerce_checkout]")}));wp_cache_flush();`,
      );
      const orders = () =>
        json(
          `echo wp_json_encode(count(wc_get_orders(array('limit'=>-1,'return'=>'ids'))));`,
        );
      const before = orders();
      const beforeIds = json(
        `global $wpdb;echo wp_json_encode($wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders WHERE type='shop_order'"));`,
      );
      await admin(page, "workflows");
      const entry = page.locator(".sspa-ck-open").first();
      await expect(entry).toBeVisible();
      await entry.click();
      const panel = page.locator("#sspa-adhoc-pop");
      await expect(
        panel
          .getByRole("button", { name: "Run again", exact: true })
          .or(panel.locator(".sspa-ck-go")),
      ).toBeVisible();
      if (
        await panel
          .getByRole("button", { name: "Run again", exact: true })
          .count()
      )
        await panel
          .getByRole("button", { name: "Run again", exact: true })
          .click();
      await expect(panel.locator(".sspa-ck-go")).toBeVisible();
      await expect(panel).toContainText(
        kind === "block" ? "block" : "shortcode",
      );
      expect(orders()).toBe(before);
      await expect(
        panel.locator('[name="sspa-ck-pm"][value="no_payment"]'),
      ).toBeChecked();
      await panel.locator(".sspa-ck-webhooks").uncheck();
      await panel.locator(".sspa-ck-integrations").uncheck();
      await expect(panel.locator(".sspa-ck-mail")).toBeChecked();
      await page.screenshot({
        path: output + "/checkout-" + kind + "-consent.png",
        fullPage: true,
      });
      const started = response(page, "sspa_checkout_start");
      await panel.locator(".sspa-ck-go").click();
      const reply = await (await started).json();
      expect(reply.success).toBe(true);
      const id = reply.data.run_id;
      await expect
        .poll(() => state(id).status, { timeout: 180000 })
        .toBe("done");
      await expect(panel.locator(".sspa-ck-grand")).toBeVisible();
      const data = json(
        `echo wp_json_encode(SSPA_Checkout_Flow::waterfall(${id}));`,
      );
      expect(data.total_ms).toBeGreaterThan(0);
      await expect(panel).toContainText("Your customer waited");
      const detail = panel.locator(".sspa-ck-step-toggle").first();
      await expect(detail).toBeVisible();
      await detail.click();
      await expect(
        panel.locator(".sspa-ck-step-detail:visible").first(),
      ).toBeVisible();
      const created = json(
        `global $wpdb;$ids=$wpdb->get_col("SELECT id FROM {$wpdb->prefix}wc_orders WHERE type='shop_order'");$before=json_decode('${JSON.stringify(beforeIds)}',true);$out=array();foreach(array_diff($ids,$before)as $id){$o=wc_get_order($id);$out[]=array('id'=>$id,'marker'=>(get_option('sspa_fleet_marked_orders',array())[$id]??''),'final_marker'=>$o->get_meta(SSPA_Checkout_Flow::TEMP_META),'status'=>$o->get_status());}echo wp_json_encode($out);`,
      );
      expect(created.length).toBeGreaterThan(0);
      for (const order of created) {
        expect(order.marker).toBe("1");
        expect(order.status).toBe("trash");
        expect(order.final_marker).toBe("");
      }

      await page.screenshot({
        path: output + "/checkout-" + kind + "-waterfall.png",
        fullPage: true,
      });
      await panel.locator(".sspa-adhoc-close").click();
      await expect(panel).toBeHidden();
      console.log(
        "PASS " +
          kind +
          " actual preflight, explicit no-payment selection, local SMTP, persisted synthetic order and expandable waterfall",
      );
    }
    wp(
      "eval",
      "wp_update_post(array('ID'=>(int)get_option('woocommerce_checkout_page_id'),'post_content'=>'[woocommerce_checkout]'));wp_cache_flush();",
    );
    const partial = json(
      "$actor=wp_insert_user(array('user_login'=>'sspa-departed-'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(32),'user_email'=>'departed-admin@example.invalid','role'=>'administrator'));if(is_wp_error($actor))WP_CLI::error($actor->get_error_message());$id=SSPA_Run_Controller::start(array('type'=>'checkout','user_id'=>$actor,'mail_mode'=>'suppress'));if(is_wp_error($id))WP_CLI::error($id->get_error_message());require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($actor);$deadline=time()+180;do{SSPA_Run_Controller::process_batch($id);$s=SSPA_Run_Controller::status($id);}while(in_array($s['status'],array('crawling','analysing'))&&time()<$deadline);echo wp_json_encode(array('id'=>$id,'status'=>$s['status']));",
    );
    expect(partial.status).toBe("done");
    await page.reload();
    await page.locator(".sspa-ck-open").first().click();
    await expect(page.locator("#sspa-adhoc-pop")).toContainText(
      "no admin session to view the order as",
    );
    const partialReason = page
      .getByText("no admin session to view the order as", { exact: false })
      .last();
    await partialReason.scrollIntoViewIfNeeded();
    await expect(partialReason).toBeInViewport();
    await page.screenshot({
      path: output + "/checkout-partial-management.png",
      fullPage: true,
    });
    console.log(
      "PASS real no-admin partial management discloses its visible reason",
    );
    await page.locator("#sspa-adhoc-pop .sspa-adhoc-close").click();
    wp(
      "eval",
      "wp_update_post(array('ID'=>(int)get_option('woocommerce_checkout_page_id'),'post_content'=>'Synthetic unsupported checkout'));wp_cache_flush();",
    );
    await admin(page, "workflows");
    await page.locator(".sspa-ck-open").first().click();
    const blocked = page.locator("#sspa-adhoc-pop");
    await expect(
      blocked.getByRole("button", { name: "Run again", exact: true }),
    ).toBeVisible();
    if (
      await blocked
        .getByRole("button", { name: "Run again", exact: true })
        .count()
    )
      await blocked
        .getByRole("button", { name: "Run again", exact: true })
        .click();
    await expect(blocked).toContainText("neither the checkout block");
    await expect(blocked.locator(".sspa-ck-go")).toHaveCount(0);
    await page.screenshot({
      path: output + "/checkout-unsupported-blocked.png",
      fullPage: true,
    });
  } finally {
    await page.screenshot({
      path: output + "/checkout-flow-final.png",
      fullPage: true,
    });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
