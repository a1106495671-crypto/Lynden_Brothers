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

function cleanInlineMarkdown(value) {
  return String(value)
    .replace(/!\[[^\]]*\]\([^)]+\)/g, '')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1')
    .replace(/(\*\*|__)(.*?)\1/g, '$2')
    .replace(/(\*|_)(.*?)\1/g, '$2')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/~~(.*?)~~/g, '$1')
    .replace(/\s+/g, ' ')
    .trim();
}

function normalizeMarkdownForToutiao(markdown) {
  const lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
  const out = [];
  let paragraph = [];

  function flushParagraph() {
    if (paragraph.length) {
      out.push(cleanInlineMarkdown(paragraph.join(' ')));
      paragraph = [];
    }
  }

  for (const raw of lines) {
    const line = raw.trim();
    if (!line) {
      flushParagraph();
      continue;
    }

    const heading = /^(#{1,6})\s+(.+)$/.exec(line);
    if (heading) {
      flushParagraph();
      out.push(cleanInlineMarkdown(heading[2]));
      continue;
    }

    const unordered = /^[-*+]\s+(.+)$/.exec(line);
    const ordered = /^\d+[.)]\s+(.+)$/.exec(line);
    if (unordered || ordered) {
      flushParagraph();
      out.push(`• ${cleanInlineMarkdown((unordered || ordered)[1])}`);
      continue;
    }

    const quoted = /^>\s*(.+)$/.exec(line);
    if (quoted) {
      flushParagraph();
      out.push(cleanInlineMarkdown(quoted[1]));
      continue;
    }

    paragraph.push(line);
  }

  flushParagraph();
  return out.filter(Boolean).join('\n\n').trim();
}

async function firstVisible(page, selectors, timeout = 4000) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    try {
      await locator.waitFor({ state: 'visible', timeout });
      return locator;
    } catch (error) {
      // try next selector
    }
  }
  return null;
}

async function clickByText(page, labels, timeout = 5000) {
  for (const label of labels) {
    const locator = page.getByText(label).first();
    try {
      await locator.waitFor({ state: 'visible', timeout });
      await locator.click();
      return true;
    } catch (error) {
      // try next label
    }
  }
  return false;
}

async function clickSidebarItem(page, label, timeout = 3000) {
  const sidebar = page.locator('aside').first();
  const hasSidebar = await sidebar.isVisible().catch(() => false);
  if (!hasSidebar) return false;

  const target = sidebar.getByText(new RegExp(`^\\s*${label}\\s*$`)).first();
  try {
    await target.waitFor({ state: 'visible', timeout });
    await target.click();
    return true;
  } catch (error) {
    return false;
  }
}

async function assertToutiaoPublishPermission(page) {
  const blocked = await page.getByText(/请完善账号信息.*解锁发布文章|解锁发布文章|立即完善/).first().isVisible().catch(() => false);
  if (blocked) {
    throw new Error('头条号账号未解锁“发布文章”权限。请先点击“立即完善”完成账号资料/认证，再执行自动发布。');
  }
}

async function ensureToutiaoArticleEditorPage(page) {
  await assertToutiaoPublishPermission(page);

  const directEditor = await firstVisible(page, [
    'input[placeholder*="标题"]',
    'textarea[placeholder*="标题"]',
    '[data-placeholder*="标题"]',
    '[contenteditable="true"][data-placeholder*="标题"]',
  ], 1200);
  if (directEditor) {
    return true;
  }

  const clickedCreateInSidebar = await clickSidebarItem(page, '创作', 2500);
  if (!clickedCreateInSidebar) {
    throw new Error('未找到左侧“创作”菜单，无法进入发布入口。');
  }
  await page.waitForTimeout(800);

  const clickedArticleInSidebar = await clickSidebarItem(page, '文章', 2500);
  if (!clickedArticleInSidebar) {
    throw new Error('未找到“创作”下的“文章”入口，无法进入文章编辑器。');
  }
  await page.waitForTimeout(1800);

  await page.goto('https://mp.toutiao.com/profile_v4/xigua/publish/article', {
    waitUntil: 'domcontentloaded',
    timeout: 60000,
  }).catch(() => null);
  await page.waitForTimeout(1200);

  const afterJumpEditor = await firstVisible(page, [
    'input[placeholder*="标题"]',
    'textarea[placeholder*="标题"]',
    '[data-placeholder*="标题"]',
    '[contenteditable="true"][data-placeholder*="标题"]',
  ], 1500);
  return !!afterJumpEditor;
}

function isToutiaoLogin(url) {
  return /mp\.toutiao\.com\/(auth|login|passport)/.test(url);
}

async function waitForToutiaoEditor(page, loginWaitMs) {
  const titleSelectors = [
    'input[placeholder*="标题"]',
    'textarea[placeholder*="标题"]',
    '[data-placeholder*="标题"]',
    '[contenteditable="true"][data-placeholder*="标题"]',
  ];

  let titleField = await firstVisible(page, titleSelectors, 2500);
  if (titleField) {
    return titleField;
  }

  const startedAt = Date.now();
  let lastReloadAt = 0;
  while (Date.now() - startedAt < loginWaitMs) {
    await assertToutiaoPublishPermission(page);

    await ensureToutiaoArticleEditorPage(page).catch(() => false);
    titleField = await firstVisible(page, titleSelectors, 2500);
    if (titleField) {
      return titleField;
    }

    const currentUrl = page.url();
    if (/mp\.toutiao\.com\/login\/?\?redirect_url=/.test(currentUrl) && Date.now() - lastReloadAt > 8000) {
      lastReloadAt = Date.now();
      await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
      await page.waitForTimeout(1800);
      await page.goto('https://mp.toutiao.com/profile_v4/xigua/publish/article', {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      }).catch(() => null);
    }

    if (!isToutiaoLogin(page.url())) {
      await page.goto('https://mp.toutiao.com/profile_v4/xigua/publish/article', {
        waitUntil: 'domcontentloaded',
        timeout: 60000,
      }).catch(() => null);
    }

    await page.waitForTimeout(3000);
  }

  throw new Error('等待头条号编辑器超时。头条号可能卡在登录中间页或风控验证，请先在自动化窗口完成登录/验证后再点“重新执行”。');
}

async function fillTitle(titleField, title) {
  await titleField.click();
  if (await titleField.isEditable().catch(() => false)) {
    await titleField.fill(title);
    return;
  }
  await titleField.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A').catch(() => null);
  await titleField.type(title, { delay: 30 });
}

async function main() {
  const inputPath = process.argv[2];
  const outputPath = process.argv[3];
  if (!inputPath || !outputPath) {
    throw new Error('缺少输入或输出文件路径');
  }

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
  await context.addInitScript(() => {
    try {
      Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
    } catch (error) {
      // ignore
    }
  });

  const page = context.pages()[0] || await context.newPage();
  page.setDefaultTimeout(20000);

  try {
    await page.goto(input.publishUrl || 'https://mp.toutiao.com/profile_v4/xigua/publish/article', {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    });
    await page.waitForTimeout(2500);
    await ensureToutiaoArticleEditorPage(page).catch(() => false);

    const titleField = await waitForToutiaoEditor(page, loginWaitMs);
    await fillTitle(titleField, input.title || '未命名文章');

    const editor = await firstVisible(page, [
      '.ProseMirror[contenteditable="true"]',
      '[contenteditable="true"][data-placeholder*="正文"]',
      '[contenteditable="true"][placeholder*="正文"]',
      '[contenteditable="true"]',
    ]);

    if (!editor) {
      throw new Error('已进入头条号页面，但没有找到正文编辑器。页面结构可能已变化。');
    }

    const contentText = String(input.contentText || '').trim()
      || normalizeMarkdownForToutiao(input.content || input.excerpt || input.title || '');

    await editor.click();
    await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A').catch(() => null);
    await page.keyboard.type(contentText, { delay: 3 });

    await page.waitForTimeout(800);

    const clickedPublish = await clickByText(page, ['发布', '确认发布', '提交发布', '立即发布'], 6000);
    if (!clickedPublish) {
      throw new Error('内容已填入头条号编辑器，但没有找到“发布”按钮。可能需要先选择封面、声明原创或通过校验。');
    }

    await page.waitForTimeout(1200);
    await clickByText(page, ['确定', '确认发布', '继续发布', '发布'], 2500);

    await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => null);
    await page.waitForTimeout(3000);

    const remoteUrl = page.url();
    const successVisible = await page.getByText(/发布成功|提交成功|审核中|已发布/).first().isVisible().catch(() => false);
    if (!successVisible && /publish\/article/.test(remoteUrl)) {
      throw new Error('头条号未返回发布成功状态，可能停留在校验步骤（如封面、分类、人机验证）。');
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
