
<?php
session_start();
date_default_timezone_set('Africa/Johannesburg');
require_once "db.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    header("Location: index.php");
    exit();
}

$role = strtolower($_SESSION["staff_role"] ?? "");

if (!in_array($role, ["doctor", "pharmacist"], true)) {
    http_response_code(403);
    exit("Access denied.");
}

$staffId = (int)($_SESSION["staff_id"] ?? 0);
$staffName = $_SESSION["staff_name"] ?? ucfirst($role);
$isDoctor = $role === "doctor";

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION["csrf_token"];
$errors = [];
$success = "";
$selectedPatientId = (int)($_GET["patient_id"] ?? $_POST["patient_id"] ?? 0);

$patients = [];
$medicines = [];
$prescriptions = [];

if ($isDoctor && $staffId <= 0) {
    $errors[] = "Your doctor account could not be identified. Please sign in again.";
}

// CREATE PRESCRIPTION
if ($isDoctor && $_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals($csrf, $_POST["csrf_token"] ?? "")) {
        $errors[] = "Your session has expired. Refresh the page and try again.";
    }

    $selectedPatientId = (int)($_POST["patient_id"] ?? 0);
    $medicineIds = $_POST["medicine_id"] ?? [];
    $dosages = $_POST["dosage"] ?? [];
    $frequencies = $_POST["frequency"] ?? [];
    $durations = $_POST["duration"] ?? [];
    $quantities = $_POST["quantity"] ?? [];

    if ($selectedPatientId <= 0) {
        $errors[] = "Please select a patient.";
    }

    if (!is_array($medicineIds) || count($medicineIds) === 0) {
        $errors[] = "Add at least one medicine to the prescription.";
    }

    $items = [];

    if (is_array($medicineIds)) {
        foreach ($medicineIds as $i => $rawMedicineId) {

            $medicineId = (int)$rawMedicineId;
            $dosage = trim((string)($dosages[$i] ?? ""));
            $frequency = trim((string)($frequencies[$i] ?? ""));
            $duration = trim((string)($durations[$i] ?? ""));
            $quantity = filter_var(
                $quantities[$i] ?? null,
                FILTER_VALIDATE_INT
            );

            if (
                $medicineId <= 0 ||
                $dosage === "" ||
                $frequency === "" ||
                $duration === "" ||
                $quantity === false ||
                $quantity <= 0 ||
                $quantity > 10000
            ) {
                $errors[] = "Complete the medicine, dosage, frequency, duration and quantity for every item.";
                break;
            }

            $items[] = [
                "medicine_id" => $medicineId,
                "dosage" => $dosage,
                "frequency" => $frequency,
                "duration" => $duration,
                "quantity" => $quantity
            ];
        }
    }

    if (!$errors) {
        try {

            $patientCheck = $pdo->prepare(
                "SELECT patient_id FROM patients WHERE patient_id = ?"
            );
            $patientCheck->execute([$selectedPatientId]);

            if (!$patientCheck->fetch()) {
                $errors[] = "The selected patient does not exist.";
            }

            $medicineCheck = $pdo->prepare(
                "SELECT medicine_id FROM medicines WHERE medicine_id = ?"
            );

            foreach ($items as $item) {
                $medicineCheck->execute([$item["medicine_id"]]);

                if (!$medicineCheck->fetch()) {
                    $errors[] = "One of the selected medicines could not be found.";
                    break;
                }
            }

            if (!$errors) {

                $pdo->beginTransaction();

                $header = $pdo->prepare(
                    "INSERT INTO prescriptions (patient_id, doctor_id, status)
                     VALUES (?, ?, 'pending')"
                );

                $header->execute([$selectedPatientId, $staffId]);

                $prescriptionId = (int)$pdo->lastInsertId();

                $line = $pdo->prepare(
                    "INSERT INTO prescription_items
                     (prescription_id, medicine_id, dosage, frequency, duration, quantity)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );

                foreach ($items as $item) {
                    $line->execute([
                        $prescriptionId,
                        $item["medicine_id"],
                        $item["dosage"],
                        $item["frequency"],
                        $item["duration"],
                        $item["quantity"]
                    ]);
                }

                $pdo->commit();

                header(
                    "Location: prescriptions.php?patient_id=" .
                    $selectedPatientId . "&sent=1"
                );
                exit();
            }

        } catch (Throwable $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Prescription save error: " . $ex->getMessage());

            $errors[] = "The prescription could not be saved. Please check the selected patient and medicines, then try again.";
        }
    }
}

if (isset($_GET["sent"]) && $_GET["sent"] === "1") {
    $success = "Prescription submitted to the pharmacy successfully.";
}

// LOAD PAGE DATA
try {

    if ($isDoctor) {

        $patients = $pdo->query(
            "SELECT patient_id, patient_number, first_name, last_name
             FROM patients
             ORDER BY last_name, first_name"
        )->fetchAll();

        $medicines = $pdo->query(
            "SELECT medicine_id, medicine_name
             FROM medicines
             ORDER BY medicine_name"
        )->fetchAll();
    }

    $sql = "
        SELECT
            p.prescription_id,
            p.patient_id,
            p.doctor_id,
            p.prescribed_at,
            p.status,
            pt.patient_number,
            pt.first_name,
            pt.last_name,
            st.full_name AS doctor_name
        FROM prescriptions p
        INNER JOIN patients pt ON pt.patient_id = p.patient_id
        LEFT JOIN staff st ON st.staff_id = p.doctor_id
    ";

    if ($isDoctor) {
        $sql .= " WHERE p.doctor_id = ?";
    } else {
        $sql .= " WHERE LOWER(p.status) IN ('pending', 'approved', 'ready')";
    }

    $sql .= " ORDER BY p.prescribed_at DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($isDoctor ? [$staffId] : []);

    $prescriptions = $stmt->fetchAll();

} catch (Throwable $ex) {

    error_log("Prescription page load error: " . $ex->getMessage());

    $errors[] = "The prescription information could not be loaded. Please check the database connection and table structure.";
}

$firstName = explode(" ", trim($staffName))[0] ?: ucfirst($role);

$optionData = [];

foreach ($medicines as $medicine) {
    $optionData[] = [
        "id" => (int)$medicine["medicine_id"],
        "name" => (string)$medicine["medicine_name"]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>CarePoint | Prescriptions</title>

<link rel="stylesheet" href="sidebar.css">

<style>
:root {
    --navy: #163B65;
    --blue: #3979B9;
    --ice: #A9D3F0;
    --light: #EAF4FC;
    --white: #FFFFFF;
    --ink: #20364D;
    --muted: #718096;
    --border: #DCE8F2;
    --surface: #F5F9FD;
    --green: #237A55;
    --red: #A83245;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: var(--surface);
    color: var(--ink);
    font-family: Arial, Helvetica, sans-serif;
}

.main-content {
    padding: 0;
}

.top {
    min-height: 94px;
    padding: 20px 34px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    background: white;
    border-bottom: 1px solid var(--border);
}

.eyebrow {
    color: var(--blue);
    font-size: 10px;
    font-weight: bold;
    letter-spacing: 1.3px;
}

.top h1 {
    margin: 6px 0 0;
    color: var(--navy);
    font-size: 25px;
}

.profile {
    display: flex;
    align-items: center;
    gap: 11px;
}

.avatar {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: var(--light);
    color: var(--navy);
    font-weight: bold;
}

.profile strong {
    display: block;
    font-size: 13px;
}

.profile small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
}

.content {
    max-width: 1450px;
    margin: auto;
    padding: 30px 34px;
}

.intro h2 {
    margin: 0 0 7px;
    color: var(--navy);
    font-size: 21px;
}

.intro p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.6;
}

.panel {
    margin-top: 22px;
    padding: 23px;
    background: white;
    border: 1px solid var(--border);
    border-radius: 13px;
    box-shadow: 0 3px 14px #163b650a;
}

.panel h3 {
    margin: 0 0 17px;
    color: var(--navy);
    font-size: 16px;
}

label {
    display: block;
    margin-bottom: 7px;
    color: var(--navy);
    font-size: 12px;
    font-weight: bold;
}

input,
select {
    width: 100%;
    padding: 12px 13px;
    border: 1px solid #CBD9E6;
    border-radius: 8px;
    background: white;
    color: var(--ink);
    font: inherit;
    font-size: 13px;
    outline: none;
}

input:focus,
select:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 3px #3979b91f;
}

button,
.button {
    border: 0;
    border-radius: 8px;
    padding: 12px 18px;
    background: var(--blue);
    color: white;
    font-size: 13px;
    font-weight: bold;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

button:hover,
.button:hover {
    background: var(--navy);
}

.button-light {
    background: var(--light);
    color: var(--navy);
    border: 1px solid var(--ice);
}

.button-light:hover {
    background: var(--ice);
    color: var(--navy);
}

.patient-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 12px;
}

.medicine-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.medicine-row {
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #fbfdff;
}

.medicine-row-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 13px;
}

.medicine-row-head strong {
    font-size: 13px;
    color: var(--navy);
}

.remove-btn {
    background: #fff0f1;
    color: var(--red);
    padding: 8px 11px;
}

.remove-btn:hover {
    background: #f8dfe3;
    color: var(--red);
}

.fields {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}

.field {
    min-width: 0;
}

.field.full {
    grid-column: 1 / -1;
}

.actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 17px;
}

.notice {
    margin-top: 18px;
    padding: 12px 14px;
    border-radius: 8px;
    font-size: 13px;
}

.success {
    color: var(--green);
    background: #EAF7F0;
    border: 1px solid #C8E9D7;
}

.error {
    color: var(--red);
    background: #FFF0F1;
    border: 1px solid #F0CDD2;
}

.error ul {
    margin: 7px 0 0;
    padding-left: 20px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th,
td {
    padding: 13px 12px;
    text-align: left;
    border-bottom: 1px solid var(--border);
    font-size: 12px;
}

th {
    color: var(--navy);
    background: #F5F9FD;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.status {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 20px;
    background: #FFF4DF;
    color: #946200;
    font-size: 11px;
    font-weight: bold;
    text-transform: capitalize;
}

.empty {
    padding: 17px;
    border-radius: 8px;
    background: var(--surface);
    color: var(--muted);
    font-size: 12px;
}

.footer {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 28px 0 8px;
    color: var(--muted);
    font-size: 11px;
}

@media(max-width: 900px) {
    .fields {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 650px) {
    .fields {
        grid-template-columns: 1fr;
    }

    .field.full {
        grid-column: auto;
    }

    .patient-row {
        grid-template-columns: 1fr;
    }

    .top {
        padding: 17px 20px;
    }

    .content {
        padding: 22px 18px;
    }

    .panel {
        padding: 17px;
    }

    .profile-info {
        display: none;
    }

    .footer {
        flex-direction: column;
    }
}
</style>

</head>

<body>

<div class="dashboard-layout">

    <?php include "sidebar.php"; ?>

    <main class="main-content">

        <header class="top">

            <div>
                <span class="eyebrow">CAREPOINT MANAGEMENT SYSTEM</span>

                <h1>
                    <?php echo $isDoctor ? "Prescriptions" : "Incoming Prescriptions"; ?>
                </h1>
            </div>

            <div class="profile">

                <div class="avatar">
                    <?php echo e(strtoupper(substr($firstName, 0, 1))); ?>
                </div>

                <div class="profile-info">
                    <strong><?php echo e($staffName); ?></strong>
                    <small><?php echo e(ucfirst($role)); ?></small>
                </div>

            </div>

        </header>

        <div class="content">

            <section class="intro">

                <h2>
                    <?php echo $isDoctor
                        ? "Create a prescription"
                        : "Pharmacy prescription inbox"; ?>
                </h2>

                <p>
                    <?php echo $isDoctor
                        ? "Choose a patient, add each medicine and send the completed prescription to the pharmacy."
                        : "Review prescriptions submitted by doctors and prepare them for dispensing."; ?>
                </p>

            </section>

            <?php if ($success): ?>
                <div class="notice success">
                    <?php echo e($success); ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="notice error">
                    Please check the following:

                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo e($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if ($isDoctor): ?>

                <!-- CREATE PRESCRIPTION -->
                <section class="panel">

                    <h3>Patient and prescription details</h3>

                    <form method="post" action="prescriptions.php">

                        <input type="hidden" name="csrf_token"
                               value="<?php echo e($csrf); ?>">

                        <div class="patient-row">

                            <div>
                                <label for="patient_id">Select patient *</label>

                                <select id="patient_id" name="patient_id" required>

                                    <option value="">Choose a patient</option>

                                    <?php foreach ($patients as $patient): ?>

                                        <option
                                            value="<?php echo (int)$patient["patient_id"]; ?>"
                                            <?php echo $selectedPatientId === (int)$patient["patient_id"] ? "selected" : ""; ?>
                                        >
                                            <?php echo e(
                                                $patient["patient_number"] .
                                                " — " .
                                                $patient["first_name"] .
                                                " " .
                                                $patient["last_name"]
                                            ); ?>
                                        </option>

                                    <?php endforeach; ?>

                                </select>
                            </div>

                        </div>

                        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin:24px 0 13px;">

                            <h3 style="margin:0;">Prescribed medicines</h3>

                            <button type="button" class="button-light"
                                    id="addMedicine">
                                + Add medicine
                            </button>

                        </div>

                        <div class="medicine-list" id="medicineList">

                            <div class="medicine-row">

                                <div class="medicine-row-head">
                                    <strong>Medicine 1</strong>
                                </div>

                                <div class="fields">

                                    <div class="field full">
                                        <label>Medicine *</label>

                                        <select name="medicine_id[]" required>

                                            <option value="">Select medicine</option>

                                            <?php foreach ($medicines as $medicine): ?>

                                                <option value="<?php echo (int)$medicine["medicine_id"]; ?>">
                                                    <?php echo e($medicine["medicine_name"]); ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>
                                    </div>

                                    <div class="field">
                                        <label>Dosage *</label>
                                        <input name="dosage[]" required
                                               maxlength="100"
                                               placeholder="e.g. 500 mg">
                                    </div>

                                    <div class="field">
                                        <label>Frequency *</label>
                                        <input name="frequency[]" required
                                               maxlength="100"
                                               placeholder="e.g. Twice daily">
                                    </div>

                                    <div class="field">
                                        <label>Duration *</label>
                                        <input name="duration[]" required
                                               maxlength="100"
                                               placeholder="e.g. 5 days">
                                    </div>

                                    <div class="field">
                                        <label>Quantity *</label>
                                        <input type="number" name="quantity[]"
                                               required min="1" max="10000"
                                               placeholder="e.g. 10">
                                    </div>

                                </div>

                            </div>

                        </div>

                        <div class="actions">

                            <button type="submit">
                                Send Prescription to Pharmacy
                            </button>

                            <a class="button button-light"
                               href="consultations.php<?php
                                   echo $selectedPatientId
                                       ? "?patient_id=" . $selectedPatientId
                                       : "";
                               ?>">
                                Back to Consultations
                            </a>

                        </div>

                        <p style="margin:10px 0 0;color:var(--muted);font-size:11px;">
                            Submitting sends the prescription with Pending status
                            for the pharmacy to review. Confirm medicine, dose
                            and quantity before sending.
                        </p>

                    </form>

                </section>

            <?php endif; ?>

            <!-- PRESCRIPTION LIST -->
            <section class="panel">

                <h3>
                    <?php echo $isDoctor
                        ? "Your recent prescriptions"
                        : "Waiting for pharmacy"; ?>
                </h3>

                <?php if ($prescriptions): ?>

                    <div class="table-wrap">

                        <table>

                            <thead>
                                <tr>
                                    <th>Prescription #</th>
                                    <th>Patient</th>
                                    <th>
                                        <?php echo $isDoctor ? "Submitted" : "Doctor"; ?>
                                    </th>
                                    <th>Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php foreach ($prescriptions as $prescription): ?>

                                    <tr>

                                        <td>
                                            <?php echo (int)$prescription["prescription_id"]; ?>
                                        </td>

                                        <td>
                                            <?php echo e(
                                                $prescription["patient_number"] .
                                                " — " .
                                                $prescription["first_name"] .
                                                " " .
                                                $prescription["last_name"]
                                            ); ?>
                                        </td>

                                        <td>
                                            <?php echo e(
                                                $isDoctor
                                                    ? "You"
                                                    : ($prescription["doctor_name"] ?: "Doctor")
                                            ); ?>
                                        </td>

                                        <td>
                                            <?php echo e(
                                                $prescription["prescribed_at"]
                                                    ? date(
                                                        "d M Y H:i",
                                                        strtotime($prescription["prescribed_at"])
                                                    )
                                                    : "—"
                                            ); ?>
                                        </td>

                                        <td>
                                            <span class="status">
                                                <?php echo e($prescription["status"]); ?>
                                            </span>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="empty">
                        <?php echo $isDoctor
                            ? "You have not submitted any prescriptions yet."
                            : "There are currently no prescriptions waiting for the pharmacy."; ?>
                    </div>

                <?php endif; ?>

            </section>

            <footer class="footer">
                <span>
                    © <?php echo date("Y"); ?>
                    CarePoint Hospital Management System.
                </span>

                <span>Healthcare made organised.</span>
            </footer>

        </div>

    </main>

</div>

<?php if ($isDoctor): ?>

<script>
(function () {

    const list = document.getElementById("medicineList");
    const addButton = document.getElementById("addMedicine");

    const medicineOptions = <?php
        echo json_encode(
            $optionData,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        );
    ?>;

    function makeMedicineRow(index) {

        const row = document.createElement("div");
        row.className = "medicine-row";

        const head = document.createElement("div");
        head.className = "medicine-row-head";

        const title = document.createElement("strong");
        title.textContent = "Medicine " + index;

        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "remove-btn";
        remove.textContent = "Remove";

        remove.addEventListener("click", function () {
            row.remove();
            renumberRows();
        });

        head.append(title, remove);

        const fields = document.createElement("div");
        fields.className = "fields";

        const medicineField = document.createElement("div");
        medicineField.className = "field full";

        const medicineLabel = document.createElement("label");
        medicineLabel.textContent = "Medicine *";

        const select = document.createElement("select");
        select.name = "medicine_id[]";
        select.required = true;

        const placeholder = document.createElement("option");
        placeholder.value = "";
        placeholder.textContent = "Select medicine";

        select.appendChild(placeholder);

        medicineOptions.forEach(function (item) {

            const option = document.createElement("option");
            option.value = String(item.id);
            option.textContent = item.name;

            select.appendChild(option);
        });

        medicineField.append(medicineLabel, select);
        fields.appendChild(medicineField);

        const inputFields = [
            ["Dosage *", "dosage[]", "e.g. 500 mg", "text"],
            ["Frequency *", "frequency[]", "e.g. Twice daily", "text"],
            ["Duration *", "duration[]", "e.g. 5 days", "text"],
            ["Quantity *", "quantity[]", "e.g. 10", "number"]
        ];

        inputFields.forEach(function (item) {

            const field = document.createElement("div");
            field.className = "field";

            const label = document.createElement("label");
            label.textContent = item[0];

            const input = document.createElement("input");
            input.name = item[1];
            input.type = item[3];
            input.required = true;
            input.placeholder = item[2];

            if (item[3] === "number") {
                input.min = "1";
                input.max = "10000";
            } else {
                input.maxLength = 100;
            }

            field.append(label, input);
            fields.appendChild(field);
        });

        row.append(head, fields);

        return row;
    }

    function renumberRows() {

        list.querySelectorAll(".medicine-row").forEach(function (row, index) {

            const title = row.querySelector(".medicine-row-head strong");

            if (title) {
                title.textContent = "Medicine " + (index + 1);
            }

        });
    }

    addButton.addEventListener("click", function () {

        const nextIndex = list.querySelectorAll(".medicine-row").length + 1;

        list.appendChild(makeMedicineRow(nextIndex));

    });

})();
</script>

<?php endif; ?>

</body>
</html>
