# GEO+AI 内容生成与交付后台

这是一个面向 GEO/AI 搜索优化的交付后台，包含客户管理、雷达诊断、关键词/标题/知识库生成、文章生成、媒体分发和 GEO 监测。

## 快速启动

推荐使用 Docker Compose，后台服务会一起启动：

```bash
cp .env.example .env
docker compose up -d --build
```

如需导入随仓库提供的演示数据：

```bash
docker compose exec app php -r 'define("FEISHU_TREASURE", true); require "includes/config.php"; require "includes/database_admin.php";'
docker compose exec -T postgres psql -U geo_user -d geo_system < docs/demo-data.sql
```

如果 `.env` 里改过数据库名或账号，请把命令里的 `geo_user`、`geo_system` 换成对应值。
导入演示数据会清空并替换演示业务表，请只在新建或测试数据库执行，不要导入生产库。

默认访问地址：

```text
http://localhost:18080/dl-console/
```

默认管理员账号来自 `.env`：

```text
ADMIN_BOOTSTRAP_USERNAME=admin
ADMIN_BOOTSTRAP_PASSWORD=change-this-before-delivery
```

正式交付前一定要修改 `ADMIN_BOOTSTRAP_PASSWORD`、`APP_SECRET_KEY`、`DB_PASSWORD`。

## 必填配置

`.env` 至少需要确认这些项：

```env
HOST_PORT=18080
DB_DRIVER=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_NAME=geo_system
DB_USER=geo_user
DB_PASSWORD=change-this-password
SITE_URL=http://localhost:18080
ADMIN_BASE_PATH=dl-console
ADMIN_BOOTSTRAP_USERNAME=admin
ADMIN_BOOTSTRAP_PASSWORD=change-this-before-delivery
APP_SECRET_KEY=replace-with-a-long-random-secret
```

Docker Compose 会读取 `.env` 并传给 `app`、`worker`、`cron` 三个容器。

## 演示数据

仓库包含一份脱敏演示数据：

```text
docs/demo-data.sql
```

这份数据包含客户、关键词、标题、知识库、文章、雷达诊断、GEO 监测记录、告警和自动化历史记录，方便新部署直接看到完整页面效果。

这份数据不包含：

```text
管理员密码
API token
真实 AI API Key
媒体账号凭证
浏览器登录态
访问日志
运行队列日志
```

如果需要从当前数据库重新生成演示数据：

```bash
php bin/export_demo_seed.php
```

重新生成后请检查 `docs/demo-data.sql`，确认没有误带敏感信息再提交。

## AI 模型配置

页面位置：

```text
AI配置 -> AI模型配置
```

至少配置一个聊天模型，自动化生成、雷达诊断、意图挖掘和 GEO 监测才会真正调用模型。  
例如 Xiaomi/MiMo 这类兼容 OpenAI Chat Completions 的模型，需要填写：

```text
模型名称
模型 ID
API URL
API Key
模型类型：聊天模型
状态：启用
```

GEO 监测没有配置专用 provider 时，会使用这里启用的聊天模型作为兜底。

## 后台服务

Docker Compose 会启动三个服务：

```text
geo-app     Web 后台
geo-worker 文章生成等异步任务 worker
geo-cron   每 60 秒执行一次轻量调度器
```

对应命令：

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f worker
docker compose logs -f cron
```

如果不用 Docker，本地至少要分别启动：

```bash
php -S 0.0.0.0:18080 router.php
php bin/worker.php
while true; do php bin/cron.php; sleep 60; done
```

## 品牌入驻自动化

页面位置：

```text
/dl-console/automation-workflow.php
```

自动化会按步骤执行：

1. 搜集品牌资料
2. 生成雷达诊断
3. 生成关键词库
4. 生成标题库
5. 生成知识库
6. 创建客户
7. 生成知识图谱
8. 意图挖掘
9. 创建并启动任务
10. 生成文章
11. 启动媒体分发
12. 启动 GEO 监测

启动弹窗里需要选择发布平台/账号。没有选择账号时，系统不会真正创建外部分发任务。

## 媒体分发说明

媒体账号有两种模式：

```text
browser 浏览器自动发布
manual  人工辅助，只创建待办
```

浏览器自动发布依赖登录态和平台页面结构。遇到扫码、验证码、滑块或编辑器结构变化时，会失败并记录到发布队列，不会假装成功。

相关环境变量：

```env
DISTRIBUTION_AUTO_PUBLISH_ENABLED=true
DISTRIBUTION_AUTO_START_ENABLED=true
DISTRIBUTION_BROWSER_HEADLESS=true
DISTRIBUTION_LOGIN_WAIT_SECONDS=600
DISTRIBUTION_PUBLISH_MIN_INTERVAL_HOURS=4
DISTRIBUTION_CRON_START_LIMIT=3
```

如需手动完成平台登录验证，可临时设置：

```env
DISTRIBUTION_BROWSER_HEADLESS=false
```

## GEO 监测说明

GEO 监测不是页面实时计算。页面只展示数据库里的监测结果。真正的监测由后台脚本执行：

```bash
php bin/geo-monitor-run.php
```

自动化第 12 步会启动一次监测；`geo-cron` 也会每天兜底启动一次监测。可用下面的环境变量关闭：

```env
GEO_MONITOR_CRON_ENABLED=false
```

监测会：

- 读取 `geo_monitor_keywords`
- 调用可用 AI provider 或 AI 配置里的兜底聊天模型
- 判断品牌是否被提及、提及次数、提及深度、竞品是否出现
- 写入 `geo_monitor_records`
- 生成 `geo_monitor_alerts`
- 将监测结果回填到雷达诊断的部分信号

查看日志：

```bash
docker compose exec cron tail -f bin/logs/geo_monitor_$(date +%F).log
```

## 多平台监测

默认只有已配置的模型会跑。例如只配置 Xiaomi/MiMo，就只会有 MiMo 的监测记录。

如果要覆盖 Kimi、DeepSeek、通义、文心、豆包、元宝等平台，需要在引用模拟/监测 API 配置里填对应 API Key 并启用。没有配置时，页面不能显示真实的多平台覆盖率。

可选搜索增强：

```env
BOCHA_API_KEY=
```

配置 Bocha 后，监测会注入搜索上下文；否则监测只基于模型自身回答。

## 常见问题

### 页面显示“未监测到”

说明关键词已经进队列，但还没有 `geo_monitor_records`。检查：

```bash
docker compose logs -f cron
docker compose exec app php bin/geo-monitor-run.php --dry-run
```

### AI 配置页提示 `column m.priority does not exist`

说明数据库是旧结构。当前代码会在打开 AI 配置页和初始化数据库时自动补 `ai_models.priority` 字段。更新代码后刷新页面即可。

### 文章显示站内发布，但外部平台没有发布

“站内发布”只表示文章进入本系统文章库，不等于已经发到知乎/公众号/CSDN/掘金。  
真实外部分发要看“外部分发队列”的成功数、失败数和手动待办数。

## 生产部署建议

- 修改所有默认密码和 `APP_SECRET_KEY`
- 使用 HTTPS 反代，例如 Caddy/Nginx
- 配置持久化数据库备份
- 不要把 `.env` 提交到 GitHub
- 确认 `geo-worker` 和 `geo-cron` 都处于运行状态
- 配置至少一个聊天模型 API Key
- 按需配置外部分发账号和多平台监测 API Key
