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

function toBool(value, fallback = false) {
  if (value === undefined || value === null || value === '') return fallback;
  if (typeof value === 'boolean') return value;
  return !['0', 'false', 'off', 'no'].includes(String(value).trim().toLowerCase());
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function cleanInlineMarkdown(value) {
  return String(value)
    .replace(/!\[([^\]]*)\]\([^)]+\)/g, '$1')
    .replace(/\[([^\]]+)\]\(([^)]+)\)/g, '$1')
    .replace(/(\*\*|__)(.*?)\1/g, '$2')
    .replace(/(\*|_)(.*?)\1/g, '$2')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/~~(.*?)~~/g, '$1')
    .replace(/\s+/g, ' ')
    .trim();
}

function normalizeMarkdownForZhihu(markdown) {
  const lines = String(markdown || '').replace(/\r\n?/g, '\n').split('\n');
  const blocks = [];
  let paragraph = [];
  let list = [];
  let quote = [];

  function flushParagraph() {
    if (paragraph.length) {
      blocks.push({ type: 'p', text: cleanInlineMarkdown(paragraph.join(' ')) });
      paragraph = [];
    }
  }

  function flushList() {
    if (list.length) {
      blocks.push({ type: 'ul', items: list.map(cleanInlineMarkdown).filter(Boolean) });
      list = [];
    }
  }

  function flushQuote() {
    if (quote.length) {
      blocks.push({ type: 'quote', text: cleanInlineMarkdown(quote.join(' ')) });
      quote = [];
    }
  }

  for (const rawLine of lines) {
    const line = rawLine.trim();
    if (!line) {
      flushParagraph();
      flushList();
      flushQuote();
      continue;
    }

    const heading = /^(#{1,6})\s+(.+)$/.exec(line);
    if (heading) {
      flushParagraph();
      flushList();
      flushQuote();
      blocks.push({ type: heading[1].length <= 2 ? 'h2' : 'h3', text: cleanInlineMarkdown(heading[2]) });
      continue;
    }

    const unordered = /^[-*+]\s+(.+)$/.exec(line);
    const ordered = /^\d+[.)]\s+(.+)$/.exec(line);
    if (unordered || ordered) {
      flushParagraph();
      flushQuote();
      list.push((unordered || ordered)[1]);
      continue;
    }

    const quoted = /^>\s*(.+)$/.exec(line);
    if (quoted) {
      flushParagraph();
      flushList();
      quote.push(quoted[1]);
      continue;
    }

    flushList();
    flushQuote();
    paragraph.push(line);
  }

  flushParagraph();
  flushList();
  flushQuote();

  const text = blocks.map((block) => {
    if (block.type === 'ul') {
      return block.items.map((item) => `• ${item}`).join('\n');
    }
    return block.text;
  }).filter(Boolean).join('\n\n');

  const html = blocks.map((block) => {
    if (!block.text && block.type !== 'ul') return '';
    if (block.type === 'h2') return `<h2>${escapeHtml(block.text)}</h2>`;
    if (block.type === 'h3') return `<h3>${escapeHtml(block.text)}</h3>`;
    if (block.type === 'quote') return `<blockquote>${escapeHtml(block.text)}</blockquote>`;
    if (block.type === 'ul') {
      return `<ul>${block.items.map((item) => `<li>${escapeHtml(item)}</li>`).join('')}</ul>`;
    }
    return `<p>${escapeHtml(block.text)}</p>`;
  }).filter(Boolean).join('');

  return { text, html };
}

function normalizeHtmlForPlainText(html) {
  const source = String(html || '');
  if (!source.trim()) return '';
  return source
    .replace(/<\/(p|h1|h2|h3|h4|h5|h6|li|blockquote|ul|ol)>/gi, '\n')
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&#39;/g, '\'')
    .replace(/&quot;/g, '"')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

async function firstVisible(page, selectors, timeout = 3000) {
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    try {
      await locator.waitFor({ state: 'visible', timeout });
      return locator;
    } catch (error) {
      // Try the next selector because Zhihu changes editor markup often.
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
      // Continue with the next visible label candidate.
    }
  }
  return false;
}

async function hasEditorContent(editor) {
  const text = await editor.textContent().catch(() => '');
  return String(text || '').trim().length > 20;
}

async function hasPageText(page, pattern) {
  return page.getByText(pattern).first().isVisible().catch(() => false);
}

function isLoginUrl(url) {
  return /zhihu\.com\/signin|zhihu\.com\/login/.test(url);
}

async function isLoginPage(page) {
  if (isLoginUrl(page.url())) return true;
  return page.getByText(/登录|密码登录|验证码登录|手机号/).first().isVisible().catch(() => false);
}

async function fillFirst(page, selectors, value) {
  if (!value) return false;
  for (const selector of selectors) {
    const locator = page.locator(selector).first();
    try {
      await locator.waitFor({ state: 'visible', timeout: 1500 });
      await locator.click({ timeout: 1500 });
      await locator.fill(String(value), { timeout: 2500 });
      return true;
    } catch (error) {
      // Continue trying alternate login form selectors.
    }
  }
  return false;
}

async function assistZhihuLogin(page, input, headless) {
  if (!await isLoginPage(page)) return false;

  await clickByText(page, ['密码登录', '账号密码登录', '手机号登录'], 1500);

  const filledUser = await fillFirst(page, [
    'input[name="username"]',
    'input[name="phoneNo"]',
    'input[type="tel"]',
    'input[placeholder*="手机号"]',
    'input[placeholder*="邮箱"]',
    'input[placeholder*="账号"]',
    'input[placeholder*="用户名"]',
  ], input.username || input.accountName || '');

  const filledPassword = await fillFirst(page, [
    'input[name="password"]',
    'input[type="password"]',
    'input[placeholder*="密码"]',
  ], input.password || '');

  if (filledUser && filledPassword) {
    await clickByText(page, ['登录', '立即登录'], 2500);
    await page.waitForTimeout(3000);
    return true;
  }

  if (headless) {
    throw new Error('知乎需要登录，但媒体账号没有可自动填写的账号或密码。请先在媒体账号里保存账号密码，或临时设置 DISTRIBUTION_BROWSER_HEADLESS=false 手动登录一次。');
  }

  return false;
}

function defaultWriteUrl() {
  return 'https://zhuanlan.zhihu.com/write';
}

async function openZhihuWritePage(page, input, headless) {
  if (await isLoginPage(page)) {
    await assistZhihuLogin(page, input, headless);
    return;
  }

  const targetUrl = /zhihu\.com\/creator\/?$/.test(page.url())
    ? defaultWriteUrl()
    : String(page.url() || defaultWriteUrl());

  if (!/zhuanlan\.zhihu\.com\/write|zhihu\.com\/creator/.test(targetUrl)) {
    await page.goto(defaultWriteUrl(), {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    }).catch(() => null);
    return;
  }

  if (/zhuanlan\.zhihu\.com\/write/.test(targetUrl)) {
    return;
  }

  const clicked = await clickByText(page, ['写文章', '发布内容', '开始创作'], 3000);
  if (!clicked) {
    await page.goto(defaultWriteUrl(), {
      waitUntil: 'domcontentloaded',
      timeout: 60000,
    }).catch(() => null);
  }
}

async function waitForZhihuEditor(page, loginWaitMs, headless, input) {
  const titleSelectors = [
    'textarea[placeholder*="标题"]',
    'input[placeholder*="标题"]',
    '[placeholder*="请输入标题"]',
    '.WriteIndex-titleInput',
    '.Input[placeholder*="标题"]',
    '[contenteditable="true"][data-placeholder*="标题"]',
    '[contenteditable="true"][aria-label*="标题"]',
  ];

  let titleField = await firstVisible(page, titleSelectors, 2500);
  if (titleField) {
    return titleField;
  }

  if (!isLoginUrl(page.url())) {
    await openZhihuWritePage(page, input, headless);
  }

  const startedAt = Date.now();
  let sawMaintenance = false;
  while (Date.now() - startedAt < loginWaitMs) {
    titleField = await firstVisible(page, titleSelectors, 2500);
    if (titleField) {
      return titleField;
    }

    if (await hasPageText(page, /系统升级中|稍后再试|维护中/)) {
      sawMaintenance = true;
      await page.waitForTimeout(15000);
      await page.reload({ waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => null);
      continue;
    }

    if (!isLoginUrl(page.url())) {
      await openZhihuWritePage(page, input, headless);
    }

    await page.waitForTimeout(3000);
  }

  if (sawMaintenance) {
    throw new Error('知乎创作页显示“系统升级中，请稍后再试”，自动发布已停止。等知乎恢复后点“重新执行”即可。');
  }

  if (headless) {
    throw new Error('知乎登录态不可用，或登录时需要验证码/扫码/滑块验证。后台发布无法人工输入验证码，请临时设置 DISTRIBUTION_BROWSER_HEADLESS=false 完成验证后重试。');
  }

  throw new Error('等待登录超时。账号密码已尽量自动填写；如页面要求验证码、扫码或滑块，请完成验证后再回系统点“重新执行”。');
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
  const headless = toBool(input.headless, true);
  const context = await chromium.launchPersistentContext(input.profileDir, {
    channel: 'chrome',
    headless,
    viewport: { width: 1366, height: 900 },
    args: ['--disable-blink-features=AutomationControlled'],
    ignoreDefaultArgs: ['--enable-automation', '--no-sandbox'],
    chromiumSandbox: true,
  });
  await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => null);

  const page = context.pages()[0] || await context.newPage();
  page.setDefaultTimeout(15000);

  try {
    const publishUrl = /zhihu\.com\/creator\/?$/.test(String(input.publishUrl || ''))
      ? defaultWriteUrl()
      : (input.publishUrl || defaultWriteUrl());
    await page.goto(publishUrl, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.waitForTimeout(2500);

    const titleField = await waitForZhihuEditor(page, loginWaitMs, headless, input);

    await titleField.click();
    await titleField.fill(input.title);

    const editor = await firstVisible(page, [
      '[placeholder*="请输入正文"]',
      '.public-DraftEditor-content[contenteditable="true"]',
      '.DraftEditor-editorContainer [contenteditable="true"]',
      '.ProseMirror[contenteditable="true"]',
      '[contenteditable="true"][data-contents="true"]',
      '[contenteditable="true"]',
    ]);

    if (!editor) {
      throw new Error('已进入知乎页面，但没有找到正文编辑器。知乎页面结构可能已变化。');
    }

    await editor.click();
    if (!await hasEditorContent(editor)) {
      const markdownFormatted = normalizeMarkdownForZhihu(input.content || input.excerpt || input.title);
      const formatted = {
        html: String(input.contentHtml || '').trim() || markdownFormatted.html,
        text: String(input.contentText || '').trim() || normalizeHtmlForPlainText(String(input.contentHtml || '')) || markdownFormatted.text,
      };
      await page.evaluate(async ({ text, html }) => {
        const item = new ClipboardItem({
          'text/plain': new Blob([text], { type: 'text/plain' }),
          'text/html': new Blob([html], { type: 'text/html' }),
        });
        await navigator.clipboard.write([item]);
      }, formatted).catch(async () => {
        await page.evaluate(async (text) => navigator.clipboard.writeText(text), formatted.text);
      });
      await page.keyboard.press(process.platform === 'darwin' ? 'Meta+V' : 'Control+V');
    }
    await page.waitForTimeout(1000);

    const clickedPublish = await clickByText(page, ['发布', '发布文章', '立即发布'], 5000);
    if (!clickedPublish) {
      throw new Error('内容已填入知乎编辑器，但没有找到“发布”按钮。请检查页面是否需要补充栏目、声明或验证码。');
    }

    await page.waitForTimeout(1200);
    await clickByText(page, ['确认发布', '确定发布', '继续发布', '发布'], 2500);

    await page.waitForLoadState('domcontentloaded', { timeout: 10000 }).catch(() => null);
    await page.waitForTimeout(3000);
    const remoteUrl = page.url();
    const looksPublished = /zhihu\.com\/(p|question|answer)\//.test(remoteUrl)
      || await page.getByText(/发布成功|已发布|审核中/).first().isVisible().catch(() => false);

    if (!looksPublished) {
      throw new Error('知乎没有返回发布成功状态。可能还停在编辑页、审核弹窗、验证码或登录验证页面。');
    }

    writeResult(outputPath, { ok: true, remote_url: remoteUrl });
  } finally {
    await context.close();
  }
}

main().catch((error) => {
  const outputPath = process.argv[3];
  const rawMessage = error.message || String(error);
  const message = /Target page, context or browser has been closed/.test(rawMessage)
    ? '自动化浏览器或标签页被关闭了，发布已中断。请重新执行，并在完成前不要关闭自动化 Chrome 窗口。'
    : rawMessage;
  if (outputPath) {
    writeResult(outputPath, { ok: false, error: message });
  } else {
    console.error(message);
  }
  process.exit(1);
});
