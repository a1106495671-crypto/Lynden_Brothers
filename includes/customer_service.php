<?php
/**
 * 客户管理API服务
 */

if (!defined('FEISHU_TREASURE')) {
    die('Access denied');
}

class CustomerService {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function createCustomer(array $data): array {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('validation_error', '客户名称不能为空');
        }

        $customerId = 'cust_' . bin2hex(random_bytes(8));

        $stmt = $this->db->prepare("
            INSERT INTO customers (customer_id, name, domain, industry, package_tier, owner, contact_name, contact_phone, contract_start_date, contract_end_date, contract_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $customerId,
            $name,
            trim((string) ($data['domain'] ?? '')),
            trim((string) ($data['industry'] ?? '')),
            trim((string) ($data['package_tier'] ?? '')),
            trim((string) ($data['owner'] ?? '')),
            trim((string) ($data['contact_name'] ?? '')),
            trim((string) ($data['contact_phone'] ?? '')),
            $data['contract_start_date'] ?? null,
            $data['contract_end_date'] ?? null,
            (float) ($data['contract_amount'] ?? 0),
        ]);

        return $this->getCustomer($customerId);
    }

    public function getCustomer(string $customerId): array {
        $stmt = $this->db->prepare("SELECT * FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ApiException('not_found', '客户不存在', 404);
        }
        return $row;
    }

    public function listCustomers(int $page = 1, int $perPage = 20, array $filters = []): array {
        $where = '1=1';
        $params = [];

        if (!empty($filters['search'])) {
            $where .= " AND (name ILIKE ? OR customer_id ILIKE ?)";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
        }
        if (!empty($filters['status'])) {
            $where .= " AND service_status = ?";
            $params[] = $filters['status'];
        }

        $offset = ($page - 1) * $perPage;

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM customers WHERE {$where}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT * FROM customers WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function updateCustomer(string $customerId, array $data): array {
        $existing = $this->getCustomer($customerId);

        $fields = ['name', 'domain', 'industry', 'package_tier', 'owner', 'contact_name', 'contact_phone', 'service_status'];
        $sets = [];
        $params = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = ?";
                $params[] = trim((string) $data[$field]);
            }
        }

        if (!empty($data['contract_start_date'])) {
            $sets[] = "contract_start_date = ?";
            $params[] = $data['contract_start_date'];
        }
        if (!empty($data['contract_end_date'])) {
            $sets[] = "contract_end_date = ?";
            $params[] = $data['contract_end_date'];
        }
        if (isset($data['contract_amount'])) {
            $sets[] = "contract_amount = ?";
            $params[] = (float) $data['contract_amount'];
        }

        if ($sets) {
            $sets[] = "updated_at = CURRENT_TIMESTAMP";
            $params[] = $customerId;
            $stmt = $this->db->prepare("UPDATE customers SET " . implode(', ', $sets) . " WHERE customer_id = ?");
            $stmt->execute($params);
        }

        return $this->getCustomer($customerId);
    }

    public function deleteCustomer(string $customerId): void {
        $this->getCustomer($customerId);
        $stmt = $this->db->prepare("DELETE FROM customers WHERE customer_id = ?");
        $stmt->execute([$customerId]);
    }
}
