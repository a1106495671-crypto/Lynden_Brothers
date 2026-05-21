const fs = require('fs');

function loadPlaywright() {
  try {
    return require('playwright');
  } catch (error) {
    throw new Error('缺少 Playwright 运行库。请配置 DISTRIBUTION_NODE_PATH 指向包含 playwright 的 node_modules。');
  }
}

function readJson(path) {
  return JSON.parse(fs.readFileSync(path, 'utf8'));
}

function writeResult(path, payload) {
  fs.writeFileSync(path, JSON.stringify(payload, null, 2));
}

async function firstVisible(page, selectors, timeout = 3500) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    try {
      await locator.waitFor({ state: 'visible', timeout });
      return locator;
    } catch (error) {
      // try next
    }
  }
  return null;
}

async function clickByText(page, labels, timeout = 4000) {
  for (const label of labels) {
    const locator = page.getByText(label, { exact: true }).first();
    try {
      await locator.waitFor({ state: 'visible', timeout });
      await locator.click();
      return true;
    } catch (error) {
      // try next
    }
  }
  return false;
}

async function waitEditor(page, loginWaitMs) {
  const titleSelectors = [
    'input[placeholder*="标题"]',
    'textarea[placeholder*="标题"]',
    '[contenteditable="true"][data-placeholder*="标题"]',
    '[contenteditable="true"][aria-label*="标题"]',
  ];

  const startedAt = Date.now();
  while (Date.now() - startedAt < loginWaitMs) {
    const titleField = await firstVisible(page, titleSelectors, 2000);
    if (titleField) return titleField;
    await page.waitForTimeout(2500);
  }

  throw new Error('等待编辑器超时。请先完成平台登录或人机验证后重新执行。');
}

async function fillTitle(titleField, title) {
  await titleField.click();
  if (await titleField.isEditable().catch(() => false)) {
    await titleField.fill(title);
    return;
  }
  await titleField.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A').catch(() => null);
  await titleField.type(title, { delay: 25 });
}

async function main() {
  const inputPath = process.argv[2];
  const outputPath = process.argv[3];
  if (!inputPath || !outputPath) throw new Error('缺少输入或输出文件路径');

  const input = readJson(inputPath);
  const { chromium } = loadPlaywright();

// Post published URL back to system
async function notifyPublished(articleId, publishedUrl, status) {
  if (!articleId || !publishedUrl) return;
  status = status || "published";
  try {
    const http = require("http");
    const data = JSON.stringify({ article_id: articleId, remote_url: publishedUrl, status: status });
    const options = {
      hostname: "127.0.0.1", port: 80,
      path: "/geo-system/admin/api/publish-callback.php",
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Content-Length": Buffer.byteLength(data),
        "X-Callback-Secret": "geo-internal-callback-2024"
      }
    };
    await new Promise(function(resolve) {
      var req = http.request(options, function(res) { res.resume(); res.on("end", resolve); });
      req.on("error", function() { resolve(); });
      req.write(data);
      req.end();
    });
  } catch(e) {}
}
  const loginWaitMs = Number(input.loginWaitMs || 600000);

  const context = await chromium.launchPersistentContext(input.profileDir, {
    channel: 'chrome',
    headless: false,
    viewport: { width: 1366, height: 900 },
    args: ['--disable-blink-features=AutomationControlled'],
    ignoreDefaultArgs: ['--enable-automation', '--no-sandbox'],
    chromiumSandbox: true,
  });
  await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => null);

  const page = context.pages()[0] || await context.newPage();
  page.setDefaultTimeout(20000);

  try {
    await page.goto(input.publishUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(2200);

    const titleField = await waitEditor(page, loginWaitMs);
    await fillTitle(titleField, input.title || '未命名文章');

    const editor = await firstVisible(page, [
      '.ProseMirror[contenteditable="true"]',
      '[contenteditable="true"][data-placeholder*="正文"]',
      '[contenteditable="true"][placeholder*="正文"]',
      '[contenteditable="true"]',
      'textarea',
    ]);

    if (!editor) {
      throw new Error('未找到正文编辑器，平台页面结构可能已变化。');
    }

    await editor.click();
    await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A').catch(() => null);
    await page.keyboard.type(String(input.contentText || ''), { delay: 3 });

    await page.waitForTimeout(800);
    const clickedPublish = await clickByText(page, ['发布', '确认发布', '提交发布', '立即发布'], 6000);
    if (!clickedPublish) {
      throw new Error('内容已填入，但没有找到“发布”按钮。可能需要先补充平台必填项。');
    }

    await page.waitForTimeout(1200);
    await clickByText(page, ['确定', '确认', '继续发布', '发布'], 2500);

    await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => null);
    await page.waitForTimeout(2500);

    const remoteUrl = page.url();
    const okText = await page.getByText(/发布成功|提交成功|审核中|已发布/).first().isVisible().catch(() => false);
    if (!okText && /publish|edit|article/.test(remoteUrl)) {
      throw new Error(`${input.platform || '目标平台'} 未返回明确发布成功状态，可能停留在审核或校验步骤。`);
    }

    writeResult(outputPath, { ok: true, remote_url: remoteUrl });
  } finally {
    await context.close();
  }
}

main().catch((error) => {
  const outputPath = process.argv[3];
  const message = error.message || String(error);
  if (outputPath) {
    writeResult(outputPath, { ok: false, error: message });
  } else {
    console.error(message);
  }
  process.exit(1);
});
