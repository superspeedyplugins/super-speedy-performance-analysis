const {wp, state, session, admin, response, expect} = require('./fleet-context.cjs');
(async () => {
  const {browser, page} = await session();
  let id = 0, record;
  page.on('request', request => {
    const data = new URLSearchParams(request.postData() || '');
    if (data.get('action') === 'sspa_browser_record') record = data;
  });
  try {
    await admin(page);
    wp('option', 'update', 'sspa_fleet_browser_transport', '1');
    id = await page.evaluate(async () => {
      const result = await jQuery.post(window.ajaxurl, {action:'sspa_start_run', nonce:sspa_admin.nonce, type:'spot', 'page_keys[]':'home'});
      if (!result.success) throw Error(typeof result === 'string' ? 'Start returned HTML instead of JSON' : JSON.stringify(result));
      return result.data.run_id;
    });
    await page.evaluate(runId => {
      SSPATransport.drive({ajaxurl:window.ajaxurl, nonce:sspa_admin.nonce, runId});
    }, id);
    await expect.poll(() => !!record, {timeout:30000}).toBe(true);
    expect(record.has('body_snippet')).toBe(false);
    expect(record.has('body_evidence')).toBe(false);
    const evidence = {schema:Number(record.get('body_evidence[schema]')), bytes_scanned:Number(record.get('body_evidence[bytes_scanned]')), body_hash:record.get('body_evidence[body_hash]')};
    expect(evidence.schema).toBe(1);
    expect(evidence.bytes_scanned).toBeGreaterThan(0);
    expect(evidence.body_hash).toMatch(/^[a-f0-9]{32}$/);
    await expect.poll(() => state(id).status, {timeout:120000}).toBe('done');
    console.log('PASS real browser measurement posts derived evidence only and completes its capture');
  } finally {
    if (id && !['done','cancelled','failed'].includes(state(id).status)) wp('eval', `SSPA_Run_Controller::cancel(${id});`);
    wp('option', 'update', 'sspa_fleet_browser_transport', '0');
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
