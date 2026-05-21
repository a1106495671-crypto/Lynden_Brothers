#!/usr/bin/env node

const fs = require('fs');
const path = require('path');

function loadPlaywright() {
  try {
    return require('playwright');
  } catch (error) {
    return null;
  }
}

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function loadDotEnv(filePath) {
  if (!fs.existsSync(filePath)) return;
  const lines = fs.readFileSync(filePath, 'utf8').split(/\r?\n/);
  for (const rawLine of lines) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#') || !line.includes('=')) continue;
    const index = line.indexOf('=');
    const key = line.slice(0, index).trim();
    let value = line.slice(index + 1).trim();
    if (!key || process.env[key]) continue;
    value = value.replace(/^['"]|['"]$/g, '');
    process.env[key] = value;
  }
}

function ensureDir(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function formatDate(date = new Date()) {
  const pad = (value) => String(value).padStart(2, '0');
  return [
    date.getFullYear(),
    pad(date.getMonth() + 1),
    pad(date.getDate()),
  ].join('-');
}

function formatDateTime(date = new Date()) {
  const pad = (value) => String(value).padStart(2, '0');
  return `${formatDate(date)} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
}

function formatRunSuffix(date = new Date()) {
  const pad = (value) => String(value).padStart(2, '0');
  const ms = String(date.getMilliseconds()).padStart(3, '0');
  return `${pad(date.getHours())}${pad(date.getMinutes())}${pad(date.getSeconds())}${ms}`;
}

function slug(value) {
  return String(value || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9\u4e00-\u9fa5]+/g, '-')
    .replace(/^-+|-+$/g, '')
    || 'run';
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function csvCell(value) {
  let text = Array.isArray(value) ? value.join('；') : String(value ?? '');
  if (/^[=+\-@]/.test(text)) {
    text = `'${text}`;
  }
  return `"${text.replace(/"/g, '""')}"`;
}

function clampNumber(value, min, max, fallback) {
  const number = Number(value);
  if (!Number.isFinite(number)) return fallback;
  return Math.max(min, Math.min(max, number));
}

function percent(numerator, denominator) {
  return denominator > 0 ? Math.round((numerator / denominator) * 100) : 0;
}

function formatRate(value) {
  return Number.isFinite(Number(value)) ? `${Math.round(Number(value))}%` : '-';
}

function formatRank(value) {
  return Number.isFinite(Number(value)) && Number(value) > 0 ? Number(value).toFixed(Number(value) % 1 === 0 ? 0 : 1) : '-';
}

function parseArgs(argv) {
  const args = {
    command: 'run',
    config: 'config/geo-monitor.example.json',
  };

  for (let index = 2; index < argv.length; index++) {
    const value = argv[index];
    if (value === 'run' || value === 'report') {
      args.command = value;
    } else if (value === '--config') {
      args.config = argv[++index];
    } else if (value === '--out') {
      args.out = argv[++index];
    } else if (value === '--no-pdf') {
      args.noPdf = true;
    } else if (value === '--input') {
      args.input = argv[++index];
    }
  }

  return args;
}

function buildPrompt(config, keyword) {
  const definitions = config.monitoring?.termDefinitions || [
    {
      term: 'GEO',
      definition: 'Generative Engine Optimization，生成式引擎优化 / AI搜索优化 / AI回答推荐优化，不是地理信息、测绘、地图、定位、GIS 或投放服务。',
    },
  ];
  const definitionLines = definitions
    .filter((item) => item?.term && item?.definition && String(keyword.keyword || '').includes(String(item.term)))
    .map((item) => `${item.term}：${item.definition}`);

  return [
    `请以真实用户视角回答这个问题：${keyword.keyword}`,
    '',
    '任务要求：',
    '1. 请模拟真实用户向 AI 咨询“推荐、选择、比较某类服务商/产品”的回答方式。',
    '2. 如果问题要求推荐，请给出你会优先考虑的品牌、服务商或产品，并说明推荐理由；可以按顺序列出推荐名单。',
    '3. 请基于通用认知、公开信息和可验证线索回答。',
    '4. 如果你不了解某个品牌，不要编造案例、资质、数据或客户评价。',
    '5. 如果问题里有行业缩写或专有名词，请按下方术语说明理解；没有说明的按用户问题原意理解。',
    ...definitionLines,
    '6. 请尽量给出本次回答实际采信或参考的公开来源链接，优先选择官网、案例页、媒体报道、百科/知识库、平台详情页、行业文章。',
    '7. sources 只允许填写你确信真实存在、且和回答内容直接相关的 URL；不要为了凑数编造链接、占位链接或只写域名。',
    '8. 如果当前模型无法联网、无法确认真实 URL，或回答没有实际采信任何网页来源，sources 必须返回空数组。',
    '仅输出 JSON，不要输出 Markdown，不要额外解释。',
    'recommended_entities 只填写回答里明确推荐或提到的品牌、公司、产品、服务商名称，不要填写“专业服务商”“本地团队”“有案例的公司”这类泛称。',
    'JSON 格式必须是：{"answer":"...","recommended_entities":["品牌或服务商名称"],"sources":[{"title":"来源名","url":"https://..."}]}',
  ].join('\n');
}

function normalizeErrorMessage(message) {
  const text = String(message || '').trim();
  if (!text) return '未知错误';
  if (/has not activated the model/i.test(text) || /activate the model service/i.test(text)) {
    return '模型未开通：当前账号未激活该模型（请到对应模型平台开通后重试）';
  }
  if (/未配置环境变量/.test(text)) {
    return text;
  }
  if (/请求超时/.test(text)) {
    return text;
  }
  return text.replace(/\s+/g, ' ').slice(0, 280);
}

function parseModelJson(content) {
  const raw = String(content || '').trim();
  if (!raw) return null;

  const candidates = [raw];
  const fenced = raw.match(/```(?:json)?\s*([\s\S]*?)\s*```/i);
  if (fenced && fenced[1]) candidates.push(fenced[1].trim());

  const braceStart = raw.indexOf('{');
  const braceEnd = raw.lastIndexOf('}');
  if (braceStart >= 0 && braceEnd > braceStart) {
    candidates.push(raw.slice(braceStart, braceEnd + 1));
  }

  for (const item of candidates) {
    try {
      const parsed = JSON.parse(item);
      if (parsed && typeof parsed === 'object') {
        return parsed;
      }
    } catch (error) {
      // try next candidate
    }
  }

  return null;
}

async function callOpenAICompatible(provider, messages) {
  if (!provider.baseUrl || !provider.model) {
    throw new Error('未配置 baseUrl 或 model');
  }

  const apiKey = process.env[provider.apiKeyEnv || ''];
  if (!apiKey) {
    throw new Error(`未配置环境变量 ${provider.apiKeyEnv}`);
  }

  const defaultTimeoutMs = provider.id === 'doubao' ? 60000 : 30000;
  const timeoutMs = Number(provider.timeoutMs || process.env.GEO_MONITOR_REQUEST_TIMEOUT_MS || defaultTimeoutMs);
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), timeoutMs);

  const endpoint = `${provider.baseUrl.replace(/\/+$/, '')}/chat/completions`;
  let response;
  try {
    response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${apiKey}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        model: provider.model,
        messages,
        temperature: 0.2,
      }),
      signal: controller.signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw new Error(`请求超时（${timeoutMs}ms）`);
    }
    throw error;
  } finally {
    clearTimeout(timeout);
  }

  const text = await response.text();
  let payload = null;
  try {
    payload = JSON.parse(text);
  } catch (error) {
    throw new Error(`接口返回非 JSON：${text.slice(0, 300)}`);
  }

  if (!response.ok) {
    throw new Error(payload?.error?.message || payload?.message || `HTTP ${response.status}`);
  }

  return payload?.choices?.[0]?.message?.content || '';
}

async function callProviderWithRetry(provider, messages) {
  const maxAttempts = Number(provider.retryAttempts || process.env.GEO_MONITOR_RETRY_ATTEMPTS || 2);
  let lastError;
  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      return await callOpenAICompatible(provider, messages);
    } catch (error) {
      lastError = error;
      const message = error?.message || String(error);
      const retryable = /请求超时|timeout|ECONNRESET|ETIMEDOUT|fetch failed/i.test(message);
      if (!retryable || attempt >= maxAttempts) {
        throw error;
      }
      await new Promise((resolve) => setTimeout(resolve, attempt * 1200));
    }
  }
  throw lastError;
}

function findMentions(answer, names) {
  const normalized = String(answer || '').toLowerCase();
  return [...new Set((names || []).filter((name) => {
    const value = String(name || '').trim();
    return value && normalized.includes(value.toLowerCase());
  }))];
}

function normalizeName(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, '');
}

function nameMatches(value, names) {
  const normalized = normalizeName(value);
  if (!normalized) return false;
  return (names || []).some((name) => {
    const target = normalizeName(name);
    return target && (normalized === target || normalized.includes(target) || target.includes(normalized));
  });
}

function containsKnownName(text, names) {
  return (names || []).some((name) => {
    const target = String(name || '').trim();
    return target && String(text || '').toLowerCase().includes(target.toLowerCase());
  });
}

function extractRecommendedEntities(answer, knownNames = []) {
  const text = String(answer || '');
  const known = new Set((knownNames || []).map((name) => String(name || '').trim()).filter(Boolean));
  const candidates = [];
  const patterns = [
    /(?:^|\n|\s)(?:\d+|[一二三四五六七八九十])[\.\、]\s*([^：:\n，。；;（）()]{2,24})(?:[：:，。；;\n（(])/g,
    /(?:推荐|优先考虑|可以考虑|值得了解|服务商包括|例如|比如)[^。；;\n]*?([A-Za-z0-9\u4e00-\u9fa5]{2,24}(?:科技|集团|营销|互动|网络|GEO|公司|品牌|服务商))/g,
  ];

  for (const pattern of patterns) {
    for (const match of text.matchAll(pattern)) {
      let name = String(match[1] || '').trim();
      name = name.replace(/^(第[一二三四五六七八九十]+优先级|第一优先级|第二优先级|第三优先级|优先选|先看|核心看|服务商方向)\s*[:：]?\s*/, '');
      name = name.replace(/^(可考虑|推荐|比如|例如)\s*/, '');
      name = name.replace(/[，。；;：:\s].*$/, '').trim();
      if (name.length < 2 || name.length > 18) continue;
      if (/公司怎么选|怎么判断|服务商哪家好|哪些公司|什么样/.test(name)) continue;
      if (/^(如果|因为|可以|建议|这类|第一|第二|第三|核心|服务|平台|品牌|企业|用户|团队|公司|服务商|比较靠谱的公司|自家品牌)$/.test(name)) continue;
      if (/(经验|能力|案例|项目|透明|监测|熟悉|本地|垂直|头部|专业|优先|服务商|团队|业务|公司已经|这类|哪些|怎样|什么)/.test(name)) continue;
      if (!known.has(name)) candidates.push(name);
    }
  }

  return [...new Set(candidates)].slice(0, 10);
}

function rankMentionObjects(answer, clientNames, competitors, discoveredCompetitors = []) {
  const text = String(answer || '').toLowerCase();
  const all = [];
  const pushRank = (name, type) => {
    const value = String(name || '').trim();
    if (!value) return;
    const pos = text.indexOf(value.toLowerCase());
    if (pos >= 0) {
      all.push({ name: value, type, pos });
    }
  };

  for (const name of clientNames || []) pushRank(name, '客户');
  for (const name of competitors || []) pushRank(name, '竞品');
  for (const name of discoveredCompetitors || []) pushRank(name, '自动发现对照');

  const dedup = [];
  const seen = new Set();
  for (const item of all.sort((a, b) => a.pos - b.pos)) {
    const key = `${item.type}::${item.name.toLowerCase()}`;
    if (seen.has(key)) continue;
    seen.add(key);
    dedup.push(item);
  }

  return dedup.map((item, index) => ({
    rank: index + 1,
    name: item.name,
    type: item.type,
  }));
}

function rankMentionObjectsFromEntities(entities, clientNames, competitors) {
  const seen = new Set();
  const ranked = [];
  for (const entity of entities || []) {
    const value = String(entity || '').trim();
    if (!value) continue;
    const key = value.toLowerCase();
    if (seen.has(key)) continue;
    seen.add(key);

    let type = '自动发现对照';
    if (nameMatches(value, clientNames)) {
      type = '客户';
    } else if (nameMatches(value, competitors)) {
      type = '竞品';
    }
    ranked.push({ name: value, type });
  }

  return ranked.map((item, index) => ({
    rank: index + 1,
    name: item.name,
    type: item.type,
  }));
}

function rankMentions(answer, clientNames, competitors, discoveredCompetitors = []) {
  return rankMentionObjects(answer, clientNames, competitors, discoveredCompetitors)
    .map((item) => `${item.rank}.${item.name}（${item.type}）`);
}

function firstRankByType(ranking, type) {
  const hit = (ranking || []).find((item) => item.type === type);
  return hit ? hit.rank : null;
}

function keywordContainsClient(keyword, clientNames) {
  return containsKnownName(keyword, clientNames);
}

function extractSources(answer) {
  const text = String(answer || '');
  const urls = [...text.matchAll(/https?:\/\/[^\s)）\]】"'<>]+/g)].map((match) => match[0]);
  return [...new Set(urls)].slice(0, 12);
}

function summarizeAnswer(answer) {
  return String(answer || '')
    .replace(/[*#`>\-\[\]]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 800);
}

async function askProvider(config, provider, keyword, sampleIndex = 1, sampleCount = 1) {
  const project = config.project || {};
  const clientNames = [project.clientName, ...(project.clientAliases || [])].filter(Boolean);
  const competitors = project.competitors || [];
  const startedAt = new Date();

  try {
    const answer = await callProviderWithRetry(provider, [
      {
        role: 'system',
        content: '你是一个中文 AI 搜索/问答平台。请像真实用户咨询时那样，回答推荐、选择、比较类问题。若问题中的 GEO 指营销服务，它表示 Generative Engine Optimization（生成式引擎优化 / AI搜索优化 / AI回答推荐优化），不是地理信息、地图、定位、测绘或 GIS。',
      },
      {
        role: 'user',
        content: buildPrompt(config, keyword),
      },
    ]);

    const parsed = parseModelJson(answer);
    const normalizedAnswer = parsed && typeof parsed.answer === 'string'
      ? parsed.answer.trim()
      : String(answer || '').trim();
    const parsedSources = Array.isArray(parsed?.sources) ? parsed.sources : [];
    const parsedEntities = Array.isArray(parsed?.recommended_entities) ? parsed.recommended_entities : [];
    const normalizedSources = parsedSources
      .map((item) => {
        if (typeof item === 'string') return item.trim();
        if (!item || typeof item !== 'object') return '';
        const title = String(item.title || '').trim();
        const url = String(item.url || '').trim();
        if (title && url) return `${title} ${url}`;
        return url || title;
      })
      .filter(Boolean)
      .slice(0, 12);

    const recommendedEntities = parsedEntities
      .map((item) => String(item || '').trim())
      .filter((item) => item.length >= 2 && item.length <= 24);
    const recommendedClientEntities = recommendedEntities.filter((item) => nameMatches(item, clientNames));
    const recommendedKnownCompetitors = recommendedEntities.filter((item) => nameMatches(item, competitors));
    const clientMentions = [...new Set([...findMentions(normalizedAnswer, clientNames), ...recommendedClientEntities])];
    const competitorMentions = [...new Set([...findMentions(normalizedAnswer, competitors), ...recommendedKnownCompetitors])];
    const discoveredCompetitors = recommendedEntities.length > 0
      ? recommendedEntities.filter((item) => !nameMatches(item, clientNames) && !nameMatches(item, competitors))
      : extractRecommendedEntities(normalizedAnswer, [...clientNames, ...competitors]);
    const allCompetitorMentions = [...new Set([...competitorMentions, ...discoveredCompetitors])];
    const mentionRankingObjects = recommendedEntities.length > 0
      ? rankMentionObjectsFromEntities(recommendedEntities, clientNames, competitors)
      : rankMentionObjects(normalizedAnswer, clientNames, competitors, discoveredCompetitors);
    const mentionRanking = mentionRankingObjects.map((item) => `${item.rank}.${item.name}（${item.type}）`);
    const clientRank = firstRankByType(mentionRankingObjects, '客户');
    const bestCompetitorRank = firstRankByType(mentionRankingObjects, '竞品') || firstRankByType(mentionRankingObjects, '自动发现对照');
    const brandQuery = keywordContainsClient(keyword.keyword, clientNames);
    return {
      ok: true,
      checked_at: formatDateTime(startedAt),
      provider_id: provider.id,
      provider_name: provider.name,
      keyword: keyword.keyword,
      intent: keyword.intent || '',
      sample_index: sampleIndex,
      sample_count: sampleCount,
      keyword_contains_client: brandQuery,
      client_mentioned: clientMentions.length > 0,
      client_mentions: clientMentions,
      client_rank: clientRank,
      competitor_mentioned: allCompetitorMentions.length > 0,
      competitor_mentions: allCompetitorMentions,
      best_competitor_rank: bestCompetitorRank,
      discovered_competitors: discoveredCompetitors,
      mention_ranking: mentionRanking,
      sources: normalizedSources.length > 0 ? normalizedSources : extractSources(normalizedAnswer),
      answer_summary: summarizeAnswer(normalizedAnswer),
      answer: normalizedAnswer,
      error: '',
    };
  } catch (error) {
    return {
      ok: false,
      checked_at: formatDateTime(startedAt),
      provider_id: provider.id,
      provider_name: provider.name,
      keyword: keyword.keyword,
      intent: keyword.intent || '',
      sample_index: sampleIndex,
      sample_count: sampleCount,
      keyword_contains_client: keywordContainsClient(keyword.keyword, clientNames),
      client_mentioned: false,
      client_mentions: [],
      client_rank: null,
      competitor_mentioned: false,
      competitor_mentions: [],
      best_competitor_rank: null,
      discovered_competitors: [],
      mention_ranking: [],
      sources: [],
      answer_summary: '',
      answer: '',
      error: normalizeErrorMessage(error.message || String(error)),
    };
  }
}

function makeMetricBucket(extra = {}) {
  return {
    total: 0,
    ok: 0,
    visible: 0,
    naturalOk: 0,
    naturalVisible: 0,
    competitor: 0,
    sourceRows: 0,
    rankSum: 0,
    rankCount: 0,
    bestClientRank: null,
    averageClientRank: null,
    visibilityRate: 0,
    naturalVisibilityRate: 0,
    competitorRate: 0,
    sourceCoverageRate: 0,
    ...extra,
  };
}

function addMetric(bucket, item) {
  bucket.total++;
  if (item.ok) bucket.ok++;
  if (item.ok && !item.keyword_contains_client) bucket.naturalOk++;
  if (item.client_mentioned) bucket.visible++;
  if (item.client_mentioned && !item.keyword_contains_client) bucket.naturalVisible++;
  if (item.competitor_mentioned) bucket.competitor++;
  if (item.ok && Array.isArray(item.sources) && item.sources.length > 0) bucket.sourceRows++;

  let rank = 0;
  for (const ranking of item.mention_ranking || []) {
    const match = String(ranking || '').match(/^(\d+(?:\.\d+)?)\..*（客户）/u);
    if (match) {
      rank = Number(match[1] || 0);
      break;
    }
  }
  if (!(rank > 0)) {
    rank = Number(item.client_rank || 0);
  }
  if (item.ok && item.client_mentioned && rank > 0) {
    bucket.rankSum += rank;
    bucket.rankCount++;
    bucket.bestClientRank = bucket.bestClientRank === null ? rank : Math.min(bucket.bestClientRank, rank);
  }
}

function finalizeMetric(bucket) {
  bucket.visibilityRate = percent(bucket.visible, bucket.ok);
  bucket.naturalVisibilityRate = percent(bucket.naturalVisible, bucket.naturalOk);
  bucket.competitorRate = percent(bucket.competitor, bucket.ok);
  bucket.sourceCoverageRate = percent(bucket.sourceRows, bucket.ok);
  bucket.averageClientRank = bucket.rankCount > 0 ? Number((bucket.rankSum / bucket.rankCount).toFixed(1)) : null;
  delete bucket.rankSum;
  delete bucket.rankCount;
  return bucket;
}

function computeSummary(results) {
  const completed = results.filter((item) => item.ok);
  const naturalCompleted = completed.filter((item) => !item.keyword_contains_client);
  const visible = completed.filter((item) => item.client_mentioned);
  const naturalVisible = naturalCompleted.filter((item) => item.client_mentioned);
  const competitor = completed.filter((item) => item.competitor_mentioned);
  const byProvider = {};
  const byKeyword = {};
  const byProviderKeyword = {};
  const overall = makeMetricBucket();

  for (const item of results) {
    addMetric(overall, item);

    if (!byProvider[item.provider_name]) {
      byProvider[item.provider_name] = makeMetricBucket({
        provider_id: item.provider_id,
        provider_name: item.provider_name,
      });
    }
    addMetric(byProvider[item.provider_name], item);

    if (!byKeyword[item.keyword]) {
      byKeyword[item.keyword] = makeMetricBucket({
        keyword: item.keyword,
        intent: item.intent || '',
        keyword_contains_client: item.keyword_contains_client,
      });
    }
    addMetric(byKeyword[item.keyword], item);

    const providerKeywordKey = `${item.provider_name}||${item.keyword}`;
    if (!byProviderKeyword[providerKeywordKey]) {
      byProviderKeyword[providerKeywordKey] = makeMetricBucket({
        provider_id: item.provider_id,
        provider_name: item.provider_name,
        keyword: item.keyword,
        intent: item.intent || '',
        keyword_contains_client: item.keyword_contains_client,
      });
    }
    addMetric(byProviderKeyword[providerKeywordKey], item);
  }

  finalizeMetric(overall);
  for (const row of Object.values(byProvider)) finalizeMetric(row);
  for (const row of Object.values(byKeyword)) finalizeMetric(row);
  for (const row of Object.values(byProviderKeyword)) finalizeMetric(row);

  return {
    projectKey: '',
    generatedAt: formatDateTime(),
    total: results.length,
    ok: completed.length,
    failed: results.length - completed.length,
    visible: visible.length,
    naturalOk: naturalCompleted.length,
    naturalVisible: naturalVisible.length,
    competitor: competitor.length,
    sourceRows: overall.sourceRows,
    visibilityRate: overall.visibilityRate,
    naturalVisibilityRate: overall.naturalVisibilityRate,
    competitorRate: overall.competitorRate,
    sourceCoverageRate: overall.sourceCoverageRate,
    averageClientRank: overall.averageClientRank,
    bestClientRank: overall.bestClientRank,
    sampleCount: Math.max(1, ...results.map((item) => Number(item.sample_count || 1))),
    byProvider,
    byKeyword,
    providerKeywordRows: Object.values(byProviderKeyword)
      .sort((a, b) => a.provider_name.localeCompare(b.provider_name, 'zh-CN') || a.keyword.localeCompare(b.keyword, 'zh-CN')),
  };
}

function trendFor(current, previous) {
  if (!previous) {
    return {
      direction: 'baseline',
      visibilityRateDelta: null,
      naturalVisibilityRateDelta: null,
      averageRankDelta: null,
    };
  }

  const visibilityRateDelta = Number(current.visibilityRate || 0) - Number(previous.visibilityRate || 0);
  const naturalVisibilityRateDelta = Number(current.naturalVisibilityRate || 0) - Number(previous.naturalVisibilityRate || 0);
  let averageRankDelta = null;
  if (current.averageClientRank !== null && previous.averageClientRank !== null) {
    averageRankDelta = Number((Number(current.averageClientRank) - Number(previous.averageClientRank)).toFixed(1));
  }

  let direction = 'flat';
  if (visibilityRateDelta > 0 || (visibilityRateDelta === 0 && averageRankDelta !== null && averageRankDelta < 0)) {
    direction = 'up';
  } else if (visibilityRateDelta < 0 || (visibilityRateDelta === 0 && averageRankDelta !== null && averageRankDelta > 0)) {
    direction = 'down';
  }

  return { direction, visibilityRateDelta, naturalVisibilityRateDelta, averageRankDelta };
}

function applyTrend(summary, previousSummary) {
  summary.trend = trendFor(summary, previousSummary);
  const previousProviders = previousSummary?.byProvider || {};
  for (const [name, row] of Object.entries(summary.byProvider || {})) {
    row.trend = trendFor(row, previousProviders[name]);
  }

  const previousKeywords = previousSummary?.byKeyword || {};
  for (const [keyword, row] of Object.entries(summary.byKeyword || {})) {
    row.trend = trendFor(row, previousKeywords[keyword]);
  }

  const previousProviderKeyword = {};
  for (const row of previousSummary?.providerKeywordRows || []) {
    previousProviderKeyword[`${row.provider_name}||${row.keyword}`] = row;
  }
  for (const row of summary.providerKeywordRows || []) {
    row.trend = trendFor(row, previousProviderKeyword[`${row.provider_name}||${row.keyword}`]);
  }

  return summary;
}

function mentionStatus(item) {
  if (!item.ok) return '无有效回答';
  return item.client_mentioned ? '提及' : '未提及';
}

function writeJsonl(filePath, rows) {
  fs.writeFileSync(filePath, rows.map((row) => JSON.stringify(row)).join('\n') + '\n');
}

function writeRunState(runDir, state) {
  fs.writeFileSync(path.join(runDir, 'run-state.json'), JSON.stringify({
    updatedAt: formatDateTime(),
    ...state,
  }, null, 2));
}

function writeCsv(filePath, rows) {
  const headers = [
    'checked_at',
    'provider_name',
    'keyword',
    'intent',
    'sample_index',
    'sample_count',
    'ok',
    'mention_status',
    'keyword_contains_client',
    'client_mentioned',
    'client_mentions',
    'client_rank',
    'competitor_mentioned',
    'competitor_mentions',
    'best_competitor_rank',
    'discovered_competitors',
    'mention_ranking',
    'sources',
    'answer_summary',
    'answer',
    'error',
  ];

  const content = [
    headers.map(csvCell).join(','),
    ...rows.map((row) => headers.map((header) => csvCell(header === 'mention_status' ? mentionStatus(row) : row[header])).join(',')),
  ].join('\n');

  fs.writeFileSync(filePath, content);
}

function readJsonl(filePath) {
  return fs.readFileSync(filePath, 'utf8')
    .split(/\r?\n/)
    .filter(Boolean)
    .map((line) => JSON.parse(line));
}

function projectKeyFromConfig(config) {
  const project = config.project || {};
  return slug(project.clientName || project.name || 'default');
}

function loadPreviousSummary(outputRoot, currentRunDir, config) {
  if (!fs.existsSync(outputRoot)) return null;
  const currentPath = path.resolve(currentRunDir);
  const projectKey = projectKeyFromConfig(config);
  const dirs = fs.readdirSync(outputRoot, { withFileTypes: true })
    .filter((item) => item.isDirectory())
    .map((item) => path.join(outputRoot, item.name))
    .filter((dir) => path.resolve(dir) !== currentPath)
    .filter((dir) => path.basename(dir).includes(projectKey) || fs.existsSync(path.join(dir, 'summary.json')))
    .sort((a, b) => fs.statSync(b).mtimeMs - fs.statSync(a).mtimeMs);

  for (const dir of dirs) {
    try {
      const summaryPath = path.join(dir, 'summary.json');
      if (fs.existsSync(summaryPath)) {
        const summary = readJson(summaryPath);
        if (!summary.projectKey || summary.projectKey === projectKey) {
          return summary;
        }
      }

      const resultsPath = path.join(dir, 'results.jsonl');
      if (fs.existsSync(resultsPath)) {
        const summary = computeSummary(readJsonl(resultsPath));
        summary.projectKey = projectKey;
        return applyTrend(summary, null);
      }
    } catch (error) {
      // Ignore malformed historical runs and keep scanning.
    }
  }

  return null;
}

function enrichSummary(config, summary, previousSummary) {
  const project = config.project || {};
  summary.projectKey = projectKeyFromConfig(config);
  summary.projectName = project.name || '';
  summary.clientName = project.clientName || '';
  summary.clientAliases = Array.isArray(project.clientAliases) ? project.clientAliases : [];
  summary.competitors = Array.isArray(project.competitors) ? project.competitors : [];
  return applyTrend(summary, previousSummary);
}

function renderTrendBadge(trend, field = 'visibilityRate') {
  if (!trend || trend.direction === 'baseline') {
    return '<span class="trend flat">基准</span>';
  }

  if (field === 'averageRankDelta') {
    if (trend.averageRankDelta === null) return '<span class="trend flat">排名无基准</span>';
    if (trend.averageRankDelta < 0) return `<span class="trend up">▲ 排名提升 ${Math.abs(trend.averageRankDelta).toFixed(1)}</span>`;
    if (trend.averageRankDelta > 0) return `<span class="trend down">▼ 排名下降 ${Math.abs(trend.averageRankDelta).toFixed(1)}</span>`;
    return '<span class="trend flat">→ 排名持平</span>';
  }

  const delta = field === 'naturalVisibilityRateDelta'
    ? trend.naturalVisibilityRateDelta
    : trend.visibilityRateDelta;
  if (delta === null || delta === undefined) return '<span class="trend flat">基准</span>';
  if (delta > 0) return `<span class="trend up">▲ +${delta}pp</span>`;
  if (delta < 0) return `<span class="trend down">▼ ${delta}pp</span>`;
  return '<span class="trend flat">→ 0pp</span>';
}

function buildReportHtml(config, results, summary = computeSummary(results)) {
  const title = config.report?.title || 'AI 搜索可见性监测报告';
  const project = config.project || {};
  const providerRows = Object.values(summary.byProvider).map((row) => `
    <tr>
      <td>${escapeHtml(row.provider_name)}</td>
      <td>${row.ok}/${row.total}</td>
      <td><strong>${formatRate(row.visibilityRate)}</strong><small>${row.visible}/${row.ok}</small></td>
      <td><strong>${formatRate(row.naturalVisibilityRate)}</strong><small>${row.naturalVisible}/${row.naturalOk}</small></td>
      <td>${formatRank(row.averageClientRank)}</td>
      <td>${formatRank(row.bestClientRank)}</td>
      <td>${formatRate(row.competitorRate)}</td>
      <td>${renderTrendBadge(row.trend)}</td>
    </tr>
  `).join('');

  const matrixRows = (summary.providerKeywordRows || []).map((row) => `
    <tr>
      <td>${escapeHtml(row.provider_name)}</td>
      <td>${escapeHtml(row.keyword)}</td>
      <td>${escapeHtml(row.intent || '-')}</td>
      <td>${row.keyword_contains_client ? '品牌词' : '泛推荐'}</td>
      <td>${row.ok}/${row.total}</td>
      <td><strong>${formatRate(row.visibilityRate)}</strong><small>${row.visible}/${row.ok}</small></td>
      <td>${formatRank(row.averageClientRank)}</td>
      <td>${formatRank(row.bestClientRank)}</td>
      <td>${renderTrendBadge(row.trend)}</td>
    </tr>
  `).join('');

  const resultRows = results.map((item) => `
    <tr>
      <td>${escapeHtml(item.provider_name)}</td>
      <td>${escapeHtml(item.keyword)}</td>
      <td>${item.sample_index || 1}/${item.sample_count || 1}</td>
      <td>${item.keyword_contains_client ? '品牌词' : '泛推荐'}</td>
      <td class="${!item.ok ? 'warn' : (item.client_mentioned ? 'yes' : 'no')}">${escapeHtml(mentionStatus(item))}</td>
      <td>${formatRank(item.client_rank)}</td>
      <td>${escapeHtml(item.client_mentions.join('、') || '-')}</td>
      <td>${escapeHtml(item.competitor_mentions.join('、') || '-')}</td>
      <td>${escapeHtml((item.mention_ranking || []).join('\n') || '-')}</td>
      <td>${escapeHtml(item.sources.join('\n') || '-')}</td>
      <td>${escapeHtml(item.answer || item.answer_summary || '-')}</td>
      <td>${escapeHtml(item.error || '-')}</td>
    </tr>
  `).join('');

  return `<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <title>${escapeHtml(title)}</title>
	  <style>
	    body { margin: 0; color: #172033; font-family: "PingFang SC", "Noto Sans CJK SC", Arial, sans-serif; background: #f5f7fb; }
	    .page { max-width: 1440px; margin: 0 auto; padding: 44px 40px 64px; background: #fff; }
	    .eyebrow { color: #64748b; font-size: 13px; text-transform: uppercase; }
	    h1 { margin: 10px 0 8px; font-size: 34px; line-height: 1.2; color: #0f172a; }
	    h2 { margin: 34px 0 14px; font-size: 20px; color: #111827; }
	    .meta { color: #64748b; font-size: 14px; }
	    .cards { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin: 28px 0; }
	    .card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; background: #fbfdff; }
	    .card strong { display: block; font-size: 28px; color: #0f172a; }
	    .card span { color: #64748b; font-size: 13px; }
	    .card .sub { margin-top: 8px; }
	    table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 13px; table-layout: fixed; }
	    th { background: #f1f5f9; color: #475569; text-align: left; font-weight: 600; }
	    th, td { border: 1px solid #e5e7eb; padding: 10px 12px; vertical-align: top; white-space: pre-line; }
	    td small { display: block; color: #64748b; margin-top: 3px; }
	    .trend { display: inline-flex; align-items: center; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; white-space: nowrap; }
	    .trend.up { color: #047857; background: #ecfdf5; }
	    .trend.down { color: #b91c1c; background: #fef2f2; }
	    .trend.flat { color: #475569; background: #f1f5f9; }
	    .yes { color: #047857; font-weight: 700; }
	    .no { color: #b91c1c; font-weight: 700; }
	    .warn { color: #b45309; font-weight: 700; }
	    .note { margin-top: 24px; color: #64748b; line-height: 1.8; font-size: 13px; }
	    .section-note { color: #64748b; font-size: 13px; margin: -6px 0 12px; }
	    @media (max-width: 980px) { .cards { grid-template-columns: repeat(2, 1fr); } .page { padding: 28px 18px; } }
	  </style>
	</head>
<body>
  <main class="page">
    <div class="eyebrow">GEO Visibility Monitor</div>
    <h1>${escapeHtml(title)}</h1>
    <div class="meta">${escapeHtml(project.name || project.clientName || '未命名项目')} · ${formatDateTime()}</div>

	    <section class="cards">
	      <div class="card"><strong>${formatRate(summary.visibilityRate)}</strong><span>总提及率</span><div class="sub">${renderTrendBadge(summary.trend)}</div></div>
	      <div class="card"><strong>${formatRate(summary.naturalVisibilityRate)}</strong><span>自然推荐提及率</span><div class="sub">${renderTrendBadge(summary.trend, 'naturalVisibilityRateDelta')}</div></div>
	      <div class="card"><strong>${formatRank(summary.averageClientRank)}</strong><span>平均提及排名</span><div class="sub">${renderTrendBadge(summary.trend, 'averageRankDelta')}</div></div>
	      <div class="card"><strong>${formatRank(summary.bestClientRank)}</strong><span>最好排名</span></div>
	      <div class="card"><strong>${formatRate(summary.competitorRate)}</strong><span>竞品出现率</span></div>
	      <div class="card"><strong>${summary.ok}/${summary.total}</strong><span>有效回答</span></div>
	    </section>

	    <h2>平台概览</h2>
	    <p class="section-note">按 AI 平台聚合：同一关键词可多次抽样，提及率按有效回答计算；排名越小越靠前。</p>
	    <table>
	      <thead><tr><th>平台</th><th>有效/总数</th><th>总提及率</th><th>自然推荐</th><th>平均排名</th><th>最好排名</th><th>竞品率</th><th>趋势</th></tr></thead>
	      <tbody>${providerRows}</tbody>
	    </table>

	    <h2>关键词 × AI 监测矩阵</h2>
	    <p class="section-note">这是核心监测表：能看到某个关键词在某个 AI 上的品牌提及率和排名波动。</p>
	    <table>
	      <thead><tr><th>平台</th><th>关键词</th><th>意图</th><th>词类型</th><th>有效/总数</th><th>提及率</th><th>平均排名</th><th>最好排名</th><th>趋势</th></tr></thead>
	      <tbody>${matrixRows}</tbody>
	    </table>

	    <h2>逐关键词明细</h2>
	    <table>
	      <thead><tr><th>平台</th><th>关键词</th><th>样本</th><th>词类型</th><th>客户</th><th>客户排名</th><th>提到客户</th><th>提到竞品</th><th>提及排名</th><th>引用/来源</th><th>完整回答</th><th>异常原因</th></tr></thead>
	      <tbody>${resultRows}</tbody>
	    </table>

	    <p class="note">监测口径：本报告统计的是配置中各 AI 模型/API 对指定问题的回答表现。泛推荐词不会在问题中出现客户品牌，更适合衡量自然推荐；品牌词用于验证 AI 对品牌的解释准确性。提及率按有效回答计算，请求超时、模型未开通、密钥缺失等情况不计入可见率分母。排名依据回答中客户、竞品和自动发现对照对象首次出现顺序估算。</p>
	  </main>
	</body>
	</html>`;
}

async function writePdf(htmlPath, pdfPath) {
  const playwright = loadPlaywright();
  if (!playwright) {
    return false;
  }

  let browser;
  try {
    browser = await playwright.chromium.launch({ channel: 'chrome', headless: true });
  } catch (error) {
    browser = await playwright.chromium.launch({ headless: true });
  }
  const page = await browser.newPage();
  await page.goto(`file://${htmlPath}`, { waitUntil: 'load' });
  await page.pdf({
    path: pdfPath,
    format: 'A4',
    printBackground: true,
    margin: { top: '12mm', right: '10mm', bottom: '12mm', left: '10mm' },
  });
  await browser.close();
  return true;
}

async function runMonitor(args) {
  const configPath = path.resolve(args.config);
  loadDotEnv(path.resolve(path.dirname(configPath), '..', '.env'));
  const config = readJson(configPath);
  const outputRoot = path.resolve(args.out || config.report?.outputDir || 'data/geo-monitor');
  const nonce = Math.random().toString(36).slice(2, 6);
  const runId = `${formatDate()}-${formatRunSuffix()}-${nonce}-${slug(config.project?.clientName || config.project?.name)}`;
  const runDir = path.join(outputRoot, runId);
  ensureDir(runDir);

  const providers = (config.providers || []).filter((provider) => provider.enabled !== false);
  const keywords = config.keywords || [];
  const sampleCount = clampNumber(config.monitoring?.sampleCount || config.monitoring?.samplesPerKeyword || 1, 1, 10, 1);
  if (providers.length === 0) {
    throw new Error('至少需要启用一个 AI 平台');
  }
  if (keywords.length === 0) {
    throw new Error('至少需要配置一个监测关键词');
  }
  const results = [];
  const totalTasks = providers.length * keywords.length * sampleCount;
  const previousSummary = loadPreviousSummary(outputRoot, runDir, config);
  const baseState = {
    status: 'running',
    completed: 0,
    totalTasks,
    providers: providers.map((item) => item.name),
    keywords: keywords.length,
    sampleCount,
    startedAt: formatDateTime(),
    error: '',
  };

  fs.writeFileSync(path.join(runDir, 'run-meta.json'), JSON.stringify({
    project: {
      name: config.project?.name || '',
      clientName: config.project?.clientName || '',
      projectKey: projectKeyFromConfig(config),
      clientAliases: Array.isArray(config.project?.clientAliases) ? config.project.clientAliases : [],
      competitors: Array.isArray(config.project?.competitors) ? config.project.competitors : [],
    },
    providers: baseState.providers,
    keywords: baseState.keywords,
    sampleCount: baseState.sampleCount,
    totalTasks: baseState.totalTasks,
    startedAt: baseState.startedAt,
  }, null, 2));
  writeRunState(runDir, baseState);

  try {
    for (const keyword of keywords) {
      for (const provider of providers) {
        for (let sampleIndex = 1; sampleIndex <= sampleCount; sampleIndex++) {
          console.log(`[${provider.name}] ${keyword.keyword} (${sampleIndex}/${sampleCount})`);
          const result = await askProvider(config, provider, keyword, sampleIndex, sampleCount);
          results.push(result);
          fs.writeFileSync(path.join(runDir, 'latest-progress.json'), JSON.stringify(results, null, 2));
          writeRunState(runDir, {
            ...baseState,
            completed: results.length,
            currentProvider: provider.name,
            currentKeyword: keyword.keyword,
            currentSample: sampleIndex,
          });
        }
      }
    }

    writeRunState(runDir, {
      ...baseState,
      status: 'building_report',
      completed: results.length,
    });

    const jsonlPath = path.join(runDir, 'results.jsonl');
    const csvPath = path.join(runDir, 'results.csv');
    const htmlPath = path.join(runDir, 'report.html');
    const pdfPath = path.join(runDir, 'report.pdf');
    const summaryPath = path.join(runDir, 'summary.json');
    const summary = enrichSummary(config, computeSummary(results), previousSummary);

    writeJsonl(jsonlPath, results);
    writeCsv(csvPath, results);
    fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2));
    fs.writeFileSync(htmlPath, buildReportHtml(config, results, summary));

    let pdfWritten = false;
    if (!args.noPdf) {
      pdfWritten = await writePdf(htmlPath, pdfPath);
    }

    writeRunState(runDir, {
      ...baseState,
      status: 'done',
      completed: results.length,
      finishedAt: formatDateTime(),
      artifacts: {
        jsonl: jsonlPath,
        csv: csvPath,
        html: htmlPath,
        summary: summaryPath,
        pdf: pdfWritten ? pdfPath : null,
      },
    });

    console.log(JSON.stringify({
      runDir,
      jsonl: jsonlPath,
      csv: csvPath,
      html: htmlPath,
      summary: summaryPath,
      pdf: pdfWritten ? pdfPath : null,
      metrics: summary,
    }, null, 2));
  } catch (error) {
    writeRunState(runDir, {
      ...baseState,
      status: 'failed',
      completed: results.length,
      error: error.message || String(error),
    });
    throw error;
  }
}

async function rebuildReport(args) {
  const configPath = path.resolve(args.config);
  const config = readJson(configPath);
  const inputPath = path.resolve(args.input || '');
  if (!inputPath || !fs.existsSync(inputPath)) {
    throw new Error('请通过 --input 指定已有 results.jsonl 文件');
  }

  const runDir = path.dirname(inputPath);
  const results = readJsonl(inputPath);
  const csvPath = path.join(runDir, 'results.csv');
  const htmlPath = path.join(runDir, 'report.html');
  const summaryPath = path.join(runDir, 'summary.json');
  const outputRoot = path.dirname(runDir);
  const previousSummary = loadPreviousSummary(outputRoot, runDir, config);
  const summary = enrichSummary(config, computeSummary(results), previousSummary);

  writeCsv(csvPath, results);
  fs.writeFileSync(summaryPath, JSON.stringify(summary, null, 2));
  fs.writeFileSync(htmlPath, buildReportHtml(config, results, summary));
  console.log(JSON.stringify({ runDir, csv: csvPath, html: htmlPath, summary: summaryPath, metrics: summary }, null, 2));
}

async function main() {
  const args = parseArgs(process.argv);
  if (args.command === 'report') {
    await rebuildReport(args);
    return;
  }
  await runMonitor(args);
}

main().catch((error) => {
  console.error(error.stack || error.message || String(error));
  process.exit(1);
});
