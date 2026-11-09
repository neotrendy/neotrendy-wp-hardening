// Uploads an image through the block editor (Image block) with Playwright and prints JSON:
// { attachmentId, clientSideProcessing, sideloadRequests, errors }.
// Environment: SITE_URL, WP_USER, WP_PASS, UPLOAD_FILE. Run by tests/editor-upload.sh.
const { chromium } = require('playwright');

const { SITE_URL, WP_USER, WP_PASS, UPLOAD_FILE } = process.env;

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
  const result = { attachmentId: null, clientSideProcessing: null, sideloadRequests: 0, errors: [] };

  page.on('console', (m) => { if (m.type() === 'error') result.errors.push(`console: ${m.text()}`); });
  page.on('response', (r) => {
    if (/\/wp\/v2\/media\/\d+\/sideload/.test(decodeURIComponent(r.url()))) result.sideloadRequests++;
    if (r.status() >= 400 && /wp-json|rest_route/.test(r.url())) result.errors.push(`http ${r.status()} ${r.url()}`);
  });

  try {
    await page.goto(`${SITE_URL}/wp-login.php`);
    await page.fill('#user_login', WP_USER);
    await page.fill('#user_pass', WP_PASS);
    await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

    await page.goto(`${SITE_URL}/wp-admin/post-new.php`);
    await page.waitForFunction(() => window.wp?.data?.select('core/editor')?.getCurrentPostId(), null, { timeout: 60000 });
    result.clientSideProcessing = await page.evaluate(() => Boolean(window.__clientSideMediaProcessing));

    const clientId = await page.evaluate(() => {
      const block = wp.blocks.createBlock('core/image');
      wp.data.dispatch('core/block-editor').insertBlocks(block);
      return block.clientId;
    });
    // The editor canvas is an iframe in current WordPress versions.
    const canvas = (await page.locator('iframe[name="editor-canvas"]').count())
      ? page.frameLocator('iframe[name="editor-canvas"]')
      : page;
    await canvas.locator(`[data-block="${clientId}"] input[type="file"]`).first().setInputFiles(UPLOAD_FILE);

    await page.waitForFunction((id) => {
      const attrs = wp.data.select('core/block-editor').getBlockAttributes(id);
      const uploading = wp.data.select('core/upload-media')?.isUploading?.() ?? false;
      return attrs?.id && attrs.url && !attrs.url.startsWith('blob:') && !uploading;
    }, clientId, { timeout: 120000 });
    await page.waitForTimeout(2000);

    result.attachmentId = await page.evaluate((id) => wp.data.select('core/block-editor').getBlockAttributes(id).id, clientId);
  } catch (e) {
    result.errors.push(String(e).split('\n')[0]);
  }

  await browser.close();
  console.log(JSON.stringify(result));
})();
