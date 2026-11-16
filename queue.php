
<?php
session_start();
date_default_timezone_set('Africa/Johannesburg');

require_once "db.php";

// --------------------------------------
// AUTHENTICATION
// --------------------------------------

if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true
) {
    header("Location: index.php");
    exit();
}

$staffId = (int)($_SESSION["staff_id"] ?? 0);
$staffName = $_SESSION["staff_name"] ?? "Staff Member";
$staffRole = strtolower($_SESSION["staff_role"] ?? "");

if (!in_array($staffRole, ["administrator", "nurse"], true)) {
    http_response_code(403);
    exit("You are not authorised to access this page.");
}

$isAdmin = ($staffRole === "administrator");
$isNurse = ($staffRole === "nurse");

// --------------------------------------
// SECURITY AND HELPER FUNCTIONS
// --------------------------------------

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function redirectQueue() {
    header("Location: queue.php");
    exit();
}

function setFlash($type, $message) {
    $_SESSION["queue_flash"] = [
        "type" => $type,
        "message" => $message
    ];
}

// --------------------------------------
// HANDLE FORM SUBMISSIONS
// --------------------------------------

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION["csrf_token"], $submittedToken)
    ) {
        http_response_code(403);
        exit("Invalid security token. Please refresh the page and try again.");
    }

    $action = $_POST["action"] ?? "";

    // ----------------------------------
    // ADMIN: ADD PATIENT TO QUEUE
    // ----------------------------------

    if ($action === "add_to_queue") {

        if (!$isAdmin) {
            http_response_code(403);
            exit("Only administrators can add patients to the queue.");
        }

        $patientId = filter_var(
            $_POST["patient_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$patientId || $patientId < 1) {
            setFlash("error", "Please select a valid patient.");
            redirectQueue();
        }

        $lockName = "carepoint_queue_" . date("Ymd");
        $lockAcquired = false;

        try {

            // Prevent two administrators receiving the same queue number.
            $lockStmt = $pdo->prepare(
                "SELECT GET_LOCK(:lock_name, 10)"
            );

            $lockStmt->execute([
                "lock_name" => $lockName
            ]);

            $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);

            if (!$lockAcquired) {
                throw new RuntimeException(
                    "The queue is busy. Please try again."
                );
            }

            $pdo->beginTransaction();

            // Confirm that the patient exists.
            $patientStmt = $pdo->prepare(
                "SELECT patient_id
                 FROM patients
                 WHERE patient_id = :patient_id
                 LIMIT 1"
            );

            $patientStmt->execute([
                "patient_id" => $patientId
            ]);

            if (!$patientStmt->fetch()) {
                throw new RuntimeException("The selected patient was not found.");
            }

            $today = date("Y-m-d");

            // Prevent duplicate active queue entries for the same day.
            $duplicateStmt = $pdo->prepare(
                "SELECT queue_id
                 FROM patient_queue
                 WHERE patient_id = :patient_id
                   AND queue_date = :queue_date
                   AND status IN ('waiting', 'called', 'in_progress')
                 LIMIT 1"
            );

            $duplicateStmt->execute([
                "patient_id" => $patientId,
                "queue_date" => $today
            ]);

            if ($duplicateStmt->fetch()) {
                throw new RuntimeException(
                    "This patient is already in today's active queue."
                );
            }

            // Get the next queue number for today.
            $numberStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(queue_number), 0) + 1
                 FROM patient_queue
                 WHERE queue_date = :queue_date"
            );

            $numberStmt->execute([
                "queue_date" => $today
            ]);

            $queueNumber = (int)$numberStmt->fetchColumn();

            // Add the patient and send the file to the nursing inbox.
            $insertStmt = $pdo->prepare(
                "INSERT INTO patient_queue
                    (
                        patient_id,
                        queue_number,
                        queue_date,
                        status,
                        created_by,
                        file_status,
                        assigned_nurse,
                        file_sent_at
                    )
                 VALUES
                    (
                        :patient_id,
                        :queue_number,
                        :queue_date,
                        'waiting',
                        :created_by,
                        'sent',
                        NULL,
                        NOW()
                    )"
            );

            $insertStmt->execute([
                "patient_id" => $patientId,
                "queue_number" => $queueNumber,
                "queue_date" => $today,
                "created_by" => $staffId
            ]);

            $queueId = (int)$pdo->lastInsertId();

            $pdo->commit();

            $_SESSION["last_queue_ticket"] = $queueId;

            setFlash(
                "success",
                "Patient added successfully. Queue number: Q" .
                str_pad((string)$queueNumber, 3, "0", STR_PAD_LEFT) .
                ". Their file has been sent to the nursing inbox."
            );

        } catch (Throwable $ex) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Queue registration error: " . $ex->getMessage());

            setFlash(
                "error",
                $ex instanceof RuntimeException
                    ? $ex->getMessage()
                    : "Unable to add the patient to the queue. Please try again."
            );

        } finally {

            if ($lockAcquired) {
                try {
                    $releaseStmt = $pdo->prepare(
                        "SELECT RELEASE_LOCK(:lock_name)"
                    );

                    $releaseStmt->execute([
                        "lock_name" => $lockName
                    ]);
                } catch (PDOException $ex) {
                    error_log("Queue lock release error: " . $ex->getMessage());
                }
            }
        }

        redirectQueue();
    }

    // ----------------------------------
    // NURSE: UPDATE OWN ASSIGNED PATIENT
    // ----------------------------------

    if ($action === "update_status") {

        if (!$isNurse) {
            http_response_code(403);
            exit("Only nurses can update patient queue status.");
        }

        $queueId = filter_var(
            $_POST["queue_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        $newStatus = $_POST["status"] ?? "";

        $allowedStatuses = [
            "called",
            "in_progress",
            "completed"
        ];

        if (
            !$queueId ||
            $queueId < 1 ||
            !in_array($newStatus, $allowedStatuses, true)
        ) {
            setFlash("error", "Invalid queue update.");
            redirectQueue();
        }

        try {

            // A nurse can only update a file they have accepted.
            $updateStmt = $pdo->prepare(
                "UPDATE patient_queue
                 SET status = :status
                 WHERE queue_id = :queue_id
                   AND assigned_nurse = :nurse_id
                   AND file_status = 'received'
                   AND status IN ('waiting', 'called', 'in_progress')"
            );

            $updateStmt->execute([
                "status" => $newStatus,
                "queue_id" => $queueId,
                "nurse_id" => $staffId
            ]);

            if ($updateStmt->rowCount() > 0) {
                setFlash("success", "Patient queue status updated.");
            } else {
                setFlash(
                    "error",
                    "The patient could not be updated. Check that you have accepted this file."
                );
            }

        } catch (PDOException $ex) {

            error_log("Queue status update error: " . $ex->getMessage());

            setFlash("error", "Unable to update the queue status.");
        }

        redirectQueue();
    }

    // ----------------------------------
    // ADMIN: CANCEL QUEUE ENTRY
    // ----------------------------------

    if ($action === "cancel_queue") {

        if (!$isAdmin) {
            http_response_code(403);
            exit("Only administrators can cancel queue entries.");
        }

        $queueId = filter_var(
            $_POST["queue_id"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$queueId || $queueId < 1) {
            setFlash("error", "Invalid queue entry.");
            redirectQueue();
        }

        try {

            $cancelStmt = $pdo->prepare(
                "UPDATE patient_queue
                 SET status = 'cancelled'
                 WHERE queue_id = :queue_id
                   AND status IN ('waiting', 'called', 'in_progress')"
            );

            $cancelStmt->execute([
                "queue_id" => $queueId
            ]);

            if ($cancelStmt->rowCount() > 0) {
                setFlash("success", "Queue entry cancelled.");
            } else {
                setFlash("error", "This queue entry could not be cancelled.");
            }

        } catch (PDOException $ex) {

            error_log("Queue cancellation error: " . $ex->getMessage());

            setFlash("error", "Unable to cancel this queue entry.");
        }

        redirectQueue();
    }

    http_response_code(400);
    exit("Unknown queue action.");
}

// --------------------------------------
// FLASH MESSAGE
// --------------------------------------

$flash = $_SESSION["queue_flash"] ?? null;
unset($_SESSION["queue_flash"]);

// --------------------------------------
// PATIENT SEARCH FOR ADMIN
// --------------------------------------

$patientSearch = trim($_GET["patient_search"] ?? "");
$patientResults = [];

if ($isAdmin && $patientSearch !== "") {

    $searchTerm = "%" . $patientSearch . "%";

    try {

        $patientStmt = $pdo->prepare(
            "SELECT
                patient_id,
                patient_number,
                national_id,
                first_name,
                last_name,
                phone
             FROM patients
             WHERE patient_number LIKE :patient_number
                OR national_id LIKE :national_id
                OR first_name LIKE :first_name
                OR last_name LIKE :last_name
                OR phone LIKE :phone
             ORDER BY first_name ASC, last_name ASC
             LIMIT 50"
        );

        $patientStmt->execute([
            "patient_number" => $searchTerm,
            "national_id" => $searchTerm,
            "first_name" => $searchTerm,
            "last_name" => $searchTerm,
            "phone" => $searchTerm
        ]);

        $patientResults = $patientStmt->fetchAll();

    } catch (PDOException $ex) {
        error_log("Patient search error: " . $ex->getMessage());
    }
}

// --------------------------------------
// TODAY'S QUEUE
// --------------------------------------

$today = date("Y-m-d");
$queueRecords = [];

try {

    $queueStmt = $pdo->prepare(
        "SELECT
            q.queue_id,
            q.queue_number,
            q.queue_date,
            q.status,
            q.file_status,
            q.assigned_nurse,
            q.file_sent_at,
            q.file_received_at,
            p.patient_id,
            p.patient_number,
            p.first_name,
            p.last_name,
            p.phone,
            s.full_name AS nurse_name
         FROM patient_queue q
         INNER JOIN patients p
            ON q.patient_id = p.patient_id
         LEFT JOIN staff s
            ON q.assigned_nurse = s.staff_id
         WHERE q.queue_date = :queue_date
         ORDER BY q.queue_number ASC"
    );

    $queueStmt->execute([
        "queue_date" => $today
    ]);

    $queueRecords = $queueStmt->fetchAll();

} catch (PDOException $ex) {

    error_log("Queue display error: " . $ex->getMessage());

    $flash = [
        "type" => "error",
        "message" => "Unable to load today's queue."
    ];
}

// --------------------------------------
// QUEUE SUMMARY
// --------------------------------------

$summary = [
    "total" => count($queueRecords),
    "waiting" => 0,
    "in_progress" => 0,
    "completed" => 0
];

foreach ($queueRecords as $record) {

    if ($record["status"] === "waiting" || $record["status"] === "called") {
        $summary["waiting"]++;
    }

    if ($record["status"] === "in_progress") {
        $summary["in_progress"]++;
    }

    if ($record["status"] === "completed") {
        $summary["completed"]++;
    }
}

// --------------------------------------
// DISPLAY HELPERS
// --------------------------------------

function queueStatusLabel($status) {

    $labels = [
        "waiting" => "Waiting",
        "called" => "Called",
        "in_progress" => "In Progress",
        "completed" => "Completed",
        "cancelled" => "Cancelled"
    ];

    return $labels[$status] ?? ucfirst($status);
}

function fileStatusLabel($status) {

    $labels = [
        "sent" => "Sent to Nursing",
        "received" => "Accepted by Nurse"
    ];

    return $labels[$status] ?? ucfirst($status);
}

function queueNumberLabel($number) {
    return "Q" . str_pad((string)$number, 3, "0", STR_PAD_LEFT);
}

function statusClass($status) {
    return preg_replace('/[^a-z_]/', '', strtolower($status));
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CarePoint | Queue Management</title>

    <link rel="stylesheet" href="dashboard.css?v=6">
    <link rel="stylesheet" href="queue.css?v=2">
</head>

<body>

<div class="queue-page">

    <!-- PAGE HEADER -->

    <div class="queue-header">

        <div>
            <span class="page-eyebrow">CAREPOINT MANAGEMENT SYSTEM</span>

            <h1>Queue Management</h1>

            <p>
                Manage patient waiting lists and electronic file transfers.
            </p>
        </div>

        <div class="queue-header-actions">

            <span class="queue-date">
                <?php echo e(date("d F Y")); ?>
            </span>

            <?php if ($isNurse): ?>
                <a href="nurse_inbox.php" class="queue-primary-button">
                    View Incoming Files
                </a>
            <?php endif; ?>

            <a href="dashboard.php" class="queue-secondary-button">
                Dashboard
            </a>

        </div>

    </div>


    <!-- NOTIFICATIONS -->

    <?php if ($flash): ?>

        <div class="queue-notice <?php echo e($flash["type"]); ?>">
            <?php echo e($flash["message"]); ?>
        </div>

    <?php endif; ?>


    <!-- SUMMARY CARDS -->

    <div class="queue-summary-grid">

        <div class="queue-summary-card">
            <span class="summary-label">TOTAL PATIENTS</span>
            <strong><?php echo number_format($summary["total"]); ?></strong>
            <small>Today's queue entries</small>
        </div>

        <div class="queue-summary-card">
            <span class="summary-label">WAITING</span>
            <strong><?php echo number_format($summary["waiting"]); ?></strong>
            <small>Waiting or called</small>
        </div>

        <div class="queue-summary-card">
            <span class="summary-label">IN PROGRESS</span>
            <strong><?php echo number_format($summary["in_progress"]); ?></strong>
            <small>Currently receiving attention</small>
        </div>

        <div class="queue-summary-card">
            <span class="summary-label">COMPLETED</span>
            <strong><?php echo number_format($summary["completed"]); ?></strong>
            <small>Completed today</small>
        </div>

    </div>


    <!-- ADMIN: PATIENT SEARCH AND QUEUE REGISTRATION -->

    <?php if ($isAdmin): ?>

        <section class="queue-panel">

            <div class="queue-panel-heading">
                <div>
                    <h2>Add Patient to Queue</h2>
                    <p>Search for an existing patient before adding them.</p>
                </div>
            </div>

            <form method="GET" action="queue.php" class="queue-search-form">

                <div class="queue-search-field">
                    <label for="patient_search">Search Patient</label>

                    <input
                        type="text"
                        id="patient_search"
                        name="patient_search"
                        value="<?php echo e($patientSearch); ?>"
                        placeholder="Enter patient number, ID, name or phone"
                        required
                    >
                </div>

                <button type="submit" class="queue-primary-button">
                    Search Patients
                </button>

                <?php if ($patientSearch !== ""): ?>
                    <a href="queue.php" class="queue-secondary-button">
                        Clear
                    </a>
                <?php endif; ?>

            </form>


            <?php if ($patientSearch !== ""): ?>

                <div class="queue-search-results">

                    <h3>Search Results</h3>

                    <?php if (count($patientResults) > 0): ?>

                        <div class="queue-table-wrapper">

                            <table class="queue-table">

                                <thead>
                                    <tr>
                                        <th>Patient Number</th>
                                        <th>Patient Name</th>
                                        <th>Contact Number</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>

                                <tbody>

                                    <?php foreach ($patientResults as $patient): ?>

                                        <tr>

                                            <td>
                                                <strong>
                                                    <?php echo e($patient["patient_number"]); ?>
                                                </strong>
                                            </td>

                                            <td>
                                                <?php
                                                echo e(
                                                    $patient["first_name"] . " " .
                                                    $patient["last_name"]
                                                );
                                                ?>
                                            </td>

                                            <td>
                                                <?php echo e($patient["phone"] ?: "Not provided"); ?>
                                            </td>

                                            <td>

                                                <form method="POST" action="queue.php">

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?php echo e($_SESSION["csrf_token"]); ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="add_to_queue"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="patient_id"
                                                        value="<?php echo (int)$patient["patient_id"]; ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="queue-add-button"
                                                    >
                                                        Add to Queue
                                                    </button>

                                                </form>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    <?php else: ?>

                        <div class="queue-empty">
                            No matching patients found.
                            Check the details or register the patient first.
                        </div>

                    <?php endif; ?>

                </div>

            <?php endif; ?>

        </section>

    <?php endif; ?>


    <!-- TODAY'S QUEUE TABLE -->

    <section class="queue-panel">

        <div class="queue-panel-heading">

            <div>
                <h2>Today's Patient Queue</h2>
                <p>Patient order and electronic file status.</p>
            </div>

            <span class="queue-count">
                <?php echo number_format($summary["total"]); ?> Records
            </span>

        </div>


        <?php if (count($queueRecords) > 0): ?>

            <div class="queue-table-wrapper">

                <table class="queue-table">

                    <thead>

                        <tr>
                            <th>Queue No.</th>
                            <th>Patient</th>
                            <th>Patient Number</th>
                            <th>Queue Status</th>
                            <th>File Status</th>
                            <th>Assigned Nurse</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($queueRecords as $record): ?>

                            <tr>

                                <td>
                                    <span class="queue-number">
                                        <?php echo e(queueNumberLabel($record["queue_number"])); ?>
                                    </span>
                                </td>

                                <td>
                                    <strong>
                                        <?php
                                        echo e(
                                            $record["first_name"] . " " .
                                            $record["last_name"]
                                        );
                                        ?>
                                    </strong>
                                </td>

                                <td>
                                    <?php echo e($record["patient_number"]); ?>
                                </td>

                                <td>
                                    <span class="status-badge <?php echo e(statusClass($record["status"])); ?>">
                                        <?php echo e(queueStatusLabel($record["status"])); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="file-status-badge <?php echo e(statusClass($record["file_status"])); ?>">
                                        <?php echo e(fileStatusLabel($record["file_status"])); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php
                                    echo e($record["nurse_name"] ?: "Awaiting acceptance");
                                    ?>
                                </td>

                                <td class="queue-actions">

                                    <?php if ($isNurse && (int)$record["assigned_nurse"] === $staffId): ?>

                                        <?php if ($record["status"] === "waiting"): ?>

                                            <form method="POST" action="queue.php">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION["csrf_token"]); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="queue_id" value="<?php echo (int)$record["queue_id"]; ?>">
                                                <input type="hidden" name="status" value="called">

                                                <button type="submit" class="queue-action-button">
                                                    Mark Called
                                                </button>
                                            </form>

                                        <?php elseif ($record["status"] === "called"): ?>

                                            <form method="POST" action="queue.php">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION["csrf_token"]); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="queue_id" value="<?php echo (int)$record["queue_id"]; ?>">
                                                <input type="hidden" name="status" value="in_progress">

                                                <button type="submit" class="queue-action-button">
                                                    Start Visit
                                                </button>
                                            </form>

                                        <?php elseif ($record["status"] === "in_progress"): ?>

                                            <form method="POST" action="queue.php">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION["csrf_token"]); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="queue_id" value="<?php echo (int)$record["queue_id"]; ?>">
                                                <input type="hidden" name="status" value="completed">

                                                <button type="submit" class="queue-action-button complete-button">
                                                    Complete
                                                </button>
                                            </form>

                                        <?php else: ?>

                                            <span class="action-muted">No action</span>

                                        <?php endif; ?>

                                    <?php elseif ($isAdmin && in_array($record["status"], ["waiting", "called", "in_progress"], true)): ?>

                                        <form method="POST" action="queue.php"
                                              onsubmit="return confirm('Cancel this queue entry?');">

                                            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION["csrf_token"]); ?>">
                                            <input type="hidden" name="action" value="cancel_queue">
                                            <input type="hidden" name="queue_id" value="<?php echo (int)$record["queue_id"]; ?>">

                                            <button type="submit" class="queue-cancel-button">
                                                Cancel
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="action-muted">No action</span>

                                    <?php endif; ?>

                                    <a
                                        href="print_queue_ticket.php?queue_id=<?php echo (int)$record["queue_id"]; ?>"
                                        target="_blank"
                                        class="queue-print-link"
                                    >
                                        Print
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="queue-empty">
                <h3>No patients in today's queue</h3>
                <p>
                    <?php if ($isAdmin): ?>
                        Search for a registered patient above to add them to the queue.
                    <?php else: ?>
                        There are currently no queue entries for today.
                    <?php endif; ?>
                </p>
            </div>

        <?php endif; ?>

    </section>


    <!-- PAGE FOOTER -->

    <footer class="queue-footer">
        <span>© <?php echo date("Y"); ?> CarePoint Hospital Management System.</span>
        <a href="dashboard.php">Back to Dashboard</a>
    </footer>

</div>

</body>
</html>
