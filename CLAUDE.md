# GEO System - Claude Code Automation Guide

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
