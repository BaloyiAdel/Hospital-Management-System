<?php
session_start();
require_once "db.php";

// Only logged-in pharmacists may access this page.
if (!isset($_SESSION["staff_id"])) {
    header("Location: index.php");
    exit;
}

$role = strtolower($_SESSION["staff_role"] ?? $_SESSION["role"] ?? "");

if ($role !== "pharmacist") {
    http_response_code(403);
    exit("Access denied. Only pharmacists may manage medicines.");
}

$staffId = (int) $_SESSION["staff_id"];
$staffName = $_SESSION["staff_name"] ?? "Pharmacist";

if (empty($_SESSION["medicine_csrf"])) {
    $_SESSION["medicine_csrf"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["medicine_csrf"];
$message = "";
$messageType = "success";

function medEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function medRedirect() {
    header("Location: medicines.php");
    exit;
}

// Handle form submissions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals(
        $_SESSION["medicine_csrf"] ?? "",
        $_POST["csrf_token"] ?? ""
    )) {
        http_response_code(403);
        exit("Invalid request. Please refresh the page and try again.");
    }

    $action = $_POST["action"] ?? "";

    try {

        // ADD MEDICINE
        if ($action === "add") {

            $name = trim($_POST["medicine_name"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $stock = filter_var($_POST["quantity_in_stock"] ?? null, FILTER_VALIDATE_INT);
            $reorder = filter_var($_POST["reorder_level"] ?? null, FILTER_VALIDATE_INT);
            $expiry = trim($_POST["expiry_date"] ?? "");

            if (
                $name === "" ||
                $stock === false || $stock < 0 ||
                $reorder === false || $reorder < 0
            ) {
                throw new Exception("Please enter valid medicine information.");
            }

            if ($expiry !== "") {
                $date = DateTime::createFromFormat("!Y-m-d", $expiry);

                if (!$date || $date->format("Y-m-d") !== $expiry) {
                    throw new Exception("Please enter a valid expiry date.");
                }
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO medicines
                (medicine_name, description, quantity_in_stock, reorder_level, expiry_date)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $description,
                $stock,
                $reorder,
                $expiry !== "" ? $expiry : null
            ]);

            $medicineId = (int)$pdo->lastInsertId();

            if ($stock > 0) {
                $log = $pdo->prepare("
                    INSERT INTO medicine_stock_logs
                    (medicine_id, staff_id, movement_type, quantity_change, notes)
                    VALUES (?, ?, 'received', ?, ?)
                ");

                $log->execute([
                    $medicineId,
                    $staffId,
                    $stock,
                    "Opening stock"
                ]);
            }

            $pdo->commit();

            $_SESSION["medicine_message"] = "Medicine added successfully.";
            medRedirect();
        }

        // EDIT MEDICINE DETAILS
        if ($action === "edit") {

            $medicineId = (int)($_POST["medicine_id"] ?? 0);
            $name = trim($_POST["medicine_name"] ?? "");
            $description = trim($_POST["description"] ?? "");
            $reorder = filter_var($_POST["reorder_level"] ?? null, FILTER_VALIDATE_INT);
            $expiry = trim($_POST["expiry_date"] ?? "");

            if (
                $medicineId <= 0 ||
                $name === "" ||
                $reorder === false ||
                $reorder < 0
            ) {
                throw new Exception("Please enter valid medicine information.");
            }

            if ($expiry !== "") {
                $date = DateTime::createFromFormat("!Y-m-d", $expiry);

                if (!$date || $date->format("Y-m-d") !== $expiry) {
                    throw new Exception("Please enter a valid expiry date.");
                }
            }

            $stmt = $pdo->prepare("
                UPDATE medicines
                SET medicine_name = ?,
                    description = ?,
                    reorder_level = ?,
                    expiry_date = ?
                WHERE medicine_id = ?
            ");

            $stmt->execute([
                $name,
                $description,
                $reorder,
                $expiry !== "" ? $expiry : null,
                $medicineId
            ]);

            $_SESSION["medicine_message"] = "Medicine details updated.";
            medRedirect();
        }

        // RECEIVE OR ADJUST STOCK
        if ($action === "stock") {

            $medicineId = (int)($_POST["medicine_id"] ?? 0);
            $movement = $_POST["movement_type"] ?? "";
            $quantity = filter_var($_POST["quantity"] ?? null, FILTER_VALIDATE_INT);
            $notes = trim($_POST["notes"] ?? "");

            if ($medicineId <= 0) {
                throw new Exception("Please select a medicine.");
            }

            if (!in_array($movement, ["received", "adjustment"], true)) {
                throw new Exception("Invalid stock movement.");
            }

            if ($movement === "received" && ($quantity === false || $quantity <= 0)) {
                throw new Exception("Enter a valid stock quantity.");
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT quantity_in_stock
                FROM medicines
                WHERE medicine_id = ?
                FOR UPDATE
            ");

            $stmt->execute([$medicineId]);
            $medicine = $stmt->fetch();

            if (!$medicine) {
                throw new Exception("Medicine not found.");
            }

            $currentStock = (int)$medicine["quantity_in_stock"];

            if ($movement === "received") {
                $change = $quantity;
            } else {
                $adjustment = filter_var(
                    $_POST["adjustment"] ?? null,
                    FILTER_VALIDATE_INT
                );

                if ($adjustment === false || $adjustment === 0) {
                    throw new Exception("Enter a non-zero adjustment.");
                }

                $change = $adjustment;
            }

            $newStock = $currentStock + $change;

            if ($newStock < 0) {
                throw new Exception("Stock cannot be reduced below zero.");
            }

            $update = $pdo->prepare("
                UPDATE medicines
                SET quantity_in_stock = ?
                WHERE medicine_id = ?
            ");

            $update->execute([$newStock, $medicineId]);

            $log = $pdo->prepare("
                INSERT INTO medicine_stock_logs
                (medicine_id, staff_id, movement_type, quantity_change, notes)
                VALUES (?, ?, ?, ?, ?)
            ");

            $log->execute([
                $medicineId,
                $staffId,
                $movement,
                $change,
                $notes
            ]);

            $pdo->commit();

            $_SESSION["medicine_message"] = "Stock updated successfully.";
            medRedirect();
        }

        throw new Exception("Invalid form action.");

    } catch (Exception $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $message = $e->getMessage();
        $messageType = "error";
    }
}

// Display saved messages.
if (isset($_SESSION["medicine_message"])) {
    $message = $_SESSION["medicine_message"];
    unset($_SESSION["medicine_message"]);
}

// Search medicines.
$search = trim($_GET["search"] ?? "");

$sql = "
    SELECT *
    FROM medicines
    WHERE medicine_name LIKE ?
    ORDER BY medicine_name ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(["%" . $search . "%"]);
$medicines = $stmt->fetchAll();

// Dashboard statistics.
$statsQuery = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE
            WHEN quantity_in_stock <= reorder_level
            THEN 1 ELSE 0
        END) AS low_stock,
        SUM(CASE
            WHEN expiry_date IS NOT NULL AND expiry_date < CURDATE()
            THEN 1 ELSE 0
        END) AS expired,
        SUM(CASE
            WHEN expiry_date BETWEEN CURDATE()
            AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            THEN 1 ELSE 0
        END) AS expiring
    FROM medicines
");

$stats = $statsQuery->fetch();

// Recent stock activity.
$activityQuery = $pdo->query("
    SELECT
        l.*,
        m.medicine_name,
        s.full_name
    FROM medicine_stock_logs l
    LEFT JOIN medicines m ON m.medicine_id = l.medicine_id
    LEFT JOIN staff s ON s.staff_id = l.staff_id
    ORDER BY l.created_at DESC
    LIMIT 10
");

$activities = $activityQuery->fetchAll();

function medicineStatus($medicine) {

    if (
        !empty($medicine["expiry_date"]) &&
        $medicine["expiry_date"] < date("Y-m-d")
    ) {
        return ["Expired", "expired"];
    }

    if (
        (int)$medicine["quantity_in_stock"] <=
        (int)$medicine["reorder_level"]
    ) {
        return ["Low Stock", "low"];
    }

    return ["Available", "available"];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Medicine Management | CarePoint</title>

<link rel="stylesheet" href="sidebar.css">

<style>
* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: Arial, sans-serif;
    background: #EAF4FC;
    color: #163B65;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 28px;
}

.header h1 {
    font-size: 27px;
    margin-bottom: 7px;
}

.header p {
    color: #607D98;
    font-size: 14px;
}

.user {
    background: white;
    padding: 12px 18px;
    border-radius: 10px;
    font-size: 14px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(22,59,101,0.05);
}

.stat-card p {
    color: #70869C;
    font-size: 13px;
    margin-bottom: 12px;
}

.stat-card h2 {
    font-size: 27px;
}

.panel {
    background: white;
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 3px 12px rgba(22,59,101,0.05);
}

.panel h2 {
    font-size: 19px;
    margin-bottom: 18px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.field label {
    display: block;
    font-size: 13px;
    font-weight: bold;
    margin-bottom: 7px;
}

.field input,
.field textarea,
.field select,
.search-input {
    width: 100%;
    padding: 12px;
    border: 1px solid #D6E4F0;
    border-radius: 7px;
    outline: none;
    font-size: 14px;
}

.field input:focus,
.field textarea:focus,
.field select:focus {
    border-color: #3979B9;
}

.field textarea {
    resize: vertical;
    min-height: 75px;
}

.full {
    grid-column: 1 / -1;
}

button,
.btn {
    background: #3979B9;
    color: white;
    border: none;
    padding: 12px 17px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
    display: inline-block;
}

button:hover,
.btn:hover {
    background: #163B65;
}

.btn-light {
    background: #EAF4FC;
    color: #163B65;
}

.btn-light:hover {
    background: #A9D3F0;
}

.message {
    padding: 13px 16px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 14px;
}

.success {
    background: #E1F5E9;
    color: #17663A;
}

.error {
    background: #FCE8E8;
    color: #A32929;
}

.search-form {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.search-input {
    max-width: 400px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th, td {
    padding: 14px 12px;
    text-align: left;
    border-bottom: 1px solid #E6EEF5;
    font-size: 13px;
}

th {
    background: #F3F8FC;
    color: #526F8A;
}

.status {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
}

.available {
    background: #E1F5E9;
    color: #17663A;
}

.low {
    background: #FFF2D6;
    color: #986500;
}

.expired {
    background: #FCE8E8;
    color: #A32929;
}

.stock-form {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    align-items: end;
}

.stock-form label {
    display: block;
    font-size: 12px;
    margin-bottom: 6px;
    font-weight: bold;
}

.stock-form input,
.stock-form select {
    width: 100%;
    padding: 10px;
    border: 1px solid #D6E4F0;
    border-radius: 7px;
}

.activity-item {
    padding: 13px 0;
    border-bottom: 1px solid #E6EEF5;
    font-size: 13px;
}

.activity-item:last-child {
    border-bottom: none;
}

@media(max-width: 1000px) {
    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .stock-form {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width: 650px) {
    .form-grid,
    .stock-form {
        grid-template-columns: 1fr;
    }

    .stats {
        grid-template-columns: 1fr 1fr;
    }

    .panel {
        padding: 17px;
    }

    .header h1 {
        font-size: 23px;
    }
}
</style>
</head>

<body>

<div class="dashboard-layout">

    <?php include "sidebar.php"; ?>

    <main class="main-content">

        <div class="header">
            <div>
                <h1>Medicine Management</h1>
                <p>Manage medicines, stock levels and expiry dates.</p>
            </div>

            <div class="user">
                Logged in as:
                <strong><?php echo medEscape($staffName); ?></strong>
            </div>
        </div>

        <?php if ($message !== ""): ?>
            <div class="message <?php echo medEscape($messageType); ?>">
                <?php echo medEscape($message); ?>
            </div>
        <?php endif; ?>

        <!-- STATISTICS -->
        <section class="stats">

            <div class="stat-card">
                <p>Total Medicines</p>
                <h2><?php echo (int)$stats["total"]; ?></h2>
            </div>

            <div class="stat-card">
                <p>Low Stock</p>
                <h2><?php echo (int)$stats["low_stock"]; ?></h2>
            </div>

            <div class="stat-card">
                <p>Expired Medicines</p>
                <h2><?php echo (int)$stats["expired"]; ?></h2>
            </div>

            <div class="stat-card">
                <p>Expiring Within 90 Days</p>
                <h2><?php echo (int)$stats["expiring"]; ?></h2>
            </div>

        </section>

        <!-- ADD MEDICINE -->
        <section class="panel">
            <h2>Add New Medicine</h2>

            <form method="POST">
                <input type="hidden" name="csrf_token"
                       value="<?php echo medEscape($csrf); ?>">
                <input type="hidden" name="action" value="add">

                <div class="form-grid">

                    <div class="field">
                        <label>Medicine Name *</label>
                        <input type="text" name="medicine_name"
                               required maxlength="150">
                    </div>

                    <div class="field">
                        <label>Opening Stock *</label>
                        <input type="number" name="quantity_in_stock"
                               min="0" required>
                    </div>

                    <div class="field">
                        <label>Reorder Level *</label>
                        <input type="number" name="reorder_level"
                               min="0" value="10" required>
                    </div>

                    <div class="field">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date">
                    </div>

                    <div class="field full">
                        <label>Description</label>
                        <textarea name="description"
                                  placeholder="Enter medicine details..."></textarea>
                    </div>

                    <div class="field full">
                        <button type="submit">Add Medicine</button>
                    </div>

                </div>
            </form>
        </section>

        <!-- STOCK MANAGEMENT -->
        <section class="panel">
            <h2>Medicine Stock Management</h2>

            <form method="POST" class="stock-form">

                <input type="hidden" name="csrf_token"
                       value="<?php echo medEscape($csrf); ?>">
                <input type="hidden" name="action" value="stock">

                <div>
                    <label>Select Medicine</label>

                    <select name="medicine_id" required>
                        <option value="">Choose medicine</option>

                        <?php foreach ($medicines as $medicine): ?>
                            <option value="<?php echo (int)$medicine["medicine_id"]; ?>">
                                <?php echo medEscape($medicine["medicine_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label>Movement Type</label>

                    <select name="movement_type" id="movementType"
                            onchange="toggleAdjustment()" required>
                        <option value="received">Receive Stock</option>
                        <option value="adjustment">Stock Adjustment</option>
                    </select>
                </div>

                <div id="quantityField">
                    <label>Quantity Received</label>
                    <input type="number" name="quantity"
                           min="1" value="1">
                </div>

                <div id="adjustmentField" style="display:none">
                    <label>Adjustment (+ or -)</label>
                    <input type="number" name="adjustment" value="1">
                </div>

                <div class="full">
                    <label>Notes</label>
                    <input type="text" name="notes"
                           placeholder="Reason for stock movement">
                </div>

                <div class="full">
                    <button type="submit">Update Stock</button>
                </div>

            </form>
        </section>

        <!-- MEDICINE CATALOGUE -->
        <section class="panel">
            <h2>Medicine Catalogue</h2>

            <form method="GET" class="search-form">
                <input class="search-input" type="text" name="search"
                       placeholder="Search medicine..."
                       value="<?php echo medEscape($search); ?>">

                <button type="submit">Search</button>

                <a href="medicines.php" class="btn btn-light">Clear</a>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Description</th>
                            <th>Available Stock</th>
                            <th>Reorder Level</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th>Edit</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (empty($medicines)): ?>

                        <tr>
                            <td colspan="7">No medicines found.</td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($medicines as $medicine): ?>
                            <?php $status = medicineStatus($medicine); ?>

                            <tr>
                                <td>
                                    <strong>
                                        <?php echo medEscape($medicine["medicine_name"]); ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php echo medEscape($medicine["description"]); ?>
                                </td>

                                <td>
                                    <?php echo (int)$medicine["quantity_in_stock"]; ?>
                                </td>

                                <td>
                                    <?php echo (int)$medicine["reorder_level"]; ?>
                                </td>

                                <td>
                                    <?php echo medEscape($medicine["expiry_date"] ?: "Not set"); ?>
                                </td>

                                <td>
                                    <span class="status <?php echo medEscape($status[1]); ?>">
                                        <?php echo medEscape($status[0]); ?>
                                    </span>
                                </td>

                                <td>
                                    <button
                                        type="button"
                                        class="btn-light"
                                        onclick='editMedicine(
                                            <?php echo (int)$medicine["medicine_id"]; ?>,
                                            <?php echo json_encode($medicine["medicine_name"], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
                                            <?php echo json_encode($medicine["description"], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>,
                                            <?php echo (int)$medicine["reorder_level"]; ?>,
                                            <?php echo json_encode($medicine["expiry_date"], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>
                                        )'>
                                        Edit
                                    </button>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>
        </section>

        <!-- EDIT MEDICINE -->
        <section class="panel">
            <h2>Edit Medicine Details</h2>

            <form method="POST" id="editForm">

                <input type="hidden" name="csrf_token"
                       value="<?php echo medEscape($csrf); ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="medicine_id" id="edit_id">

                <div class="form-grid">

                    <div class="field">
                        <label>Medicine Name</label>
                        <input type="text" name="medicine_name"
                               id="edit_name" required>
                    </div>

                    <div class="field">
                        <label>Reorder Level</label>
                        <input type="number" name="reorder_level"
                               id="edit_reorder" min="0" required>
                    </div>

                    <div class="field">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date"
                               id="edit_expiry">
                    </div>

                    <div class="field">
                        <label>Description</label>
                        <input type="text" name="description"
                               id="edit_description">
                    </div>

                    <div class="field full">
                        <button type="submit">Save Changes</button>
                    </div>

                </div>
            </form>
        </section>

        <!-- RECENT STOCK ACTIVITY -->
        <section class="panel">
            <h2>Recent Stock Activity</h2>

            <?php if (empty($activities)): ?>

                <p>No stock activity recorded yet.</p>

            <?php else: ?>

                <?php foreach ($activities as $activity): ?>

                    <div class="activity-item">

                        <strong>
                            <?php echo medEscape($activity["medicine_name"] ?? "Unknown medicine"); ?>
                        </strong>

                        — <?php echo medEscape(ucfirst($activity["movement_type"])); ?>

                        (<?php echo (int)$activity["quantity_change"] > 0 ? "+" : ""; ?><?php echo (int)$activity["quantity_change"]; ?>)

                        <br>

                        <small>
                            <?php echo medEscape($activity["full_name"] ?? "Unknown staff"); ?>
                            |
                            <?php echo medEscape($activity["created_at"]); ?>
                        </small>

                        <?php if (!empty($activity["notes"])): ?>
                            <br>
                            <small>
                                <?php echo medEscape($activity["notes"]); ?>
                            </small>
                        <?php endif; ?>

                    </div>

                <?php endforeach; ?>

            <?php endif; ?>
        </section>

    </main>
</div>

<script>
function toggleAdjustment() {

    const type = document.getElementById("movementType").value;

    document.getElementById("quantityField").style.display =
        type === "received" ? "block" : "none";

    document.getElementById("adjustmentField").style.display =
        type === "adjustment" ? "block" : "none";
}

function editMedicine(id, name, description, reorder, expiry) {

    document.getElementById("edit_id").value = id;
    document.getElementById("edit_name").value = name;
    document.getElementById("edit_description").value = description;
    document.getElementById("edit_reorder").value = reorder;

    document.getElementById("edit_expiry").value =
        expiry === null ? "" : expiry;

    document.getElementById("editForm").scrollIntoView({
        behavior: "smooth"
    });
}
</script>

</body>
</html>