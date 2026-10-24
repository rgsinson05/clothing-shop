-- Phase A only. REVIEW BEFORE EXECUTION; the application never applies this file.
-- Preconditions: migrations 001-015 and the inspected MySQL 8.4 / MariaDB 11.4
-- schema. OUT_FOR_DELIVERY already exists: preserve every existing ENUM ordinal.
-- No orders.status change, data backfill, notification rewrite, or inventory release.
-- Cycle 1/revision 0 are initial control values, NOT fabricated historical events.
-- DDL implicitly commits: take a backup, inspect SHOW CREATE TABLE and triggers,
-- use a maintenance window, and apply exactly once on an explicitly approved
-- environment. Neither clothing_shop nor the shared clothing_shop_bundle_test
-- is an authorized migration target for this task. Do not auto-retry partial DDL.
-- Rollout and partial-DDL recovery/verification procedure is below the statements.

ALTER TABLE shipments
    MODIFY COLUMN shipment_status ENUM(
        'NOT_SHIPPED','READY_TO_SHIP','SHIPPED','IN_TRANSIT','OUT_FOR_DELIVERY',
        'DELIVERED','DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP'
    ) NOT NULL DEFAULT 'NOT_SHIPPED',
    ADD COLUMN delivery_cycle INT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN delivery_revision INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    ADD CONSTRAINT chk_delivery_cycle CHECK (delivery_cycle >= 1),
    ADD CONSTRAINT chk_delivery_attempts CHECK (failed_attempts BETWEEN 0 AND 3);

CREATE TABLE delivery_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    delivery_cycle INT UNSIGNED NOT NULL,
    delivery_revision INT UNSIGNED NOT NULL,
    event_type ENUM('OUT_FOR_DELIVERY','DELIVERY_ATTEMPT_FAILED',
        'RETURN_TO_SELLER','RETURNED_TO_SHOP','RETURN_INSPECTED',
        'DELIVERY_CYCLE_AUTHORIZED','DELIVERED') NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    attempt_number TINYINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    previous_state VARCHAR(32) NOT NULL,
    resulting_state VARCHAR(32) NOT NULL,
    action_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    event_index TINYINT UNSIGNED NOT NULL DEFAULT 0,
    request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_delivery_action (action_key, event_index),
    UNIQUE KEY uq_delivery_event_identity (id, order_id, delivery_cycle),
    UNIQUE KEY uq_delivery_failure (order_id, delivery_cycle, attempt_number),
    KEY idx_delivery_history (order_id, delivery_cycle, id),
    FOREIGN KEY (order_id) REFERENCES orders (id),
    FOREIGN KEY (admin_id) REFERENCES admins (id),
    CONSTRAINT chk_event_cycle CHECK (delivery_cycle >= 1),
    CONSTRAINT chk_event_failure CHECK (
        (event_type = 'DELIVERY_ATTEMPT_FAILED' AND attempt_number IS NOT NULL
            AND attempt_number BETWEEN 1 AND 3 AND reason IS NOT NULL
            AND CHAR_LENGTH(TRIM(reason)) > 0)
        OR (event_type <> 'DELIVERY_ATTEMPT_FAILED' AND attempt_number IS NULL)
    )
) ENGINE=InnoDB;

-- Item-level physical return and inspection evidence. An inspection is separate
-- from return confirmation; neither is a cancellation, refund or release.
CREATE TABLE delivery_event_items (
    event_id BIGINT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    delivery_cycle INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    record_type ENUM('RETURNED','INSPECTED') NOT NULL,
    outcome ENUM('RETURNED','RETRY_APPROVED','MANUAL_REVIEW') NOT NULL,
    PRIMARY KEY (event_id, order_item_id),
    UNIQUE KEY uq_delivery_item_record (order_id, delivery_cycle, order_item_id, record_type),
    FOREIGN KEY (event_id, order_id, delivery_cycle)
        REFERENCES delivery_events (id, order_id, delivery_cycle),
    FOREIGN KEY (order_id) REFERENCES orders (id),
    FOREIGN KEY (order_item_id) REFERENCES order_items (id),
    FOREIGN KEY (product_id) REFERENCES products (id),
    CONSTRAINT chk_delivery_item_cycle CHECK (delivery_cycle >= 1),
    CONSTRAINT chk_delivery_item_outcome CHECK (
        (record_type = 'RETURNED' AND outcome = 'RETURNED') OR
        (record_type = 'INSPECTED' AND outcome IN ('RETRY_APPROVED','MANUAL_REVIEW'))
    )
) ENGINE=InnoDB;

-- Protect append-only evidence even from accidental application UPDATE/DELETE.
CREATE TRIGGER delivery_events_no_update BEFORE UPDATE ON delivery_events
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery history is append-only';
CREATE TRIGGER delivery_events_no_delete BEFORE DELETE ON delivery_events
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery history is append-only';
CREATE TRIGGER delivery_event_items_no_update BEFORE UPDATE ON delivery_event_items
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery item history is append-only';
CREATE TRIGGER delivery_event_items_no_delete BEFORE DELETE ON delivery_event_items
    FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Delivery item history is append-only';

-- Legacy calls omit occurrence_key and keep their existing once-per-type identity.
-- Historical ids, messages, times and read states remain exactly as recorded.
ALTER TABLE customer_order_notifications
    ADD COLUMN occurrence_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'legacy',
    MODIFY COLUMN notification_type ENUM('ORDER_SHIPPED','ORDER_DELIVERED',
        'PICKUP_READY','PICKUP_HANDED_OVER','ORDER_CANCELLED','OUT_FOR_DELIVERY',
        'DELIVERY_ATTEMPT_FAILED','RETURN_TO_SELLER','RETURNED_TO_SHOP',
        'DELIVERY_CYCLE_AUTHORIZED') NOT NULL,
    DROP INDEX uq_customer_notification_order_type,
    ADD UNIQUE KEY uq_customer_notification_occurrence
        (customer_id, order_id, notification_type, occurrence_key);

-- ============================================================================
-- OPERATIONAL RUNBOOK: ROLLOUT BLOCKER AND PARTIAL-DDL RECOVERY (MySQL 8.4)
-- Documentation only. No migration/recovery execution is authorized by Phase A.
-- ============================================================================
--
-- 1. ROLLOUT DEPENDENCY / C1
-- There is NO environment feature flag for this workflow. In order-view.php,
-- the locked generic SHIPPED -> DELIVERED action uses:
--     SHOW COLUMNS FROM shipments LIKE 'delivery_revision';
-- Absent column: preserve the legacy advance, existing payment/verification
-- gates, delivered timestamps and once-per-type ORDER_DELIVERED notification.
-- Present column (including partial deployment): throw OrderActionException
-- BEFORE any advance writes. No fallback to the legacy Delivered action.
-- A schema-query failure also aborts/rolls back through the existing exception
-- handler. It is not interpreted as an absent column.
-- admin/api/delivery-action.php is backend-only and currently has no form/JS
-- caller. Its explicit non-legacy notification occurrences require the final
-- notification ALTER; missing dependencies fail and roll back the action.
-- Column detection is a cutoff, NOT a full schema-readiness check or UI flag.
-- The existing UI can still offer the old button, but its POST fails closed.
-- Do NOT approve migration 016 until replacement action UI, token handling,
-- manual-review presentation and a tested coordinated rollout are ready.
-- Do not remove the cutoff to restore old UI functionality after stage S1.
--
-- 2. BEFORE ANY FUTURE APPROVED EXECUTION
-- Stop all application writers, including API/worker/payment/order endpoints;
-- prevent direct delivery-action calls. Maintenance mode must prevent writes,
-- not merely hide a button. Record version/database/engine, SHOW CREATE TABLE
-- for all dependencies, SHOW CREATE TRIGGER for existing triggers, baseline
-- shipment controls, notification row counts/ids/read states, and a consistent
-- verified backup with a tested restore and retained binary logs/PITR position.
-- Check migrations 001-015 and exact types of referenced PKs (INT UNSIGNED).
-- Rehearse separately on an approved disposable MySQL 8.4 instance first.
-- Neither clothing_shop nor clothing_shop_bundle_test is a disposable target.
--
-- 3. DDL STAGES AND EXPECTED STATE (execute at most one reviewed stage at a time)
-- S0: pre-016 baseline: no new controls/tables/triggers/occurrence_key; original
--     six shipment enum values and five notification enum values retained.
-- S1: first ALTER shipments completes AS ONE STATEMENT: original enum ordinals
--     retained, three new states appended, cycle=1/revision=0/attempts=0 defaults,
--     unsigned NOT NULL controls, chk_delivery_cycle/chk_delivery_attempts.
--     Legacy Delivered action is now disabled. No history is fabricated.
-- S2: CREATE delivery_events: all columns, primary/unique/history/FK indexes,
--     two FKs and two enforced checks as defined above; no triggers yet.
-- S3: CREATE delivery_event_items: all columns, primary/unique/FK indexes,
--     composite event identity FK plus order/item/product FKs, two checks.
-- S4a: delivery_events_no_update installed (BEFORE UPDATE).
-- S4b: delivery_events_no_delete installed (BEFORE DELETE).
-- S4c: delivery_event_items_no_update installed (BEFORE UPDATE).
-- S4d: delivery_event_items_no_delete installed (BEFORE DELETE).
--     Each trigger is a SEPARATE DDL stage, not one atomic four-trigger block.
-- S5: final notification ALTER completes AS ONE STATEMENT: occurrence_key
--     VARCHAR(64) ascii/ascii_bin NOT NULL DEFAULT 'legacy', five original
--     notification ordinals retained and five appended, old unique key removed,
--     uq_customer_notification_occurrence installed in the stated column order.
--     Existing notification ids/messages/times/read states remain unchanged;
--     old rows acquire 'legacy'. Existing notification FKs/read indexes survive.
--
-- MySQL 8.4 ALTER/CREATE TABLE (InnoDB) and CREATE TRIGGER participate in atomic
-- DDL: a supported individual statement commits or rolls back its own dictionary,
-- engine and binlog changes, including crash recovery. Atomic DDL is NOT
-- transactional DDL. These statements implicitly commit a pending transaction
-- before execution and normally commit after; BEGIN/ROLLBACK cannot undo earlier
-- stages. A client disconnect/timeout does not establish success or failure:
-- reconnect and inspect, and have the DBA check any still-running DDL first.
-- An unexpected mixed state WITHIN S1 or S5 indicates drift/manual execution or
-- a different engine/version, not an expected transactional rollback mechanism.
-- Do not assume these MySQL guarantees establish MariaDB deployment safety.
-- ALTER on a TEMPORARY table can also commit a transaction; tests create/alter
-- all temporary fixtures before any action transaction. Temporary tests do NOT
-- validate persistent FK/trigger/DDL/crash behavior.
-- References: MySQL 8.4 Reference Manual, Atomic Data Definition Statement
-- Support (15.1.1) and Statements That Cause an Implicit Commit (15.3.3).
--
-- 4. READ-ONLY SCHEMA VERIFICATION (run individually, never the whole SQL file)
-- All information_schema queries below are safe when a stage/table is absent.
-- Compare definitions, not just object counts, with the statements above.
-- SELECT VERSION(), DATABASE(), @@transaction_isolation;
-- SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
-- WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
-- ('shipments','delivery_events','delivery_event_items',
--  'customer_order_notifications','orders','order_items','products','admins');
--
-- Columns, unsigned types, exact ENUM order, nullability, defaults and collation:
-- SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE,
--        COLUMN_DEFAULT, CHARACTER_SET_NAME, COLLATION_NAME, EXTRA
-- FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
-- AND TABLE_NAME IN ('shipments','delivery_events','delivery_event_items',
--                    'customer_order_notifications')
-- ORDER BY TABLE_NAME, ORDINAL_POSITION;
--
-- All indexes, including FK-created supporting indexes (their names may be
-- generated; compare ordered columns rather than guessing generated names):
-- SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME,
--        SUB_PART, INDEX_TYPE FROM information_schema.STATISTICS
-- WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN
-- ('shipments','delivery_events','delivery_event_items','customer_order_notifications')
-- ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
-- Expected named event keys: PRIMARY(id), uq_delivery_action(action_key,event_index),
-- uq_delivery_event_identity(id,order_id,delivery_cycle),
-- uq_delivery_failure(order_id,delivery_cycle,attempt_number),
-- idx_delivery_history(order_id,delivery_cycle,id).
-- Item keys: PRIMARY(event_id,order_item_id),
-- uq_delivery_item_record(order_id,delivery_cycle,order_item_id,record_type).
-- Notification keys: PRIMARY(id), idx_customer_notification_newest,
-- idx_customer_notification_unread, uq_customer_notification_occurrence;
-- uq_customer_notification_order_type must be absent after S5.
--
-- FK targets, component order and referential actions:
-- SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME,
--        k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
--        r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k
-- JOIN information_schema.REFERENTIAL_CONSTRAINTS r
-- ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME
-- AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
-- WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN
-- ('delivery_events','delivery_event_items','customer_order_notifications')
-- ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION;
-- Event/item FKs have no explicit actions (MySQL NO ACTION/RESTRICT), not CASCADE.
-- Notification FKs retain RESTRICT deletes / CASCADE updates from migration 015.
--
-- Check definitions and enforcement, not merely their names:
-- SELECT t.TABLE_NAME, t.CONSTRAINT_NAME, t.ENFORCED, c.CHECK_CLAUSE
-- FROM information_schema.TABLE_CONSTRAINTS t
-- JOIN information_schema.CHECK_CONSTRAINTS c
-- ON c.CONSTRAINT_SCHEMA=t.CONSTRAINT_SCHEMA AND c.CONSTRAINT_NAME=t.CONSTRAINT_NAME
-- WHERE t.TABLE_SCHEMA=DATABASE() AND t.CONSTRAINT_TYPE='CHECK'
-- AND t.TABLE_NAME IN ('shipments','delivery_events','delivery_event_items')
-- ORDER BY t.TABLE_NAME, t.CONSTRAINT_NAME;
-- Expect all six named checks above, ENFORCED=YES.
--
-- Trigger timing, event, body, definer and SQL mode (also detect unexpected ones):
-- SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION,
--        ACTION_STATEMENT, DEFINER, SQL_MODE FROM information_schema.TRIGGERS
-- WHERE TRIGGER_SCHEMA=DATABASE()
-- AND EVENT_OBJECT_TABLE IN ('delivery_events','delivery_event_items')
-- ORDER BY TRIGGER_NAME;
-- Expect four BEFORE triggers with SIGNAL SQLSTATE '45000' and exact messages.
-- After existence is verified, record SHOW CREATE TABLE for each changed table
-- and SHOW CREATE TRIGGER for each of the four named triggers as full evidence.
--
-- 5. READ-ONLY DATA/IDENTITY VERIFICATION (only after required columns exist)
-- SELECT order_id, delivery_cycle, delivery_revision, failed_attempts,
--        shipment_status FROM shipments
-- WHERE delivery_cycle<1 OR failed_attempts>3;
-- Immediately after S1, compare all control defaults to the frozen baseline;
-- after any writer ran, nonzero controls require event reconciliation, NOT reset.
-- SELECT COUNT(*) AS events, MIN(id), MAX(id) FROM delivery_events;
-- SELECT COUNT(*) AS item_evidence FROM delivery_event_items;
-- SELECT action_key, event_index, COUNT(*) FROM delivery_events
-- GROUP BY action_key,event_index HAVING COUNT(*)>1;
-- SELECT order_id,delivery_cycle,attempt_number,COUNT(*) FROM delivery_events
-- WHERE attempt_number IS NOT NULL GROUP BY order_id,delivery_cycle,attempt_number
-- HAVING COUNT(*)>1;
-- SELECT customer_id,order_id,notification_type,occurrence_key,COUNT(*)
-- FROM customer_order_notifications
-- GROUP BY customer_id,order_id,notification_type,occurrence_key HAVING COUNT(*)>1;
-- SELECT occurrence_key, COUNT(*) FROM customer_order_notifications
-- GROUP BY occurrence_key;
-- SELECT n.id,n.order_id,n.notification_type,n.occurrence_key
-- FROM customer_order_notifications n LEFT JOIN delivery_events e
-- ON n.occurrence_key=CONCAT('delivery-event-',e.id) AND n.order_id=e.order_id
-- AND n.notification_type=CASE WHEN e.event_type='DELIVERED'
--     THEN 'ORDER_DELIVERED' ELSE e.event_type END
-- LEFT JOIN orders o ON o.id=n.order_id
-- WHERE n.occurrence_key<>'legacy'
-- AND (e.id IS NULL OR e.event_type='RETURN_INSPECTED'
--      OR o.id IS NULL OR o.customer_id<>n.customer_id);
-- SELECT e.id,e.order_id,e.event_type FROM delivery_events e
-- JOIN orders o ON o.id=e.order_id LEFT JOIN customer_order_notifications n
-- ON n.occurrence_key=CONCAT('delivery-event-',e.id) AND n.order_id=e.order_id
-- AND n.customer_id=o.customer_id AND n.notification_type=CASE
--     WHEN e.event_type='DELIVERED' THEN 'ORDER_DELIVERED' ELSE e.event_type END
-- WHERE e.event_type<>'RETURN_INSPECTED' AND n.id IS NULL;
-- Compare original legacy notification ids/messages/created_at/read_at to the
-- backup/baseline. Empty anomaly queries alone do not prove deployment safety.
-- Do not rewrite messages or read states to make the verification look clean.
--
-- 6. STOP AND STAGE-SPECIFIC RECOVERY DECISION TREE
-- At any error/mismatch: STOP subsequent stages, keep ALL writers stopped,
-- preserve error/binlog/DDL output, take a forensic backup of the CURRENT state,
-- inspect the queries above and active DDL with the DBA. Do not auto-retry the
-- whole file, use IF NOT EXISTS to mask drift, or truncate/reset evidence.
-- Determine whether ANY post-start events, notifications, shipment changes,
-- sales or payments committed; compare backups and logs, not only row counts.
--
-- S0 intact: no 016 effects; deployment remains blocked until prerequisites are
-- ready. Returning to the prior app/schema needs no destructive schema undo.
-- S1 absent/intact baseline: diagnose failed ALTER. Future approved retry may
-- execute S1 only after verifying baseline and resolving cause.
-- S1 exact, S2 absent: do not replay S1. Future approved forward completion can
-- start S2; otherwise keep maintenance (old Delivered must not be re-enabled).
-- S2 exact, S3 absent: preserve delivery_events even if empty; inspect for writes.
-- Future approved forward completion starts S3, never DROP delivery_events.
-- S3 exact, S4 incomplete: inventory each trigger separately. Install only the
-- missing exact S4a-d statements in a reviewed reconciliation plan. A same-name
-- but different body is drift requiring explicit DBA reconciliation, not skip.
-- S4 exact, S5 absent: preserve tables/triggers. Verify original notification
-- column/enum/key baseline; future approved completion executes S5 only.
-- S5 exact: do not rerun anything. Verify full schema and preserved data before
-- separately approved UI rollout/smoke/concurrency checks and reopening writers.
-- Any mixed/unexpected schema: stop; a DBA must write a minimal stage-specific
-- reconciliation plan from actual SHOW CREATE definitions and preserved data.
-- Validate constraints/duplicates first without deleting conflicting evidence.
-- Even a structurally complete schema is not release approval in this task.
--
-- RESTORE versus RECONCILE:
-- If writers were provably stopped throughout and no post-backup writes exist,
-- a verified pre-deployment backup restore may be approved as the simplest
-- return to baseline. A restore is itself destructive and requires separate
-- operational approval/verification, not an automatic exception handler.
-- If any writes occurred or absence cannot be proven, blindly restoring the
-- backup loses events, notifications and business changes. Preserve current
-- data and prefer reviewed forward schema reconciliation. If corruption makes
-- restore unavoidable, require a forensic copy plus a tested PITR/data recovery
-- plan preserving legitimate post-backup writes before authorizing restore.
-- Do not drop delivery tables/columns, downgrade enums or the notification key:
-- this can destroy history, states or repeated-cycle notification identity.
-- An automatic rollback of this multi-statement migration is UNSAFE.
--
-- 7. LEGACY ITEM AND UNSUPPORTED RETURN RESOLUTION POLICY / H2, H4
-- order_items.quantity is unsigned but not restricted to one; product_id is
-- nullable with ON DELETE SET NULL. No persisted per-unit identity mapping
-- exists. Current inventory is one products row per unique physical garment.
-- Delivery accepts quantity=1, distinct positive product_ids and existing
-- locked products only. Multi-quantity, null/missing product, duplicate identity
-- returns legacy_item_review before any shipment/event/payment/inventory write.
-- Snapshot name/size/color/price is display/review evidence only, never proof of
-- custody; do not split rows, clone products or infer identities from snapshots.
-- These cases remain unavailable until a separately approved admin resolution
-- workflow proves item identity/custody and preserves audit/financial history.
-- No UI, refund, return-to-stock, automatic release or bypass is provided here.
-- For SHIPPED courier returns: COD must remain PENDING without collection
-- attribution; GCash must be PAID with valid submission/reference and existing
-- verifier attribution and no rejection evidence. FAILED/REFUNDED or pending
-- GCash have no supported shipped-return financial resolution and fail closed.
-- proof_path is optional and is not required as physical-return evidence.
-- A retired but still SOLD identifiable item may be recorded in shop custody;
-- inspection/retry remains blocked. Sale, customer receipt, pickup state,
-- incompatible cancellation, other active hold or missing identity blocks new
-- custody records. Retry requires every current item's same-cycle RETURNED and
-- RETRY_APPROVED inspection evidence; MANUAL_REVIEW cannot authorize retry.
-- Previous cycles/events remain append-only; only a new cycle resets attempts.
--
-- 8. GENUINE CONCURRENCY VERIFICATION BLOCKER / H3, M1
-- delivery_action uses the supplied PDO, owns its transaction and commits it.
-- Every helper/notification query uses that same connection. Two independently
-- built temporary namespaces cannot lock or modify the same rows; omitting a
-- shadow on the second connection risks persistent-table access. An outer
-- rollback cannot contain an action that owns/commits its own transaction.
-- database/tests/delivery_concurrency_test.php --probe-temporary-isolation
-- independently builds/proves all 13 shadows on each of two MySQL 8.4 sessions,
-- calls the real helper while the other session holds identically numbered
-- local rows, and demonstrates separate event/hold visibility. Exit 3 is
-- BLOCKED/SKIP, NOT a concurrent idempotency pass. Sequential workflow tests
-- exercise replay, stale revisions and other holds without claiming contention.
--
-- Future strategy (separate explicit approval; DO NOT provision in this task):
-- 1. Provision a network-isolated disposable MySQL 8.4 SERVER/INSTANCE with no
--    production/test-business credentials, using ordinary shared InnoDB tables.
--    Approve schema/migration/fixture writes and entire-environment teardown.
-- 2. Load reviewed schema through 016 and commit ONE shared courier fixture with
--    valid attribution/verification. Use two independent native-prepare PDOs.
-- 3. Synchronize workers on the same initial cycle/revision/stage using a bounded
--    IPC barrier. A coordinator holds the shared order lock while both workers
--    invoke delivery_action. Observe BOTH waits on that row (performance_schema
--    data_lock_waits/thread identities), then release the lock. Bound all waits
--    and kill/stop test workers on timeout; a process launch alone is not overlap.
-- 4. Same admin/order/action_key/payload: one fresh successful OUT_FOR_DELIVERY
--    and one ok/idempotent=true result with identical event_id/state/cycle/revision;
--    assert exactly ONE event and ONE occurrence notification, revision increment 1.
-- 5. Reset via a NEW disposable fixture, not deletion of append-only history.
--    Same stale tokens/different keys: one valid outing commits, other returns
--    conflict; one event/notification and no second counter/state change.
-- 6. For inventory race, start a READ COMMITTED reservation/checkout writer that
--    locks the SAME shared product before inserting a competing order membership.
--    Hold its product lock while delivery reaches its product-lock wait; verify
--    overlap, commit that membership, then let delivery continue. The other-hold
--    guard must return manual_review with no delivery/event/payment/inventory
--    mutation. Also exercise the inverse lock acquisition ordering with valid
--    writer eligibility; never invent an AVAILABLE product to bypass a SOLD hold.
-- 7. Record results, observed shared-row waits, final cardinalities and full
--    schema/trigger checks. Dispose of the approved environment as a unit.
-- Temporary probes cannot substitute for these tests. Real races and persistent
-- migration/FK/trigger/deployment safety remain UNVERIFIED in Phase A remediation.
