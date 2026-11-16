<?php
session_start();
date_default_timezone_set('Africa/Johannesburg');

require_once "db.php";

// Doctors only: enforce access on the server.
if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true
) {
    header("Location: index.php");
    exit();
}

$staffRole = strtolower($_SESSION["staff_role"] ?? "");
if ($staffRole !== "doctor") {
    http_response_code(403);
    exit("Access denied. This page is only available to doctors.");
}

$doctorId = (int)($_SESSION["staff_id"] ?? 0);
$staffName = $_SESSION["staff_name"] ?? "Doctor";

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

$errors = [];
$success = "";
$selectedPatientId = (int)($_GET["patient_id"] ?? $_POST["patient_id"] ?? 0);
$diagnosis = "";
$clinicalNotes = "";

// Save a consultation.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken = $_POST["csrf_token"] ?? "";

    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = "Your session security token is invalid. Please refresh the page and try again.";
    }

    $diagnosis = trim($_POST["diagnosis"] ?? "");
    $clinicalNotes = trim($_POST["clinical_notes"] ?? "");
    $selectedPatientId = (int)($_POST["patient_id"] ?? 0);

    if ($doctorId <= 0) {
        $errors[] = "Your staff account could not be identified. Please sign out and sign in again.";
    }
    if ($selectedPatientId <= 0) {
        $errors[] = "Please select a patient.";
    }
    if ($diagnosis === "") {
        $errors[] = "Please enter a diagnosis.";
    }
    if ($clinicalNotes === "") {
        $errors[] = "Please enter the clinical notes.";
    }

    if (!$errors) {
        try {
            $patientCheck = $pdo->prepare(
                "SELECT patient_id FROM patients WHERE patient_id = ? LIMIT 1"
            );
            $patientCheck->execute([$selectedPatientId]);

            if (!$patientCheck->fetch()) {
                $errors[] = "The selected patient could not be found.";
            } else {
                $insert = $pdo->prepare(
                    "INSERT INTO consultations
                        (patient_id, doctor_id, diagnosis, clinical_notes)
                     VALUES (?, ?, ?, ?)"
                );
                $insert->execute([
                    $selectedPatientId,
                    $doctorId,
                    $diagnosis,
                    $clinicalNotes
                ]);

                header("Location: consultations.php?patient_id=" . $selectedPatientId . "&saved=1");
                exit();
            }
        } catch (PDOException $ex) {
            error_log("Consultation save error: " . $ex->getMessage());
            $errors[] = "The consultation could not be saved. Please check that your staff account and database tables are set up correctly.";
        }
    }
}

if (isset($_GET["saved"]) && $_GET["saved"] === "1") {
    $success = "Consultation saved successfully.";
}

// Load patients for the doctor to select/search.
$patients = [];
try {
    $patientStmt = $pdo->query(
        "SELECT patient_id, patient_number, first_name, last_name, date_of_birth, gender
         FROM patients
         ORDER BY last_name ASC, first_name ASC"
    );
    $patients = $patientStmt->fetchAll();
} catch (PDOException $ex) {
    error_log("Consultation patient list error: " . $ex->getMessage());
    $errors[] = "Patient records could not be loaded.";
}

$selectedPatient = null;
$observations = [];
$consultations = [];

if ($selectedPatientId > 0) {
    try {
        $selectedStmt = $pdo->prepare(
            "SELECT patient_id, patient_number, first_name, last_name, date_of_birth,
                    gender, phone
             FROM patients
             WHERE patient_id = ?
             LIMIT 1"
        );
        $selectedStmt->execute([$selectedPatientId]);
        $selectedPatient = $selectedStmt->fetch();

        if ($selectedPatient) {
            $observationStmt = $pdo->prepare(
                "SELECT o.blood_pressure, o.temperature, o.pulse_rate, o.weight,
                        o.notes, o.recorded_at, s.full_name AS nurse_name
                 FROM observations o
                 LEFT JOIN staff s ON s.staff_id = o.nurse_id
                 WHERE o.patient_id = ?
                 ORDER BY o.recorded_at DESC
                 LIMIT 5"
            );
            $observationStmt->execute([$selectedPatientId]);
            $observations = $observationStmt->fetchAll();

            $consultationStmt = $pdo->prepare(
                "SELECT c.diagnosis, c.clinical_notes, c.consultation_date,
                        s.full_name AS doctor_name
                 FROM consultations c
                 LEFT JOIN staff s ON s.staff_id = c.doctor_id
                 WHERE c.patient_id = ?
                 ORDER BY c.consultation_date DESC
                 LIMIT 10"
            );
            $consultationStmt->execute([$selectedPatientId]);
            $consultations = $consultationStmt->fetchAll();
        } else {
            $selectedPatientId = 0;
        }
    } catch (PDOException $ex) {
        error_log("Consultation patient details error: " . $ex->getMessage());
        $errors[] = "The selected patient's clinical information could not be loaded.";
    }
}

$firstName = explode(" ", trim($staffName))[0] ?: "Doctor";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CarePoint | Consultations</title>
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
    --danger: #A83245;
    --success: #237A55;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    background: var(--surface);
    color: var(--ink);
    font-family: Arial, Helvetica, sans-serif;
}
.dashboard-layout { display: flex; min-height: 100vh; }
.sidebar {
    width: 250px; min-width: 250px; min-height: 100vh;
    height: 100vh; position: sticky; top: 0;
    display: flex; flex-direction: column;
    padding: 28px 18px 20px;
    background: var(--navy); color: var(--white);
}
.brand {
    display: flex; align-items: center; gap: 12px;
    min-height: 48px; margin: 0 8px 34px;
    color: var(--white); text-decoration: none;
}
.brand-icon {
    width: 40px; height: 40px; flex: 0 0 40px;
    display: grid; place-items: center; border-radius: 12px;
    background: var(--ice); color: var(--navy);
    font-size: 27px; font-weight: 700;
}
.brand-text { display: flex; flex-direction: column; gap: 3px; }
.brand-text strong { font-size: 19px; }
.brand-text small { color: #D7E9F7; font-size: 11px; }
.sidebar-section-label {
    margin: 0 10px 12px; color: var(--ice);
    font-size: 10px; font-weight: 700; letter-spacing: 1.4px;
}
.sidebar-nav { display: flex; flex-direction: column; gap: 6px; }
.nav-link, .logout-link {
    min-height: 46px; display: flex; align-items: center; gap: 12px;
    padding: 11px 13px; border-radius: 9px;
    color: #EAF4FC; text-decoration: none;
    font-size: 13px; font-weight: 600;
}
.nav-link:hover, .nav-link.active, .logout-link:hover {
    background: var(--blue); color: var(--white);
}
.nav-icon { width: 20px; text-align: center; font-size: 17px; }
.sidebar-bottom {
    margin-top: auto; padding-top: 18px;
    border-top: 1px solid rgba(234,244,252,.18);
}
.logout-link { background: rgba(234,244,252,.10); }
.main-content { min-width: 0; flex: 1; }
.top-header {
    min-height: 94px; padding: 20px 34px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 20px; background: var(--white);
    border-bottom: 1px solid var(--border);
}
.eyebrow { color: var(--blue); font-size: 10px; font-weight: 700; letter-spacing: 1.3px; }
.top-header h1 { margin: 6px 0 0; color: var(--navy); font-size: 25px; }
.profile { display: flex; align-items: center; gap: 11px; }
.avatar {
    width: 40px; height: 40px; display: grid; place-items: center;
    border-radius: 50%; background: var(--light);
    color: var(--navy); font-weight: 700;
}
.profile strong { display: block; font-size: 13px; }
.profile small { display: block; margin-top: 4px; color: var(--muted); }
.content { max-width: 1450px; margin: 0 auto; padding: 30px 34px; }
.intro h2 { margin: 0 0 7px; color: var(--navy); font-size: 21px; }
.intro p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.6; }
.panel {
    margin-top: 22px; padding: 23px;
    background: var(--white); border: 1px solid var(--border);
    border-radius: 13px; box-shadow: 0 3px 14px rgba(22,59,101,.04);
}
.panel h3 { margin: 0 0 17px; color: var(--navy); font-size: 16px; }
.patient-picker { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; }
input, select, textarea {
    width: 100%; padding: 12px 13px; border: 1px solid #CBD9E6;
    border-radius: 8px; background: #fff; color: var(--ink);
    font: inherit; font-size: 13px; outline: none;
}
input:focus, select:focus, textarea:focus {
    border-color: var(--blue); box-shadow: 0 0 0 3px rgba(57,121,185,.12);
}
button {
    border: 0; border-radius: 8px; padding: 12px 18px;
    background: var(--blue); color: white; font-size: 13px;
    font-weight: 700; cursor: pointer;
}
button:hover { background: var(--navy); }
.patient-summary {
    display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px; margin-top: 17px;
}
.summary-item { padding: 13px; border-radius: 9px; background: var(--light); }
.summary-item small { display: block; margin-bottom: 6px; color: var(--muted); font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
.summary-item strong { color: var(--navy); font-size: 13px; overflow-wrap: anywhere; }
.two-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
.form-grid { display: grid; grid-template-columns: 1fr; gap: 15px; }
.field label { display: block; margin-bottom: 7px; color: var(--navy); font-size: 12px; font-weight: 700; }
textarea { min-height: 125px; resize: vertical; line-height: 1.5; }
.help { margin: 7px 0 0; color: var(--muted); font-size: 11px; }
.notice { margin-top: 18px; padding: 12px 14px; border-radius: 8px; font-size: 13px; }
.notice.success { color: var(--success); background: #EAF7F0; border: 1px solid #C8E9D7; }
.notice.error { color: var(--danger); background: #FFF0F1; border: 1px solid #F0CDD2; }
.notice ul { margin: 7px 0 0; padding-left: 20px; }
.data-list { display: flex; flex-direction: column; gap: 12px; }
.data-item { padding: 13px; border: 1px solid var(--border); border-radius: 9px; }
.data-item-top { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.data-item strong { color: var(--navy); font-size: 13px; }
.data-item small { color: var(--muted); font-size: 11px; }
.data-item p { margin: 7px 0 0; color: #526579; font-size: 12px; line-height: 1.55; white-space: pre-wrap; }
.empty { padding: 17px; border-radius: 8px; background: var(--surface); color: var(--muted); font-size: 12px; }
.footer { display: flex; justify-content: space-between; gap: 12px; padding: 28px 0 8px; color: var(--muted); font-size: 11px; }
@media (max-width: 900px) {
    .patient-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .two-columns { grid-template-columns: 1fr; }
}
@media (max-width: 760px) {
    .dashboard-layout { display: block; }
    .sidebar { width: 100%; min-width: 0; min-height: auto; height: auto; position: relative; padding: 18px 16px; }
    .brand { margin: 0 4px 20px; }
    .sidebar-nav { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); }
    .sidebar-bottom { margin-top: 14px; padding-top: 12px; }
    .top-header { padding: 17px 20px; }
    .content { padding: 22px 18px; }
}
@media (max-width: 480px) {
    .sidebar-nav { grid-template-columns: 1fr; }
    .patient-picker { grid-template-columns: 1fr; }
    .patient-summary { grid-template-columns: 1fr 1fr; }
    .profile-info { display: none; }
    .panel { padding: 17px; }
    .footer { flex-direction: column; }
}
</style>
</head>
<body>
<div class="dashboard-layout">
    <aside class="sidebar">
        <a href="dashboard.php" class="brand">
            <span class="brand-icon">+</span>
            <span class="brand-text"><strong>CarePoint</strong><small>Hospital Management</small></span>
        </a>
        <div class="sidebar-section-label">MAIN MENU</div>
        <nav class="sidebar-nav">
            <a class="nav-link" href="dashboard.php"><span class="nav-icon">⌂</span>Dashboard</a>
            <a class="nav-link" href="patients.php"><span class="nav-icon">♧</span>Patients</a>
            <a class="nav-link active" href="consultations.php"><span class="nav-icon">▤</span>Consultations</a>
            <a class="nav-link" href="prescriptions.php"><span class="nav-icon">▧</span>Prescriptions</a>
        </nav>
        <div class="sidebar-bottom">
            <a class="logout-link" href="logout.php"><span class="nav-icon">⇥</span>Sign Out</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-header">
            <div>
                <span class="eyebrow">CAREPOINT MANAGEMENT SYSTEM</span>
                <h1>Doctor Consultations</h1>
            </div>
            <div class="profile">
                <div class="avatar"><?php echo e(strtoupper(substr($firstName, 0, 1))); ?></div>
                <div class="profile-info"><strong><?php echo e($staffName); ?></strong><small>Doctor</small></div>
            </div>
        </header>

        <div class="content">
            <section class="intro">
                <h2>Consultation workspace</h2>
                <p>Find a patient, review the latest nursing observations and record your clinical assessment.</p>
            </section>

            <?php if ($success): ?>
                <div class="notice success"><?php echo e($success); ?></div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="notice error">
                    Please check the following:
                    <ul><?php foreach ($errors as $error): ?><li><?php echo e($error); ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <section class="panel">
                <h3>Find a patient</h3>
                <form method="get" action="consultations.php" class="patient-picker">
                    <select name="patient_id" required>
                        <option value="">Select a patient by patient number or name</option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?php echo (int)$patient["patient_id"]; ?>" <?php echo $selectedPatientId === (int)$patient["patient_id"] ? "selected" : ""; ?>>
                                <?php echo e($patient["patient_number"] . " — " . $patient["first_name"] . " " . $patient["last_name"]); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">View Patient</button>
                </form>

                <?php if ($selectedPatient): ?>
                    <div class="patient-summary">
                        <div class="summary-item"><small>Patient number</small><strong><?php echo e($selectedPatient["patient_number"]); ?></strong></div>
                        <div class="summary-item"><small>Patient name</small><strong><?php echo e($selectedPatient["first_name"] . " " . $selectedPatient["last_name"]); ?></strong></div>
                        <div class="summary-item"><small>Date of birth</small><strong><?php echo e($selectedPatient["date_of_birth"] ?: "Not recorded"); ?></strong></div>
                        <div class="summary-item"><small>Gender</small><strong><?php echo e($selectedPatient["gender"] ?: "Not recorded"); ?></strong></div>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($selectedPatient): ?>
                <div class="two-columns">
                    <section class="panel">
                        <h3>Record consultation</h3>
                        <form method="post" action="consultations.php">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                            <input type="hidden" name="patient_id" value="<?php echo (int)$selectedPatient["patient_id"]; ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="diagnosis">Diagnosis *</label>
                                    <textarea id="diagnosis" name="diagnosis" required maxlength="10000" placeholder="Enter the patient's diagnosis..."><?php echo e($diagnosis); ?></textarea>
                                </div>
                                <div class="field">
                                    <label for="clinical_notes">Clinical notes and treatment plan *</label>
                                    <textarea id="clinical_notes" name="clinical_notes" required maxlength="20000" placeholder="Record examination findings, assessment and treatment plan..."><?php echo e($clinicalNotes); ?></textarea>
                                    <p class="help">Record only information relevant to this patient's consultation.</p>
                                </div>
                                <div><button type="submit">Save Consultation</button></div>
                            </div>
                        </form>
                    </section>

                    <section class="panel">
                        <h3>Latest nursing observations</h3>
                        <?php if ($observations): ?>
                            <div class="data-list">
                                <?php foreach ($observations as $observation): ?>
                                    <article class="data-item">
                                        <div class="data-item-top">
                                            <strong><?php echo e(date("d M Y, H:i", strtotime($observation["recorded_at"]))); ?></strong>
                                            <small>Nurse: <?php echo e($observation["nurse_name"] ?: "Staff member"); ?></small>
                                        </div>
                                        <p>
                                            Blood pressure: <?php echo e($observation["blood_pressure"] ?: "Not recorded"); ?><br>
                                            Temperature: <?php echo e($observation["temperature"] !== null ? $observation["temperature"] . " °C" : "Not recorded"); ?><br>
                                            Pulse: <?php echo e($observation["pulse_rate"] !== null ? $observation["pulse_rate"] . " bpm" : "Not recorded"); ?><br>
                                            Weight: <?php echo e($observation["weight"] !== null ? $observation["weight"] . " kg" : "Not recorded"); ?>
                                        </p>
                                        <?php if (!empty($observation["notes"])): ?><p><?php echo e($observation["notes"]); ?></p><?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty">No nursing observations have been recorded for this patient yet.</div>
                        <?php endif; ?>
                    </section>
                </div>

                <section class="panel">
                    <h3>Previous consultations</h3>
                    <?php if ($consultations): ?>
                        <div class="data-list">
                            <?php foreach ($consultations as $consultation): ?>
                                <article class="data-item">
                                    <div class="data-item-top">
                                        <strong><?php echo e(date("d M Y, H:i", strtotime($consultation["consultation_date"]))); ?></strong>
                                        <small>Doctor: <?php echo e($consultation["doctor_name"] ?: "Staff member"); ?></small>
                                    </div>
                                    <p><strong>Diagnosis:</strong> <?php echo e($consultation["diagnosis"]); ?></p>
                                    <p><strong>Clinical notes:</strong> <?php echo e($consultation["clinical_notes"]); ?></p>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">No previous consultations are recorded for this patient.</div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="panel">
                    <h3>Patient clinical information</h3>
                    <div class="empty">Select a patient above to review their nursing observations and consultation history, or to record a new consultation.</div>
                </section>
            <?php endif; ?>

            <footer class="footer">
                <span>© <?php echo date("Y"); ?> CarePoint Hospital Management System.</span>
                <span>Healthcare made organised.</span>
            </footer>
        </div>
    </main>
</div>
</body>
</html>
