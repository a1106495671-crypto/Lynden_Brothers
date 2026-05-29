const express = require('express');
const { chromium } = require('playwright');
const { monitorKimi } = require('./platforms/kimi');
const { monitorDeepSeek } = require('./platforms/deepseek');
const { monitorDoubao } = require('./platforms/doubao');
const { monitorTongyi } = require('./platforms/tongyi');

const app = express();
app.use(express.json({ limit: '10mb' }));

const PORT = process.env.PORT || 3456;

const PLATFORM_MAP = {
  kimi: monitorKimi,
  deepseek: monitorDeepSeek,
  doubao: monitorDoubao,
  tongyi: monitorTongyi,
};

app.get('/health', (req, res) => res.json({ ok: true, ts: Date.now() }));

/**
 * POST /monitor
 * Body: { platform, keyword, brand_name, cookies: [...] }
 * Returns: { success, response_text, brand_mentioned, mention_count, error? }
 */
app.post('/monitor', async (req, res) => {
  const { platform, keyword, brand_name, cookies } = req.body || {};

  if (!platform || !keyword || !brand_name) {
    return res.status(400).json({ success: false, error: 'Missing: platform, keyword, brand_name' });
  }

  const handler = PLATFORM_MAP[platform];
  if (!handler) {
    return res.status(400).json({ success: false, error: `Unknown platform: ${platform}` });
  }

  let browser;
  try {
    browser = await chromium.launch({
      headless: true,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--lang=zh-CN',
      ],
    });

    const context = await browser.newContext({
      userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
      locale: 'zh-CN',
      timezoneId: 'Asia/Shanghai',
      viewport: { width: 1280, height: 800 },
    });

    if (Array.isArray(cookies) && cookies.length > 0) {
      await context.addCookies(cookies);
    }

    const page = await context.newPage();
    page.setDefaultTimeout(60000);

    console.log(`[${platform}] Monitoring keyword: "${keyword}"`);
    const result = await handler(page, keyword, brand_name);

    const responseText = result.response_text || '';
    const brandMentioned = checkBrandMention(responseText, brand_name);
    const mentionCount = countMentions(responseText, brand_name);

    res.json({
      success: true,
      platform,
      keyword,
      response_text: responseText,
      brand_mentioned: brandMentioned,
      mention_count: mentionCount,
      logged_in: result.logged_in ?? true,
    });

  } catch (err) {
    console.error(`[${platform}] Error: ${err.message}`);
    res.status(500).json({
      success: false,
      platform,
      keyword,
      error: err.message,
      brand_mentioned: false,
      mention_count: 0,
    });
  } finally {
    if (browser) await browser.close().catch(() => {});
  }
});

function checkBrandMention(text, brandName) {
  if (!text || !brandName) return false;
  const aliases = buildAliases(brandName);
  return aliases.some(alias => text.toLowerCase().includes(alias.toLowerCase()));
}

function countMentions(text, brandName) {
  if (!text || !brandName) return 0;
  const aliases = buildAliases(brandName);
  let count = 0;
  for (const alias of aliases) {
    const re = new RegExp(escapeRegex(alias), 'gi');
    const matches = text.match(re);
    if (matches) count += matches.length;
  }
  return count;
}

function buildAliases(brandName) {
  const aliases = [brandName];
  // 常见缩写：取前两个汉字
  const short = brandName.replace(/\s+/g, '').substring(0, 2);
  if (short && short !== brandName) aliases.push(short);
  return [...new Set(aliases)];
}

function escapeRegex(str) {
  return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

app.listen(PORT, () => {
  console.log(`Playwright monitor service running on port ${PORT}`);
});
