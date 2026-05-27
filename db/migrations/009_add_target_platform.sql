-- Migration 009: Add target_platform to tasks table
ALTER TABLE tasks ADD COLUMN IF NOT EXISTS target_platform VARCHAR(20) DEFAULT 'general';
