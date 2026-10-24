<?php

/*
 * Hopia Fits - Legacy bundle assignment tests (DEVELOPER-RUN).
 *
 * Exercises the REAL legacy assignment logic (includes/bundle_assignment.php)
 * against the dedicated local test database clothing_shop_bundle_test ONLY.
 * It refuses to run on the web (CLI only), reuses the .env host/user/password
 * but FORCES the database name to clothing_shop_bundle_test and hard-aborts
 * if the connected database is anything else, and creates self-contained
 * fixtures that are removed again in a finally block. It never touches
 * live/original products or any other database. Run:
 *
 *   php database/tests/legacy_assignment_test.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This test runner is CLI-only.\n");
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../includes/bundle_assignment.php';

const TEST_DB_NAME = 'clothing_shop_bundle_test';

date_default_timezone_set('Asia/Manila');

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../');
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASS'] ?? '';

$dsn = 'mysql:host=' . $host . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
} catch (Throwable $e) {
    fwrite(STDERR, "Could not connect to " . TEST_DB_NAME . ".\n");
    fwrite(STDERR, "Create the test database and apply migrations 001-009 first.\n");
    fwrite(STDERR, "Details: " . $e->getMessage() . "\n");
    exit(2);
}

/* Hard guard: never run against anything but the test database. */
$connectedDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connectedDb !== TEST_DB_NAME) {
    fwrite(STDERR, "Refusing to run: connected to '{$connectedDb}', expected '" . TEST_DB_NAME . "'.\n");
    exit(2);
}

/* Require the bundle/barcode structures from migration 005. */
try {
    $pdo->query('SELECT 1 FROM purchase_bundles LIMIT 1');
    $pdo->query('SELECT bundle_id, bundle_item_number, barcode_value FROM products LIMIT 1');
} catch (Throwable $e) {
    fwrite(STDERR, "Bundle columns/table missing. Apply migration 005 to " . TEST_DB_NAME . " first.\n");
    exit(2);
}

$tests = 0;
$failures = 0;
function assertTrue(string $label, bool $cond): void
{
    global $tests, $failures;
    $tests++;
    if ($cond) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label}\n";
    }
}
function assertEq(string $label, $actual, $expected): void
{
    global $tests, $failures;
    $tests++;
    if ($actual === $expected) {
        echo "  ok:   {$label}\n";
    } else {
        $failures++;
        echo "  FAIL: {$label} (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")\n";
    }
}

/* Fixture registries for finally-block cleanup. */
$createdBundleIds = [];
$createdProductIds = [];

/* Insert a legacy (fully unassigned) product fixture. */
function makeLegacyProduct(PDO $pdo, string $name, string $category, string $status = 'AVAILABLE'): int
{
    global $createdProductIds;

    $stmt = $pdo->prepare(
        'INSERT INTO products
            (name, category, gender, description, condition_label, defects,
             price, size, color, status)
         VALUES
            (:name, :category, :gender, :description, :condition_label, :defects,
             :price, :size, :color, :status)'
    );
    $stmt->execute([
        ':name' => $name,
        ':category' => $category,
        ':gender' => 'MEN',
        ':description' => 'Legacy unassigned item',
        ':condition_label' => 'Good',
        ':defects' => 'Minor fading on sleeve',
        ':price' => 150.00,
        ':size' => 'Large',
        ':color' => 'Black',
        ':status' => $status,
    ]);

    $id = (int) $pdo->lastInsertId();
    $createdProductIds[] = $id;
    return $id;
}

/* Insert a purchase bundle fixture. */
function makeTestBundle(PDO $pdo, string $category, float $capital, int $qty): int
{
    global $createdBundleIds;

    $stmt = $pdo->prepare(
        'INSERT INTO purchase_bundles (category, capital_total, original_quantity, purchase_date)
         VALUES (:category, :capital, :qty, NULL)'
    );
    $stmt->execute([
        ':category' => $category,
        ':capital' => $capital,
        ':qty' => $qty,
    ]);

    $id = (int) $pdo->lastInsertId();
    $createdBundleIds[] = $id;
    return $id;
}

echo "== Legacy assignment tests (DB: " . TEST_DB_NAME . ") ==\n";

try {

    $fetchProduct = function (PDO $pdo, int $id): array {
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new RuntimeException("fixture product {$id} vanished");
        }
        return $row;
    };

    /* Bundle A: SHIRTS, capacity 5, happy path + sequence tests. */
    $bundleA = makeTestBundle($pdo, 'SHIRTS', 500.00, 5);
    /* Bundle B: SHIRTS, capacity 1, capacity test. */
    $bundleB = makeTestBundle($pdo, 'SHIRTS', 250.00, 1);
    /* Bundle C: PANTS, category-mismatch test. */
    $bundleC = makeTestBundle($pdo, 'PANTS', 300.00, 5);

    $p1 = makeLegacyProduct($pdo, 'Legacy Shirt A', 'SHIRTS');
    $p2 = makeLegacyProduct($pdo, 'Legacy Shirt B', 'SHIRTS');
    $p3 = makeLegacyProduct($pdo, 'Legacy Shirt C', 'SHIRTS');
    $pPants = makeLegacyProduct($pdo, 'Legacy Pants X', 'PANTS');
    $pSold = makeLegacyProduct($pdo, 'Legacy Sold Shirt', 'SHIRTS', 'SOLD');

    /* ---- 1. Valid assignment ---- */
    echo "\n[1] valid assignment\n";

    $beforeP1 = $fetchProduct($pdo, $p1);
    $result = assignLegacyProductToBundle($pdo, $p1, $bundleA);

    assertEq('returns allocated item number 1', $result['bundle_item_number'], 1);
    assertEq('returns correct bundle id', $result['bundle_id'], $bundleA);
    assertEq(
        'barcode follows HOPEBUNDLE-<id>-01 format',
        $result['barcode_value'],
        'HOPEBUNDLE-' . str_pad((string) $bundleA, 6, '0', STR_PAD_LEFT) . '-01'
    );

    $afterP1 = $fetchProduct($pdo, $p1);
    assertEq('bundle_id written', (int) $afterP1['bundle_id'], $bundleA);
    assertEq('bundle_item_number written', (int) $afterP1['bundle_item_number'], 1);
    assertEq('barcode_value written', $afterP1['barcode_value'], $result['barcode_value']);
    assertEq('status preserved (AVAILABLE)', $afterP1['status'], 'AVAILABLE');
    assertEq('retired_at preserved (still NULL)', $afterP1['retired_at'], null);

    /* ---- 2. Already-assigned product rejected ---- */
    echo "\n[2] already-assigned product rejected\n";

    $rejected = false;
    try {
        assignLegacyProductToBundle($pdo, $p1, $bundleA);
    } catch (RuntimeException $e) {
        $rejected = true;
    }
    assertTrue('second assignment of the same product is rejected', $rejected);

    $afterP1b = $fetchProduct($pdo, $p1);
    assertEq('item number unchanged after rejected duplicate', (int) $afterP1b['bundle_item_number'], 1);
    assertEq('barcode unchanged after rejected duplicate', $afterP1b['barcode_value'], $result['barcode_value']);

    /* ---- 3. Category mismatch rejected ---- */
    echo "\n[3] category mismatch rejected\n";

    $rejected = false;
    try {
        assignLegacyProductToBundle($pdo, $pPants, $bundleB);
    } catch (RuntimeException $e) {
        $rejected = (strpos($e->getMessage(), 'Category mismatch') !== false);
    }
    assertTrue('PANTS product rejected for SHIRTS bundle', $rejected);

    $checkPants = $fetchProduct($pdo, $pPants);
    assertEq('mismatched product stays fully unassigned', $checkPants['bundle_id'], null);

    /* ---- 4. Bundle at capacity rejected ---- */
    echo "\n[4] bundle capacity enforced\n";

    $filler = makeLegacyProduct($pdo, 'Capacity Filler Shirt', 'SHIRTS');
    assignLegacyProductToBundle($pdo, $filler, $bundleB);

    $rejected = false;
    try {
        assignLegacyProductToBundle($pdo, $p2, $bundleB);
    } catch (RuntimeException $e) {
        $rejected = (strpos($e->getMessage(), 'full capacity') !== false);
    }
    assertTrue('assignment to full bundle rejected', $rejected);

    $checkP2 = $fetchProduct($pdo, $p2);
    assertEq('product stays unassigned after full-bundle attempt', $checkP2['bundle_id'], null);

    /* ---- 5. Nonexistent bundle rejected ---- */
    echo "\n[5] nonexistent bundle rejected\n";

    $rejected = false;
    try {
        assignLegacyProductToBundle($pdo, $p2, 99999999);
    } catch (RuntimeException $e) {
        $rejected = true;
    }
    assertTrue('assignment to nonexistent bundle rejected', $rejected);

    $checkP2 = $fetchProduct($pdo, $p2);
    assertEq('product stays unassigned after nonexistent-bundle attempt', $checkP2['bundle_id'], null);

    /* ---- 6. Sequence + duplicate barcode/sequence protection ---- */
    echo "\n[6] duplicate/sequence protection\n";

    /* Assign two more SHIRTS to bundle A: item numbers must be 2 then 3. */
    $r2 = assignLegacyProductToBundle($pdo, $p2, $bundleA);
    $r3 = assignLegacyProductToBundle($pdo, $p3, $bundleA);

    assertEq('second assignment gets item number 2', (int) $r2['bundle_item_number'], 2);
    assertEq('third assignment gets item number 3', (int) $r3['bundle_item_number'], 3);
    assertEq(
        'second barcode',
        $r2['barcode_value'],
        'HOPEBUNDLE-' . str_pad((string) $bundleA, 6, '0', STR_PAD_LEFT) . '-02'
    );
    assertTrue(
        'all issued barcodes are distinct',
        $result['barcode_value'] !== $r2['barcode_value']
        && $r2['barcode_value'] !== $r3['barcode_value']
    );

    /* Duplicate re-assignment must fail, not corrupt numbering. */
    $rejected = false;
    try {
        assignLegacyProductToBundle($pdo, $p1, $bundleA);
    } catch (RuntimeException $e) {
        $rejected = true;
    }
    assertTrue('duplicate assignment of a barcoded item still rejected', $rejected);

    /* Direct duplicate-barcode insert must violate the unique constraint. */
    $dupRejected = false;
    try {
        $dupStmt = $pdo->prepare(
            'INSERT INTO products
                (name, category, condition_label, price,
                 bundle_id, bundle_item_number, barcode_value)
             VALUES (:n, :c, :cond, 1.00, :b, :i, :bc)'
        );
        $dupStmt->execute([
            ':n' => 'Duplicate barcode probe',
            ':c' => 'SHIRTS',
            ':cond' => 'Good',
            ':b' => $bundleA,
            ':i' => 1,
            ':bc' => $result['barcode_value'],
        ]);
        /* If the insert somehow succeeded, remove the probe row. */
        $pdo->prepare('DELETE FROM products WHERE id = :id')
            ->execute([':id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        $dupRejected = true;
    }
    assertTrue('duplicate barcode insert rejected by unique constraint', $dupRejected);

    /* Duplicate (bundle, item number) insert must also be rejected. */
    $dupSeqRejected = false;
    try {
        $dupStmt = $pdo->prepare(
            'INSERT INTO products
                (name, category, condition_label, price,
                 bundle_id, bundle_item_number, barcode_value)
             VALUES (:n, :c, :cond, 1.00, :b, :i, :bc)'
        );
        $dupStmt->execute([
            ':n' => 'Duplicate sequence probe',
            ':c' => 'SHIRTS',
            ':cond' => 'Good',
            ':b' => $bundleA,
            ':i' => 1,
            ':bc' => 'HOPEBUNDLE-' . str_pad((string) $bundleA, 6, '0', STR_PAD_LEFT) . '-99',
        ]);
        $pdo->prepare('DELETE FROM products WHERE id = :id')
            ->execute([':id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        $dupSeqRejected = true;
    }
    assertTrue('duplicate (bundle, item number) insert rejected by unique constraint', $dupSeqRejected);

    /* ---- 7. Field preservation, including SOLD status ---- */
    echo "\n[7] field preservation\n";

    $soldBefore = $fetchProduct($pdo, $pSold);
    $soldResult = assignLegacyProductToBundle($pdo, $pSold, $bundleA);
    $soldAfter = $fetchProduct($pdo, $pSold);

    assertEq('SOLD product remains SOLD after assignment', $soldAfter['status'], 'SOLD');
    assertEq('SOLD product name preserved', $soldAfter['name'], 'Legacy Sold Shirt');
    assertEq('SOLD product price preserved', (float) $soldAfter['price'], (float) $soldBefore['price']);
    assertEq('SOLD product defects preserved', $soldAfter['defects'], $soldBefore['defects']);
    assertEq('SOLD product retired_at preserved', $soldAfter['retired_at'], null);
    assertEq('SOLD product created_at preserved', $soldAfter['created_at'], $soldBefore['created_at']);

    /* Full row comparison for p1: everything except the three
     * assignment columns (and updated_at, which is a maintenance
     * timestamp) must be identical. */
    $afterP1c = $fetchProduct($pdo, $p1);
    $changedColumns = [];
    foreach ($beforeP1 as $column => $value) {
        if (in_array($column, ['bundle_id', 'bundle_item_number', 'barcode_value', 'updated_at'], true)) {
            continue;
        }
        if ($afterP1c[$column] !== $value) {
            $changedColumns[] = $column;
        }
    }
    assertEq('no other product column changed for p1', $changedColumns, []);

    /* No sale/order/verification rows created by assignment itself:
     * assign one more product and confirm the counts did not move. */
    $salesBefore = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    $ordersBefore = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $verifsBefore = (int) $pdo->query('SELECT COUNT(*) FROM order_item_verifications')->fetchColumn();

    $p4 = makeLegacyProduct($pdo, 'Legacy Shirt D', 'SHIRTS');
    assignLegacyProductToBundle($pdo, $p4, $bundleA);

    $salesAfter = (int) $pdo->query('SELECT COUNT(*) FROM sales_transactions')->fetchColumn();
    $ordersAfter = (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    $verifsAfter = (int) $pdo->query('SELECT COUNT(*) FROM order_item_verifications')->fetchColumn();

    assertEq('no sales_transactions created by assignment', $salesAfter, $salesBefore);
    assertEq('no orders created by assignment', $ordersAfter, $ordersBefore);
    assertEq('no order_item_verifications created by assignment', $verifsAfter, $verifsBefore);

} catch (Throwable $e) {
    $failures++;
    echo "  FAIL: unexpected exception: " . $e->getMessage() . "\n";

} finally {

    /*
     * Remove the fixtures created by this run, in FK-safe order
     * (products first, then bundles). Only clearly-labelled test rows
     * created above are deleted; pre-existing test-database records are
     * never reset or removed.
     */
    echo "\n[cleanup]\n";

    try {
        if (!empty($createdProductIds)) {
            $placeholders = implode(',', array_fill(0, count($createdProductIds), '?'));
            $stmt = $pdo->prepare("DELETE FROM products WHERE id IN ($placeholders)");
            $stmt->execute($createdProductIds);
            echo "  removed " . $stmt->rowCount() . " test product(s)\n";
        }

        if (!empty($createdBundleIds)) {
            $stmt = $pdo->prepare(
                'DELETE FROM purchase_bundles WHERE id IN (' .
                implode(',', array_fill(0, count($createdBundleIds), '?')) . ')'
            );
            $stmt->execute($createdBundleIds);
            echo "  removed " . $stmt->rowCount() . " test bundle(s)\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Cleanup warning: " . $e->getMessage() . "\n");
    }
}

echo "\n== {$tests} assertions, {$failures} failure(s) ==\n";
exit($failures > 0 ? 1 : 0);

