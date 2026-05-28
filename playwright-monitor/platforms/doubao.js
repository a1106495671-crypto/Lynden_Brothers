/**
 * 豆包 (doubao.com) browser monitor
 */

const DOUBAO_URL = 'https://www.doubao.com/chat/';
const RESPONSE_STABLE_MS = 3000;
const MAX_WAIT_MS = 90000;

async function monitorDoubao(page, keyword, brandName) {
  await page.goto(DOUBAO_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });
  await page.waitForTimeout(2500);

  const currentUrl = page.url();
  const loggedIn = !currentUrl.includes('/login') && !currentUrl.includes('/passport');

  if (!loggedIn) {
    throw new Error('豆包: not logged in — please update cookies');
  }

  const inputSelector = await findInputSelector(page, [
    '[data-testid="send_input"]',
    'textarea[placeholder*="发送"]',
    'textarea[placeholder*="输入"]',
    'textarea',
    '[contenteditable="true"]',
  ]);

  if (!inputSelector) {
    throw new Error('豆包: could not find chat input');
  }

  // Enable web search if available
  await tryEnableWebSearch(page, [
    'button[aria-label*="搜索增强"]',
    'button[aria-label*="联网"]',
    '[data-testid*="search"]',
    'button:has-text("搜索增强")',
  ]);

  await page.click(inputSelector);
  await page.waitForTimeout(300);
  await page.fill(inputSelector, keyword).catch(async () => {
    await page.keyboard.type(keyword, { delay: 30 });
  });

  await page.keyboard.press('Enter');
  await page.waitForTimeout(1500);

  const responseText = await waitForStableResponse(page, [
    '.markdown-body',
    '[class*="messageContent"]',
    '[class*="chat-message"]:last-child [class*="content"]',
    'div[class*="receive"] div[class*="content"]',
    '[data-testid="receive_message"] [class*="content"]',
  ]);

  return { response_text: responseText, logged_in: loggedIn };
}

async function findInputSelector(page, selectors) {
  for (const sel of selectors) {
    try {
      const el = await page.waitForSelector(sel, { timeout: 5000, state: 'visible' });
      if (el) return sel;
    } catch (_) {}
  }
  return null;
}

async function tryEnableWebSearch(page, selectors) {
  for (const sel of selectors) {
    try {
      const btn = await page.$(sel);
      if (!btn) continue;
      const isActive = await btn.evaluate(el =>
        el.classList.contains('active') ||
        el.getAttribute('aria-pressed') === 'true' ||
        el.getAttribute('data-state') === 'on'
      );
      if (!isActive) {
        await btn.click();
        await page.waitForTimeout(500);
      }
      return true;
    } catch (_) {}
  }
  return false;
}

async function waitForStableResponse(page, selectors) {
  const startMs = Date.now();
  let lastText = '';
  let stableSince = 0;

  while (Date.now() - startMs < MAX_WAIT_MS) {
    await page.waitForTimeout(1000);

    let currentText = '';
    for (const sel of selectors) {
      try {
        const elements = await page.$$(sel);
        if (elements.length === 0) continue;
        const lastEl = elements[elements.length - 1];
        const text = await lastEl.innerText();
        if (text && text.length > currentText.length) {
          currentText = text;
        }
      } catch (_) {}
    }

    if (currentText.length > 0) {
      if (currentText === lastText) {
        if (stableSince === 0) stableSince = Date.now();
        if (Date.now() - stableSince >= RESPONSE_STABLE_MS) {
          return currentText;
        }
      } else {
        stableSince = 0;
        lastText = currentText;
      }
    }
  }

  return lastText || '';
}

module.exports = { monitorDoubao };
