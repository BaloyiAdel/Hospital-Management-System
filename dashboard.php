
<?php
session_start();
date_default_timezone_set('Africa/Johannesburg');

require_once "db.php";

// Authentication
if (
    !isset($_SESSION["logged_in"]) ||
    $_SESSION["logged_in"] !== true
) {
    header("Location: index.php");
    exit();
}

$staffName = $_SESSION["staff_name"] ?? "Staff Member";
$staffRole = strtolower($_SESSION["staff_role"] ?? "");

// Safe output
function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

// Role-specific navigation
$navigation = [
    "administrator" => [
        ["title" => "Dashboard", "link" => "dashboard.php", "icon" => "home"],
        ["title" => "Patients", "link" => "patients.php", "icon" => "users"],
        ["title" => "Staff Management", "link" => "staff.php", "icon" => "user-cog"],
        ["title" => "Queue Management", "link" => "queue.php", "icon" => "list"],
    ],

    "nurse" => [
        ["title" => "Dashboard", "link" => "dashboard.php", "icon" => "home"],
        ["title" => "Patients", "link" => "patients.php", "icon" => "users"],
        ["title" => "Queue", "link" => "queue.php", "icon" => "list"],
        ["title" => "Incoming Files", "link" => "nurse_inbox.php", "icon" => "inbox"],
        ["title" => "Observations", "link" => "observations.php", "icon" => "activity"],
    ],

    "doctor" => [
        ["title" => "Dashboard", "link" => "dashboard.php", "icon" => "home"],
        ["title" => "Patients", "link" => "patients.php", "icon" => "users"],
        ["title" => "Consultations", "link" => "consultations.php", "icon" => "clipboard"],
        ["title" => "Prescriptions", "link" => "prescriptions.php", "icon" => "file-text"],
    ],

    "pharmacist" => [
        ["title" => "Dashboard", "link" => "dashboard.php", "icon" => "home"],
        ["title" => "Prescriptions", "link" => "prescriptions.php", "icon" => "file-text"],
        ["title" => "Medicines", "link" => "medicines.php", "icon" => "pill"],
        ["title" => "Dispensing", "link" => "dispensing.php", "icon" => "package"],
    ]
];

if (!isset($navigation[$staffRole])) {
    http_response_code(403);
    exit("Access denied.");
}

$roleLabels = [
    "administrator" => "Administrator",
    "nurse" => "Nurse",
    "doctor" => "Doctor",
    "pharmacist" => "Pharmacist"
];

$roleLabel = $roleLabels[$staffRole];

// Database statistics
$statistics = [
    "patients" => 0,
    "queue" => 0,
    "prescriptions" => 0,
    "medicines" => 0
];

try {
    // Registered patients
    $stmt = $pdo->query("SELECT COUNT(*) FROM patients");
    $statistics["patients"] = (int)$stmt->fetchColumn();

    // Today's queue
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM patient_queue
         WHERE queue_date = ?
         AND status NOT IN ('cancelled', 'completed')"
    );
    $stmt->execute([date("Y-m-d")]);
    $statistics["queue"] = (int)$stmt->fetchColumn();

    // Prescriptions
    $stmt = $pdo->query("SELECT COUNT(*) FROM prescriptions");
    $statistics["prescriptions"] = (int)$stmt->fetchColumn();

    // Available medicine types
    $stmt = $pdo->query("SELECT COUNT(*) FROM medicines");
    $statistics["medicines"] = (int)$stmt->fetchColumn();

} catch (PDOException $ex) {
    error_log("Dashboard statistics error: " . $ex->getMessage());
}

// Role-specific quick actions
$quickActions = [
    "administrator" => [
        ["title" => "Register Patient", "description" => "Create a new patient record.", "link" => "patients.php", "icon" => "user-plus"],
        ["title" => "Manage Queue", "description" => "Add patients and send files to nurses.", "link" => "queue.php", "icon" => "list"],
        ["title" => "Manage Staff", "description" => "Manage hospital staff accounts.", "link" => "staff.php", "icon" => "users"],
    ],

    "nurse" => [
        ["title" => "Incoming Files", "description" => "View and accept patient files.", "link" => "nurse_inbox.php", "icon" => "inbox"],
        ["title" => "Patient Records", "description" => "Search authorised patient records.", "link" => "patients.php", "icon" => "users"],
        ["title" => "Record Observations", "description" => "Record patient vital signs.", "link" => "observations.php", "icon" => "activity"],
    ],

    "doctor" => [
        ["title" => "View Patients", "description" => "Review patient records.", "link" => "patients.php", "icon" => "users"],
        ["title" => "Consultations", "description" => "Manage patient consultations.", "link" => "consultations.php", "icon" => "clipboard"],
        ["title" => "Prescriptions", "description" => "Create and manage prescriptions.", "link" => "prescriptions.php", "icon" => "file-text"],
    ],

    "pharmacist" => [
        ["title" => "Prescriptions", "description" => "Review approved prescriptions.", "link" => "prescriptions.php", "icon" => "file-text"],
        ["title" => "Medicine Stock", "description" => "Manage available medicines.", "link" => "medicines.php", "icon" => "pill"],
        ["title" => "Dispensing", "description" => "Manage medicine dispensing.", "link" => "dispensing.php", "icon" => "package"],
    ]
];

$firstName = explode(" ", trim($staffName))[0];
$avatarLetter = strtoupper(substr($firstName, 0, 1));

// Inline SVG icon helper
function icon($name) {
    $icons = [
        "home" => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1z"/>',
        "users" => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        "user-cog" => '<circle cx="10" cy="8" r="4"/><path d="M3 21v-2a7 7 0 0 1 12-4.9"/><circle cx="19" cy="18" r="3"/><path d="M19 13v2m0 6v2m5-5h-2m-6 0h-2"/>',
        "list" => '<path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/>',
        "inbox" => '<path d="M4 4h16l2 11v5H2v-5z"/><path d="M2 15h6l2 3h4l2-3h6"/>',
        "activity" => '<path d="M3 12h4l3-9 4 18 3-9h4"/>',
        "clipboard" => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4V2h6v2M9 11h6m-6 4h6"/>',
        "file-text" => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8m-8 4h8"/>',
        "pill" => '<path d="m10 3 11 11a5 5 0 0 1-7 7L3 10a5 5 0 0 1 7-7z"/><path d="m8.5 8.5 7 7"/>',
        "package" => '<path d="m12 3 9 5v9l-9 5-9-5V8z"/><path d="m3 8 9 5 9-5m-9 5v9"/>',
        "user-plus" => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M19 8v6m-3-3h6"/>',
        "arrow-right" => '<path d="M5 12h14m-7-7 7 7-7 7"/>',
        "logout" => '<path d="M10 17l5-5-5-5m5 5H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/>'
    ];

    $content = $icons[$name] ?? $icons["home"];

    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
        stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
        aria-hidden="true">' . $content . '</svg>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CarePoint | Dashboard</title>

    <link rel="stylesheet" href="dashboard.css?v=6">
</head>

<body>

<div class="dashboard-layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <a href="dashboard.php" class="brand">
            <span class="brand-icon">+</span>
            <span class="brand-text">
                <strong>CarePoint</strong>
                <small>Hospital Management</small>
            </span>
        </a>

        <div class="sidebar-section-label">WORKSPACE</div>

        <nav class="sidebar-nav">

            <?php foreach ($navigation[$staffRole] as $item): ?>

                <a href="<?php echo e($item["link"]); ?>"
                   class="nav-link <?php echo $item["link"] === "dashboard.php" ? "active" : ""; ?>">

                    <span class="nav-icon">
                        <?php echo icon($item["icon"]); ?>
                    </span>

                    <span><?php echo e($item["title"]); ?></span>

                    <?php if ($item["link"] === "nurse_inbox.php"): ?>
                        <span class="nav-indicator"></span>
                    <?php endif; ?>

                </a>

            <?php endforeach; ?>

        </nav>

        <div class="sidebar-bottom">

            <div class="sidebar-user">
                <div class="user-avatar">
                    <?php echo e($avatarLetter); ?>
                </div>

                <div class="user-info">
                    <strong><?php echo e($staffName); ?></strong>
                    <span><?php echo e($roleLabel); ?></span>
                </div>
            </div>

            <a href="logout.php" class="logout-link">
                <span class="nav-icon"><?php echo icon("logout"); ?></span>
                <span>Sign Out</span>
            </a>

        </div>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-content">

        <!-- TOP HEADER -->
        <header class="top-header">

            <div class="header-title">
                <span class="header-eyebrow">CAREPOINT MANAGEMENT SYSTEM</span>
                <h1>Dashboard</h1>
            </div>

            <div class="header-profile">

                <div class="profile-avatar">
                    <?php echo e($avatarLetter); ?>
                </div>

                <div class="profile-info">
                    <strong><?php echo e($staffName); ?></strong>
                    <span><?php echo e($roleLabel); ?></span>
                </div>

            </div>

        </header>

        <div class="dashboard-content">

            <!-- WELCOME BANNER -->
            <section class="welcome-banner">

                <div class="welcome-text">

                    <span class="welcome-label">WELCOME BACK</span>

                    <h2>Hello, <?php echo e($firstName); ?>!</h2>

                    <p>
                        Welcome to CarePoint. Manage your hospital
                        activities and access your workspace.
                    </p>

                    <span class="role-label">
                        <?php echo e($roleLabel); ?> Workspace
                    </span>

                </div>

                <div class="welcome-art">
                    <div class="medical-symbol">+</div>
                    <span class="art-circle circle-one"></span>
                    <span class="art-circle circle-two"></span>
                </div>

            </section>

            <!-- OVERVIEW -->
            <section class="overview-section">

                <div class="section-heading">
                    <div>
                        <h2>Overview</h2>
                        <p>Hospital activity at a glance.</p>
                    </div>

                    <span class="date-label">
                        <?php echo e(date("d F Y")); ?>
                    </span>
                </div>

                <div class="statistics-grid">

                    <!-- PATIENTS -->
                    <article class="stat-card">

                        <div class="stat-top">
                            <span class="stat-icon blue-icon">
                                <?php echo icon("users"); ?>
                            </span>
                            <span class="stat-caption">TOTAL RECORDS</span>
                        </div>

                        <div class="stat-value">
                            <?php echo number_format($statistics["patients"]); ?>
                        </div>

                        <h3>Registered Patients</h3>
                        <p>Total patient records in CarePoint</p>

                    </article>

                    <!-- QUEUE -->
                    <article class="stat-card">

                        <div class="stat-top">
                            <span class="stat-icon purple-icon">
                                <?php echo icon("list"); ?>
                            </span>
                            <span class="stat-caption">TODAY</span>
                        </div>

                        <div class="stat-value">
                            <?php echo number_format($statistics["queue"]); ?>
                        </div>

                        <h3>Today's Queue</h3>
                        <p>Patients awaiting or receiving attention</p>

                    </article>

                    <!-- PRESCRIPTIONS -->
                    <article class="stat-card">

                        <div class="stat-top">
                            <span class="stat-icon green-icon">
                                <?php echo icon("file-text"); ?>
                            </span>
                            <span class="stat-caption">ALL RECORDS</span>
                        </div>

                        <div class="stat-value">
                            <?php echo number_format($statistics["prescriptions"]); ?>
                        </div>

                        <h3>Prescriptions</h3>
                        <p>Total prescriptions recorded</p>

                    </article>

                    <!-- MEDICINES -->
                    <article class="stat-card">

                        <div class="stat-top">
                            <span class="stat-icon orange-icon">
                                <?php echo icon("pill"); ?>
                            </span>
                            <span class="stat-caption">CATALOGUE</span>
                        </div>

                        <div class="stat-value">
                            <?php echo number_format($statistics["medicines"]); ?>
                        </div>

                        <h3>Available Medicines</h3>
                        <p>Medicine types in the catalogue</p>

                    </article>

                </div>

            </section>

            <!-- QUICK ACTIONS -->
            <section class="quick-section">

                <div class="section-heading">
                    <div>
                        <h2>Quick Actions</h2>
                        <p>Shortcuts to your daily responsibilities.</p>
                    </div>
                </div>

                <div class="quick-grid">

                    <?php foreach ($quickActions[$staffRole] as $action): ?>

                        <a href="<?php echo e($action["link"]); ?>" class="quick-card">

                            <span class="quick-icon">
                                <?php echo icon($action["icon"]); ?>
                            </span>

                            <span class="quick-card-content">
                                <strong><?php echo e($action["title"]); ?></strong>
                                <small><?php echo e($action["description"]); ?></small>
                            </span>

                            <span class="quick-arrow">
                                <?php echo icon("arrow-right"); ?>
                            </span>

                        </a>

                    <?php endforeach; ?>

                </div>

            </section>

            <!-- FOOTER -->
            <footer class="dashboard-footer">
                <span>© <?php echo date("Y"); ?> CarePoint Hospital Management System.</span>
                <span>Healthcare made organised.</span>
            </footer>

        </div>

    </main>

</div>

</body>
</html>