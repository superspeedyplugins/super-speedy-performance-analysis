const { session, admin, response, expect, json, output } = require('./fleet-context.cjs');
(async () => {
  const { browser, page } = await session();
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  try {
    await admin(page, 'tools');
    const button = () => page.locator('#sspa-profiler-toggle');
    await expect(button()).toHaveText('Disable profiler');
    const disabled = response(page, 'sspa_profiler_toggle');
    await button().click();
    expect((await (await disabled).json()).success).toBe(true);
    await expect(button()).toHaveText('Enable profiler');
    expect(json('echo wp_json_encode(file_exists(SSPA_Helper_Files::mu_path()));')).toBe(false);
    await page.reload();
    await expect(button()).toHaveText('Enable profiler');
    expect(json('echo wp_json_encode(file_exists(SSPA_Helper_Files::mu_path()));')).toBe(false);
    await admin(page, 'overview');
    const health = await page.locator('.sspa-health').filter({ hasText: 'Profiler loader (mu-plugin)' }).innerText();
    await page.screenshot({ path: output + '/profiler-disabled.png' });
    console.log('MEASURED disabled profiler health: ' + health);
    expect(health).not.toContain('mu-plugins is not writable');
    expect(health).toContain('Enable profiler in Tools');
    const refused = response(page, 'sspa_start_run');
    const notice = page.waitForEvent('dialog', { timeout: 10000 }).then(async dialog => {
      const message = dialog.message();
      await dialog.dismiss();
      return message;
    });
    await page.locator('#sspa-run-analysis').click();
    expect((await (await refused).json()).success).toBe(false);
    expect(await notice).toContain('Enable profiler in Tools');
    expect(json('echo wp_json_encode(file_exists(SSPA_Helper_Files::mu_path()));')).toBe(false);
    const prompt = page.getByRole('link', { name: 'Enable profiler in Tools before starting an analysis.' });
    await expect(prompt).toBeVisible();
    await prompt.click();
    await expect(button()).toHaveText('Enable profiler');
    const enabled = response(page, 'sspa_profiler_toggle');
    await button().click();
    expect((await (await enabled).json()).success).toBe(true);
    await expect(button()).toHaveText('Disable profiler');
    await page.reload();
    await expect(button()).toHaveText('Disable profiler');
    expect(json('echo wp_json_encode(SSPA_Helper_Files::health()["mu"]);')).toBe(true);
    expect(errors).toEqual([]);
    await page.screenshot({ path: output + '/profiler-tools.png' });
    console.log('PASS: Tools Disable, reload, Overview prompt, Enable and reload preserve explicit profiler state');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
