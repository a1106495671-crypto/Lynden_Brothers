-- Persist selected media accounts for automation workflows.
-- This is required so Step 11 distributes only to accounts chosen at workflow start.

ALTER TABLE automation_workflows
    ADD COLUMN IF NOT EXISTS media_account_ids TEXT DEFAULT '[]';
