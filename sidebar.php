<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$staffName = $_SESSION["staff_name"] ?? "Staff Member";
$staffRole = strtolower($_SESSION["staff_role"] ?? $_SESSION["role"] ?? "");
$currentPage = basename($_SERVER["PHP_SELF"]);

$navigation = [
    "administrator" => [
        ["Dashboard", "dashboard.php", "home"],
        ["Patients", "patients.php", "users"],
        ["Staff Management", "staff.php", "user-cog"],
        ["Queue Management", "queue.php", "list"]
    ],
    "nurse" => [
        ["Dashboard", "dashboard.php", "home"],
        ["Patients", "patients.php", "users"],
        ["Queue Management", "queue.php", "list"],
        ["Incoming Files", "nurse_inbox.php", "inbox"],
        ["Observations", "observations.php", "activity"]
    ],
    "doctor" => [
        ["Dashboard", "dashboard.php", "home"],
        ["Patients", "patients.php", "users"],
        ["Consultations", "consultations.php", "clipboard"],
        ["Prescriptions", "prescriptions.php", "file-text"]
    ],
    "pharmacist" => [
        ["Dashboard", "dashboard.php", "home"],
        ["Prescriptions", "prescriptions.php", "file-text"],
        ["Medicines", "medicines.php", "pill"],
        ["Dispensing", "dispensing.php", "package"]
    ]
];

$roleLabels = [
    "administrator" => "Administrator",
    "nurse" => "Nurse",
    "doctor" => "Doctor",
    "pharmacist" => "Pharmacist"
];

function sidebarIcon($name) {
    $icons = [
        "home" => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1z"/>',
        "users" => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        "user-cog" => '<circle cx="10" cy="8" r="4"/><path d="M3 21v-2a7 7 0 0 1 10-6.3"/>',
        "list" => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="M4 6h.01M4 12h.01M4 18h.01"/>',
        "inbox" => '<path d="M4 4h16l2 11v5H2v-5z"/><path d="M2 15h6l2 3h4l2-3h6"/>',
        "activity" => '<path d="M3 12h4l3-9 4 18 3-9h4"/>',
        "clipboard" => '<rect x="5" y="4" width="14" height="18" rx="2"/><path d="M9 4V2h6v2M9 10h6M9 14h6"/>',
        "file-text" => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
        "pill" => '<path d="m10 14 4-4"/><path d="M7 21a5 5 0 0 1-3.5-8.5l7-7a5 5 0 0 1 7 7l-7 7A5 5 0 0 1 7 21z"/>',
        "package" => '<path d="m12 3 9 5-9 5-9-5z"/><path d="M3 8v9l9 5 9-5V8M12 13v9"/>'
    ];

    return '<svg viewBox="0 0 24 24" aria-hidden="true">' .
        ($icons[$name] ?? '') . '</svg>';
}
?>

<aside class="sidebar">
    <a href="dashboard.php" class="sidebar-brand">
        <span class="brand-icon">+</span>
        <span>
            <strong>CarePoint</strong>
            <small>Hospital Management</small>
        </span>
    </a>

    <div class="sidebar-section-label">WORKSPACE</div>

    <nav class="sidebar-nav">
        <?php foreach (($navigation[$staffRole] ?? []) as $item): ?>
            <a
                href="<?= htmlspecialchars($item[1], ENT_QUOTES, "UTF-8") ?>"
                class="sidebar-link <?= $currentPage === $item[1] ? "active" : "" ?>"
            >
                <?= sidebarIcon($item[2]) ?>
                <span><?= htmlspecialchars($item[0], ENT_QUOTES, "UTF-8") ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-bottom">
        <div class="sidebar-user">
            <div class="user-avatar">
                <?= htmlspecialchars(strtoupper(substr($staffName, 0, 1)), ENT_QUOTES, "UTF-8") ?>
            </div>

            <div class="user-details">
                <strong><?= htmlspecialchars($staffName, ENT_QUOTES, "UTF-8") ?></strong>
                <small>
                    <?= htmlspecialchars($roleLabels[$staffRole] ?? ucfirst($staffRole), ENT_QUOTES, "UTF-8") ?>
                </small>
            </div>
        </div>

        <a href="logout.php" class="sidebar-logout">
            <span>↪</span> Sign Out
        </a>
    </div>
</aside>