<?php
session_start();
date_default_timezone_set('Africa/Johannesburg');

require_once "db.php";

if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true ||
    ($_SESSION["staff_role"] ?? "") !== "nurse"
) {
    header("Location: index.php");
    exit;
}

$nurse_id = (int) $_SESSION["staff_id"];

function h($value) {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

// Records an important action: user, action, record affected, outcome (time is set by the database)
function audit_log(PDO $pdo, int $user_id, string $action, string $entity_type, int $entity_id, string $outcome = "success"): void {
    try {
        $log = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, entity_type, entity_id, outcome)
            VALUES (?, ?, ?, ?, ?)
        ");
        $log->execute([$user_id, $action, $entity_type, $entity_id, $outcome]);
    } catch (PDOException $e) {
        error_log("Audit log error: " . $e->getMessage());
    }
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$queue_id = filter_input(INPUT_GET, "queue_id", FILTER_VALIDATE_INT);

if (!$queue_id) {
    die("Invalid patient visit.");
}

// Get the visit and make sure it belongs to this nurse
$stmt = $pdo->prepare("
    SELECT
        q.queue_id,
        q.queue_number,
        q.queue_date,
        q.status AS queue_status,
        q.doctor_status,
        p.patient_id,
        p.patient_number,
        p.first_name,
        p.last_name,
        p.date_of_birth,
        p.gender,
        p.phone,
        p.email,
        p.address,
        p.suburb,
        p.city,
        p.province,
        p.postal_code,
        p.next_of_kin_name,
        p.next_of_kin_relationship,
        p.next_of_kin_phone
    FROM patient_queue q
    INNER JOIN patients p ON q.patient_id = p.patient_id
    WHERE q.queue_id = ?
      AND q.assigned_nurse = ?
      AND q.file_status = 'received'
    LIMIT 1
");

$stmt->execute([$queue_id, $nurse_id]);
$patient = $stmt->fetch();

if (!$patient) {
    die("Patient visit not found or you are not authorised to access it.");
}

$patient_id = (int)$patient["patient_id"];

$message = "";
$message_type = "";

if (isset($_GET["saved"])) {
    $message = "Observation saved successfully.";
    $message_type = "success";
} elseif (isset($_GET["updated"])) {
    $message = "Observation updated successfully.";
    $message_type = "success";
} elseif (isset($_GET["sent"])) {
    $message = "Patient observations sent to the doctor successfully.";
    $message_type = "success";
} elseif (isset($_GET["discharged"])) {
    $message = "Discharge request sent to the pharmacy successfully.";
    $message_type = "success";
}

// Check whether the doctor handover has already happened
$locked = ($patient["doctor_status"] !== "not_sent");

// Values to re-show in the form if a save fails
$posted_form = null;

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], (string)$_POST["csrf_token"])
    ) {
        $message = "Your session has expired. Refresh the page and try again.";
        $message_type = "error";

    } elseif ($locked && ($_POST["action"] ?? "") !== "submit_discharge") {
        $message = "This visit has already been sent to the doctor. Its observations can no longer be edited.";
        $message_type = "error";

    } else {

        $action = $_POST["action"] ?? "";

        // Save a new observation or update an existing one
        if ($action === "save_observation" && !$locked) {

            $observation_id = (int)($_POST["observation_id"] ?? 0);

            $blood_pressure = trim($_POST["blood_pressure"] ?? "");
            $temperature    = trim($_POST["temperature"] ?? "");
            $pulse_rate     = trim($_POST["pulse_rate"] ?? "");
            $weight         = trim($_POST["weight"] ?? "");
            $height         = trim($_POST["height"] ?? "");
            $notes          = trim($_POST["notes"] ?? "");

            // Keep what the nurse typed in case validation fails
            $posted_form = [
                "observation_id" => $observation_id,
                "blood_pressure" => $blood_pressure,
                "temperature"    => $temperature,
                "pulse_rate"     => $pulse_rate,
                "weight"         => $weight,
                "height"         => $height,
                "notes"          => $notes,
            ];

            $errors = [];

            if ($blood_pressure !== "" && !preg_match('/^\d{2,3}\/\d{2,3}$/', $blood_pressure)) {
                $errors[] = "Blood pressure must look like 120/80.";
            }

            if (
                $temperature !== "" &&
                (!is_numeric($temperature) || (float)$temperature < 25 || (float)$temperature > 45)
            ) {
                $errors[] = "Enter a valid temperature (25 - 45 °C).";
            }

            if (
                $pulse_rate !== "" &&
                (!ctype_digit($pulse_rate) || (int)$pulse_rate < 1 || (int)$pulse_rate > 300)
            ) {
                $errors[] = "Enter a valid pulse rate (1 - 300 bpm).";
            }

            if (
                $weight !== "" &&
                (!is_numeric($weight) || (float)$weight <= 0 || (float)$weight > 500)
            ) {
                $errors[] = "Enter a valid weight.";
            }

            if (
                $height !== "" &&
                (!is_numeric($height) || (float)$height <= 0 || (float)$height > 300)
            ) {
                $errors[] = "Enter a valid height.";
            }

            if (mb_strlen($notes) > 5000) {
                $errors[] = "Nursing notes are too long (maximum 5000 characters).";
            }

            if (
                $blood_pressure === "" && $temperature === "" && $pulse_rate === "" &&
                $weight === "" && $height === "" && $notes === ""
            ) {
                $errors[] = "Enter at least one observation before saving.";
            }

            if (empty($errors)) {

                try {

                    $temperature_value = ($temperature === "") ? null : $temperature;
                    $pulse_value       = ($pulse_rate === "") ? null : (int)$pulse_rate;
                    $weight_value      = ($weight === "") ? null : $weight;
                    $height_value      = ($height === "") ? null : $height;
                    $bp_value          = ($blood_pressure === "") ? null : $blood_pressure;
                    $notes_value       = ($notes === "") ? null : $notes;

                    if ($observation_id > 0) {

                        // Make sure this is the nurse's own unsent observation
                        $exists = $pdo->prepare("
                            SELECT 1
                            FROM observations
                            WHERE observation_id = ?
                              AND patient_id = ?
                              AND queue_id = ?
                              AND nurse_id = ?
                              AND sent_to_doctor = 0
                            LIMIT 1
                        ");

                        $exists->execute([$observation_id, $patient_id, $queue_id, $nurse_id]);

                        if (!$exists->fetchColumn()) {
                            $message = "That observation could not be found or can no longer be edited.";
                            $message_type = "error";
                        } else {

                            $update = $pdo->prepare("
                                UPDATE observations
                                SET
                                    blood_pressure = ?,
                                    temperature = ?,
                                    pulse_rate = ?,
                                    weight = ?,
                                    height = ?,
                                    notes = ?
                                WHERE observation_id = ?
                                  AND patient_id = ?
                                  AND queue_id = ?
                                  AND nurse_id = ?
                                  AND sent_to_doctor = 0
                            ");

                            $update->execute([
                                $bp_value,
                                $temperature_value,
                                $pulse_value,
                                $weight_value,
                                $height_value,
                                $notes_value,
                                $observation_id,
                                $patient_id,
                                $queue_id,
                                $nurse_id
                            ]);

                            audit_log($pdo, $nurse_id, "observation_updated", "observation", $observation_id);

                            header("Location: open_patient_file.php?queue_id=" . $queue_id . "&updated=1");
                            exit;
                        }

                    } else {

                        // A new record is created instead of overwriting history
                        $insert = $pdo->prepare("
                            INSERT INTO observations (
                                patient_id,
                                queue_id,
                                nurse_id,
                                blood_pressure,
                                temperature,
                                pulse_rate,
                                weight,
                                height,
                                notes,
                                sent_to_doctor
                            )
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
                        ");

                        $insert->execute([
                            $patient_id,
                            $queue_id,
                            $nurse_id,
                            $bp_value,
                            $temperature_value,
                            $pulse_value,
                            $weight_value,
                            $height_value,
                            $notes_value
                        ]);

                        audit_log($pdo, $nurse_id, "observation_created", "observation", (int)$pdo->lastInsertId());

                        header("Location: open_patient_file.php?queue_id=" . $queue_id . "&saved=1");
                        exit;
                    }

                } catch (PDOException $e) {
                    error_log("Observation save error: " . $e->getMessage());
                    $message = "Unable to save the observation. Please try again.";
                    $message_type = "error";
                }

            } else {
                $message = implode(" ", $errors);
                $message_type = "error";
            }
        }

        // Submit a discharge request to the pharmacy (only after the visit was sent to the doctor)
        if ($action === "submit_discharge" && $locked) {

            $discharge_notes = trim($_POST["discharge_notes"] ?? "");

            try {
                if (mb_strlen($discharge_notes) > 2000) {
                    throw new Exception("Discharge notes are too long (maximum 2000 characters).");
                }

                $dup = $pdo->prepare("SELECT 1 FROM discharge_requests WHERE queue_id = ? LIMIT 1");
                $dup->execute([$queue_id]);

                if ($dup->fetchColumn()) {
                    throw new Exception("A discharge request has already been submitted for this visit.");
                }

                $discharge = $pdo->prepare("
                    INSERT INTO discharge_requests (queue_id, patient_id, nurse_id, status, notes)
                    VALUES (?, ?, ?, 'submitted', ?)
                ");

                $discharge->execute([
                    $queue_id,
                    $patient_id,
                    $nurse_id,
                    ($discharge_notes === "") ? null : $discharge_notes
                ]);

                audit_log($pdo, $nurse_id, "discharge_request_submitted", "discharge_request", (int)$pdo->lastInsertId());

                header("Location: open_patient_file.php?queue_id=" . $queue_id . "&discharged=1");
                exit;

            } catch (PDOException $e) {
                error_log("Discharge request error: " . $e->getMessage());
                audit_log($pdo, $nurse_id, "discharge_request_submitted", "visit", $queue_id, "failure");
                $message = "Unable to submit the discharge request. Please try again.";
                $message_type = "error";

            } catch (Exception $e) {
                $message = $e->getMessage();
                $message_type = "error";
            }
        }

        // Send the visit's observations to the doctor
        if ($action === "send_to_doctor" && !$locked) {

            try {
                // The nurse must confirm the patient's identity before the file goes to the doctor
                if (empty($_POST["identity_verified"])) {
                    throw new Exception("Please confirm that you have verified the patient's identity before sending.");
                }

                $pdo->beginTransaction();

                // Lock the queue row so it cannot be sent twice
                $lock = $pdo->prepare("
                    SELECT doctor_status
                    FROM patient_queue
                    WHERE queue_id = ?
                      AND assigned_nurse = ?
                    FOR UPDATE
                ");

                $lock->execute([$queue_id, $nurse_id]);
                $current_status = $lock->fetchColumn();

                if ($current_status !== "not_sent") {
                    throw new Exception("This patient file has already been sent to the doctor.");
                }

                $check = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM observations
                    WHERE queue_id = ?
                      AND patient_id = ?
                      AND nurse_id = ?
                      AND sent_to_doctor = 0
                ");

                $check->execute([$queue_id, $patient_id, $nurse_id]);

                if ((int)$check->fetchColumn() < 1) {
                    throw new Exception("Please save at least one observation before sending.");
                }

                $send = $pdo->prepare("
                    UPDATE observations
                    SET sent_to_doctor = 1,
                        sent_at = NOW()
                    WHERE queue_id = ?
                      AND patient_id = ?
                      AND nurse_id = ?
                      AND sent_to_doctor = 0
                ");

                $send->execute([$queue_id, $patient_id, $nurse_id]);

                $handover = $pdo->prepare("
                    UPDATE patient_queue
                    SET doctor_status = 'sent',
                        sent_to_doctor_at = NOW()
                    WHERE queue_id = ?
                      AND assigned_nurse = ?
                      AND doctor_status = 'not_sent'
                ");

                $handover->execute([$queue_id, $nurse_id]);

                if ($handover->rowCount() !== 1) {
                    throw new Exception("The patient file could not be sent. Please refresh and check its status.");
                }

                $pdo->commit();

                audit_log($pdo, $nurse_id, "sent_to_doctor", "visit", $queue_id);

                header("Location: open_patient_file.php?queue_id=" . $queue_id . "&sent=1");
                exit;

            } catch (Exception $e) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log("Doctor handover error: " . $e->getMessage());
                audit_log($pdo, $nurse_id, "sent_to_doctor", "visit", $queue_id, "failure");
                $message = $e->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Load an observation for editing, if requested
$edit_observation = null;

$edit_id = filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT);

if ($edit_id && !$locked) {

    $edit_stmt = $pdo->prepare("
        SELECT *
        FROM observations
        WHERE observation_id = ?
          AND patient_id = ?
          AND queue_id = ?
          AND nurse_id = ?
          AND sent_to_doctor = 0
        LIMIT 1
    ");

    $edit_stmt->execute([
        $edit_id,
        $patient_id,
        $queue_id,
        $nurse_id
    ]);

    $edit_observation = $edit_stmt->fetch();
}

// Values shown in the form: blank, the observation being edited,
// or whatever the nurse just typed if the save failed
$form = [
    "observation_id" => 0,
    "blood_pressure" => "",
    "temperature"    => "",
    "pulse_rate"     => "",
    "weight"         => "",
    "height"         => "",
    "notes"          => "",
];

if ($edit_observation) {
    foreach ($form as $key => $value) {
        $form[$key] = $edit_observation[$key] ?? "";
    }
}

if ($posted_form !== null) {
    $form = $posted_form;
}

$is_editing = ((int)$form["observation_id"] > 0);

// Discharge request for this visit (if one was submitted)
$discharge_request = null;

try {
    $discharge_stmt = $pdo->prepare("
        SELECT status, notes, submitted_at, pharmacy_updated_at
        FROM discharge_requests
        WHERE queue_id = ?
        LIMIT 1
    ");
    $discharge_stmt->execute([$queue_id]);
    $discharge_request = $discharge_stmt->fetch() ?: null;
} catch (PDOException $e) {
    error_log("Discharge request load error: " . $e->getMessage());
}

// Current visit's observations
$current_stmt = $pdo->prepare("
    SELECT o.*, s.full_name AS nurse_name
    FROM observations o
    LEFT JOIN staff s ON o.nurse_id = s.staff_id
    WHERE o.queue_id = ?
      AND o.patient_id = ?
    ORDER BY o.recorded_at DESC
");

$current_stmt->execute([$queue_id, $patient_id]);
$current_observations = $current_stmt->fetchAll();

// Previous visits and their observations
$history_stmt = $pdo->prepare("
    SELECT
        o.*,
        q.queue_date,
        q.queue_number,
        s.full_name AS nurse_name
    FROM observations o
    LEFT JOIN patient_queue q ON o.queue_id = q.queue_id
    LEFT JOIN staff s ON o.nurse_id = s.staff_id
    WHERE o.patient_id = ?
      AND (o.queue_id IS NULL OR o.queue_id != ?)
    ORDER BY o.recorded_at DESC
");

$history_stmt->execute([$patient_id, $queue_id]);
$previous_observations = $history_stmt->fetchAll();

$address_parts = array_filter([
    $patient["address"],
    $patient["suburb"],
    $patient["city"],
    $patient["province"],
    $patient["postal_code"]
]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient File | CarePoint</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: #EAF4FC;
    color: #24364B;
    line-height: 1.4;
}

/* ---------- Sidebar ---------- */
.sidebar {
    position: fixed;
    top: 0;
    left: 0;
    width: 250px;
    height: 100vh;
    background: #163B65;
    color: white;
    padding: 28px 18px;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    z-index: 10;
}

.logo {
    font-size: 25px;
    font-weight: bold;
    margin-bottom: 45px;
    padding-left: 10px;
}

.logo span {
    color: #A9D3F0;
}

.sidebar-label {
    color: #A9D3F0;
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 1.5px;
    margin: 0 10px 15px;
    text-transform: uppercase;
}

.sidebar-nav {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.nav-link {
    display: block;
    padding: 13px 14px;
    color: #EAF4FC;
    text-decoration: none;
    border-radius: 8px;
    font-size: 14px;
}

.nav-link:hover,
.nav-link.active {
    background: #3979B9;
    color: white;
}

.sidebar-bottom {
    margin-top: auto;
    padding-top: 20px;
}

.sidebar-user {
    border-top: 1px solid rgba(255,255,255,.2);
    padding-top: 18px;
    margin-bottom: 15px;
}

.sidebar-user strong {
    display: block;
    font-size: 14px;
    margin-bottom: 3px;
}

.sidebar-user small {
    color: #A9D3F0;
    font-size: 12px;
}

.logout-link {
    display: block;
    padding: 12px;
    border-radius: 8px;
    color: white;
    text-decoration: none;
    background: rgba(255,255,255,.1);
    font-size: 14px;
    text-align: center;
}

.logout-link:hover {
    background: rgba(255,255,255,.2);
}

/* ---------- Main ---------- */
.main-content {
    margin-left: 250px;
    width: calc(100% - 250px);
    padding: 35px;
    max-width: 1500px;
}

.page-header {
    margin-bottom: 25px;
}

.page-header h1 {
    color: #163B65;
    font-size: 27px;
    margin-bottom: 8px;
}

.page-header p {
    color: #718096;
    font-size: 14px;
}

.section-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    border: 1px solid #DFEAF4;
    box-shadow: 0 4px 15px rgba(22,59,101,.05);
    margin-bottom: 25px;
}

.section-card h2 {
    color: #163B65;
    font-size: 19px;
    margin-bottom: 20px;
}

.section-note {
    font-size: 13px;
    color: #718096;
    margin: -8px 0 18px;
}

/* ---------- Patient info ---------- */
.patient-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 22px 24px;
    align-items: start;
}

.info-item label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: #718096;
    margin-bottom: 6px;
}

.info-item p {
    color: #163B65;
    font-size: 14px;
    font-weight: bold;
    overflow-wrap: anywhere;
    text-transform: none;
}

.info-item p.capitalise {
    text-transform: capitalize;
}

/* ---------- Form ---------- */
.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 20px;
    align-items: start;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 7px;
    min-width: 0;
}

.form-group.full {
    grid-column: 1 / -1;
}

.form-group label {
    color: #40556D;
    font-size: 13px;
    font-weight: bold;
}

.form-group input,
.form-group textarea {
    width: 100%;
    border: 1px solid #D5E1ED;
    border-radius: 7px;
    padding: 12px;
    font-size: 14px;
    outline: none;
    font-family: inherit;
    color: #24364B;
    background: #FCFDFF;
}

.form-group input:focus,
.form-group textarea:focus {
    border-color: #3979B9;
    box-shadow: 0 0 0 3px rgba(57,121,185,.1);
}

.form-group textarea {
    min-height: 110px;
    resize: vertical;
}

.button-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    margin-top: 20px;
}

.btn {
    display: inline-block;
    border: none;
    padding: 12px 18px;
    border-radius: 7px;
    cursor: pointer;
    text-decoration: none;
    font-family: inherit;
    font-size: 13px;
    font-weight: bold;
    line-height: 1.2;
    text-align: center;
}

.btn-primary {
    background: #3979B9;
    color: white;
}

.btn-primary:hover {
    background: #163B65;
}

.btn-secondary {
    background: #D6E8F7;
    color: #245B91;
}

.btn-secondary:hover {
    background: #C3DCF1;
}

.btn-send {
    background: #267447;
    color: white;
}

.btn-send:hover {
    background: #1D5D38;
}

.btn-send:disabled {
    background: #A9BFB1;
    cursor: not-allowed;
}

.btn-small {
    padding: 8px 14px;
    font-size: 12px;
}

.message {
    padding: 14px 18px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-size: 13px;
}

.message.success {
    background: #E4F5EB;
    color: #267447;
}

.message.error {
    background: #FCE8E8;
    color: #A52A2A;
}

.notice {
    background: #FFF4DC;
    color: #805A00;
    border-radius: 8px;
    padding: 15px 18px;
    margin-bottom: 25px;
    font-size: 13px;
    line-height: 1.5;
}

/* ---------- Tables ---------- */
.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 950px;
}

th {
    background: #F2F7FC;
    color: #52657A;
    padding: 13px;
    text-align: left;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
    white-space: nowrap;
}

td {
    padding: 13px;
    border-bottom: 1px solid #E8EEF5;
    font-size: 13px;
    color: #35485D;
    vertical-align: top;
}

td.nowrap {
    white-space: nowrap;
}

td.notes-cell {
    min-width: 200px;
    max-width: 320px;
    overflow-wrap: anywhere;
}

tbody tr:last-child td {
    border-bottom: none;
}

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    background: #E4F5EB;
    color: #267447;
    font-size: 11px;
    font-weight: bold;
    white-space: nowrap;
}

.status.locked {
    background: #FFF4DC;
    color: #805A00;
}

.edit-link {
    color: #245B91;
    text-decoration: none;
    font-weight: bold;
}

.edit-link:hover {
    text-decoration: underline;
}

.read-only {
    color: #718096;
    white-space: nowrap;
}

.empty {
    color: #718096;
    padding: 10px 0;
    font-size: 13px;
}

.page-footer {
    margin-bottom: 10px;
}

.verify-check {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 13px;
    font-weight: bold;
    color: #40556D;
    cursor: pointer;
}

.verify-check input {
    width: 16px;
    height: 16px;
}

/* ---------- Responsive ---------- */
@media(max-width: 900px) {
    .sidebar {
        width: 210px;
    }

    .main-content {
        margin-left: 210px;
        width: calc(100% - 210px);
        padding: 25px;
    }

    .patient-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 650px) {
    .sidebar {
        position: relative;
        width: 100%;
        height: auto;
        padding: 18px;
    }

    .logo {
        margin-bottom: 18px;
    }

    .sidebar-nav {
        display: grid;
        grid-template-columns: 1fr 1fr;
    }

    .sidebar-bottom {
        margin-top: 20px;
    }

    .main-content {
        margin-left: 0;
        width: 100%;
        padding: 20px 14px;
    }

    .patient-grid,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .section-card {
        padding: 17px;
    }

    .button-row .btn {
        flex: 1 1 100%;
    }
}
</style>
</head>

<body>

<aside class="sidebar">

    <div class="logo">Care<span>Point</span></div>

    <div class="sidebar-label">Nurse Menu</div>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-link">Dashboard</a>
        <a href="patients.php" class="nav-link">Patients</a>
        <a href="queue.php" class="nav-link">Patient Queue</a>
        <a href="nurse_inbox.php" class="nav-link active">Nurse Inbox</a>
        <a href="observations.php" class="nav-link">Observations</a>
    </nav>

    <div class="sidebar-bottom">
        <div class="sidebar-user">
            <strong><?php echo h($_SESSION["staff_name"] ?? "Nurse"); ?></strong>
            <small>Nurse Account</small>
        </div>
        <a href="logout.php" class="logout-link">Log out</a>
    </div>

</aside>

<main class="main-content">

    <header class="page-header">
        <h1>Patient File</h1>
        <p>Review patient information and record nursing observations.</p>
    </header>

    <?php if ($message !== ""): ?>
        <div class="message <?php echo h($message_type); ?>">
            <?php echo h($message); ?>
        </div>
    <?php endif; ?>

    <section class="section-card">
        <h2>Patient Information</h2>

        <div class="patient-grid">

            <div class="info-item">
                <label>Patient Name</label>
                <p><?php echo h($patient["first_name"] . " " . $patient["last_name"]); ?></p>
            </div>

            <div class="info-item">
                <label>Patient Number</label>
                <p><?php echo h($patient["patient_number"]); ?></p>
            </div>

            <div class="info-item">
                <label>Date of Birth</label>
                <p><?php echo h($patient["date_of_birth"] ?: "Not recorded"); ?></p>
            </div>

            <div class="info-item">
                <label>Gender</label>
                <p class="capitalise"><?php echo h($patient["gender"] ?: "Not recorded"); ?></p>
            </div>

            <div class="info-item">
                <label>Phone</label>
                <p><?php echo h($patient["phone"] ?: "Not recorded"); ?></p>
            </div>

            <div class="info-item">
                <label>Email</label>
                <p><?php echo h($patient["email"] ?: "Not recorded"); ?></p>
            </div>

            <div class="info-item">
                <label>Address</label>
                <p><?php echo h(count($address_parts) ? implode(", ", $address_parts) : "Not recorded"); ?></p>
            </div>

            <div class="info-item">
                <label>Next of Kin</label>
                <p>
                    <?php
                    $kin = $patient["next_of_kin_name"] ?: "Not recorded";
                    if (!empty($patient["next_of_kin_name"]) && !empty($patient["next_of_kin_relationship"])) {
                        $kin .= " (" . $patient["next_of_kin_relationship"] . ")";
                    }
                    echo h($kin);
                    ?>
                </p>
            </div>

            <div class="info-item">
                <label>Next of Kin Contact</label>
                <p><?php echo h($patient["next_of_kin_phone"] ?: "Not recorded"); ?></p>
            </div>

            <div class="info-item">
                <label>Visit Queue Number</label>
                <p>Q<?php echo str_pad((string)(int)$patient["queue_number"], 3, "0", STR_PAD_LEFT); ?></p>
            </div>

            <div class="info-item">
                <label>Visit Date</label>
                <p><?php echo h($patient["queue_date"]); ?></p>
            </div>

            <div class="info-item">
                <label>Doctor Handover</label>
                <p class="capitalise"><?php echo h(str_replace("_", " ", $patient["doctor_status"])); ?></p>
            </div>

        </div>
    </section>

    <?php if ($locked): ?>

        <div class="notice">
            This visit has already been sent to the doctor. Its observations are now read-only.
            If the patient returns for another visit, a new queue entry will allow you to create new observations.
        </div>

    <?php else: ?>

        <section class="section-card" id="observation-form">

            <h2><?php echo $is_editing ? "Edit Observation" : "Record New Observation"; ?></h2>

            <form method="POST" action="open_patient_file.php?queue_id=<?php echo (int)$queue_id; ?>">

                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="action" value="save_observation">
                <input type="hidden" name="observation_id" value="<?php echo (int)$form["observation_id"]; ?>">

                <div class="form-grid">

                    <div class="form-group">
                        <label for="blood_pressure">Blood Pressure</label>
                        <input
                            type="text"
                            id="blood_pressure"
                            name="blood_pressure"
                            placeholder="e.g. 120/80"
                            maxlength="20"
                            pattern="\d{2,3}/\d{2,3}"
                            title="Format: 120/80"
                            value="<?php echo h($form["blood_pressure"]); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="temperature">Temperature (°C)</label>
                        <input
                            type="number"
                            id="temperature"
                            name="temperature"
                            step="0.1"
                            min="25"
                            max="45"
                            placeholder="e.g. 36.5"
                            value="<?php echo h($form["temperature"]); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="pulse_rate">Pulse Rate (bpm)</label>
                        <input
                            type="number"
                            id="pulse_rate"
                            name="pulse_rate"
                            min="1"
                            max="300"
                            placeholder="e.g. 72"
                            value="<?php echo h($form["pulse_rate"]); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="weight">Weight (kg)</label>
                        <input
                            type="number"
                            id="weight"
                            name="weight"
                            step="0.01"
                            min="0.01"
                            max="500"
                            placeholder="e.g. 65.5"
                            value="<?php echo h($form["weight"]); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="height">Height (cm)</label>
                        <input
                            type="number"
                            id="height"
                            name="height"
                            step="0.01"
                            min="0.01"
                            max="300"
                            placeholder="e.g. 165"
                            value="<?php echo h($form["height"]); ?>"
                        >
                    </div>

                    <div class="form-group full">
                        <label for="notes">Nursing Notes</label>
                        <textarea
                            id="notes"
                            name="notes"
                            maxlength="5000"
                            placeholder="Record relevant observations and nursing notes..."
                        ><?php echo h($form["notes"]); ?></textarea>
                    </div>

                </div>

                <div class="button-row">

                    <button type="submit" class="btn btn-primary">
                        <?php echo $is_editing ? "Save Changes" : "Save New Observation"; ?>
                    </button>

                    <?php if ($is_editing): ?>
                        <a
                            href="open_patient_file.php?queue_id=<?php echo (int)$queue_id; ?>"
                            class="btn btn-secondary"
                        >
                            Cancel Editing
                        </a>
                    <?php endif; ?>

                </div>

            </form>

        </section>

    <?php endif; ?>

    <section class="section-card">

        <h2>Observations for This Visit</h2>

        <?php if (count($current_observations) > 0): ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date and Time</th>
                            <th>Blood Pressure</th>
                            <th>Temperature</th>
                            <th>Pulse</th>
                            <th>Weight</th>
                            <th>Height</th>
                            <th>Notes</th>
                            <th>Recorded By</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($current_observations as $observation): ?>
                        <tr>
                            <td class="nowrap"><?php echo h($observation["recorded_at"]); ?></td>
                            <td class="nowrap"><?php echo h($observation["blood_pressure"] ?: "-"); ?></td>
                            <td class="nowrap"><?php echo h($observation["temperature"] !== null ? $observation["temperature"] . " °C" : "-"); ?></td>
                            <td class="nowrap"><?php echo h($observation["pulse_rate"] !== null ? $observation["pulse_rate"] . " bpm" : "-"); ?></td>
                            <td class="nowrap"><?php echo h($observation["weight"] !== null ? $observation["weight"] . " kg" : "-"); ?></td>
                            <td class="nowrap"><?php echo h($observation["height"] !== null ? $observation["height"] . " cm" : "-"); ?></td>
                            <td class="notes-cell"><?php echo nl2br(h($observation["notes"] ?: "-")); ?></td>
                            <td><?php echo h($observation["nurse_name"] ?? "Unknown"); ?></td>
                            <td>
                                <?php if ($observation["sent_to_doctor"]): ?>
                                    <span class="status locked">Sent</span>
                                <?php else: ?>
                                    <span class="status">Not sent</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$locked && !$observation["sent_to_doctor"] && (int)$observation["nurse_id"] === $nurse_id): ?>
                                    <a
                                        class="btn btn-primary btn-small"
                                        href="open_patient_file.php?queue_id=<?php echo (int)$queue_id; ?>&amp;edit=<?php echo (int)$observation["observation_id"]; ?>#observation-form"
                                    >
                                        Edit
                                    </a>
                                <?php else: ?>
                                    <span class="read-only">Read only</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <p class="empty">No observations have been recorded for this visit yet.</p>

        <?php endif; ?>

        <?php if (!$locked): ?>
            <form
                method="POST"
                action="open_patient_file.php?queue_id=<?php echo (int)$queue_id; ?>"
                class="button-row"
            >
                <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">
                <input type="hidden" name="action" value="send_to_doctor">

                <label class="verify-check">
                    <input type="checkbox" name="identity_verified" value="1" required>
                    I have verified this patient's identity
                </label>

                <button
                    type="submit"
                    class="btn btn-send"
                    <?php echo count($current_observations) < 1 ? "disabled" : ""; ?>
                    onclick="return confirm('Are you sure you want to send this patient file to the doctor? You will not be able to edit these observations afterwards.');"
                >
                    Send to Doctor
                </button>

                <?php if (count($current_observations) < 1): ?>
                    <span class="section-note" style="margin:0;">Save at least one observation to enable sending.</span>
                <?php endif; ?>
            </form>
        <?php endif; ?>

    </section>

    <?php if ($locked): ?>

        <section class="section-card">

            <h2>Discharge Request to Pharmacy</h2>

            <?php if ($discharge_request): ?>

                <div class="patient-grid">
                    <div class="info-item">
                        <label>Status</label>
                        <p class="capitalise"><?php echo h(str_replace("_", " ", $discharge_request["status"])); ?></p>
                    </div>

                    <div class="info-item">
                        <label>Submitted</label>
                        <p><?php echo h($discharge_request["submitted_at"]); ?></p>
                    </div>

                    <div class="info-item">
                        <label>Last Pharmacy Update</label>
                        <p><?php echo h($discharge_request["pharmacy_updated_at"] ?: "Not yet updated"); ?></p>
                    </div>

                    <div class="info-item" style="grid-column: 1 / -1;">
                        <label>Notes</label>
                        <p><?php echo nl2br(h($discharge_request["notes"] ?: "-")); ?></p>
                    </div>
                </div>

            <?php else: ?>

                <p class="section-note">
                    Send an electronic discharge request to the pharmacy work queue instead of walking the file over.
                </p>

                <form method="POST" action="open_patient_file.php?queue_id=<?php echo (int)$queue_id; ?>">

                    <input type="hidden" name="csrf_token" value="<?php echo h($_SESSION["csrf_token"]); ?>">
                    <input type="hidden" name="action" value="submit_discharge">

                    <div class="form-group">
                        <label for="discharge_notes">Notes for Pharmacy (optional)</label>
                        <textarea
                            id="discharge_notes"
                            name="discharge_notes"
                            maxlength="2000"
                            placeholder="e.g. medication to be collected at discharge..."
                        ></textarea>
                    </div>

                    <div class="button-row">
                        <button
                            type="submit"
                            class="btn btn-send"
                            onclick="return confirm('Send this discharge request to the pharmacy?');"
                        >
                            Send Discharge Request to Pharmacy
                        </button>
                    </div>

                </form>

            <?php endif; ?>

        </section>

    <?php endif; ?>

    <section class="section-card">

        <h2>Previous Visit Records</h2>

        <p class="section-note">
            These are records from earlier visits. They are kept for history and are not changed when you record a new visit.
        </p>

        <?php if (count($previous_observations) > 0): ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Visit Date</th>
                            <th>Queue Number</th>
                            <th>Blood Pressure</th>
                            <th>Temperature</th>
                            <th>Pulse</th>
                            <th>Weight</th>
                            <th>Height</th>
                            <th>Nursing Notes</th>
                            <th>Recorded By</th>
                        </tr>
                    </thead>

                    <tbody>
                    <?php foreach ($previous_observations as $old): ?>
                        <tr>
                            <td class="nowrap"><?php echo h($old["queue_date"] ?? $old["recorded_at"]); ?></td>
                            <td class="nowrap">
                                <?php
                                echo $old["queue_number"] !== null
                                    ? "Q" . str_pad((string)(int)$old["queue_number"], 3, "0", STR_PAD_LEFT)
                                    : "Earlier record";
                                ?>
                            </td>
                            <td class="nowrap"><?php echo h($old["blood_pressure"] ?: "-"); ?></td>
                            <td class="nowrap"><?php echo h($old["temperature"] !== null ? $old["temperature"] . " °C" : "-"); ?></td>
                            <td class="nowrap"><?php echo h($old["pulse_rate"] !== null ? $old["pulse_rate"] . " bpm" : "-"); ?></td>
                            <td class="nowrap"><?php echo h($old["weight"] !== null ? $old["weight"] . " kg" : "-"); ?></td>
                            <td class="nowrap"><?php echo h($old["height"] !== null ? $old["height"] . " cm" : "-"); ?></td>
                            <td class="notes-cell"><?php echo nl2br(h($old["notes"] ?: "-")); ?></td>
                            <td><?php echo h($old["nurse_name"] ?? "Unknown"); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <p class="empty">There are no previous visit observations recorded for this patient.</p>

        <?php endif; ?>

    </section>

    <div class="page-footer">
        <a href="nurse_inbox.php" class="btn btn-secondary">Back to Nurse Inbox</a>
    </div>

</main>

</body>
</html>
