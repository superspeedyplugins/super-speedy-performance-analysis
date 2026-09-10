const assert = require('node:assert/strict');
const path = require('node:path');
const {chromium} = require(process.env.SSPA_PLAYWRIGHT_MODULE || path.resolve(__dirname, '../observatory/node_modules/playwright'));
(async () => {
 const browser = await chromium.launch({headless:true});
 const page = await browser.newPage({viewport:{width:1600,height:1000}});
 const errors=[]; page.on('pageerror', e=>errors.push(e.message));
 try {
  await page.goto(process.env.SSPA_E2E_URL + '/wp-login.php');
  await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
  await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
  await Promise.all([page.waitForURL(/wp-admin/),page.locator('#wp-submit').click()]);
  await page.goto(process.env.SSPA_E2E_URL + '/wp-admin/admin.php?page=sspa#history');
  const mount=page.locator('.sspa-history-chart');
  await page.locator('.sspa-history-chart-status').filter({hasText:'chart loaded'}).waitFor();
  const state=await mount.evaluate(el=>{
   const o=el.sspaChart.getOption(); const doc=el.closest('[data-sspa-history-chart]').sspaDocument;
   return {labels:o.xAxis[0].data.map(k=>o.xAxis[0].axisLabel.formatter(k)),home:doc.pages.find(p=>p.page_key==='home')};
  });
  assert.ok(state.home && state.home.relative_url, 'Retained Home measurement provides a real URL');
  assert.ok(state.labels.every(s=>!s.includes('\n')), 'Axis labels contain page names only, not URLs');
  const timing=await mount.evaluate(el=>{const o=el.sspaChart.getOption();return [o.tooltip[0].showDelay,o.tooltip[0].hideDelay,o.tooltip[0].transitionDuration];});
  assert.deepEqual(timing,[0,0,0],'HTML tooltips have no deliberate show/hide delay or motion transition');
  await mount.scrollIntoViewIfNeeded();
  const coordinates=await mount.evaluate(el=>{
   const text=el.sspaChart.getZr().storage.getDisplayList().find(t=>t.type==='tspan' && t.style.text==='Home');
   if(!text) throw Error('Home axis label not rendered');
   const rect=text.getBoundingRect().clone(); rect.applyTransform(text.getComputedTransform());
   return {x:rect.x+rect.width/2,y:rect.y+rect.height/2};
  });
  const box=await mount.boundingBox();
  await page.mouse.move(box.x+coordinates.x,box.y+coordinates.y);
  const tip=page.locator('.sspa-history-tooltip');
  await tip.waitFor({state:'visible',timeout:1000});
  assert.match(await tip.innerText(),/Home/);
  assert.ok((await tip.innerText()).includes(state.home.relative_url));
  assert.match(await tip.innerText(),/GET/);
  assert.equal(await tip.locator('strong').innerText(),'Home');
  assert.equal(await mount.locator('[title]').count(),0,'No native browser title tooltip');
  const tipBox=await tip.boundingBox(); assert.ok(tipBox.x>=0 && tipBox.x+tipBox.width<=1601,'Tooltip fits viewport');
  if(process.env.SSPA_E2E_SCREENSHOT) await page.screenshot({path:process.env.SSPA_E2E_SCREENSHOT});
  await page.mouse.move(5,5); await tip.waitFor({state:'hidden',timeout:1000});
  const escaped=await mount.evaluate(el=>{
   const doc=el.closest('[data-sspa-history-chart]').sspaDocument;
   const home=doc.pages.find(p=>p.page_key==='home'); const old=[home.label,home.relative_url];
   home.label='<img src=x onerror=alert(1)>'; home.relative_url='/?q=<script>alert(1)</script>';
   const node=document.createElement('div');node.innerHTML=el.sspaChart.getOption().xAxis[0].tooltip.formatter({value:home.key});
   home.label=old[0];home.relative_url=old[1];
   return {text:node.textContent,markup:node.querySelectorAll('img,script').length};
  });
  assert.ok(escaped.text.includes('<img src=x onerror=alert(1)>'));
  assert.equal(escaped.markup,0,'Labels and URLs render as inert text in the custom tooltip');
  const point=await mount.evaluate(el=>{
   const o=el.sspaChart.getOption();const home=el.closest('[data-sspa-history-chart]').sspaDocument.pages.find(p=>p.page_key==='home');
   const d=o.series[1].data.filter(d=>d.value[0]===home.key).at(-1);
   const pixel=el.sspaChart.convertToPixel({seriesIndex:1},d.value);
   return {x:pixel[0]+d.symbolOffset[0],y:pixel[1]};
  });
  await page.mouse.move(box.x+point.x,box.y+point.y);await tip.waitFor({state:'visible',timeout:1000});
  assert.equal(await tip.locator('strong').innerText(),'Home (recent)','Measurement tooltip retains period and repeated page name');
  assert.ok((await tip.innerText()).includes(state.home.relative_url));
  await page.setViewportSize({width:360,height:900});
  await page.locator('.sspa-history-page-filter').fill('home');
  await page.waitForFunction(()=>document.querySelector('.sspa-history-chart').sspaChart.getOption().xAxis[0].data.length===1);
  await page.waitForFunction(()=>{const el=document.querySelector('.sspa-history-chart');return Math.abs(el.sspaChart.getWidth()-el.clientWidth)<1;});
  await mount.scrollIntoViewIfNeeded();
  const mobile=await mount.evaluate(el=>{const t=el.sspaChart.getZr().storage.getDisplayList().find(t=>t.type==='tspan'&&t.style.text==='Home');const r=t.getBoundingRect().clone();r.applyTransform(t.getComputedTransform());return {x:r.x+r.width/2,y:r.y+r.height/2};});
  const mobileBox=await mount.boundingBox();await page.mouse.move(mobileBox.x+mobile.x,mobileBox.y+mobile.y);
  await tip.waitFor({state:'visible',timeout:1000});const mobileTip=await tip.boundingBox();
  assert.ok(mobileTip.x>=0&&mobileTip.x+mobileTip.width<=361,'Axis tooltip stays within the narrow viewport');
  assert.deepEqual(errors,[]);
  console.log('PASS: Actual axis hover opens instant HTML page/URL/context tooltip; name-only labels and pointer exit verified');
 } finally {await browser.close();}
})().catch(e=>{console.error('FAIL:',e.stack);process.exit(1);});
