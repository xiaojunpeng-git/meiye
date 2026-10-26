SELECT DATABASE() AS target_database;
SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('eb_store_fund_subject','eb_store_fund_document','eb_store_fund_document_line');
