
<?php
session_start();
date_default_timezone_set('Africa/Johannesburg');

require_once "db.php";

// Only nurses may access this page
if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true ||
    $_SESSION["staff_role"] !== "nurse"
) {
    header("Location: index.php");
    exit;
}

$nurse_id = (int) $_SESSION["staff_id"];
$nurse_name = $_SESSION["staff_name"];

// CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";

// Accept a patient file
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accept_file"])) {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $message = "Your session has expired. Please refresh the page.";
        $message_type = "error";
    } else {
        $queue_id = (int) $_POST["queue_id"];

        try {
            $stmt = $pdo->prepare("
                UPDATE patient_queue
                SET
                    assigned_nurse = ?,
                    file_status = 'received',
                    file_received_at = NOW()
                WHERE
                    queue_id = ?
                    AND queue_date = CURDATE()
                    AND file_status = 'sent'
                    AND assigned_nurse IS NULL
                    AND status NOT IN ('cancelled', 'completed')
            ");

            $stmt->execute([$nurse_id, $queue_id]);

            if ($stmt->rowCount() === 1) {
                $message = "Patient file accepted successfully.";
                $message_type = "success";
            } else {
                $message = "This file has already been accepted or is no longer available.";
                $message_type = "error";
            }

        } catch (PDOException $e) {
            error_log("Nurse inbox error: " . $e->getMessage());
            $message = "Unable to accept the patient file. Please try again.";
            $message_type = "error";
        }
    }
}

// Incoming files available for nurses
$incoming_stmt = $pdo->prepare("
    SELECT
        q.queue_id,
        q.queue_number,
        q.status,
        q.file_sent_at,
        p.patient_number,
        p.first_name,
        p.last_name,
        p.gender
    FROM patient_queue q
    INNER JOIN patients p ON q.patient_id = p.patient_id
    WHERE
        q.queue_date = CURDATE()
        AND q.file_status = 'sent'
        AND q.assigned_nurse IS NULL
        AND q.status NOT IN ('cancelled', 'completed')
    ORDER BY q.queue_number ASC
");
$incoming_stmt->execute();
$incoming_files = $incoming_stmt->fetchAll();

// Files accepted by this nurse
$my_stmt = $pdo->prepare("
    SELECT
        q.queue_id,
        q.queue_number,
        q.status,
        q.doctor_status,
        p.patient_number,
        p.first_name,
        p.last_name,
        p.gender
    FROM patient_queue q
    INNER JOIN patients p ON q.patient_id = p.patient_id
    WHERE
        q.queue_date = CURDATE()
        AND q.assigned_nurse = ?
        AND q.file_status = 'received'
    ORDER BY q.queue_number ASC
");
$my_stmt->execute([$nurse_id]);
$my_files = $my_stmt->fetchAll();

// Files accepted by other nurses
$team_stmt = $pdo->prepare("
    SELECT
        q.queue_number,
        q.status,
        q.file_received_at,
        p.patient_number,
        p.first_name,
        p.last_name,
        s.full_name AS nurse_name
    FROM patient_queue q
    INNER JOIN patients p ON q.patient_id = p.patient_id
    LEFT JOIN staff s ON q.assigned_nurse = s.staff_id
    WHERE
        q.queue_date = CURDATE()
        AND q.file_status = 'received'
        AND q.assigned_nurse IS NOT NULL
        AND q.assigned_nurse != ?
    ORDER BY q.queue_number ASC
");
$team_stmt->execute([$nurse_id]);
$team_files = $team_stmt->fetchAll();

// Counts
$total_incoming = count($incoming_files);
$total_mine = count($my_files);
$total_team = count($team_files);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Nurse Inbox | CarePoint</title>

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
        }

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
            z-index: 100;
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
            transition: 0.2s;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #3979B9;
            color: white;
        }

        .sidebar-bottom {
            margin-top: auto;
        }

        .sidebar-user {
            border-top: 1px solid rgba(255,255,255,0.2);
            padding-top: 18px;
            margin-bottom: 15px;
        }

        .sidebar-user strong {
            display: block;
            font-size: 14px;
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
            background: rgba(255,255,255,0.1);
            font-size: 14px;
        }

        .logout-link:hover {
            background: #3979B9;
        }

        .main-content {
            margin-left: 250px;
            width: calc(100% - 250px);
            min-height: 100vh;
            padding: 35px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 28px;
            color: #163B65;
            margin-bottom: 7px;
        }

        .page-header p {
            color: #718096;
            font-size: 14px;
        }

        .date-label {
            background: white;
            padding: 12px 18px;
            border-radius: 8px;
            color: #3979B9;
            font-size: 13px;
            font-weight: bold;
            box-shadow: 0 3px 12px rgba(22,59,101,0.05);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(22,59,101,0.06);
            border: 1px solid #DFEAF4;
        }

        .stat-card p {
            color: #718096;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .stat-card h2 {
            color: #163B65;
            font-size: 30px;
        }

        .section-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(22,59,101,0.05);
            border: 1px solid #DFEAF4;
            min-width: 0;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .section-header h2 {
            color: #163B65;
            font-size: 19px;
        }

        .section-header span {
            color: #718096;
            font-size: 13px;
        }

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th {
            background: #F2F7FC;
            color: #52657A;
            text-align: left;
            padding: 14px 12px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        td {
            padding: 15px 12px;
            border-bottom: 1px solid #E8EEF5;
            font-size: 13px;
            color: #35485D;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .patient-name {
            color: #163B65;
            font-weight: bold;
        }

        .patient-number {
            color: #718096;
            font-size: 12px;
            margin-top: 4px;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: capitalize;
            background: #EAF4FC;
            color: #3979B9;
        }

        .status.sent {
            background: #FFF4DC;
            color: #946200;
        }

        .status.received {
            background: #E4F5EB;
            color: #267447;
        }

        .status.not_sent {
            background: #F0F1F3;
            color: #596579;
        }

        .accept-btn {
            border: none;
            background: #3979B9;
            color: white;
            padding: 9px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
        }

        .accept-btn:hover {
            background: #163B65;
        }

        .open-btn {
            display: inline-block;
            background: #EAF4FC;
            color: #245B91;
            text-decoration: none;
            padding: 9px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: bold;
        }

        .open-btn:hover {
            background: #D5E9F9;
        }

        .message {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 22px;
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

        .empty-state {
            text-align: center;
            padding: 35px 15px;
            color: #718096;
            font-size: 14px;
        }

        .empty-state strong {
            display: block;
            color: #163B65;
            margin-bottom: 8px;
            font-size: 15px;
        }

        @media (max-width: 900px) {
            .sidebar {
                width: 210px;
            }

            .main-content {
                margin-left: 210px;
                width: calc(100% - 210px);
                padding: 25px;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 650px) {
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .section-card {
                padding: 17px;
            }

            .page-header h1 {
                font-size: 23px;
            }
        }
    </style>
</head>

<body>

<!-- Sidebar -->
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
            <strong><?php echo htmlspecialchars($nurse_name); ?></strong>
            <small>Nurse Account</small>
        </div>

        <a href="logout.php" class="logout-link">Log out</a>
    </div>

</aside>

<!-- Main Content -->
<main class="main-content">

    <header class="page-header">
        <div>
            <h1>Nurse Inbox</h1>
            <p>Receive and manage patient files sent by administration.</p>
        </div>

        <div class="date-label">
            <?php echo date("d F Y"); ?>
        </div>
    </header>

    <?php if ($message !== ""): ?>
        <div class="message <?php echo htmlspecialchars($message_type); ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <section class="stats-grid">

        <div class="stat-card">
            <p>Available Patient Files</p>
            <h2><?php echo $total_incoming; ?></h2>
        </div>

        <div class="stat-card">
            <p>My Accepted Files</p>
            <h2><?php echo $total_mine; ?></h2>
        </div>

        <div class="stat-card">
            <p>Files With Other Nurses</p>
            <h2><?php echo $total_team; ?></h2>
        </div>

    </section>

    <!-- Incoming Files -->
    <section class="section-card">

        <div class="section-header">
            <h2>Incoming Patient Files</h2>
            <span>Files waiting for a nurse to accept</span>
        </div>

        <?php if (count($incoming_files) > 0): ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Queue Number</th>
                            <th>Patient</th>
                            <th>Patient ID</th>
                            <th>Gender</th>
                            <th>File Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($incoming_files as $file): ?>
                            <tr>
                                <td>
                                    <strong>
                                        Q<?php echo str_pad($file["queue_number"], 3, "0", STR_PAD_LEFT); ?>
                                    </strong>
                                </td>

                                <td class="patient-name">
                                    <?php
                                    echo htmlspecialchars(
                                        $file["first_name"] . " " . $file["last_name"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($file["patient_number"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars(ucfirst($file["gender"] ?? "Not specified")); ?>
                                </td>

                                <td>
                                    <span class="status sent">Waiting</span>
                                </td>

                                <td>
                                    <form method="POST" action="nurse_inbox.php">
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="queue_id"
                                            value="<?php echo (int) $file["queue_id"]; ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="accept_file"
                                            class="accept-btn"
                                        >
                                            Accept File
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <strong>No incoming files</strong>
                There are currently no patient files waiting to be accepted.
            </div>

        <?php endif; ?>

    </section>

    <!-- My Files -->
    <section class="section-card">

        <div class="section-header">
            <h2>My Accepted Patient Files</h2>
            <span>Patients assigned to you</span>
        </div>

        <?php if (count($my_files) > 0): ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Queue Number</th>
                            <th>Patient</th>
                            <th>Patient ID</th>
                            <th>Queue Status</th>
                            <th>Doctor Handover</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($my_files as $file): ?>
                            <tr>
                                <td>
                                    <strong>
                                        Q<?php echo str_pad($file["queue_number"], 3, "0", STR_PAD_LEFT); ?>
                                    </strong>
                                </td>

                                <td class="patient-name">
                                    <?php
                                    echo htmlspecialchars(
                                        $file["first_name"] . " " . $file["last_name"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($file["patient_number"]); ?>
                                </td>

                                <td>
                                    <span class="status">
                                        <?php echo htmlspecialchars(str_replace("_", " ", $file["status"])); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="status <?php echo htmlspecialchars($file["doctor_status"]); ?>">
                                        <?php echo htmlspecialchars(str_replace("_", " ", $file["doctor_status"])); ?>
                                    </span>
                                </td>

                                <td>
                                    <a
                                        class="open-btn"
                                        href="open_patient_file.php?queue_id=<?php echo (int) $file["queue_id"]; ?>"
                                    >
                                        Open File
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <strong>No accepted files yet</strong>
                Accept an incoming patient file to see it here.
            </div>

        <?php endif; ?>

    </section>

    <!-- Team Files -->
    <section class="section-card">

        <div class="section-header">
            <h2>Files Accepted by Other Nurses</h2>
            <span>For team visibility</span>
        </div>

        <?php if (count($team_files) > 0): ?>

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Queue Number</th>
                            <th>Patient</th>
                            <th>Patient ID</th>
                            <th>Assigned Nurse</th>
                            <th>Queue Status</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($team_files as $file): ?>
                            <tr>
                                <td>
                                    <strong>
                                        Q<?php echo str_pad($file["queue_number"], 3, "0", STR_PAD_LEFT); ?>
                                    </strong>
                                </td>

                                <td class="patient-name">
                                    <?php
                                    echo htmlspecialchars(
                                        $file["first_name"] . " " . $file["last_name"]
                                    );
                                    ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($file["patient_number"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($file["nurse_name"] ?? "Unknown"); ?>
                                </td>

                                <td>
                                    <span class="status">
                                        <?php echo htmlspecialchars(str_replace("_", " ", $file["status"])); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>

            <div class="empty-state">
                <strong>No other nurse files</strong>
                No other nurses have accepted files today.
            </div>

        <?php endif; ?>

    </section>

</main>

</body>
</html>
