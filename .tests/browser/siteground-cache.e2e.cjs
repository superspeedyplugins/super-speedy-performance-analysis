// Real SiteGround file-cache integration; no copied purge implementation or mock backend.
const { wp, json, output, session, expect } = require('./fleet-context.cjs');
(async () => {
  const {browser, page} = await session();
  try {
    wp('option','update','siteground_optimizer_file_caching','1');
    const planted = json(`$c=SiteGround_Optimizer\\File_Cacher\\File_Cacher::get_instance(); $dir=$c->get_cache_dir().'w5c-real-cache/'; wp_mkdir_p($dir); $file=$dir.'index.html'; file_put_contents($file,'Retained cached page'); echo wp_json_encode(array('file'=>$file,'exists'=>is_file($file)));`);
    expect(planted.exists).toBe(true);
    await page.goto(process.env.SSPA_TEST_SITE_URL + '/wp-admin/index.php');
    await page.locator('#wp-admin-bar-sspa-menu > a').hover();
    await expect(page.locator('#wp-admin-bar-sspa-purge-all > a')).toContainText('SG Optimizer');
    await Promise.all([
      page.waitForResponse(r => r.url().includes('admin-post.php') && r.url().includes('do=purge_all') && r.status()===302),
      page.locator('#wp-admin-bar-sspa-purge-all > a').click(),
    ]);
    expect(json(`echo wp_json_encode(is_file(${JSON.stringify(planted.file)}));`)).toBe(false);
    await expect(page.locator('.notice').filter({hasText:'Requested a whole page-cache purge from SG Optimizer.'})).toBeVisible();
    await page.screenshot({path:output+'/siteground-real-file-cache.png',fullPage:true});
    console.log('PASS real SiteGround cached file removed through existing admin-bar Page cache everything control and notice');
    const replanted = json(`$dir=dirname(${JSON.stringify(planted.file)}); wp_mkdir_p($dir); file_put_contents(${JSON.stringify(planted.file)},'Retained cached page'); echo wp_json_encode(is_file(${JSON.stringify(planted.file)}));`);
    expect(replanted).toBe(true);
    await page.locator('#wp-admin-bar-sspa-menu > a').hover();
    await Promise.all([
      page.waitForResponse(r => r.url().includes('admin-post.php') && r.url().includes('do=clear_all_caches') && r.status()===302),
      page.locator('#wp-admin-bar-sspa-clear-all-caches > a').click(),
    ]);
    expect(json(`echo wp_json_encode(is_file(${JSON.stringify(planted.file)}));`)).toBe(false);
    const combined = page.locator('.notice').filter({hasText:'Flushed the object cache.'});
    await expect(combined).toContainText('Requested a whole page-cache purge from SG Optimizer.');
    await expect(combined).toContainText('Reset OPcache.');
    await page.screenshot({path:output+'/siteground-combined-cache.png',fullPage:true});
    console.log('PASS real Clear all caches click removes the SiteGround cached file and reports all three steps');

  } finally { await browser.close(); }
})().catch(error => {console.error(error);process.exit(1);});
