-- Migration 013: Manual first-answer QA baselines for GEO diagnosis and monitoring
CREATE TABLE IF NOT EXISTS geo_baseline_qa (
    id BIGSERIAL PRIMARY KEY,
    diagnosis_id UUID DEFAULT NULL,
    customer_id VARCHAR(80) NOT NULL DEFAULT '',
    brand_name VARCHAR(200) NOT NULL DEFAULT '',
    question TEXT NOT NULL DEFAULT '',
    platform VARCHAR(30) NOT NULL DEFAULT 'deepseek',
    baseline_answer TEXT NOT NULL DEFAULT '',
    mention_brand BOOLEAN NOT NULL DEFAULT FALSE,
    sentiment VARCHAR(20) NOT NULL DEFAULT 'neutral',
    keywords TEXT NOT NULL DEFAULT '',
    sort_order SMALLINT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_geo_baseline_qa_diagnosis ON geo_baseline_qa(diagnosis_id, sort_order);
CREATE INDEX IF NOT EXISTS idx_geo_baseline_qa_customer ON geo_baseline_qa(customer_id, status);
CREATE INDEX IF NOT EXISTS idx_geo_baseline_qa_lookup ON geo_baseline_qa(customer_id, platform, status);
