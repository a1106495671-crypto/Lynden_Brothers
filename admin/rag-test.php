<?php
define('FEISHU_TREASURE', true);
session_start();

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database_admin.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/knowledge-retrieval.php';

require_admin_login();
session_write_close();

$csrf_token = generate_csrf_token();

// ---------- pgvector & embedding model status ----------
$pgvector_ok = false;
$embedding_model = null;
$db_error = '';

try {
    $pgvector_ok = embedding_service_pgvector_available($db);
    $embedding_model = embedding_service_get_default_model($db);
} catch (Throwable $e) {
    $db_error = $e->getMessage();
}

// ---------- knowledge base list with chunk stats ----------
$kb_stats = [];
try {
    $stmt = $db->query("
        SELECT kb.id, kb.name, kb.word_count,
               COUNT(kc.id) AS chunk_count,
               SUM(CASE WHEN kc.embedding_model_id IS NOT NULL AND kc.embedding_model_id > 0 THEN 1 ELSE 0 END) AS vector_count
        FROM knowledge_bases kb
        LEFT JOIN knowledge_chunks kc ON kc.knowledge_base_id = kb.id
        GROUP BY kb.id, kb.name, kb.word_count
        ORDER BY kb.id DESC
    ");
    $kb_stats = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    // knowledge_chunks table may not exist yet
}

// ---------- RAG test (AJAX JSON endpoint) ----------
if (isset($_GET['action']) && $_GET['action'] === 'query') {
    header('Content-Type: application/json; charset=utf-8');

    $kb_id = (int) ($_GET['kb_id'] ?? 0);
    $query  = trim($_GET['q'] ?? '');
    $top_k  = max(1, min(10, (int) ($_GET['k'] ?? 4)));

    if ($kb_id <= 0 || $query === '') {
        echo json_encode(['error' => '请选择知识库并输入查询'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $t0 = microtime(true);
        $result = knowledge_retrieval_fetch_context($db, $kb_id, $query, $top_k, 9999);
        $elapsed_ms = round((microtime(true) - $t0) * 1000);

        $chunks = $result['chunks'] ?? [];
        $out = [];
        foreach ($chunks as $i => $chunk) {
            $out[] = [
                'rank'       => $i + 1,
                'chunk_idx'  => (int) ($chunk['chunk_index'] ?? $i),
                'content'    => (string) ($chunk['content'] ?? ''),
                'score'      => round((float) ($chunk['score'] ?? 0), 4),
                'has_vector' => isset($chunk['vector_distance']),
                'token_count'=> (int) ($chunk['token_count'] ?? 0),
            ];
        }
        echo json_encode([
            'ok'         => true,
            'elapsed_ms' => $elapsed_ms,
            'mode'       => $pgvector_ok ? 'pgvector + lexical hybrid' : 'lexical fallback',
            'chunks'     => $out,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ---------- POST: rebuild chunks ----------
$post_msg = '';
$post_err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $post_err = 'CSRF 验证失败';
    } else {
        $rebuild_id = (int) ($_POST['rebuild_kb_id'] ?? 0);
        if ($rebuild_id > 0) {
            try {
                $stmt = $db->prepare("SELECT id, content FROM knowledge_bases WHERE id = ?");
                $stmt->execute([$rebuild_id]);
                $kb = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($kb) {
                    $count = knowledge_retrieval_sync_chunks($db, $rebuild_id, (string) ($kb['content'] ?? ''));
                    $post_msg = "知识库 #{$rebuild_id} 重建完成，共 {$count} 个切片";
                } else {
                    $post_err = '知识库不存在';
                }
            } catch (Throwable $e) {
                $post_err = '重建失败: ' . $e->getMessage();
            }
        }
    }
    header('Location: rag-test.php?rebuilt=1');
    exit;
}
if (!empty($_GET['rebuilt'])) {
    $post_msg = '切片重建完成，请刷新页面查看统计';
}

$page_title = 'RAG 检索测试';
require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-900">RAG 知识库检索测试</h1>
    <p class="mt-1 text-sm text-gray-500">验证向量检索质量，查看片段得分，诊断 embedding 状态</p>
</div>

<?php if ($post_msg): ?>
    <div class="mb-5 px-4 py-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-700"><?php echo htmlspecialchars($post_msg); ?></div>
<?php endif; ?>
<?php if ($post_err): ?>
    <div class="mb-5 px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700"><?php echo htmlspecialchars($post_err); ?></div>
<?php endif; ?>

<!-- Status row -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    <!-- pgvector -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-4">
        <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $pgvector_ok ? 'bg-green-100' : 'bg-yellow-100'; ?>">
            <i data-lucide="database" class="w-5 h-5 <?php echo $pgvector_ok ? 'text-green-600' : 'text-yellow-600'; ?>"></i>
        </div>
        <div>
            <p class="text-xs text-gray-400 mb-0.5">pgvector 扩展</p>
            <p class="font-semibold text-sm <?php echo $pgvector_ok ? 'text-green-700' : 'text-yellow-600'; ?>">
                <?php echo $pgvector_ok ? '可用（向量检索）' : '不可用（词法 fallback）'; ?>
            </p>
        </div>
    </div>

    <!-- embedding model -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-4">
        <div class="w-10 h-10 rounded-full flex items-center justify-center <?php echo $embedding_model ? 'bg-blue-100' : 'bg-gray-100'; ?>">
            <i data-lucide="cpu" class="w-5 h-5 <?php echo $embedding_model ? 'text-blue-600' : 'text-gray-400'; ?>"></i>
        </div>
        <div>
            <p class="text-xs text-gray-400 mb-0.5">Embedding 模型</p>
            <p class="font-semibold text-sm <?php echo $embedding_model ? 'text-blue-700' : 'text-gray-500'; ?>">
                <?php echo $embedding_model ? htmlspecialchars($embedding_model['name'] ?? $embedding_model['model_id'] ?? '-') : '未配置'; ?>
            </p>
        </div>
    </div>

    <!-- total KBs -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-4">
        <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center">
            <i data-lucide="book-open" class="w-5 h-5 text-purple-600"></i>
        </div>
        <div>
            <p class="text-xs text-gray-400 mb-0.5">知识库总数</p>
            <p class="font-semibold text-sm text-gray-900"><?php echo count($kb_stats); ?> 个</p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

    <!-- Left: KB table + rebuild -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden mb-5">
            <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                <h2 class="font-semibold text-gray-700 text-sm">知识库向量覆盖率</h2>
            </div>
            <?php if (empty($kb_stats)): ?>
                <div class="p-8 text-center text-gray-400 text-sm">暂无知识库</div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead><tr class="bg-gray-50 text-xs text-gray-400 uppercase">
                            <th class="px-3 py-2 text-left">名称</th>
                            <th class="px-3 py-2 text-center">切片</th>
                            <th class="px-3 py-2 text-center">向量</th>
                            <th class="px-3 py-2 text-center">覆盖</th>
                        </tr></thead>
                        <tbody class="divide-y divide-gray-50">
                        <?php foreach ($kb_stats as $kb): ?>
                            <?php
                            $chunks = (int) $kb['chunk_count'];
                            $vecs   = (int) $kb['vector_count'];
                            $pct    = $chunks > 0 ? round($vecs / $chunks * 100) : 0;
                            $bar_color = $pct >= 90 ? 'bg-green-500' : ($pct > 0 ? 'bg-yellow-400' : 'bg-gray-200');
                            ?>
                            <tr class="hover:bg-gray-50 cursor-pointer" onclick="selectKb(<?php echo $kb['id']; ?>, <?php echo json_encode($kb['name']); ?>)">
                                <td class="px-3 py-2.5">
                                    <span class="font-medium text-gray-800 text-xs"><?php echo htmlspecialchars(mb_substr($kb['name'], 0, 16, 'UTF-8')); ?></span>
                                </td>
                                <td class="px-3 py-2.5 text-center text-gray-600"><?php echo $chunks; ?></td>
                                <td class="px-3 py-2.5 text-center text-gray-600"><?php echo $vecs; ?></td>
                                <td class="px-3 py-2.5 text-center">
                                    <div class="flex items-center gap-1.5">
                                        <div class="flex-1 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                            <div class="h-full <?php echo $bar_color; ?> rounded-full" style="width:<?php echo $pct; ?>%"></div>
                                        </div>
                                        <span class="text-xs text-gray-500 w-8 text-right"><?php echo $pct; ?>%</span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Rebuild chunks -->
        <div class="bg-white rounded-xl border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">重建知识切片</h3>
            <p class="text-xs text-gray-400 mb-3">重新分段并生成 embedding，可修复覆盖率 0% 的知识库</p>
            <form method="POST" action="rag-test.php" class="flex gap-2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <select name="rebuild_kb_id" class="flex-1 border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                    <option value="">选择知识库</option>
                    <?php foreach ($kb_stats as $kb): ?>
                        <option value="<?php echo $kb['id']; ?>"><?php echo htmlspecialchars($kb['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700">重建</button>
            </form>
        </div>
    </div>

    <!-- Right: query test -->
    <div class="lg:col-span-3">
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100">
                <h2 class="font-semibold text-gray-700 text-sm">在线检索测试</h2>
            </div>
            <div class="p-5">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
                    <div class="sm:col-span-1">
                        <label class="text-xs text-gray-500 block mb-1">知识库</label>
                        <select id="kb-select" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                            <option value="">-- 选择 --</option>
                            <?php foreach ($kb_stats as $kb): ?>
                                <option value="<?php echo $kb['id']; ?>"><?php echo htmlspecialchars($kb['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sm:col-span-1">
                        <label class="text-xs text-gray-500 block mb-1">Top-K</label>
                        <input type="number" id="topk" value="4" min="1" max="10" class="w-full border border-gray-300 rounded-lg px-2 py-1.5 text-sm">
                    </div>
                    <div class="sm:col-span-1 flex items-end">
                        <button onclick="runQuery()" class="w-full py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700 flex items-center justify-center gap-1">
                            <i data-lucide="search" class="w-4 h-4"></i> 检索
                        </button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="text-xs text-gray-500 block mb-1">查询语句</label>
                    <textarea id="query-input" rows="2" placeholder="输入问题或关键词，例如：品牌的核心优势是什么？" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm resize-none focus:outline-none focus:ring-2 focus:ring-blue-300"></textarea>
                </div>

                <!-- Results -->
                <div id="result-area" class="hidden">
                    <div id="result-meta" class="flex items-center gap-3 mb-3 text-xs text-gray-400"></div>
                    <div id="result-list" class="space-y-3"></div>
                </div>
                <div id="result-empty" class="py-12 text-center text-gray-300 text-sm">
                    <i data-lucide="search" class="w-8 h-8 mx-auto mb-2 opacity-40"></i>
                    <p>输入查询后点击「检索」查看匹配片段</p>
                </div>
                <div id="result-loading" class="hidden py-8 text-center text-gray-400 text-sm">
                    <div class="inline-block w-5 h-5 border-2 border-blue-400 border-t-transparent rounded-full animate-spin mb-2"></div>
                    <p>检索中...</p>
                </div>
                <div id="result-error" class="hidden px-4 py-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-700"></div>
            </div>
        </div>
    </div>
</div>

<script>
function selectKb(id, name) {
    document.getElementById('kb-select').value = id;
}

async function runQuery() {
    const kbId  = document.getElementById('kb-select').value;
    const query = document.getElementById('query-input').value.trim();
    const topk  = document.getElementById('topk').value;

    if (!kbId) { alert('请选择知识库'); return; }
    if (!query) { alert('请输入查询语句'); return; }

    document.getElementById('result-area').classList.add('hidden');
    document.getElementById('result-empty').classList.add('hidden');
    document.getElementById('result-error').classList.add('hidden');
    document.getElementById('result-loading').classList.remove('hidden');

    try {
        const url = `rag-test.php?action=query&kb_id=${kbId}&q=${encodeURIComponent(query)}&k=${topk}`;
        const resp = await fetch(url);
        const data = await resp.json();

        document.getElementById('result-loading').classList.add('hidden');

        if (data.error) {
            document.getElementById('result-error').textContent = data.error;
            document.getElementById('result-error').classList.remove('hidden');
            return;
        }

        const meta = document.getElementById('result-meta');
        meta.innerHTML = `
            <span class="bg-blue-100 text-blue-700 px-2 py-0.5 rounded">${data.mode}</span>
            <span>返回 ${data.chunks.length} 片段</span>
            <span>${data.elapsed_ms} ms</span>
        `;

        const list = document.getElementById('result-list');
        list.innerHTML = '';

        if (data.chunks.length === 0) {
            list.innerHTML = '<p class="text-center text-gray-400 text-sm py-6">无匹配片段</p>';
        } else {
            data.chunks.forEach(chunk => {
                const scoreColor = chunk.score >= 0.5 ? '#16a34a' : chunk.score >= 0.2 ? '#d97706' : '#9ca3af';
                const vectorBadge = chunk.has_vector
                    ? '<span class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded text-xs">向量</span>'
                    : '<span class="bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded text-xs">词法</span>';
                const div = document.createElement('div');
                div.className = 'border border-gray-200 rounded-lg p-3';
                div.innerHTML = `
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-5 h-5 rounded-full bg-gray-800 text-white text-xs flex items-center justify-center font-bold">${chunk.rank}</span>
                        ${vectorBadge}
                        <span class="text-xs text-gray-400">切片 #${chunk.chunk_idx}</span>
                        <span class="text-xs text-gray-400">${chunk.token_count} tokens</span>
                        <span class="ml-auto font-mono text-xs font-semibold" style="color:${scoreColor}">score ${chunk.score}</span>
                    </div>
                    <p class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">${escHtml(chunk.content)}</p>
                `;
                list.appendChild(div);
            });
        }

        document.getElementById('result-area').classList.remove('hidden');
    } catch (e) {
        document.getElementById('result-loading').classList.add('hidden');
        document.getElementById('result-error').textContent = '请求失败: ' + e.message;
        document.getElementById('result-error').classList.remove('hidden');
    }
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// allow Enter key in textarea to trigger search with Ctrl+Enter
document.getElementById('query-input')?.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') runQuery();
});

document.addEventListener('DOMContentLoaded', () => {
    if (typeof lucide !== 'undefined') lucide.createIcons();
});
</script>
