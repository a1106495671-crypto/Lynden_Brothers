<?php
/**
 * GEO语义优化系统
 *
 * 将AI生成内容从"普通文章"升级为"易被AI引用的结构化知识块"。
 * 三维评分：结构分(0-40) + 事实密度分(0-30) + 品牌合规分(0-30) = GEO总分(0-100)
 *
 * @version 1.0
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class GeoSemanticOptimizer {

    private $db;

    // 红线关键词（绝对禁止）
    private const RED_LINE_SUPERLATIVES = [
        '最好', '最强', '最优', '第一', '领先', '顶级', '一流', '无与伦比',
        '行业领先', '最佳', '卓越', '业界领先', '全国领先', '全球领先',
    ];

    private const RED_LINE_CTA = [
        '立即购买', '立即咨询', '立即了解', '马上购买', '点击购买',
        '联系我们', '了解更多', '免费试用', '免费咨询', '扫码', '扫一扫',
    ];

    private const RED_LINE_AI_CLICHE = [
        '作为一家', '我们致力于', '我们的使命', '致力于为', '秉承', '以客户为中心',
    ];

    // 6段结构信号词
    private const STRUCTURE_SIGNALS = [
        'anti_consensus' => ['但实际上', '其实', '事实上', '很多人误以为', '普遍认为', '然而', '反常识', '出人意料'],
        'industry_status' => ['行业现状', '目前', '当前', '市场上', '普遍做法', '行业痛点', '数据显示'],
        'user_pain'       => ['用户', '客户', '面临', '痛点', '困惑', '难点', '问题在于', '挑战'],
        'criteria'        => ['判断标准', '选择时', '评估', '核心指标', '关键是', '区别在于', '如何辨别'],
        'brand_case'      => ['案例', '实际场景', '举例', '比如说', '实践中', '验证过', '经过'],
        'decision_action' => ['建议', '推荐', '适合', '如果你', '下一步', '可以从', '实操'],
    ];

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * 从 geo_brand_facts 加载客户品牌事实
     */
    public function loadBrandFacts(string $customerId): array {
        if ($customerId === '') {
            return [];
        }
        try {
            $stmt = $this->db->prepare("
                SELECT fact_key, fact_label, fact_value, is_core
                FROM geo_brand_facts
                WHERE customer_id = ?
                ORDER BY is_core DESC, sort_order ASC
            ");
            $stmt->execute([$customerId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('GeoSemanticOptimizer::loadBrandFacts error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 获取品牌母句（master_sentence），AI生成时必须逐字出现
     */
    public function getMasterSentence(array $facts): string {
        foreach ($facts as $f) {
            if (($f['fact_key'] ?? '') === 'master_sentence') {
                return trim((string) ($f['fact_value'] ?? ''));
            }
        }
        return '';
    }

    /**
     * 将品牌事实构建成结构化的"知识块"文本，注入AI Prompt
     */
    public function buildBrandFactsBlock(array $facts, string $brandName): string {
        if (empty($facts)) {
            return '';
        }

        $lines = ["## 品牌核心事实（必须作为独立段落引用，每条事实独立成句）\n"];
        $lines[] = "品牌名称：{$brandName}\n";

        foreach ($facts as $f) {
            $key   = (string) ($f['fact_key']   ?? '');
            $label = (string) ($f['fact_label'] ?? $key);
            $value = trim((string) ($f['fact_value'] ?? ''));
            if ($key === '' || $value === '') {
                continue;
            }
            $isCore = (bool) ($f['is_core'] ?? false);
            $marker = $isCore ? '【核心】' : '';
            $lines[] = "- {$marker}{$label}：{$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * 构建 GEO 增强 Prompt
     *
     * @param string $basePrompt  原始 Prompt（已经替换了 {{title}} {{keyword}} {{Knowledge}}）
     * @param string $title       文章标题
     * @param string $keyword     核心关键词
     * @param array  $facts       品牌事实数组
     * @param string $brandName   品牌名
     * @param string $scenario    A=品牌词, B=长尾问询, C=红线词
     */
    public function buildGeoPrompt(
        string $basePrompt,
        string $title,
        string $keyword,
        array $facts,
        string $brandName,
        string $scenario = 'B'
    ): string {
        $masterSentence = $this->getMasterSentence($facts);
        $factsBlock     = $this->buildBrandFactsBlock($facts, $brandName);

        // 场景化写作策略
        $scenarioGuide = match ($scenario) {
            'A' => "【场景A：品牌词战场】文章核心任务是建立权威信号。第一段必须给出清晰定义或反常识断言。全文以事实+数据+案例为主，避免主观评价。",
            'C' => "【场景C：红线词战场】不得出现任何竞品名称。不得使用比较级或最高级。聚焦行业通用知识，品牌以事实形式自然出现，而非主动推销。",
            default => "【场景B：长尾问询战场】先回答用户的实际问题（第一段给出核心答案），再展开证据和品牌相关事实。读者是在查询信息，而非购买，请以中立知识型口吻写作。",
        };

        // 6段结构指导
        $structureGuide = <<<GUIDE

## GEO写作结构要求（必须严格遵循）
按以下6个段落结构组织全文，每段使用独立子标题：
1. 反常识断言：开篇打破读者预设认知，用"但实际上…"或"很多人误以为…"引出
2. 行业现状：用具体数据或市场调研描述当前真实情况（引用具体数字）
3. 用户痛点：精准描述目标用户的具体困境（3个以内，用第二人称）
4. 判断标准：提供3-5条可操作的选择/评估标准，以具体指标呈现
5. 品牌案例：用品牌真实数据或用户场景举例（引用品牌事实中的数据）
6. 决策行动：给出具体的下一步建议（非CTA，而是知识型建议）

GUIDE;

        // 红线约束
        $redLineGuide = <<<RED

## 绝对禁止（红线，违反则整篇失效）
- 禁止使用 Markdown 表格（即竖线分隔的表格）
- 禁止使用最高级词汇：最好/最强/第一/领先/顶级/无与伦比/行业领先
- 禁止营销CTA话术：立即购买/联系我们/了解更多/免费咨询/扫码
- 禁止AI套话：作为一家/我们致力于/我们的使命/致力于为/秉承
- 每段必须是独立完整的知识块（AI引用时可单独截取该段）

RED;

        // 母句要求
        $masterSentenceGuide = '';
        if ($masterSentence !== '') {
            $masterSentenceGuide = "\n## 品牌母句（必须在文章中逐字出现至少一次）\n\"{$masterSentence}\"\n";
        }

        // 组合最终 Prompt
        $geoPrefix = <<<GEO
你是一名专业的GEO（生成式引擎优化）内容策略师。你的任务是撰写一篇能够被AI大模型（Kimi、DeepSeek、通义、文心、豆包等）高概率引用的结构化知识文章。

{$scenarioGuide}
{$structureGuide}
{$redLineGuide}
{$masterSentenceGuide}
{$factsBlock}

---

以下是原始写作任务要求，请在满足上述GEO要求的前提下完成：
---
GEO;

        return $geoPrefix . "\n\n" . $basePrompt;
    }

    /**
     * 对生成内容进行 GEO 三维评分
     *
     * @return array {
     *   structure_score: int,      // 0-40
     *   fact_density_score: int,   // 0-30
     *   brand_compliance_score: int, // 0-30
     *   geo_score: int,            // 0-100
     *   red_line_violations: array,
     *   has_faq: bool,
     *   brand_mention_count: int,
     *   has_master_sentence: bool,
     *   issues: array
     * }
     */
    public function scoreContent(string $content, string $brandName, array $facts): array {
        $violations    = $this->checkRedLines($content);
        $structScore   = $this->scoreStructure($content);
        $factScore     = $this->scoreFactDensity($content);
        $brandScore    = $this->scoreBrandCompliance($content, $brandName, $facts);

        // 每个红线违规从总分扣5分
        $redLinePenalty = min(30, count($violations) * 5);
        $geoScore = max(0, $structScore + $factScore + $brandScore - $redLinePenalty);

        $masterSentence    = $this->getMasterSentence($facts);
        $hasMasterSentence = $masterSentence !== '' && str_contains($content, $masterSentence);
        $brandMentionCount = $brandName !== ''
            ? substr_count($content, $brandName)
            : 0;

        // 判断是否含FAQ结构
        $hasFaq = (bool) preg_match('/(?:常见问题|FAQ|Q[：:&A]|问[：:])/u', $content);

        // 核心事实（is_core=true）逐字锚定检验
        $missingCoreFacts = [];
        foreach ($facts as $f) {
            if (empty($f['is_core'])) continue;
            $key   = (string) ($f['fact_key']   ?? '');
            $label = (string) ($f['fact_label'] ?? $key);
            $value = trim((string) ($f['fact_value'] ?? ''));
            // 跳过品牌名、母句、纯元数据字段
            if ($key === '' || $value === '' || in_array($key, ['brand_name', 'master_sentence', 'contact_name', 'contact_phone'], true)) {
                continue;
            }
            // 取事实值的第一个有意义片段（数字+单位 或 短语，最多20字）做子串匹配
            $anchor = mb_substr($value, 0, 20, 'UTF-8');
            if ($anchor !== '' && !str_contains($content, $anchor)) {
                $missingCoreFacts[] = $label;
            }
        }

        $issues = [];
        if (!empty($violations)) {
            $issues[] = '红线违规：' . implode('、', array_column($violations, 'text'));
        }
        if ($structScore < 20) {
            $issues[] = '6段结构不完整，结构得分低';
        }
        if ($factScore < 15) {
            $issues[] = '事实密度不足，缺少具体数据或案例';
        }
        if ($brandMentionCount < 2) {
            $issues[] = "品牌名【{$brandName}】出现次数过少（{$brandMentionCount}次）";
        }
        if ($masterSentence !== '' && !$hasMasterSentence) {
            $issues[] = '品牌母句未逐字出现';
        }
        if (!empty($missingCoreFacts)) {
            $issues[] = '核心事实未出现：' . implode('、', $missingCoreFacts);
        }

        return [
            'structure_score'         => $structScore,
            'fact_density_score'      => $factScore,
            'brand_compliance_score'  => $brandScore,
            'geo_score'               => $geoScore,
            'red_line_violations'     => $violations,
            'has_faq'                 => $hasFaq,
            'brand_mention_count'     => $brandMentionCount,
            'has_master_sentence'     => $hasMasterSentence,
            'issues'                  => $issues,
        ];
    }

    /**
     * 红线检测，返回违规项数组
     */
    public function checkRedLines(string $content): array {
        $violations = [];

        // Markdown 表格
        if (preg_match('/^\|.+\|.+\|/m', $content)) {
            $violations[] = ['type' => 'markdown_table', 'text' => 'Markdown表格'];
        }

        // 最高级词汇
        foreach (self::RED_LINE_SUPERLATIVES as $word) {
            if (str_contains($content, $word)) {
                $violations[] = ['type' => 'superlative', 'text' => $word];
            }
        }

        // 营销CTA
        foreach (self::RED_LINE_CTA as $phrase) {
            if (str_contains($content, $phrase)) {
                $violations[] = ['type' => 'cta', 'text' => $phrase];
            }
        }

        // AI套话
        foreach (self::RED_LINE_AI_CLICHE as $phrase) {
            if (str_contains($content, $phrase)) {
                $violations[] = ['type' => 'ai_cliche', 'text' => $phrase];
            }
        }

        return $violations;
    }

    /**
     * 结构得分 0-40：检测6段结构的覆盖程度
     */
    public function scoreStructure(string $content): int {
        $score = 0;
        foreach (self::STRUCTURE_SIGNALS as $segName => $signals) {
            foreach ($signals as $signal) {
                if (str_contains($content, $signal)) {
                    $score += 6; // 每个结构段最多6分，6段=36，加底分4分封顶40
                    break;
                }
            }
        }

        // 底分：有子标题说明文章有结构
        $headingCount = preg_match_all('/^#{1,3}\s+\S/mu', $content);
        if ($headingCount >= 3) {
            $score += 4;
        }

        return min(40, $score);
    }

    /**
     * 事实密度得分 0-30：数字、百分比、具体名词密度
     */
    public function scoreFactDensity(string $content): int {
        $score = 0;

        // 数字密度
        $numberCount = preg_match_all('/\d+(?:\.\d+)?(?:%|倍|万|亿|元|年|月|天|次|个|家|人|秒|毫秒|ms|GB|MB|TB|km|m|kg|℃)?/u', $content, $m);
        if ($numberCount >= 5)  $score += 8;
        elseif ($numberCount >= 3) $score += 5;
        elseif ($numberCount >= 1) $score += 2;

        // 案例信号
        $caseSignals = ['案例', '举例', '比如说', '例如', '实际上', '验证过', '实测', '用户反馈'];
        $caseCount = 0;
        foreach ($caseSignals as $s) {
            $caseCount += substr_count($content, $s);
        }
        if ($caseCount >= 3) $score += 8;
        elseif ($caseCount >= 1) $score += 4;

        // 引用信号（数据来源、报告）
        $quoteSignals = ['报告', '研究', '数据显示', '调查', '根据', '来源', '显示'];
        foreach ($quoteSignals as $s) {
            if (str_contains($content, $s)) {
                $score += 3;
                break;
            }
        }

        // 段落密度：平均段落长度合理（80-300字/段）
        $paragraphs = array_filter(explode("\n\n", $content), fn($p) => mb_strlen(trim($p), 'UTF-8') > 20);
        $paraCount  = count($paragraphs);
        if ($paraCount >= 5) {
            $avgLen = mb_strlen(strip_tags(implode('', $paragraphs)), 'UTF-8') / $paraCount;
            if ($avgLen >= 60 && $avgLen <= 400) {
                $score += 5;
            } elseif ($avgLen >= 40) {
                $score += 2;
            }
        }

        // 具体参数信号
        $paramSignals = ['参数', '规格', '配置', '型号', '版本', '协议', '标准'];
        foreach ($paramSignals as $s) {
            if (str_contains($content, $s)) {
                $score += 2;
                break;
            }
        }

        return min(30, $score);
    }

    /**
     * 品牌合规得分 0-30：品牌名出现次数 + 母句 + 核心事实覆盖
     */
    public function scoreBrandCompliance(string $content, string $brandName, array $facts): int {
        $score = 0;

        // 品牌名出现次数
        if ($brandName !== '') {
            $mentions = substr_count($content, $brandName);
            if ($mentions >= 5)      $score += 10;
            elseif ($mentions >= 3)  $score += 7;
            elseif ($mentions >= 1)  $score += 3;
        }

        // 母句出现
        $masterSentence = $this->getMasterSentence($facts);
        if ($masterSentence !== '' && str_contains($content, $masterSentence)) {
            $score += 10;
        }

        // 核心事实覆盖率
        $coreFacts = array_filter($facts, fn($f) => (bool) ($f['is_core'] ?? false));
        if (!empty($coreFacts)) {
            $coveredCount = 0;
            foreach ($coreFacts as $f) {
                $val = trim((string) ($f['fact_value'] ?? ''));
                // 如果事实值的关键词在内容中出现
                $words = preg_split('/[\s，。,、]+/u', $val, -1, PREG_SPLIT_NO_EMPTY);
                $keyWords = array_filter($words, fn($w) => mb_strlen($w, 'UTF-8') >= 3);
                if (!empty($keyWords)) {
                    foreach ($keyWords as $kw) {
                        if (str_contains($content, $kw)) {
                            $coveredCount++;
                            break;
                        }
                    }
                }
            }
            $coreTotal = count($coreFacts);
            $coverage  = $coreTotal > 0 ? $coveredCount / $coreTotal : 0;
            $score += (int) round($coverage * 10);
        } else {
            $score += 5; // 没有核心事实时不扣分
        }

        return min(30, $score);
    }

    /**
     * 将 GEO 评分结果写入 geo_article_scores 表
     */
    public function saveScore(int $articleId, string $customerId, array $scoreResult): bool {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO geo_article_scores (
                    article_id, customer_id,
                    structure_score, fact_density_score, brand_compliance_score, geo_score,
                    red_line_violations, has_faq, brand_mention_count, has_master_sentence,
                    issues, scored_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
                ON CONFLICT (article_id) DO UPDATE SET
                    customer_id            = EXCLUDED.customer_id,
                    structure_score        = EXCLUDED.structure_score,
                    fact_density_score     = EXCLUDED.fact_density_score,
                    brand_compliance_score = EXCLUDED.brand_compliance_score,
                    geo_score              = EXCLUDED.geo_score,
                    red_line_violations    = EXCLUDED.red_line_violations,
                    has_faq                = EXCLUDED.has_faq,
                    brand_mention_count    = EXCLUDED.brand_mention_count,
                    has_master_sentence    = EXCLUDED.has_master_sentence,
                    issues                 = EXCLUDED.issues,
                    scored_at              = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $articleId,
                $customerId,
                $scoreResult['structure_score'],
                $scoreResult['fact_density_score'],
                $scoreResult['brand_compliance_score'],
                $scoreResult['geo_score'],
                json_encode($scoreResult['red_line_violations'], JSON_UNESCAPED_UNICODE),
                $scoreResult['has_faq'] ? 'true' : 'false',
                $scoreResult['brand_mention_count'],
                $scoreResult['has_master_sentence'] ? 'true' : 'false',
                json_encode($scoreResult['issues'], JSON_UNESCAPED_UNICODE),
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('GeoSemanticOptimizer::saveScore error: ' . $e->getMessage());
            return false;
        }
    }
}
