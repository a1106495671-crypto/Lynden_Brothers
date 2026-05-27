-- Migration 011: Citation baseline snapshots for before/after comparison
CREATE TABLE IF NOT EXISTS geo_citation_baselines (
    id BIGSERIAL PRIMARY KEY,
    customer_id VARCHAR(80) NOT NULL,
    baseline_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
    keyword_count INTEGER DEFAULT 0,
    note VARCHAR(200),
    recorded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_geo_citation_baselines_customer ON geo_citation_baselines(customer_id, recorded_at);
