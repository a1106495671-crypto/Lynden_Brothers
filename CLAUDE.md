# GEO System - Claude Code Automation Guide

## 必读：Docker 同步规则

新终端或新会话开始前，先读 `CODEX_WORKFLOW.md` 和 `DOCKER_SYNC_REQUIRED.md`。

本项目浏览器页面通常来自 Docker 容器 `geo-app`。修改本地文件后，必须同步到 `geo-app:/app`，否则 `localhost:18094` 页面不会变化。

## 项目概述
GEO+AI内容生成系统，用于品牌GEO优化的全链路交付。
技术栈：PHP + PostgreSQL + Node.js
本地端口：18081（可通过.env修改）

## 品牌入驻自动化流程

当用户提供品牌信息时，按以下标准流程自动执行：

### 用户输入格式
```
品牌名：XX
行业：XX
官网：xxx.com
核心服务：XX、XX、XX
竞品：XX、XX、XX
定位：一句话描述
```

### 自动执行步骤

#### Step 1: 搜集品牌资料
- 用WebSearch搜索品牌官网、媒体报道
- 整理品牌基本信息
- 如果用户提供了已知资料则直接使用

#### Step 2: 生成3个素材库
使用 `prompts/` 目录下的prompt模板：

1. **关键词库** → 读取 `prompts/keyword_library.md`
   - 填入品牌信息
   - 生成25-40个关键词（5类各5-8个）
   - 调用 `POST /api/v1/keyword-libraries` 创建

2. **标题库** → 读取 `prompts/title_library.md`
   - 填入品牌信息
   - 生成20个标题模板（6种类型）
   - 调用 `POST /api/v1/title-libraries` 创建

3. **知识库** → 读取 `prompts/knowledge_base.md`
   - 填入品牌信息+搜索到的资料
   - 生成1200-1500字的品牌知识文档
   - 调用 `POST /api/v1/knowledge-bases` 创建

#### Step 3: 创建客户
- 调用 `POST /api/v1/customers` 创建客户
- 填入品牌名、行业、官网等信息

#### Step 4: 创建并启动任务
- 调用 `POST /api/v1/tasks` 创建任务
- 关联：标题库 + AI模型（DeepSeek）
- 调用 `POST /api/v1/tasks/{id}/start` 启动

#### Step 5: 等待文章生成
- 轮询 `GET /api/v1/tasks/{id}` 检查状态
- 等待 created_count >= draft_limit

#### Step 6: 分发到媒体平台
- 调用 `POST /api/v1/articles/{id}/publish` 发布
- 系统自动调用分发脚本发到知乎等平台
- 首次需要用户在浏览器中扫码登录知乎

#### Step 7: 设置监测
- 调用 `POST /api/v1/monitor/keywords` 添加监测关键词

### 快速执行脚本
```bash
# 设置品牌信息后执行
source scripts/onboard-brand.sh
```

## API端点一览

| 方法 | 路径 | 说明 |
|------|------|------|
| POST | `/api/v1/auth/login` | 登录获取Token |
| GET/POST | `/api/v1/customers` | 客户CRUD |
| GET/POST | `/api/v1/keyword-libraries` | 关键词库CRUD |
| POST | `/api/v1/keyword-libraries/{id}/keywords` | 添加关键词 |
| GET/POST | `/api/v1/title-libraries` | 标题库CRUD |
| POST | `/api/v1/title-libraries/{id}/titles` | 添加标题 |
| GET/POST | `/api/v1/knowledge-bases` | 知识库CRUD |
| GET/POST | `/api/v1/tasks` | 任务CRUD |
| POST | `/api/v1/tasks/{id}/start` | 启动任务 |
| POST | `/api/v1/tasks/{id}/stop` | 停止任务 |
| GET | `/api/v1/tasks/{id}/jobs` | 查看任务队列 |
| GET/POST | `/api/v1/articles` | 文章CRUD |
| POST | `/api/v1/articles/{id}/publish` | 发布文章 |
| POST | `/api/v1/monitor/keywords` | 添加监测关键词 |
| GET | `/api/v1/catalog` | 获取目录数据 |

## 部署

### 本地开发
```bash
./start-local.sh     # 启动
./stop-local.sh      # 停止
```

### 阿里云部署
```bash
# 在服务器上执行
bash scripts/deploy-alicloud.sh
```

### Docker部署
```bash
docker-compose up -d --build
```

## 系统访问信息
- 本地后台：http://127.0.0.1:18081/dl-console/
- 本地API：http://127.0.0.1:18081/api/v1/
- 默认账号：admin / change-this-before-delivery

## 关键文件
- 启动脚本：`./start-local.sh`
- 停止脚本：`./stop-local.sh`
- Worker：`bin/worker.php`
- 分发Worker：`bin/distribution_worker.php`
- Prompt模板：`prompts/` 目录
- 自动化脚本：`scripts/onboard-brand.sh`
- 部署脚本：`scripts/deploy-alicloud.sh`
- Docker配置：`docker-compose.yml` + `Dockerfile`

## 共享记忆 / Agent Handoff

此文件作为 Claude、Codex 等代码助手的项目共享上下文。新会话开始时，优先读取 `CLAUDE.md`，再根据用户当前目标继续。

### 协作约定

- 用户使用中文时，默认用中文沟通。
- 只记录对未来会话有帮助的项目上下文、决策、待办和注意事项。
- 不把完整聊天记录粘贴进本文档，只保留高信号摘要。
- 修改代码前先阅读相关文件，避免覆盖用户或其他助手已做的改动。
- 如有新的长期约定、任务状态或坑点，追加到本节。

### 会话记录

- 2026-05-24：用户希望 VS Code 插件里的不同终端/会话能共享上下文。项目已有 `CLAUDE.md`，决定以后 Claude 与 Codex 共用此文件作为长期记忆入口。
- 2026-05-24：排查 AI 模型配置页 API Key 问题。编辑弹窗中 API Key 为空是安全设计，留空会保留已保存密钥；小米 MiMo Token Plan 地址 `token-plan-*.xiaomimimo.com` 通常应配 `tp-` 开头密钥，`sk-` 开头密钥通常配 `https://api.xiaomimimo.com/v1`，地址与密钥类型不匹配会导致 401。
- 2026-05-24：排查品牌入驻自动化“假完成”。根因：`bin/automation_worker.php` 第 7 步等待文章生成超时后即使 `0/10` 也返回成功，导致后续分发/监测被标为完成；任务创建还会选中无 API Key 的“本地占位聊天模型”，文章队列失败于 `127.0.0.1/not-configured`。已改为文章数量不足时失败、0 篇不可分发、创建任务只选有效聊天模型、自动化页重开后恢复最近一次 workflow。
- 2026-05-24：自动化创建任务已接入 GEO 语义优化：第 5 步创建客户时同步写入 `geo_brand_facts` 基础品牌事实；第 6 步创建任务时默认写入 `geo_mode=true`、`geo_scenario=B`、`geo_brand_name=品牌名`、`geo_customer_id=自动创建客户的 customer_id`；任务创建/编辑页的绑定客户下拉也改用 `customers.customer_id`，确保 `GeoSemanticOptimizer::loadBrandFacts()` 能加载品牌事实。已把旧任务中数字型 `geo_customer_id` 转换为 `customer_id`，并为 `dongluoji-mgeo` 补入基础品牌事实。
- 2026-05-24：用户要求自动化所有 AI 生成必须调用真实 API，不能使用本地占位/本地化。已禁用数据库中的 `local-placeholder`，自动化 AI 调用只遍历 active 且有 API Key 的真实聊天模型；所有模型失败时直接报错，不再二次 fallback；自动化步骤输出会记录 `model_used`，任务步骤输出记录模型名、model_id 和 api_url。当前有效模型为 `MiMo-V2.5-Pro` / `mimo-v2.5-pro` / `https://api.xiaomimimo.com/v1`。
- 2026-05-24：排查知乎页面每分钟弹出。根因：文章发布后自动创建媒体分发任务，`DISTRIBUTION_AUTO_START_ENABLED` 默认开启会立即拉起浏览器；知乎账号入口又配置为 `https://www.zhihu.com/creator`，导致 Chrome 反复停在创作中心。已将知乎默认入口和数据库账号入口改为 `https://zhuanlan.zhihu.com/write`，发布脚本默认 `DISTRIBUTION_BROWSER_HEADLESS=true` 后台执行，不抢用户本地 Chrome；只有需要登录/排错时才临时设为 `false` 打开可见浏览器。
- 2026-05-24：知乎发布脚本增加辅助登录：发布任务会从媒体账号读取 `username` 和加密保存的 `credential`，临时传给 `scripts/publish-zhihu.js` 自动填写账号密码；若遇到验证码/扫码/滑块，后台模式会失败并提示切换 `DISTRIBUTION_BROWSER_HEADLESS=false` 完成人工验证，可见模式会等待用户在浏览器里完成验证。
- 2026-05-24：为媒体自动发布增加账号级风控节流：默认 `DISTRIBUTION_PUBLISH_MIN_INTERVAL_HOURS=4`，同一个媒体账号 4 小时内只启动一条发布任务；被限速的任务保持 `queued` 并更新 `scheduled_at`，`bin/distribution_worker.php`、队列启动和 `bin/cron.php` 只处理到点任务。可用 `DISTRIBUTION_ZHIHU_MIN_INTERVAL_HOURS` 等平台变量单独覆盖；cron 每轮默认最多启动 `DISTRIBUTION_CRON_START_LIMIT=3` 条。
- 2026-05-24：自动化第 7 步语义改为“首篇文章生成”：默认 `AUTOMATION_MIN_ARTICLES_TO_CONTINUE=1`，生成首篇后立即进入媒体分发和监测；剩余文章继续通过 `job_queue` 补齐，后续生成的文章会因 `need_review=0` 自动发布并进入媒体分发队列。当前 `wf_20260524125211_f7bde77a` 已按 4/10 继续完成分发和监测，写入 11 个监测关键词，并为 task 5 补入后续生成 job。
- 2026-05-24：修复“启动监测但监测页无动作”。根因：自动化重复新建了 `cust_*` 客户，监测页当前客户是 `dongluoji-mgeo`；且 `bin/geo-monitor-run.php` 只认 citation simulator 专用提供商，未配置时不跑。已将本次 workflow/task/关键词迁到 `dongluoji-mgeo`，自动化创建客户优先复用同域名/同品牌客户；第 9 步添加关键词后会后台启动 `geo-monitor-run.php`；监测 runner 在没有专用提供商时回退使用默认 AI 模型。已手动跑通 `dongluoji-mgeo`：写入 11 条 `geo_monitor_records`，其中 7 条品牌提及。
- 2026-05-24：继续收口自动化可见性和数据一致性。`admin/api/automation-status.php` 现在返回运行时统计：文章生成队列、媒体发布队列、下一次发布时间、监测关键词/记录/提及数；`admin/automation-workflow.php` 会展示这些后台状态，主流程完成但仍有生成/分发队列时顶部显示“后台运行”并继续轮询。已合并 `董逻辑 MGEO / dongluoji.com` 重复客户到 `dongluoji-mgeo`，当前仅保留一个客户，监测关键词 16 个、监测记录 11 条、品牌提及 7 条。CSDN/掘金账号已改为 `manual`，避免未实现自动发布脚本时反复失败；知乎仍为 `browser` 自动发布。
- 2026-05-24：当前最新 workflow `wf_20260524125211_f7bde77a` 的真实运行状态：关键词 40、标题 20、文章 5、已发布 5；生成队列有 1 条失败，错误为小米接口真实返回 `402 Insufficient account balance`，不是本地假数据。媒体队列：知乎自动待发 1 条，成功 1 条，历史失败 3 条；手动待处理 15 条（公众号/CSDN/掘金）。余额或可用模型恢复后，需要重试失败的 `job_queue` 才能继续补齐 10 篇。
- 2026-05-24：用户将小米模型从 `sk` 换成 `tp` 后复测通过。当前模型配置为 `MiMo-V2.5-Pro / mimo-v2.5-pro / https://token-plan-cn.xiaomimimo.com/v1`，短请求和约 831 tokens 的长请求均 HTTP 200；已重试旧失败 `job_queue #19` 并成功生成 `article_id=16`，当前任务文章 6、已发布 6、生成失败 0。测试触发的知乎发布 job 已退回队列，避免误显示 running。
- 2026-05-24：雷达诊断之前未接入品牌入驻自动化。已新增自动化第 2 步 `diagnosis`，未来新 workflow 会在搜集品牌资料后调用 `geo_diagnosis_create()` 生成六维 GEO 权威性基线，并写回 `automation_workflows.diagnosis_id`；自动化页也新增“诊断”统计。已给当前 `wf_20260524125211_f7bde77a` 补生成诊断 `470cde9b-6745-43fc-9bd4-68398df8606b`，诊断页现在总诊断数 1、平均分 45、优化动作 3。注意：当前雷达数据源 provider 为 `disabled`，诊断使用内置估算/官网抓取线索；若要更真实的全网雷达，需要在雷达诊断页配置 Bing/SerpAPI/Google CSE/Bocha 搜索源。
- 2026-05-24：用户担心各功能只是展示、AI 输出同质化。已重写并落地一组更有区分度的 GEO prompts：`prompts/keyword_library.md` 按品牌认知/品类发现/购买决策/场景问题/竞品对比/风险质疑/内容资产生成关键词；`prompts/title_library.md` 按定义解释/判断标准/操作流程/竞品对比/成本收益/风险避坑/案例证据/趋势观点生成标题；`prompts/knowledge_base.md` 生成品牌实体卡、主叙述句、可核验证据、边界与禁用表达；`includes/geo_semantic_optimizer.php` 的文章生成 prompt 改为直接答案、判断标准、证据清单、边界对比、FAQ、行动建议结构。数据库 `prompts` 表中默认内容/标题/关键词/描述提示词也已同步更新。注意：只接一个模型时文风仍会受单模型影响，但各功能的目标、结构和输出差异已被 prompt 约束。
- 2026-05-24：用户要求其他模块也不要只是展示。已开始去展示化：`admin/monthly-report.php` 不再用 mock 趋势、假 AI 原话和假竞品数据，改为只展示真实 `geo_monitor_records` / `geo_monitor_alerts` / `geo_diagnosis_industry_benchmarks` 数据，没有数据时明确提示先监测；并支持通过 `?customer=` / `?customer_id=` 选择真实客户。`admin/citation-simulator.php` 的“派发到任务管理”不再是 toast 演示，已改为保存模拟结果并把勾选的优化动作写入 `geo_content_queue`。`admin/geo-content-queue.php` 和 `admin/geo-content-suggest.php` 统一队列表字段与默认值，内容生成提示不再要求 AI 编造数据，缺证据时必须标注待补。
- 2026-05-24：`includes/citation_simulator_service.php` 的真实反查模式已改为“不静默降级”：选择真实反查时必须有已配置且启用的 provider；API 调用失败、缺 key 或解析不到引用源会直接报错，不再用本地 stub 补齐排行榜。只有明确选择本地/估算模式时才使用本地推演数据。
- 2026-05-24：自动化页诊断步骤已存在，但用户反馈看不明显。已增强 `admin/api/automation-status.php` 返回 `diagnosis` 详情（分数、命中率、短板信号、动作数、报告 ID），并在 `admin/automation-workflow.php` 的品牌信息卡展示雷达诊断结果和”查看诊断报告”链接；步骤卡也会显示诊断评分摘要。当前最新 workflow `wf_20260524125211_f7bde77a` 诊断 ID 为 `470cde9b-6745-43fc-9bd4-68398df8606b`，分数 45.0，命中率等级 low。
- 2026-05-24：重写 GEO 全景诊断模块。新建 `prompts/panorama_diagnosis.md` 独立 prompt 模板，替换原来 `admin/api/panorama-generate.php` 中的内联 prompt；新 prompt 增加了 5 条反幻觉原则（只用数据说话、标注数据缺失、区分事实与推断、不说空话、竞品只用实际出现的），输出结构从 5 章扩展到 6 章（新增关键词诊断分层），每章有更严格的格式和内容要求；API 端现在返回 `prompt_used` 字段供前端展示。`admin/geo-panorama.php` 前端 UI 全面重设计：统计卡用环形图展示提及率、平台/关键词/竞品三列卡用色点和徽章、报告区用渐变头部和导出按钮、底部可折叠展示发送给 AI 的完整 prompt（黑底代码块风格）。
- 2026-05-24：重写 GEO 意图挖掘模块。新建 `prompts/intent_mining.md` 独立 prompt 模板，替换 `admin/geo-intent.php` 中的内联 prompt；新 prompt 从 6 维度扩展到 7 维度（新增「风险质疑」），要求 AI 输出 `reason`（为什么重要）和 `suggested_action`（建议内容形式），并严格基于品牌事实和已有监测/文章数据判断覆盖状态。意图挖掘现在读取 `geo_monitor_records`（近 30 天关键词提及率）和 `articles`（已发布文章标题）作为覆盖判断依据，不再让 AI 凭空猜测。数据库 `geo_intent_questions` 表新增 `reason`、`suggested_action`、`dimension` 三个字段。前端 UI 增加：5 卡统计（总数/空白/P0/覆盖率/主题数）、意图维度 × 优先级矩阵、筛选栏（全部/空白/已覆盖/P0/P1/P2/各维度）、每条问题展示理由和建议行动、可折叠 prompt 预览、点击「空白」可切换覆盖状态。

### 待办

- 暂无。
