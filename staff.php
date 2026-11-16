<?php
session_start();

// Always define the edit record before the page can display the form.
$editStaff = null;

date_default_timezone_set('Africa/Johannesburg');
require_once "db.php";

/* Only an authenticated Administrator may manage staff accounts. */
if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true) {
    header("Location: index.php");
    exit();
}

$role = strtolower($_SESSION["staff_role"] ?? "");
if ($role !== "administrator") {
    http_response_code(403);
    exit("Access denied. Only administrators can manage staff accounts.");
}

$adminId = (int)($_SESSION["staff_id"] ?? 0);

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION["csrf_token"];

function e($value): string {
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function staff_flash(string $message, string $type = "success"): void {
    $_SESSION["staff_flash"] = ["message" => $message, "type" => $type];
}

$validRoles = ["administrator", "nurse", "doctor", "pharmacist"];
$message = "";
$messageType = "success";

if (!empty($_SESSION["staff_flash"])) {
    $message = $_SESSION["staff_flash"]["message"];
    $messageType = $_SESSION["staff_flash"]["type"];
    unset($_SESSION["staff_flash"]);
}

/* Handle all changes on the server, not only through the visible form. */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postedToken = $_POST["csrf_token"] ?? "";
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(400);
        exit("Invalid security token. Please refresh the page and try again.");
    }

    $action = $_POST["action"] ?? "";

    try {
        if ($action === "create" || $action === "update") {
            $staffId = (int)($_POST["staff_id"] ?? 0);
            $fullName = trim($_POST["full_name"] ?? "");
            $email = strtolower(trim($_POST["email"] ?? ""));
            $phone = trim($_POST["phone"] ?? "");
            $staffRole = $_POST["role"] ?? "";
            $password = $_POST["password"] ?? "";

            if ($fullName === "" || mb_strlen($fullName) > 150) {
                throw new RuntimeException("Enter a staff name (maximum 150 characters).");
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
                throw new RuntimeException("Enter a valid email address (maximum 150 characters).");
            }
            if (!in_array($staffRole, $validRoles, true)) {
                throw new RuntimeException("Select a valid staff role.");
            }
            if (strlen($phone) > 20) {
                throw new RuntimeException("The phone number must be 20 characters or fewer.");
            }

            $duplicate = $pdo->prepare("SELECT staff_id FROM staff WHERE email = ? AND staff_id <> ?");
            $duplicate->execute([$email, $action === "update" ? $staffId : 0]);
            if ($duplicate->fetch()) {
                throw new RuntimeException("That email address is already assigned to another staff account.");
            }

            if ($action === "create") {
                if (strlen($password) < 8) {
                    throw new RuntimeException("For a new account, the password must contain at least 8 characters.");
                }
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("
                    INSERT INTO staff (full_name, email, password_hash, role, phone, status)
                    VALUES (?, ?, ?, ?, ?, 'active')
                ");
                $stmt->execute([$fullName, $email, $hash, $staffRole, $phone !== "" ? $phone : null]);
                staff_flash("Staff account created successfully.");
            } else {
                if ($staffId < 1) {
                    throw new RuntimeException("The selected staff account is invalid.");
                }
                $currentStmt = $pdo->prepare("SELECT staff_id, role, status FROM staff WHERE staff_id = ?");
                $currentStmt->execute([$staffId]);
                $currentStaff = $currentStmt->fetch();
                if (!$currentStaff) {
                    throw new RuntimeException("The staff account could not be found.");
                }

                if ($staffId === $adminId && $staffRole !== "administrator") {
                    throw new RuntimeException("You cannot remove Administrator access from your own account.");
                }

                if ($password !== "") {
                    if (strlen($password) < 8) {
                        throw new RuntimeException("A new password must contain at least 8 characters.");
                    }
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        UPDATE staff
                        SET full_name = ?, email = ?, password_hash = ?, role = ?, phone = ?
                        WHERE staff_id = ?
                    ");
                    $stmt->execute([$fullName, $email, $hash, $staffRole, $phone !== "" ? $phone : null, $staffId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE staff
                        SET full_name = ?, email = ?, role = ?, phone = ?
                        WHERE staff_id = ?
                    ");
                    $stmt->execute([$fullName, $email, $staffRole, $phone !== "" ? $phone : null, $staffId]);
                }
                staff_flash("Staff account updated successfully.");
            }

            header("Location: staff.php");
            exit();
        }

        if ($action === "toggle_status") {
            $staffId = (int)($_POST["staff_id"] ?? 0);
            if ($staffId < 1) {
                throw new RuntimeException("The selected staff account is invalid.");
            }
            if ($staffId === $adminId) {
                throw new RuntimeException("You cannot deactivate the account you are currently using.");
            }

            $stmt = $pdo->prepare("SELECT staff_id, role, status FROM staff WHERE staff_id = ?");
            $stmt->execute([$staffId]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException("The staff account could not be found.");
            }

            if ($target["status"] === "active" && $target["role"] === "administrator") {
                $countStmt = $pdo->query("SELECT COUNT(*) FROM staff WHERE role = 'administrator' AND status = 'active'");
                if ((int)$countStmt->fetchColumn() <= 1) {
                    throw new RuntimeException("You cannot deactivate the last active Administrator account.");
                }
            }

            $newStatus = $target["status"] === "active" ? "inactive" : "active";
            $update = $pdo->prepare("UPDATE staff SET status = ? WHERE staff_id = ?");
            $update->execute([$newStatus, $staffId]);
            staff_flash("Staff account " . ($newStatus === "active" ? "activated" : "deactivated") . " successfully.");
            header("Location: staff.php");
            exit();
        }

        throw new RuntimeException("The requested action is not recognised.");
    } catch (PDOException $ex) {
        error_log("Staff management database error: " . $ex->getMessage());
        staff_flash("The staff account could not be saved. Please check the details and try again.", "error");
        header("Location: staff.php");
        exit();
    } catch (RuntimeException $ex) {
        staff_flash($ex->getMessage(), "error");
        header("Location: staff.php" . (!empty($_POST["staff_id"]) ? "?edit=" . (int)$_POST["staff_id"] : ""));
        exit();
    }
}

$search = trim($_GET["search"] ?? "");
$editId = (int)($_GET["edit"] ?? 0);
$editStaff = null;

if ($editId > 0) {
    $editStmt = $pdo->prepare("SELECT staff_id, full_name, email, role, phone, status FROM staff WHERE staff_id = ?");
    $editStmt->execute([$editId]);
    $editStaff = $editStmt->fetch();
    if (!$editStaff) {
        $message = "The selected staff account was not found.";
        $messageType = "error";
    }
}

$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'active') AS active_count,
        SUM(status = 'inactive') AS inactive_count
    FROM staff
")->fetch();

if ($search !== "") {
    $like = "%" . $search . "%";
    $listStmt = $pdo->prepare("
        SELECT staff_id, full_name, email, role, phone, status, created_at
        FROM staff
        WHERE full_name LIKE ? OR email LIKE ? OR role LIKE ? OR phone LIKE ?
        ORDER BY created_at DESC, staff_id DESC
    ");
    $listStmt->execute([$like, $like, $like, $like]);
    $staffMembers = $listStmt->fetchAll();
} else {
    $staffMembers = $pdo->query("
        SELECT staff_id, full_name, email, role, phone, status, created_at
        FROM staff
        ORDER BY created_at DESC, staff_id DESC
    ")->fetchAll();
}

$staffCount = count($staffMembers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Management | CarePoint</title>
<style>
:root {
    --cp-navy:#163B65; --cp-blue:#3979B9; --cp-ice:#A9D3F0;
    --cp-light:#EAF4FC; --cp-white:#FFFFFF; --cp-text:#26384A;
    --cp-muted:#718096; --cp-border:#DCE6EF; --cp-bg:#F5F8FC;
    --cp-green:#16794B; --cp-green-bg:#E8F6EF; --cp-red:#B42332;
    --cp-red-bg:#FFF0F0; --cp-amber:#946200; --cp-amber-bg:#FFF6DF;
}
*{box-sizing:border-box}
body{margin:0;background:var(--cp-bg);color:var(--cp-text);font-family:Inter,"Segoe UI",Arial,sans-serif}
.dashboard{display:flex;min-height:100vh;width:100%}
.sidebar{position:fixed;inset:0 auto 0 0;z-index:10;display:flex;flex-direction:column;width:250px;min-width:250px;height:100vh;padding:26px 18px 20px;overflow-y:auto;background:var(--cp-navy);color:#fff}
.logo{display:flex;align-items:center;gap:11px;min-height:48px;margin:0 8px 38px;color:#fff;font-size:21px;font-weight:750;letter-spacing:.2px}
.logo-symbol{display:grid;place-items:center;width:36px;height:36px;flex:0 0 36px;border-radius:10px;background:rgba(255,255,255,.14);color:#fff;font-size:23px}
.sidebar-label{margin:0 10px 12px;color:#B9CDE0;font-size:10px;font-weight:700;letter-spacing:1.35px}
.sidebar-nav{display:flex;flex-direction:column;gap:6px}
.sidebar-nav a,.logout-link{display:flex;align-items:center;min-height:44px;padding:0 13px;border-radius:9px;color:#E8F1F9;font-size:14px;font-weight:550;line-height:1.3;text-decoration:none;transition:background .18s ease,color .18s ease}
.sidebar-nav a:hover,.logout-link:hover{background:rgba(255,255,255,.11);color:#fff}
.sidebar-nav a.active{background:#fff;color:var(--cp-navy);font-weight:700}
.sidebar-bottom{margin-top:auto;padding-top:24px;border-top:1px solid rgba(255,255,255,.16)}
.logout-link{color:#fff}
.main-content{width:calc(100% - 250px);min-width:0;min-height:100vh;margin-left:250px;padding:0 34px 42px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:20px;min-height:96px;padding:18px 0;border-bottom:1px solid var(--cp-border)}
.topbar h2{margin:0 0 5px;color:var(--cp-navy);font-size:21px}
.topbar p{margin:0;color:var(--cp-muted);font-size:13px}
.profile{display:flex;align-items:center;gap:11px}
.profile-avatar{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;background:var(--cp-light);color:var(--cp-navy);font-weight:750}
.profile-details{display:flex;flex-direction:column;gap:3px}
.profile-details strong{font-size:13px;color:var(--cp-text)}
.profile-details span{font-size:12px;color:var(--cp-muted);text-transform:capitalize}
.page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin:30px 0 22px}
.page-heading h1{margin:0 0 7px;color:var(--cp-navy);font-size:27px;letter-spacing:-.4px}
.page-heading p{margin:0;color:var(--cp-muted);font-size:14px;line-height:1.5}
.stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-bottom:22px}
.stat-card,.panel{background:#fff;border:1px solid var(--cp-border);border-radius:13px;box-shadow:0 3px 12px rgba(22,59,101,.045)}
.stat-card{padding:18px 20px}
.stat-card span{display:block;margin-bottom:9px;color:var(--cp-muted);font-size:12px;font-weight:650}
.stat-card strong{color:var(--cp-navy);font-size:27px;line-height:1}
.panel{padding:22px;margin-bottom:22px}
.panel-heading{margin-bottom:18px}
.panel-heading h2{margin:0 0 5px;color:var(--cp-navy);font-size:18px}
.panel-heading p{margin:0;color:var(--cp-muted);font-size:13px;line-height:1.5}
.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 18px}
.form-group{display:flex;flex-direction:column;gap:7px;min-width:0}
.form-group.full{grid-column:1/-1}
label{color:#35485B;font-size:13px;font-weight:650}
input,select{width:100%;min-height:43px;padding:10px 12px;border:1px solid #CBD8E5;border-radius:8px;background:#fff;color:var(--cp-text);font:inherit;font-size:13px;outline:none}
input:focus,select:focus{border-color:var(--cp-blue);box-shadow:0 0 0 3px rgba(57,121,185,.12)}
.help{color:var(--cp-muted);font-size:11px;line-height:1.4}
.form-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
button,.button-link{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 16px;border:0;border-radius:8px;background:var(--cp-blue);color:#fff;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}
button:hover,.button-link:hover{background:var(--cp-navy)}
.button-secondary{background:#EDF3F8;color:var(--cp-navy)}
.button-secondary:hover{background:#DDEAF5}
.button-danger{background:#FFF0F0;color:var(--cp-red)}
.button-danger:hover{background:#FCE0E0}
.notice{padding:13px 15px;margin:0 0 18px;border-radius:9px;font-size:13px;line-height:1.45}
.notice.success{background:var(--cp-green-bg);color:var(--cp-green);border:1px solid #C8E9D7}
.notice.error{background:var(--cp-red-bg);color:var(--cp-red);border:1px solid #F2CACA}
.search-row{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.search-row input{flex:1}
.table-wrap{width:100%;overflow-x:auto}
table{width:100%;border-collapse:collapse;min-width:760px}
th{padding:12px 13px;background:#F2F7FB;color:#52677B;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.45px;white-space:nowrap}
td{padding:14px 13px;border-bottom:1px solid #E9EFF5;color:#34495E;font-size:13px;vertical-align:middle}
tbody tr:hover{background:#FAFCFE}
.staff-name{font-weight:700;color:var(--cp-navy)}
.staff-email{display:block;margin-top:4px;color:var(--cp-muted);font-size:12px}
.role-pill,.status-pill{display:inline-flex;align-items:center;padding:5px 9px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.role-pill{background:var(--cp-light);color:var(--cp-navy)}
.status-pill.active{background:var(--cp-green-bg);color:var(--cp-green)}
.status-pill.inactive{background:var(--cp-amber-bg);color:var(--cp-amber)}
.actions{display:flex;align-items:center;gap:7px;white-space:nowrap}
.actions a,.actions button{min-height:31px;padding:0 10px;font-size:11px}
.empty-state{padding:30px 12px;text-align:center;color:var(--cp-muted);font-size:13px}
.current-account{color:var(--cp-muted);font-size:11px}
@media(max-width:1000px){.main-content{padding:0 24px 35px}.stats-grid{gap:12px}}
@media(max-width:760px){.dashboard{display:block}.sidebar{position:relative;width:100%;min-width:0;height:auto;padding:18px}.logo{margin:0 8px 18px}.sidebar-nav{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.sidebar-bottom{margin-top:18px}.main-content{width:100%;margin-left:0;padding:0 16px 30px}.topbar{align-items:flex-start;flex-direction:column;padding:18px 0}.page-heading{align-items:flex-start;flex-direction:column;margin:24px 0 18px}.stats-grid{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.form-group.full{grid-column:auto}.panel{padding:17px}.search-row{align-items:stretch;flex-direction:column}.form-actions{flex-direction:column-reverse}.form-actions button,.form-actions a{width:100%}}
</style>
</head>
<body>
<div class="dashboard">
    <aside class="sidebar">
        <div class="logo"><span class="logo-symbol">✚</span><span>CarePoint</span></div>
        <p class="sidebar-label">MAIN MENU</p>
        <nav class="sidebar-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="patients.php">Patients</a>
            <a href="staff.php" class="active">Staff Management</a>
            <a href="queue.php">Patient Queue</a>
        </nav>
        <div class="sidebar-bottom">
            <a href="logout.php" class="logout-link">Log Out</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <div>
                <h2>Staff Management</h2>
                <p>Create staff accounts and control access to CarePoint.</p>
            </div>
            <div class="profile">
                <div class="profile-avatar"><?= e(strtoupper(substr($_SESSION["staff_name"] ?? "A", 0, 1))) ?></div>
                <div class="profile-details">
                    <strong><?= e($_SESSION["staff_name"] ?? "Administrator") ?></strong>
                    <span>Administrator</span>
                </div>
            </div>
        </header>

        <section class="page-heading">
            <div>
                <h1>Staff Management</h1>
                <p>Manage individual staff login accounts, roles and account status.</p>
            </div>
        </section>

        <?php if ($message !== ""): ?>
            <div class="notice <?= e($messageType) ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <section class="stats-grid">
            <div class="stat-card"><span>Total Staff Accounts</span><strong><?= (int)($stats["total"] ?? 0) ?></strong></div>
            <div class="stat-card"><span>Active Accounts</span><strong><?= (int)($stats["active_count"] ?? 0) ?></strong></div>
            <div class="stat-card"><span>Inactive Accounts</span><strong><?= (int)($stats["inactive_count"] ?? 0) ?></strong></div>
        </section>

        <section class="panel" id="staff-form">
            <div class="panel-heading">
                <h2><?= $editStaff ? "Edit Staff Account" : "Add New Staff Member" ?></h2>
                <p><?= $editStaff ? "Update the staff member's details. Leave the password blank to keep the current password." : "Create a separate login account for each staff member." ?></p>
            </div>

            <form method="POST" action="staff.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="action" value="<?= $editStaff ? "update" : "create" ?>">
                <?php if ($editStaff): ?>
                    <input type="hidden" name="staff_id" value="<?= (int)$editStaff["staff_id"] ?>">
                <?php endif; ?>

                <div class="form-grid">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" maxlength="150" required value="<?= e($editStaff["full_name"] ?? "") ?>" placeholder="Enter staff member's full name">
                    </div>
                    <div class="form-group">
                        <label for="email">Email Address *</label>
                        <input type="email" id="email" name="email" maxlength="150" required value="<?= e($editStaff["email"] ?? "") ?>" placeholder="name@carepoint.test">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone Number</label>
                        <input type="tel" id="phone" name="phone" maxlength="20" value="<?= e($editStaff["phone"] ?? "") ?>" placeholder="Enter contact number">
                    </div>
                    <div class="form-group">
                        <label for="role">Staff Role *</label>
                        <select id="role" name="role" required>
                            <option value="">Select staff role</option>
                            <?php foreach ($validRoles as $option): ?>
                                <option value="<?= e($option) ?>" <?= (($editStaff["role"] ?? "") === $option) ? "selected" : "" ?>><?= e(ucfirst($option)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label for="password"><?= $editStaff ? "New Password (optional)" : "Temporary Password *" ?></label>
                        <input type="password" id="password" name="password" minlength="8" <?= $editStaff ? "" : "required" ?> autocomplete="new-password" placeholder="<?= $editStaff ? "Leave blank to keep the current password" : "At least 8 characters" ?>">
                        <span class="help"><?= $editStaff ? "Only enter a password if you want to change it." : "The password is securely hashed before it is saved. Give the temporary password to the staff member privately." ?></span>
                    </div>
                </div>
                <div class="form-actions">
                    <?php if ($editStaff): ?>
                        <a class="button-link button-secondary" href="staff.php">Cancel Edit</a>
                    <?php else: ?>
                        <button type="reset" class="button-secondary">Clear Form</button>
                    <?php endif; ?>
                    <button type="submit"><?= $editStaff ? "Save Changes" : "Create Staff Account" ?></button>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <h2>Staff Directory</h2>
                <p><?= $staffCount ?> account<?= $staffCount === 1 ? "" : "s" ?> shown. Search by name, email, role or phone number.</p>
            </div>
            <form method="GET" action="staff.php" class="search-row">
                <input type="search" name="search" value="<?= e($search) ?>" placeholder="Search staff members...">
                <button type="submit">Search Staff</button>
                <?php if ($search !== ""): ?><a class="button-link button-secondary" href="staff.php">Clear</a><?php endif; ?>
            </form>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Staff Member</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Date Added</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$staffMembers): ?>
                        <tr><td colspan="6" class="empty-state">No staff accounts found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($staffMembers as $member): ?>
                            <tr>
                                <td>
                                    <span class="staff-name"><?= e($member["full_name"]) ?></span>
                                    <span class="staff-email"><?= e($member["email"]) ?></span>
                                </td>
                                <td><?= e($member["phone"] ?: "—") ?></td>
                                <td><span class="role-pill"><?= e(ucfirst($member["role"])) ?></span></td>
                                <td><span class="status-pill <?= e($member["status"]) ?>"><?= e(ucfirst($member["status"])) ?></span></td>
                                <td><?= e($member["created_at"] ? date("d M Y", strtotime($member["created_at"])) : "—") ?></td>
                                <td>
                                    <div class="actions">
                                        <a class="button-link button-secondary" href="staff.php?edit=<?= (int)$member["staff_id"] ?>#staff-form">Edit</a>
                                        <?php if ((int)$member["staff_id"] === $adminId): ?>
                                            <span class="current-account">Current account</span>
                                        <?php else: ?>
                                            <form method="POST" action="staff.php" onsubmit="return confirm('Are you sure you want to <?= $member["status"] === "active" ? "deactivate" : "activate" ?> this account?');">
                                                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="staff_id" value="<?= (int)$member["staff_id"] ?>">
                                                <button type="submit" class="<?= $member["status"] === "active" ? "button-danger" : "" ?>"><?= $member["status"] === "active" ? "Deactivate" : "Activate" ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
</body>
</html>
