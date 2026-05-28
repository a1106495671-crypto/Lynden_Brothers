/**
 * Kimi (kimi.ai) browser monitor
 * Enables web search, sends keyword, extracts response
 */

const KIMI_URL = 'https://kimi.ai';
const RESPONSE_STABLE_MS = 3000;
const MAX_WAIT_MS = 90000;

async function monitorKimi(page, keyword, brandName) {
  await page.goto(KIMI_URL, { waitUntil: 'domcontentloaded', timeout: 30000 });

  // Check login state
  await page.waitForTimeout(2000);
  const currentUrl = page.url();
  const loggedIn = !currentUrl.includes('/login') && !currentUrl.includes('/signin');

  if (!loggedIn) {
    throw new Error('Kimi: not logged in — please update cookies');
  }

  // Wait for chat input to appear
  const inputSelector = await findInputSelector(page, [
    '[data-testid="msh-chatinput-editor"]',
    '.chat-input [contenteditable]',
    'textarea',
    '[contenteditable="true"]',
  ]);

  if (!inputSelector) {
    throw new Error('Kimi: could not find chat input');
  }

  // Enable web search toggle if visible
  await tryEnableWebSearch(page, [
    '[data-testid="msh-chatinput-search-btn"]',
    'button[aria-label*="联网"]',
    'button[title*="联网"]',
    'button[aria-label*="搜索"]',
    '.search-toggle',
  ]);

  // Type keyword
  await page.click(inputSelector);
  await page.waitForTimeout(300);
  await page.fill(inputSelector, keyword).catch(async () => {
    await page.click(inputSelector);
    await page.keyboard.type(keyword, { delay: 30 });
  });

  // Submit
  await page.keyboard.press('Enter');
  await page.waitForTimeout(1000);

  // Wait for response
  const responseText = await waitForStableResponse(page, [
    '.chat-render .markdown-body',
    '.message-content',
    '[data-testid="chat-message-content"]',
    '.segment-content',
    '.chat-message.assistant .content',
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
        el.getAttribute('data-active') === 'true'
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
        // Get the last (most recent) message
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

module.exports = { monitorKimi };
