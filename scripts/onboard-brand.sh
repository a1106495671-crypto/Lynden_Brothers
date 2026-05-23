#!/bin/bash
# ============================================================
# GEO System - 品牌一键入驻脚本
# 用法: ./scripts/onboard-brand.sh
# ============================================================

set -euo pipefail

# 配置
API_URL="${GEO_API_URL:-http://127.0.0.1:18081/api/v1}"
ADMIN_USER="${GEO_ADMIN_USER:-admin}"
ADMIN_PASS="${GEO_ADMIN_PASS:-change-this-before-delivery}"

# 颜色
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log_info()  { echo -e "${BLUE}[INFO]${NC} $1"; }
log_ok()    { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn()  { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# ---- 登录获取Token ----
login() {
    log_info "正在登录..."
    local resp
    resp=$(curl -sf -X POST "${API_URL}/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"username\":\"${ADMIN_USER}\",\"password\":\"${ADMIN_PASS}\"}")

    TOKEN=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['token'])" 2>/dev/null || true)
    if [ -z "$TOKEN" ]; then
        log_error "登录失败"
        exit 1
    fi
    log_ok "登录成功"
}

# ---- API请求封装 ----
api_post() {
    local endpoint="$1"
    local data="$2"
    curl -sf -X POST "${API_URL}${endpoint}" \
        -H "Content-Type: application/json" \
        -H "Authorization: Bearer ${TOKEN}" \
        -d "$data"
}

api_get() {
    local endpoint="$1"
    curl -sf -X GET "${API_URL}${endpoint}" \
        -H "Authorization: Bearer ${TOKEN}"
}

# ---- 创建客户 ----
create_customer() {
    log_info "创建客户: ${BRAND_NAME}"
    local resp
    resp=$(api_post "/customers" "{
        \"name\": \"${BRAND_NAME}\",
        \"industry\": \"${INDUSTRY}\",
        \"domain\": \"${DOMAIN}\",
        \"package_tier\": \"${PACKAGE_TIER:-pro}\"
    }")
    CUSTOMER_ID=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['customer_id'])" 2>/dev/null)
    log_ok "客户创建成功: ${CUSTOMER_ID}"
}

# ---- 创建关键词库 ----
create_keyword_library() {
    log_info "创建关键词库: ${BRAND_NAME}核心词"
    local kw_json
    kw_json=$(printf '%s\n' "${KEYWORDS[@]}" | python3 -c "import sys,json; print(json.dumps([l.strip() for l in sys.stdin if l.strip()]))")

    local resp
    resp=$(api_post "/keyword-libraries" "{
        \"name\": \"${BRAND_NAME}核心词\",
        \"description\": \"${BRAND_NAME}的GEO核心关键词\",
        \"keywords\": ${kw_json}
    }")
    KEYWORD_LIB_ID=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
    log_ok "关键词库创建成功: ID=${KEYWORD_LIB_ID}, 关键词数=$(echo "$kw_json" | python3 -c 'import sys,json; print(len(json.load(sys.stdin)))')"
}

# ---- 创建标题库 ----
create_title_library() {
    log_info "创建标题库: ${BRAND_NAME}文章标题"
    local title_json
    title_json=$(printf '%s\n' "${TITLES[@]}" | python3 -c "import sys,json; print(json.dumps([l.strip() for l in sys.stdin if l.strip()]))")

    local resp
    resp=$(api_post "/title-libraries" "{
        \"name\": \"${BRAND_NAME}文章标题\",
        \"description\": \"${BRAND_NAME}的GEO文章标题模板\",
        \"titles\": ${title_json},
        \"keyword_library_id\": ${KEYWORD_LIB_ID}
    }")
    TITLE_LIB_ID=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
    log_ok "标题库创建成功: ID=${TITLE_LIB_ID}"
}

# ---- 创建知识库 ----
create_knowledge_base() {
    log_info "创建知识库: ${BRAND_NAME}品牌知识"
    local resp
    resp=$(api_post "/knowledge-bases" "{
        \"name\": \"${BRAND_NAME}品牌知识\",
        \"description\": \"${BRAND_NAME}的品牌知识库\",
        \"content\": $(echo "$KNOWLEDGE_CONTENT" | python3 -c "import sys,json; print(json.dumps(sys.stdin.read()))")
    }")
    KB_ID=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
    log_ok "知识库创建成功: ID=${KB_ID}"
}

# ---- 创建AI任务 ----
create_task() {
    log_info "创建AI生成任务..."
    local resp
    resp=$(api_post "/tasks" "{
        \"name\": \"${BRAND_NAME}GEO首月内容\",
        \"title_library_id\": ${TITLE_LIB_ID},
        \"prompt_id\": 1,
        \"ai_model_id\": ${AI_MODEL_ID:-2},
        \"draft_limit\": ${DRAFT_LIMIT:-5},
        \"is_loop\": 1,
        \"need_review\": ${NEED_REVIEW:-0}
    }")
    TASK_ID=$(echo "$resp" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)
    log_ok "任务创建成功: ID=${TASK_ID}"
}

# ---- 启动任务 ----
start_task() {
    log_info "启动任务..."
    api_post "/tasks/${TASK_ID}/start" '{"enqueue_now": true}' > /dev/null
    log_ok "任务已启动"
}

# ---- 等待任务完成 ----
wait_for_task() {
    log_info "等待文章生成..."
    local max_wait=300
    local elapsed=0
    while [ $elapsed -lt $max_wait ]; do
        local resp
        resp=$(api_get "/tasks/${TASK_ID}")
        local status
        status=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(d.get('status','unknown'))" 2>/dev/null)
        local created
        created=$(echo "$resp" | python3 -c "import sys,json; d=json.load(sys.stdin)['data']; print(d.get('created_count',0))" 2>/dev/null)

        if [ "$created" -ge "${DRAFT_LIMIT:-5}" ] 2>/dev/null; then
            log_ok "文章生成完成: ${created}篇"
            return 0
        fi

        sleep 5
        elapsed=$((elapsed + 5))
        echo -ne "\r  等待中... ${elapsed}s / ${max_wait}s (已生成: ${created}篇)"
    done
    log_warn "等待超时，请手动检查任务状态"
}

# ---- 发布文章到媒体平台 ----
distribute_articles() {
    log_info "发布文章到媒体平台..."
    local articles_resp
    articles_resp=$(api_get "/articles?task_id=${TASK_ID}&per_page=50")
    local article_ids
    article_ids=$(echo "$articles_resp" | python3 -c "
import sys, json
data = json.load(sys.stdin)['data']
items = data.get('items', data) if isinstance(data, dict) else data
print(' '.join([str(a['id']) for a in items]))
" 2>/dev/null)

    if [ -z "$article_ids" ]; then
        log_warn "没有找到已生成的文章"
        return 0
    fi

    for aid in $article_ids; do
        api_post "/articles/${aid}/publish" '{}' > /dev/null 2>&1 || true
        log_ok "文章 #${aid} 已加入发布队列"
    done
}

# ---- 设置监测关键词 ----
setup_monitoring() {
    log_info "设置GEO监测关键词..."
    local kw_json
    kw_json=$(printf '%s\n' "${MONITOR_KEYWORDS[@]}" | python3 -c "import sys,json; print(json.dumps([l.strip() for l in sys.stdin if l.strip()]))")

    api_post "/monitor/keywords" "{
        \"customer_id\": \"${CUSTOMER_ID}\",
        \"keywords\": ${kw_json}
    }" > /dev/null
    log_ok "监测关键词设置完成"
}

# ---- 输出报告 ----
print_report() {
    echo ""
    echo "========================================================"
    echo -e "${GREEN}  品牌入驻完成报告${NC}"
    echo "========================================================"
    echo "  品牌名称:    ${BRAND_NAME}"
    echo "  客户ID:      ${CUSTOMER_ID}"
    echo "  关键词库ID:  ${KEYWORD_LIB_ID}"
    echo "  标题库ID:    ${TITLE_LIB_ID}"
    echo "  知识库ID:    ${KB_ID}"
    echo "  任务ID:      ${TASK_ID}"
    echo "  关键词数:    ${#KEYWORDS[@]}"
    echo "  标题数:      ${#TITLES[@]}"
    echo "  生成篇数:    ${DRAFT_LIMIT:-5}"
    echo "========================================================"
    echo ""
    echo "  下一步操作:"
    echo "  1. 登录后台检查文章质量: http://127.0.0.1:18081/dl-console/"
    echo "  2. 审核通过后手动发布到外部平台"
    echo "  3. 配置GEO监测关键词"
    echo ""
}

# ============================================================
# 主流程 — 需要在调用前设置以下变量:
#   BRAND_NAME, INDUSTRY, DOMAIN, CORE_SERVICES
#   COMPETITORS, POSITIONING, KEYWORDS, TITLES
#   KNOWLEDGE_CONTENT, MONITOR_KEYWORDS
# ============================================================
main() {
    login
    create_customer
    create_keyword_library
    create_title_library
    create_knowledge_base
    create_task
    start_task
    wait_for_task
    distribute_articles
    setup_monitoring
    print_report
}

main "$@"
