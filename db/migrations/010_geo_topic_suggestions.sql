-- Migration 010: Auto-generated topic suggestions from coverage gaps
CREATE TABLE IF NOT EXISTS geo_topic_suggestions (
    id BIGSERIAL PRIMARY KEY,
    customer_id VARCHAR(80) NOT NULL,
    title TEXT NOT NULL,
    source_keyword VARCHAR(200),
    mention_rate DECIMAL(5,2),
    status VARCHAR(20) DEFAULT 'pending',
    task_id BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_geo_topic_suggestions_customer ON geo_topic_suggestions(customer_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_geo_topic_suggestions_status ON geo_topic_suggestions(status);
