-- GEO Monitor V3 migration
-- Adds the backend fields required to turn monitoring from a dashboard into a closed-loop center.

ALTER TABLE monitor_alerts
    ADD COLUMN IF NOT EXISTS sop_trigger_status VARCHAR(16) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS sop_triggered_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS sop_instance_id VARCHAR(64) DEFAULT NULL;

COMMENT ON COLUMN monitor_alerts.sop_trigger_status IS 'Emergency SOP trigger state: pending/triggered/failed/skipped.';
COMMENT ON COLUMN monitor_alerts.sop_triggered_at IS 'Time when a HIGH monitor alert actually triggered emergency SOP.';
COMMENT ON COLUMN monitor_alerts.sop_instance_id IS 'Linked emergency SOP instance id.';

ALTER TABLE monitor_batches
    ADD COLUMN IF NOT EXISTS last_renewal_pack_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS renewal_pack_pushed BOOLEAN DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS keyword_tiers JSONB DEFAULT '{}'::jsonb;

COMMENT ON COLUMN monitor_batches.last_renewal_pack_at IS 'Last auto-generated renewal evidence pack time.';
COMMENT ON COLUMN monitor_batches.renewal_pack_pushed IS 'Whether the renewal evidence pack has been pushed to owner/customer center.';
COMMENT ON COLUMN monitor_batches.keyword_tiers IS 'Keyword run tiers. Core keywords run weekly; long-tail keywords run monthly.';

ALTER TABLE monitor_snapshots
    ADD COLUMN IF NOT EXISTS data_origin VARCHAR(16) NOT NULL DEFAULT 'live';

COMMENT ON COLUMN monitor_snapshots.data_origin IS 'Snapshot source: live or radar_baseline.';

CREATE INDEX IF NOT EXISTS idx_monitor_alerts_sop_trigger_status
    ON monitor_alerts (sop_trigger_status);

CREATE INDEX IF NOT EXISTS idx_monitor_snapshots_data_origin
    ON monitor_snapshots (data_origin);
