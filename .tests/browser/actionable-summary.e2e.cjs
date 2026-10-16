const net = require('node:net');
const { fs, target, site, wp, json, output, login, admin, response, state, expect } = require('./fleet-context.cjs');
(async () => {
  const sockets = new Set();
  const reset = net.createServer(socket => {
    sockets.add(socket);
    socket.on('error', () => {});
    socket.on('close', () => sockets.delete(socket));
    socket.once('data', () => socket.resetAndDestroy());
  });
  let browser;
  let page;
  let run = 0;
  let primaryError = null;
  function setOption(name, value) {
    wp('eval', `update_option(${JSON.stringify(name)}, ${JSON.stringify(String(value))}, true); if ((string)get_option(${JSON.stringify(name)}) !== ${JSON.stringify(String(value))}) throw new RuntimeException('Fixture option did not persist');`);
  }
  try {
    await new Promise((resolve, reject) => { reset.once('error', reject); reset.listen(0, '127.0.0.1', resolve); });
    browser = await require('@playwright/test').chromium.launch();
    const context = await browser.newContext({viewport:{width:1280,height:900}, reducedMotion:'reduce', permissions:['clipboard-read','clipboard-write']});
    page = await login(context);
    wp('eval', `copy(SSPA_PLUGIN_DIR . '.tests/fixtures/summary-loopback-reset.php', WPMU_PLUGIN_DIR . '/sspa-summary-loopback-reset.php'); update_option('sspa_summary_reset_port', '0', true); update_option('sspa_summary_browser_deny', '0', true);`);
    await page.goto(site + '/summary-fixture/');
    await page.$eval('#wp-admin-bar-sspa-adhoc > a', a => a.click());
    const panel = page.locator('#sspa-adhoc-pop');
    await expect(panel.locator('.sspa-page-summary')).toContainText('PHP or waiting during page render');
    await expect(panel.locator('.sspa-page-summary')).toContainText('Unattributed:');
    for (const width of [1280, 320]) {
      await page.setViewportSize({ width, height: 900 });
      const summary = panel.locator('.sspa-page-summary');
      expect(await summary.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
      expect(await summary.locator('.sspa-adhoc-measure').evaluate(el => el.getBoundingClientRect().bottom < window.innerHeight - 70)).toBe(true);
      await page.screenshot({ path: `${output}/summary-${width}.png`, fullPage: true });
    }
    await page.setViewportSize({ width: 1280, height: 900 });
    await panel.locator('.sspa-page-summary > details > summary').click();
    await expect(panel.locator('.sspa-page-summary')).toContainText('plugins reading $http_response_header may behave differently');
    const streamStarted = response(page, 'sspa_adhoc_start');
    await panel.locator('.sspa-time-raw-streams').click();
    const streamReply = await (await streamStarted).json();
    expect(streamReply.success).toBe(true);
    await expect(panel.locator('.sspa-page-summary')).toContainText('Opt-in HTTP/HTTPS stream wrappers ran', {timeout:90000});
    await panel.locator('.sspa-page-summary > details > summary').click();
    await expect(panel.locator('.sspa-page-summary')).toContainText('summary-slow-endpoint.php');
    fs.writeFileSync(output + '/raw-stream-pass.json', JSON.stringify({run:streamReply.data.run_id, state:state(streamReply.data.run_id)}, null, 2));
    await panel.locator('.sspa-adhoc-measure').click();
    await expect(panel.locator('.sspa-adhoc-pluginpick:checked')).not.toHaveCount(0);
    await panel.locator('[data-pick="none"]').click();
    await panel.locator('.sspa-adhoc-pluginpick[value="sspa-render-fixture"]').check();
    const cache = panel.locator('#sspa-adhoc-cachemodes');
    await expect(cache).toBeVisible();
    await cache.check();
    setOption('sspa_summary_reset_port', reset.address().port);
    const probe = await new Promise((resolve, reject) => require('node:child_process').execFile(process.env.SSPA_TEST_REAL_WP || 'wp', ['eval', 'echo wp_json_encode(SSPA_Run_Controller::loopback_preflight());', '--path=' + target, '--url=' + site], {timeout: 30000}, (error, stdout) => error ? reject(error) : resolve(JSON.parse(stdout))));
    fs.writeFileSync(output + '/loopback-probe.json', JSON.stringify(probe, null, 2));
    expect(probe.healthy).toBe(false);
    expect(probe.reason).toContain('cURL error 35');
    const plugins = json('echo wp_json_encode(get_option("active_plugins"));');
    const dropin = json('echo wp_json_encode(hash_file("sha256", WP_CONTENT_DIR . "/db.php"));');
    const started = response(page, 'sspa_start_run');
    await panel.locator('.sspa-adhoc-measure-start').click();
    const reply = await (await started).json();
    fs.writeFileSync(output + '/deep-start.json', JSON.stringify(reply, null, 2));
    expect(reply.success).toBe(true);
    run = reply.data.run_id;
    expect(state(run).transport).toBe('browser');
    await expect.poll(() => state(run).status, { timeout: 240000 }).toBe('done');
    const evidence = json(`global $wpdb; $r = $wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE run_id=%d', SSPA_Schema::table('profiles'), ${run}), ARRAY_A); foreach ($r as &$p) unset($p['profile_blob']); echo wp_json_encode(array('profiles'=>$r,'impacts'=>SSPA_Report::build(${run})['impacts']));`);
    fs.writeFileSync(output + '/deep-evidence.json', JSON.stringify(evidence, null, 2));
    expect(evidence.profiles.length).toBeGreaterThanOrEqual(2);
    expect(evidence.profiles.every(p => p.url === site + '/summary-fixture/')).toBe(true);
    expect(evidence.profiles.some(p => p.plugin_set_hash !== '')).toBe(true);
    expect(evidence.profiles.some(p => p.object_cache_mode === 'disabled')).toBe(true);
    expect(evidence.profiles.some(p => p.object_cache_mode === 'prime')).toBe(true);
    expect(evidence.profiles.every(p => p.response_code === '200' && !p.blocked_by)).toBe(true);
    expect(json('echo wp_json_encode(get_option("active_plugins"));')).toEqual(plugins);
    expect(json('echo wp_json_encode(hash_file("sha256", WP_CONTENT_DIR . "/db.php"));')).toEqual(dropin);
    // The site-wide entry point offers the same single-page planner.
    const chooserPage = await context.newPage();
    await admin(chooserPage);
    await chooserPage.locator('#sspa-run-deep').click();
    await chooserPage.locator('.sspa-impact-only-url').fill(site + '/summary-fixture/');
    const planned = response(chooserPage, 'sspa_impact_plan');
    await chooserPage.locator('.sspa-impact-only-page').click();
    const pagePlan = await (await planned).json();
    expect(pagePlan.success).toBe(true);
    expect(pagePlan.data.scope).toBe('page');
    expect(pagePlan.data.url).toBe(site + '/summary-fixture/');
    expect(pagePlan.data.pages).toBe(1);
    await expect(chooserPage.locator('.sspa-adhoc-pluginpick:checked')).not.toHaveCount(0);
    fs.writeFileSync(output + '/site-chooser-page-plan.json', JSON.stringify(pagePlan.data, null, 2));
    await chooserPage.close();
    // A working browser does not broaden the unverified run types.
    for (const type of ['deep', 'cache_impact', 'checkout']) {
      const denied = await page.evaluate(async type => {
        const data = new URLSearchParams({ action: type === 'checkout' ? 'sspa_checkout_start' : 'sspa_start_run', nonce: sspa_adhoc.nonce, type });
        return (await fetch(sspa_adhoc.ajaxurl, {method:'POST', body:data})).json();
      }, type);
      if (denied.success && denied.data.run_id) wp('eval', `SSPA_Run_Controller::cancel(${denied.data.run_id});`);
      expect(denied.success).toBe(false);
      expect(denied.data).toContain('needs working loopback');
    }
    setOption('sspa_summary_browser_deny', 1);
    const failed = await page.evaluate(async url => {
      const data = new URLSearchParams({action:'sspa_start_run', nonce:sspa_adhoc.nonce, type:'deep', url, cache_modes:'0', 'suspects[]':'sspa-render-fixture'});
      const started = await (await fetch(sspa_adhoc.ajaxurl, {method:'POST', body:data})).json();
      if (!started.success) throw Error(JSON.stringify(started));
      const reason = await new Promise(resolve => SSPATransport.drive({ajaxurl:sspa_adhoc.ajaxurl, nonce:sspa_adhoc.nonce, runId:started.data.run_id, onDone:() => resolve('unexpected completion'), onFail:resolve}));
      return {run:started.data.run_id, reason};
    }, site + '/summary-fixture/');
    expect(state(failed.run).status).toBe('failed');
    expect(failed.reason).toContain('HTTP 403');
    expect(json(`global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE run_id=%d AND plugin_set_hash<>''", SSPA_Schema::table('profiles'), ${failed.run}));`)).toBe(0);
    fs.writeFileSync(output + '/blocked-browser-baseline.json', JSON.stringify(failed, null, 2));
    setOption('sspa_summary_browser_deny', 0);
    await page.screenshot({ path: output + '/deep-complete.png', fullPage: true });
    console.log('PASS page summary, narrow-screen coverage, reviewed suspects and real TLS-reset browser sweep');
  } catch (error) {
    primaryError = error;
    try { if (page) await page.screenshot({path:output + '/failure.png', fullPage:true}); } catch (screenshotError) { console.error('Failure screenshot unavailable', screenshotError); }
    throw error;
  } finally {
    const cleanupErrors = [];
    const deadline = setTimeout(() => { console.error('FAIL summary cleanup exceeded 15 seconds'); process.exit(1); }, 15000);
    deadline.unref();
    for (const cleanup of [
      () => setOption('sspa_summary_reset_port', 0),
      () => setOption('sspa_summary_browser_deny', 0),
      () => { if (run && state(run).status !== 'done') wp('eval', `SSPA_Run_Controller::cancel(${run});`); },
    ]) {
      try { cleanup(); } catch (error) { cleanupErrors.push(error); }
    }
    for (const socket of sockets) socket.destroy();
    const closed = await Promise.allSettled([browser ? browser.close() : Promise.resolve(), reset.listening ? new Promise((resolve, reject) => reset.close(error => error ? reject(error) : resolve())) : Promise.resolve()]);
    for (const result of closed) if (result.status === 'rejected') cleanupErrors.push(result.reason);
    clearTimeout(deadline);
    fs.writeFileSync(output + '/resources-closed.json', JSON.stringify({browserClosed: !browser || !browser.isConnected(), listenerClosed: !reset.listening, sockets: sockets.size}, null, 2));
    if (cleanupErrors.length) {
      console.error('FAIL summary cleanup', cleanupErrors);
      if (!primaryError) throw new AggregateError(cleanupErrors, 'Summary cleanup failed');
    }
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
