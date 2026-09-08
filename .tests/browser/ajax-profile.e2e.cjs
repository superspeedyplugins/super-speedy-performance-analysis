const assert=require('node:assert/strict');
const {chromium}=require(process.env.SSPA_PLAYWRIGHT_MODULE || '../observatory/node_modules/playwright');
(async()=>{
 const site=process.env.SSPA_E2E_URL;assert.match(new URL(site).hostname,/\.super-speedy-performance-analysis\.localhost$/);
 const browser=await chromium.launch({headless:true});
 try{
 const page=await browser.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
 await page.goto(site+'/wp-login.php');await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);await Promise.all([page.waitForURL(/\/wp-admin\//),page.locator('#wp-submit').click()]);
 await page.goto(site+'/wp-admin/admin.php?page=sspa#ajax');await page.locator('.sspa-ajax-compare').waitFor();
 const choices=await page.locator('.sspa-ajax-compare select[name=before] option').evaluateAll(o=>o.map(x=>({value:x.value,label:x.textContent})));
 const before=choices.find(x=>x.label.startsWith('SPro before')),after=choices.find(x=>x.label.startsWith('SPro after'));assert.ok(before&&after,'real integration fixture windows exist');
 await page.locator('select[name=before]').selectOption(before.value);await page.locator('select[name=after]').selectOption(after.value);await page.locator('.sspa-ajax-compare button').click();await page.locator('.sspa-ajax-summary h3').first().waitFor();
 assert.match(await page.locator('.sspa-ajax-summary').innerText(),/ms saved per request/);
 await page.waitForFunction(()=>document.querySelector('.sspa-ajax-chart canvas'));
 const download=page.waitForEvent('download');await page.locator('.sspa-ajax-export').click();const file=await download;const output=process.env.SSPA_AJAX_ARTIFACT_DIR;require('node:fs').mkdirSync(output,{recursive:true});await file.saveAs(output+'/ajax-before-after.html');await page.screenshot({path:output+'/ajax-before-after.png',fullPage:true});
 const html=require('node:fs').readFileSync(output+'/ajax-before-after.html','utf8');assert.match(html,/sspa\/ajax-series@1/);assert.match(html,/data:image\/png/);assert.match(html,/spro\/endpoint-context@1/);assert.doesNotMatch(html,/request_body|response_body/);assert.deepEqual(errors,[]);
 await page.locator('.sspa-ajax-filter').fill('no matching endpoint fixture');
 const filteredDownload=page.waitForEvent('download');await page.locator('.sspa-ajax-export').click();await (await filteredDownload).saveAs(output+'/ajax-filtered.html');
 const filteredHtml=require('node:fs').readFileSync(output+'/ajax-filtered.html','utf8');
 const filtered=await page.evaluate(html=>JSON.parse(new DOMParser().parseFromString(html,'text/html').querySelector('pre').textContent),filteredHtml);
 assert.equal(filtered.pages.length,0,'export JSON and summaries use the same filter as chart');
 assert.equal(await page.locator('.sspa-ajax-headlines h3').count(),0,'filtered visual summary matches exported selection');
 assert.equal(await page.locator('.sspa-ajax-summary h3').count(),0,'detailed visible summary follows the same filter');
 await page.locator('.sspa-ajax-filter').fill('');

 console.log('PASS AJAX tab, measured chart, actual summary, capture-time policy and standalone chart export; no browser errors');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
