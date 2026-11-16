<?php
session_start();
date_default_timezone_set('Africa/Johannesburg');
require_once "db.php";

// Pharmacist-only page.
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    header("Location: index.php");
    exit();
}

$role = strtolower($_SESSION["staff_role"] ?? "");
if ($role !== "pharmacist") {
    http_response_code(403);
    exit("Access denied. This page is only available to pharmacists.");
}

$pharmacistId = (int)($_SESSION["staff_id"] ?? 0);
$staffName = $_SESSION["staff_name"] ?? "Pharmacist";

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION["csrf_token"];
$errors = [];
$success = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "dispense") {
    if (!hash_equals($csrf, $_POST["csrf_token"] ?? "")) {
        $errors[] = "Your session security token is invalid. Refresh the page and try again.";
    }

    $prescriptionId = (int)($_POST["prescription_id"] ?? 0);

    if ($pharmacistId <= 0) {
        $errors[] = "Your pharmacist account could not be identified. Please sign in again.";
    }
    if ($prescriptionId <= 0) {
        $errors[] = "Invalid prescription selected.";
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Lock the prescription while checking and dispensing it.
            $prescriptionStmt = $pdo->prepare(
                "SELECT prescription_id, status
                 FROM prescriptions
                 WHERE prescription_id = ?
                 FOR UPDATE"
            );
            $prescriptionStmt->execute([$prescriptionId]);
            $prescription = $prescriptionStmt->fetch();

            if (!$prescription) {
                throw new RuntimeException("The prescription could not be found.");
            }

            if (strtolower($prescription["status"]) === "dispensed") {
                throw new RuntimeException("This prescription has already been dispensed.");
            }

            if (!in_array(strtolower($prescription["status"]), ["pending", "approved", "ready"], true)) {
                throw new RuntimeException("This prescription is not available for dispensing.");
            }

            $itemsStmt = $pdo->prepare(
                "SELECT pi.medicine_id, pi.quantity, m.medicine_name,
                        m.quantity_in_stock
                 FROM prescription_items pi
                 INNER JOIN medicines m ON m.medicine_id = pi.medicine_id
                 WHERE pi.prescription_id = ?
                 FOR UPDATE"
            );
            $itemsStmt->execute([$prescriptionId]);
            $items = $itemsStmt->fetchAll();

            if (!$items) {
                throw new RuntimeException("This prescription has no medicine items.");
            }

            // Verify all stock levels before changing any of them.
            foreach ($items as $item) {
                if ((int)$item["quantity"] <= 0) {
                    throw new RuntimeException("Invalid quantity for " . $item["medicine_name"] . ".");
                }
                if ((int)$item["quantity_in_stock"] < (int)$item["quantity"]) {
                    throw new RuntimeException(
                        "Not enough stock for " . $item["medicine_name"] .
                        ". Available: " . (int)$item["quantity_in_stock"] .
                        ", required: " . (int)$item["quantity"] . "."
                    );
                }
            }

            $stockUpdate = $pdo->prepare(
                "UPDATE medicines
                 SET quantity_in_stock = quantity_in_stock - ?
                 WHERE medicine_id = ?"
            );
            foreach ($items as $item) {
                $stockUpdate->execute([(int)$item["quantity"], (int)$item["medicine_id"]]);
            }

            $dispenseStmt = $pdo->prepare(
                "INSERT INTO dispensing
                    (prescription_id, pharmacist_id, dispensing_status, dispensed_at)
                 VALUES (?, ?, 'dispensed', NOW())"
            );
            $dispenseStmt->execute([$prescriptionId, $pharmacistId]);

            $statusUpdate = $pdo->prepare(
                "UPDATE prescriptions SET status = 'dispensed' WHERE prescription_id = ?"
            );
            $statusUpdate->execute([$prescriptionId]);

            $pdo->commit();

            header("Location: dispensing.php?dispensed=1");
            exit();

        } catch (RuntimeException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $ex->getMessage();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Dispensing transaction error: " . $ex->getMessage());
            $errors[] = "The medicines could not be dispensed. Please check the dispensing table columns and database structure.";
        }
    }
}

if (isset($_GET["dispensed"]) && $_GET["dispensed"] === "1") {
    $success = "Prescription dispensed successfully. Medicine stock has been updated.";
}

$incoming = [];
$history = [];
$medicineCount = 0;
$lowStockCount = 0;

try {
    $incomingStmt = $pdo->query(
        "SELECT p.prescription_id, p.status, p.prescribed_at,
                pt.patient_number, pt.first_name, pt.last_name,
                st.full_name AS doctor_name
         FROM prescriptions p
         INNER JOIN patients pt ON pt.patient_id = p.patient_id
         LEFT JOIN staff st ON st.staff_id = p.doctor_id
         WHERE LOWER(p.status) IN ('pending', 'approved', 'ready')
         ORDER BY p.prescribed_at ASC"
    );
    $incoming = $incomingStmt->fetchAll();

    $historyStmt = $pdo->prepare(
        "SELECT d.dispensing_id, d.prescription_id, d.dispensed_at,
                pt.patient_number, pt.first_name, pt.last_name
         FROM dispensing d
         INNER JOIN prescriptions p ON p.prescription_id = d.prescription_id
         INNER JOIN patients pt ON pt.patient_id = p.patient_id
         WHERE d.pharmacist_id = ? AND LOWER(d.dispensing_status) IN ('dispensed', 'collected')
         ORDER BY d.dispensed_at DESC
         LIMIT 20"
    );
    $historyStmt->execute([$pharmacistId]);
    $history = $historyStmt->fetchAll();

    $medicineCount = (int)$pdo->query("SELECT COUNT(*) FROM medicines")->fetchColumn();
    $lowStockCount = (int)$pdo->query(
        "SELECT COUNT(*) FROM medicines WHERE quantity_in_stock <= reorder_level"
    )->fetchColumn();

} catch (PDOException $ex) {
    error_log("Pharmacy page load error: " . $ex->getMessage());
    $errors[] = "Pharmacy information could not be loaded. Please check that the database tables match the CarePoint structure.";
}

$prescriptionItems = [];
if ($incoming) {
    $ids = array_map(static fn($row) => (int)$row["prescription_id"], $incoming);
    $placeholders = implode(",", array_fill(0, count($ids), "?"));

    try {
        $itemsStmt = $pdo->prepare(
            "SELECT pi.prescription_id, pi.dosage, pi.frequency, pi.duration, pi.quantity,
                    m.medicine_name, m.quantity_in_stock, m.reorder_level, m.expiry_date
             FROM prescription_items pi
             INNER JOIN medicines m ON m.medicine_id = pi.medicine_id
             WHERE pi.prescription_id IN ($placeholders)
             ORDER BY pi.prescription_id, m.medicine_name"
        );
        $itemsStmt->execute($ids);

        foreach ($itemsStmt->fetchAll() as $item) {
            $prescriptionItems[(int)$item["prescription_id"]][] = $item;
        }
    } catch (PDOException $ex) {
        error_log("Pharmacy prescription items error: " . $ex->getMessage());
        $errors[] = "Prescription medicine details could not be loaded.";
    }
}

$firstName = explode(" ", trim($staffName))[0] ?: "Pharmacist";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CarePoint | Pharmacy</title>
<style>
:root{--navy:#163B65;--blue:#3979B9;--ice:#A9D3F0;--light:#EAF4FC;--white:#fff;--ink:#20364D;--muted:#718096;--border:#DCE8F2;--surface:#F5F9FD;--green:#237A55;--red:#A83245;--amber:#946200}
*{box-sizing:border-box}body{margin:0;background:var(--surface);color:var(--ink);font-family:Arial,Helvetica,sans-serif}
.layout{display:flex;min-height:100vh}.sidebar{width:250px;min-width:250px;height:100vh;min-height:100vh;position:sticky;top:0;display:flex;flex-direction:column;padding:28px 18px 20px;background:var(--navy);color:white}
.brand{display:flex;align-items:center;gap:12px;min-height:48px;margin:0 8px 34px;color:white;text-decoration:none}.brand-icon{width:40px;height:40px;display:grid;place-items:center;border-radius:12px;background:var(--ice);color:var(--navy);font-size:27px;font-weight:bold}.brand-text{display:flex;flex-direction:column;gap:3px}.brand-text strong{font-size:19px}.brand-text small{color:#D7E9F7;font-size:11px}
.side-label{margin:0 10px 12px;color:var(--ice);font-size:10px;font-weight:bold;letter-spacing:1.4px}.nav{display:flex;flex-direction:column;gap:6px}.nav a,.logout{min-height:46px;display:flex;align-items:center;gap:12px;padding:11px 13px;border-radius:9px;color:#EAF4FC;text-decoration:none;font-size:13px;font-weight:600}.nav a:hover,.nav a.active,.logout:hover{background:var(--blue);color:white}.nav-icon{width:20px;text-align:center;font-size:17px}.side-bottom{margin-top:auto;padding-top:18px;border-top:1px solid #ffffff30}.logout{background:#ffffff1a}
.main{min-width:0;flex:1}.top{min-height:94px;padding:20px 34px;display:flex;align-items:center;justify-content:space-between;gap:15px;background:white;border-bottom:1px solid var(--border)}.eyebrow{color:var(--blue);font-size:10px;font-weight:bold;letter-spacing:1.3px}.top h1{margin:6px 0 0;color:var(--navy);font-size:25px}.profile{display:flex;align-items:center;gap:11px}.avatar{width:40px;height:40px;display:grid;place-items:center;border-radius:50%;background:var(--light);color:var(--navy);font-weight:bold}.profile strong{display:block;font-size:13px}.profile small{display:block;margin-top:4px;color:var(--muted)}
.content{max-width:1450px;margin:auto;padding:30px 34px}.intro h2{margin:0 0 7px;color:var(--navy);font-size:21px}.intro p{margin:0;color:var(--muted);font-size:13px;line-height:1.6}.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:22px}.stat{padding:17px;background:white;border:1px solid var(--border);border-radius:11px}.stat small{display:block;color:var(--muted);font-size:10px;font-weight:bold;letter-spacing:.7px;text-transform:uppercase}.stat strong{display:block;margin-top:8px;color:var(--navy);font-size:25px}
.panel{margin-top:22px;padding:23px;background:white;border:1px solid var(--border);border-radius:13px;box-shadow:0 3px 14px #163b650a}.panel h3{margin:0 0 17px;color:var(--navy);font-size:16px}.notice{margin-top:18px;padding:12px 14px;border-radius:8px;font-size:13px}.success{color:var(--green);background:#EAF7F0;border:1px solid #C8E9D7}.error{color:var(--red);background:#FFF0F1;border:1px solid #F0CDD2}.error ul{margin:7px 0 0;padding-left:20px}
.rx-card{padding:17px 0;border-top:1px solid var(--border)}.rx-card:first-of-type{padding-top:0;border-top:0}.rx-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}.rx-head h4{margin:0;color:var(--navy);font-size:14px}.rx-meta{margin-top:6px;color:var(--muted);font-size:11px;line-height:1.6}.status{display:inline-block;padding:5px 9px;border-radius:20px;background:#FFF4DF;color:var(--amber);font-size:11px;font-weight:bold;text-transform:capitalize}
.table-wrap{overflow-x:auto;margin-top:13px}table{width:100%;border-collapse:collapse;min-width:620px}th,td{padding:10px;text-align:left;border-bottom:1px solid var(--border);font-size:12px}th{color:var(--navy);background:#F5F9FD;font-size:10px;text-transform:uppercase;letter-spacing:.4px}.stock-low{color:var(--red);font-weight:bold}.stock-ok{color:var(--green);font-weight:bold}
button{border:0;border-radius:8px;padding:11px 16px;background:var(--blue);color:white;font-size:12px;font-weight:bold;cursor:pointer}button:hover{background:var(--navy)}button:disabled{background:#9BAABA;cursor:not-allowed}.dispense-form{margin-top:14px}.empty{padding:17px;border-radius:8px;background:var(--surface);color:var(--muted);font-size:12px}.footer{display:flex;justify-content:space-between;gap:12px;padding:28px 0 8px;color:var(--muted);font-size:11px}
@media(max-width:850px){.stats{grid-template-columns:1fr 1fr}}@media(max-width:760px){.layout{display:block}.sidebar{width:100%;min-width:0;height:auto;min-height:auto;position:relative;padding:18px 16px}.brand{margin:0 4px 20px}.nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.side-bottom{margin-top:14px;padding-top:12px}.top{padding:17px 20px}.content{padding:22px 18px}}@media(max-width:480px){.nav{grid-template-columns:1fr}.stats{grid-template-columns:1fr}.profile-info{display:none}.panel{padding:17px}.footer{flex-direction:column}.rx-head{flex-direction:column}}
</style>
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <a class="brand" href="dashboard.php"><span class="brand-icon">+</span><span class="brand-text"><strong>CarePoint</strong><small>Hospital Management</small></span></a>
    <div class="side-label">MAIN MENU</div>
    <nav class="nav">
        <a href="dashboard.php"><span class="nav-icon">⌂</span>Dashboard</a>
        <a href="prescriptions.php"><span class="nav-icon">▧</span>Prescriptions</a>
        <a href="medicines.php"><span class="nav-icon">▱</span>Medicines</a>
        <a class="active" href="dispensing.php"><span class="nav-icon">▣</span>Dispensing</a>
    </nav>
    <div class="side-bottom"><a class="logout" href="logout.php"><span class="nav-icon">⇥</span>Sign Out</a></div>
</aside>

<main class="main">
<header class="top">
    <div><span class="eyebrow">CAREPOINT MANAGEMENT SYSTEM</span><h1>Pharmacy & Dispensing</h1></div>
    <div class="profile"><div class="avatar"><?php echo e(strtoupper(substr($firstName,0,1))); ?></div><div class="profile-info"><strong><?php echo e($staffName); ?></strong><small>Pharmacist</small></div></div>
</header>

<div class="content">
<section class="intro">
    <h2>Pharmacy workspace</h2>
    <p>Review prescriptions submitted by doctors, verify stock and record dispensing.</p>
</section>

<?php if ($success): ?><div class="notice success"><?php echo e($success); ?></div><?php endif; ?>
<?php if ($errors): ?><div class="notice error">Please check the following:<ul><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<section class="stats">
    <article class="stat"><small>Incoming prescriptions</small><strong><?php echo count($incoming); ?></strong></article>
    <article class="stat"><small>Medicine types</small><strong><?php echo number_format($medicineCount); ?></strong></article>
    <article class="stat"><small>Low-stock medicines</small><strong><?php echo number_format($lowStockCount); ?></strong></article>
</section>

<section class="panel">
    <h3>Incoming prescriptions</h3>
    <?php if ($incoming): ?>
        <?php foreach ($incoming as $rx): ?>
            <?php $rxId = (int)$rx["prescription_id"]; $rxItems = $prescriptionItems[$rxId] ?? []; ?>
            <article class="rx-card">
                <div class="rx-head">
                    <div>
                        <h4>Prescription #<?php echo $rxId; ?> — <?php echo e($rx["patient_number"] . " · " . $rx["first_name"] . " " . $rx["last_name"]); ?></h4>
                        <div class="rx-meta">
                            Doctor: <?php echo e($rx["doctor_name"] ?: "Doctor"); ?><br>
                            Prescribed: <?php echo e($rx["prescribed_at"] ? date("d M Y, H:i", strtotime($rx["prescribed_at"])) : "—"); ?>
                        </div>
                    </div>
                    <span class="status"><?php echo e($rx["status"]); ?></span>
                </div>

                <?php if ($rxItems): ?>
                <div class="table-wrap"><table>
                    <thead><tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Qty prescribed</th><th>Stock available</th></tr></thead>
                    <tbody>
                    <?php foreach ($rxItems as $item): ?>
                    <tr>
                        <td><?php echo e($item["medicine_name"]); ?></td>
                        <td><?php echo e($item["dosage"] ?: "—"); ?></td>
                        <td><?php echo e($item["frequency"] ?: "—"); ?></td>
                        <td><?php echo e($item["duration"] ?: "—"); ?></td>
                        <td><?php echo (int)$item["quantity"]; ?></td>
                        <td class="<?php echo (int)$item["quantity_in_stock"] < (int)$item["quantity"] ? "stock-low" : "stock-ok"; ?>">
                            <?php echo (int)$item["quantity_in_stock"]; ?>
                            <?php if ((int)$item["quantity_in_stock"] < (int)$item["quantity"]): ?> (insufficient)<?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
                <?php else: ?>
                    <div class="empty">No medicine items were found for this prescription.</div>
                <?php endif; ?>

                <?php
                    $canDispense = count($rxItems) > 0;
                    foreach ($rxItems as $item) {
                        if ((int)$item["quantity_in_stock"] < (int)$item["quantity"] || (int)$item["quantity"] <= 0) {
                            $canDispense = false;
                        }
                    }
                ?>
                <form class="dispense-form" method="post" action="dispensing.php" onsubmit="return confirm('Confirm that you have checked this prescription and are dispensing all listed medicines?');">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="action" value="dispense">
                    <input type="hidden" name="prescription_id" value="<?php echo $rxId; ?>">
                    <button type="submit" <?php echo $canDispense ? "" : "disabled"; ?>>
                        <?php echo $canDispense ? "Confirm & Dispense Medicines" : "Cannot Dispense — Check Stock"; ?>
                    </button>
                </form>
            </article>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty">There are no prescriptions waiting to be dispensed.</div>
    <?php endif; ?>
</section>

<section class="panel">
    <h3>Recent dispensing history</h3>
    <?php if ($history): ?>
    <div class="table-wrap"><table>
        <thead><tr><th>Dispensing #</th><th>Prescription #</th><th>Patient</th><th>Dispensed at</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($history as $row): ?>
        <tr>
            <td><?php echo (int)$row["dispensing_id"]; ?></td>
            <td><?php echo (int)$row["prescription_id"]; ?></td>
            <td><?php echo e($row["patient_number"] . " — " . $row["first_name"] . " " . $row["last_name"]); ?></td>
            <td><?php echo e($row["dispensed_at"] ? date("d M Y, H:i", strtotime($row["dispensed_at"])) : "—"); ?></td>
            <td><span class="status">Dispensed</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
        <div class="empty">No dispensing history is available yet.</div>
    <?php endif; ?>
</section>

<footer class="footer"><span>© <?php echo date("Y"); ?> CarePoint Hospital Management System.</span><span>Healthcare made organised.</span></footer>
</div>
</main>
</div>
</body>
</html>
