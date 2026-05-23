<?php
/**
 * 素材库API服务 — 关键词库、标题库、知识库
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class MaterialService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // ========== 关键词库 ==========

    public function createKeywordLibrary(array $data): array {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('validation_error', '库名称不能为空');
        }

        $stmt = $this->db->prepare("INSERT INTO keyword_libraries (name, description) VALUES (?, ?)");
        $stmt->execute([$name, trim((string) ($data['description'] ?? ''))]);
        $id = (int) $this->db->lastInsertId();

        if (!empty($data['keywords']) && is_array($data['keywords'])) {
            $this->addKeywords($id, $data['keywords']);
        }

        return $this->getKeywordLibrary($id);
    }

    public function getKeywordLibrary(int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM keyword_libraries WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('not_found', '关键词库不存在', 404);
        }
        $row['keywords'] = $this->listKeywords($id);
        return $row;
    }

    public function listKeywordLibraries(): array {
        $stmt = $this->db->query("SELECT * FROM keyword_libraries ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addKeywords(int $libraryId, array $keywords): array {
        $this->getKeywordLibrary($libraryId);
        $added = 0;
        $stmt = $this->db->prepare("INSERT INTO keywords (library_id, keyword) VALUES (?, ?) ON CONFLICT DO NOTHING");
        foreach ($keywords as $kw) {
            $kw = trim((string) $kw);
            if ($kw !== '') {
                $stmt->execute([$libraryId, $kw]);
                $added++;
            }
        }
        $this->db->prepare("UPDATE keyword_libraries SET keyword_count = (SELECT COUNT(*) FROM keywords WHERE library_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$libraryId, $libraryId]);
        return ['added' => $added, 'library_id' => $libraryId];
    }

    public function listKeywords(int $libraryId): array {
        $stmt = $this->db->prepare("SELECT * FROM keywords WHERE library_id = ? ORDER BY id");
        $stmt->execute([$libraryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteKeywordLibrary(int $id): void {
        $this->getKeywordLibrary($id);
        $this->db->prepare("DELETE FROM keyword_libraries WHERE id = ?")->execute([$id]);
    }

    // ========== 标题库 ==========

    public function createTitleLibrary(array $data): array {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('validation_error', '库名称不能为空');
        }

        $keywordLibraryId = !empty($data['keyword_library_id']) ? (int) $data['keyword_library_id'] : null;

        $stmt = $this->db->prepare("INSERT INTO title_libraries (name, description, keyword_library_id) VALUES (?, ?, ?)");
        $stmt->execute([$name, trim((string) ($data['description'] ?? '')), $keywordLibraryId]);
        $id = (int) $this->db->lastInsertId();

        if (!empty($data['titles']) && is_array($data['titles'])) {
            $this->addTitles($id, $data['titles']);
        }

        return $this->getTitleLibrary($id);
    }

    public function getTitleLibrary(int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM title_libraries WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('not_found', '标题库不存在', 404);
        }
        $row['titles'] = $this->listTitles($id);
        return $row;
    }

    public function listTitleLibraries(): array {
        $stmt = $this->db->query("SELECT * FROM title_libraries ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addTitles(int $libraryId, array $titles): array {
        $this->getTitleLibrary($libraryId);
        $added = 0;
        $stmt = $this->db->prepare("INSERT INTO titles (library_id, title) VALUES (?, ?)");
        foreach ($titles as $title) {
            $title = trim((string) $title);
            if ($title !== '') {
                $stmt->execute([$libraryId, $title]);
                $added++;
            }
        }
        $this->db->prepare("UPDATE title_libraries SET title_count = (SELECT COUNT(*) FROM titles WHERE library_id = ?), updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$libraryId, $libraryId]);
        return ['added' => $added, 'library_id' => $libraryId];
    }

    public function listTitles(int $libraryId): array {
        $stmt = $this->db->prepare("SELECT * FROM titles WHERE library_id = ? ORDER BY id");
        $stmt->execute([$libraryId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function deleteTitleLibrary(int $id): void {
        $this->getTitleLibrary($id);
        $this->db->prepare("DELETE FROM title_libraries WHERE id = ?")->execute([$id]);
    }

    // ========== 知识库 ==========

    public function createKnowledgeBase(array $data): array {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('validation_error', '知识库名称不能为空');
        }

        $content = trim((string) ($data['content'] ?? ''));
        $charCount = mb_strlen($content);

        $stmt = $this->db->prepare("INSERT INTO knowledge_bases (name, content, description, character_count, word_count) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $content, trim((string) ($data['description'] ?? '')), $charCount, $charCount]);
        $id = (int) $this->db->lastInsertId();

        return $this->getKnowledgeBase($id);
    }

    public function getKnowledgeBase(int $id): array {
        $stmt = $this->db->prepare("SELECT * FROM knowledge_bases WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('not_found', '知识库不存在', 404);
        }
        return $row;
    }

    public function listKnowledgeBases(): array {
        $stmt = $this->db->query("SELECT * FROM knowledge_bases ORDER BY created_at DESC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateKnowledgeBase(int $id, array $data): array {
        $this->getKnowledgeBase($id);

        $sets = [];
        $params = [];

        if (isset($data['name'])) {
            $sets[] = "name = ?";
            $params[] = trim((string) $data['name']);
        }
        if (isset($data['content'])) {
            $content = trim((string) $data['content']);
            $sets[] = "content = ?";
            $params[] = $content;
            $sets[] = "character_count = ?";
            $params[] = mb_strlen($content);
        }
        if (isset($data['description'])) {
            $sets[] = "description = ?";
            $params[] = trim((string) $data['description']);
        }

        if ($sets) {
            $sets[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = $id;
            $this->db->prepare("UPDATE knowledge_bases SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
        }

        return $this->getKnowledgeBase($id);
    }

    public function deleteKnowledgeBase(int $id): void {
        $this->getKnowledgeBase($id);
        $this->db->prepare("DELETE FROM knowledge_bases WHERE id = ?")->execute([$id]);
    }
}
