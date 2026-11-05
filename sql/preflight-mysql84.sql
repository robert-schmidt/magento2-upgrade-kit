-- Read-only checks to run against a COPY of production (Azure PITR restore) BEFORE the MySQL 8.0 -> 8.4 upgrade
-- and before setup:upgrade on 2.4.8. Every query should return 0 rows unless noted.
--   mysql --force --table -h <host> -u <user> -p --ssl-mode=REQUIRED <db> < preflight-mysql84.sql   (--force: the 8.4-only variable errors on 8.0)

-- 1. FKs that reference columns that are not exactly a PK/UNIQUE key.
--    MySQL 8.4 restrict_fk_on_non_standard_key=ON rejects creating these (ERROR 6125). EE row_id/entity_id staging
--    tables are known to hit this; Adobe says set restrict_fk_on_non_standard_key=OFF.
SELECT '1 fk-on-non-unique-key' AS chk, k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME,
       GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.POSITION_IN_UNIQUE_CONSTRAINT) AS ref_cols
FROM information_schema.KEY_COLUMN_USAGE k
WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL
GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.REFERENCED_TABLE_NAME
HAVING ref_cols NOT IN (
    SELECT GROUP_CONCAT(s.COLUMN_NAME ORDER BY s.SEQ_IN_INDEX)
    FROM information_schema.STATISTICS s
    WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = k.REFERENCED_TABLE_NAME AND s.NON_UNIQUE = 0
    GROUP BY s.INDEX_NAME
);

-- 2. 2.4.8 adds UNIQUE(option_id, store_id) on eav_attribute_option_value: duplicates break setup:upgrade.
SELECT '2 eav-option-value-dupes' AS chk, option_id, store_id, COUNT(*) AS n, GROUP_CONCAT(value_id) AS value_ids
FROM eav_attribute_option_value GROUP BY option_id, store_id HAVING n > 1;

-- 3. Identifiers that are reserved words in MySQL 8.4 (MANUAL, PARALLEL, QUALIFY, TABLESAMPLE). Verify list against
--    https://dev.mysql.com/doc/refman/8.4/en/keywords.html ; unquoted use in custom SQL breaks.
SELECT '3 reserved-word-identifier' AS chk, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND UPPER(COLUMN_NAME) IN ('MANUAL', 'PARALLEL', 'QUALIFY', 'TABLESAMPLE')
UNION ALL
SELECT '3 reserved-word-identifier', TABLE_NAME, NULL FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND UPPER(TABLE_NAME) IN ('MANUAL', 'PARALLEL', 'QUALIFY', 'TABLESAMPLE');

-- 4. AUTO_INCREMENT on FLOAT/DOUBLE columns: removed in 8.4.
SELECT '4 float-auto-increment' AS chk, TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%auto_increment%' AND DATA_TYPE IN ('float', 'double');

-- 5. Tables without a primary key: Azure sql_generate_invisible_primary_key=ON makes setup:upgrade fail on these
--    (magento/magento2#38114). Keep that server parameter OFF.
SELECT '5 no-primary-key' AS chk, t.TABLE_NAME FROM information_schema.TABLES t
LEFT JOIN information_schema.TABLE_CONSTRAINTS c
  ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME AND c.CONSTRAINT_TYPE = 'PRIMARY KEY'
WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND c.CONSTRAINT_NAME IS NULL;

-- 6. Non-Magento triggers (Magento mview triggers are named trg_*). Custom triggers are unsupported and get in the way of mview.
SELECT '6 custom-trigger' AS chk, TRIGGER_NAME, EVENT_OBJECT_TABLE, DEFINER FROM information_schema.TRIGGERS
WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME NOT LIKE 'trg\_%';

-- 7. INFO (not an error): tables/columns still on utf8mb3. 2.4.8 defaults to utf8mb4 and converts core tables;
--    custom tables joined to core ones can hit "Illegal mix of collations".
SELECT '7 info-utf8mb3-table' AS chk, TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_COLLATION LIKE 'utf8mb3%' OR TABLE_COLLATION LIKE 'utf8\_%');

-- 8. INFO: server settings that matter for Magento on 8.4 / Azure.
SELECT '8 info-vars' AS chk, @@version AS version,
       @@log_bin_trust_function_creators AS log_bin_trust_function_creators_should_be_1,
       @@explicit_defaults_for_timestamp AS explicit_defaults_for_timestamp_should_be_1,
       @@sql_generate_invisible_primary_key AS sql_generate_invisible_pk_should_be_0;
-- on 8.4 only (errors on 8.0, that's fine):
SELECT '8 info-vars-84' AS chk, @@restrict_fk_on_non_standard_key AS restrict_fk_on_non_standard_key_should_be_0;

-- 9. INFO: auth plugin of the Magento DB user (mysql_native_password is disabled by default on 8.4, removed in 9.0).
--    Needs SELECT on mysql.user; on Azure run as the admin user.
SELECT '9 info-auth-plugin' AS chk, user, host, plugin FROM mysql.user WHERE user NOT LIKE 'mysql.%' AND user NOT LIKE 'azure_%';

-- 10. 8.4 only: can THIS user switch the FK restriction off per session? If this works, Magento can run it from
--     env.php 'initStatements' (e.g. "SET NAMES utf8; SET SESSION restrict_fk_on_non_standard_key=OFF;") even when the
--     managed service does not expose the global parameter. Verified on mysql:8.4: no extra privilege needed.
SET SESSION restrict_fk_on_non_standard_key = OFF;
SELECT '10 session-restrict-fk-off-works' AS chk, @@session.restrict_fk_on_non_standard_key AS now_0_means_ok;
