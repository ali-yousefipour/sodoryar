-- Sodoryar 012: add A4 to existing thermal80/A5 company printing formats.
-- Non-destructive ENUM extension. Verify backups and stage before production.
-- Existing paper_type values remain unchanged.
ALTER TABLE print_templates
    MODIFY COLUMN paper_type ENUM('thermal80','a5','a4') NOT NULL;
