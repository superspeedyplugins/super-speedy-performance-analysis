const assert = require('node:assert/strict');
const {chromium} = require(process.env.SSPA_PLAYWRIGHT_MODULE || '../observatory/node_modules/playwright');
(async () => {
 const site = process.env.SSPA_E2E_URL;
 assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
 const browser = await chromium.launch({headless:true});
 try {
  const page = await browser.newPage();
  const errors = [], writes = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('request', r => {const action = new URLSearchParams(r.postData() || '').get('action'); if (['sspa_share_optin','sspa_share_run','sspa_submit_now','sspa_community_backfill'].includes(action)) writes.push(action);});
  await page.goto(site+'/wp-login.php');
  await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
  await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
  await Promise.all([page.waitForURL(/\/wp-admin\//),page.locator('#wp-submit').click()]);
  for (const order of [['history','share'],['share','history','share'],['share']]) {
   await page.goto(site+'/wp-admin/admin.php?page=sspa');
   for (const tab of order) {
    await page.locator('.nav-tab[data-tab="'+tab+'"]').click();
    await page.locator('.tab-contents[data-tab="'+tab+'"] .sspa-payload-preview').waitFor({state:'attached'});
   }
   const share=page.locator('.tab-contents[data-tab="share"]');
   const history=page.locator('.tab-contents[data-tab="history"]');
   const before=await history.locator('pre').allTextContents();
   await share.locator('.sspa-preview-outbox[data-outbox-id="0"]').click();
   await page.waitForFunction(()=>jQuery.active===0);
   const text=await share.locator('pre').textContent();
   assert.ok(text.trim().startsWith('{'), 'Visible Share tab must contain its JSON preview after '+order.join(' -> '));
   assert.doesNotThrow(()=>JSON.parse(text));
   assert.deepEqual(await history.locator('pre').allTextContents(),before,'Share preview must not overwrite hidden History');
   assert.equal(await share.locator('pre').isVisible(),true);
   await page.evaluate(() => new Promise(resolve => sspa_refresh_tabs(['share'], resolve)));
   assert.equal(await share.locator('pre').textContent(),text,'Background refresh preserves the selected preview');
   assert.equal(await share.locator('.sspa-download-payload').count(),1,'Refresh preserves its download control');
   await share.locator('.sspa-preview-outbox[data-outbox-id="0"]').click();
   assert.equal(await share.locator('pre').isVisible(),false,'Same preview control toggles only its own preview');
   console.log('PASS preview ownership: '+order.join(' -> '));
  }
  assert.deepEqual(writes,[],'Local preview does not consent, queue or submit');
  assert.deepEqual(errors,[]);
  console.log('SHARE PREVIEW VERIFIED');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
