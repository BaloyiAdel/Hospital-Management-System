
<?php
session_start();

// Protect the dashboard: users must log in first
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header("Location: index.php");
    exit();
}

$staffName = $_SESSION['staff_name'] ?? 'Staff Member';
$staffRole = $_SESSION['staff_role'] ?? 'Staff';

// Role-specific dashboard information
$role = strtolower($staffRole);

$roleDescriptions = [
    'administrator' => 'Manage staff, patients and hospital operations.',
    'nurse' => 'Manage patient records, observations and nursing activities.',
    'doctor' => 'Review patient records, diagnoses and prescriptions.',
    'pharmacist' => 'Manage prescriptions, medicines and pharmacy stock.'
];

$description = $roleDescriptions[$role] ?? 'Welcome to CarePoint Hospital Management System.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarePoint | Dashboard</title>
    <link rel="stylesheet" href="style.css">

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #FFF8F7;
            color: #343A40;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 250px;
            background: #702D3B;
            color: white;
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
        }

        .logo {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 40px;
        }

        .logo span {
            color: #F5C6CB;
        }

        .sidebar h4 {
            font-size: 12px;
            color: #E9BFC4;
            margin: 20px 10px 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .sidebar a {
            display: block;
            color: #FFFFFF;
            text-decoration: none;
            padding: 13px 12px;
            border-radius: 8px;
            margin-bottom: 7px;
            transition: 0.3s;
        }

        .sidebar a:hover,
        .sidebar a.active {
            background: #A63F50;
        }

        .logout {
            margin-top: auto;
            background: #8B2638;
        }

        /* Main content */
        .main-content {
            flex: 1;
            padding: 30px;
            min-width: 0;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 35px;
        }

        .topbar h1 {
            color: #702D3B;
            font-size: 28px;
        }

        .profile {
            background: white;
            padding: 12px 18px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .profile strong {
            color: #702D3B;
        }

        .welcome {
            background: linear-gradient(120deg, #702D3B, #A63F50);
            color: white;
            padding: 30px;
            border-radius: 18px;
            margin-bottom: 30px;
            box-shadow: 0 8px 22px rgba(112,45,59,0.18);
        }

        .welcome h2 {
            font-size: 25px;
            margin-bottom: 10px;
        }

        .welcome p {
            color: #FBEAEC;
            line-height: 1.6;
        }

        /* Summary cards */
        .section-title {
            color: #702D3B;
            margin-bottom: 18px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 35px;
        }

        .card {
            background: white;
            padding: 23px;
            border-radius: 15px;
            box-shadow: 0 5px 18px rgba(0,0,0,0.05);
            border: 1px solid #F5E4E5;
            transition: 0.3s;
        }

        .card:hover {
            transform: translateY(-4px);
        }

        .card .icon {
            width: 43px;
            height: 43px;
            border-radius: 12px;
            background: #F5E4E5;
            color: #8B2638;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 15px;
        }

        .card h3 {
            font-size: 14px;
            color: #777;
            margin-bottom: 9px;
        }

        .card .number {
            font-size: 27px;
            font-weight: bold;
            color: #702D3B;
        }

        /* Quick actions */
        .actions {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .action-card {
            background: white;
            padding: 23px;
            border-radius: 15px;
            border: 1px solid #F5E4E5;
            box-shadow: 0 5px 18px rgba(0,0,0,0.04);
        }

        .action-card h3 {
            color: #702D3B;
            margin-bottom: 10px;
        }

        .action-card p {
            color: #777;
            line-height: 1.5;
            margin-bottom: 18px;
        }

        .action-card a {
            display: inline-block;
            text-decoration: none;
            background: #702D3B;
            color: white;
            padding: 11px 18px;
            border-radius: 8px;
            font-size: 14px;
        }

        .action-card a:hover {
            background: #A63F50;
        }

        .footer {
            margin-top: 35px;
            text-align: center;
            color: #999;
            font-size: 13px;
        }

        @media (max-width: 1000px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .dashboard {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
            }

            .main-content {
                padding: 20px;
            }

            .actions {
                grid-template-columns: 1fr;
            }

            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 420px) {
            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<div class="dashboard">

    <!-- Sidebar -->
    <aside class="sidebar">

        <div class="logo">Care<span>Point</span></div>

        <h4>Main Menu</h4>

        <a href="dashboard.php" class="active">Dashboard</a>

        <?php if ($role === 'administrator'): ?>
            <h4>Administration</h4>
            <a href="patients.php">Patient Management</a>
            <a href="staff.php">Staff Management</a>
            <a href="queue.php">Patient Queue</a>
            <a href="reports.php">Reports</a>

        <?php elseif ($role === 'nurse'): ?>
            <h4>Nursing</h4>
            <a href="patients.php">Patient Records</a>
            <a href="observations.php">Patient Observations</a>
            <a href="queue.php">Patient Queue</a>

        <?php elseif ($role === 'doctor'): ?>
            <h4>Medical</h4>
            <a href="patients.php">Patient Records</a>
            <a href="consultations.php">Consultations</a>
            <a href="prescriptions.php">Prescriptions</a>

        <?php elseif ($role === 'pharmacist'): ?>
            <h4>Pharmacy</h4>
            <a href="prescriptions.php">Prescriptions</a>
            <a href="medicines.php">Medicine Catalogue</a>
            <a href="stock.php">Medicine Stock</a>
            <a href="dispensing.php">Dispensing</a>
        <?php endif; ?>

        <h4>Account</h4>
        <a href="logout.php" class="logout">Log Out</a>

    </aside>

    <!-- Main content -->
    <main class="main-content">

        <div class="topbar">
            <h1>Dashboard</h1>

            <div class="profile">
                Welcome, <strong>
                    <?php echo htmlspecialchars($staffName); ?>
                </strong>
            </div>
        </div>

        <!-- Welcome section -->
        <section class="welcome">
            <h2>Welcome to CarePoint</h2>

            <p>
                <?php echo htmlspecialchars($description); ?>
            </p>

            <p style="margin-top: 12px;">
                Your hospital management workspace.
            </p>
        </section>

        <!-- Summary cards -->
        <h2 class="section-title">Overview</h2>

        <section class="cards">

            <div class="card">
                <div class="icon">+</div>
                <h3>Total Patients</h3>
                <div class="number">0</div>
            </div>

            <div class="card">
                <div class="icon">#</div>
                <h3>Today's Queue</h3>
                <div class="number">0</div>
            </div>

            <div class="card">
                <div class="icon">✓</div>
                <h3>Completed Visits</h3>
                <div class="number">0</div>
            </div>

            <div class="card">
                <div class="icon">+</div>
                <h3>Pending Tasks</h3>
                <div class="number">0</div>
            </div>

        </section>

        <!-- Quick actions -->
        <h2 class="section-title">Quick Actions</h2>

        <section class="actions">

            <?php if (in_array($role, ['administrator', 'nurse', 'doctor'])): ?>
                <div class="action-card">
                    <h3>Patient Management</h3>
                    <p>Register a new patient or search for an existing patient record.</p>
                    <a href="patients.php">Manage Patients</a>
                </div>
            <?php endif; ?>

            <?php if ($role === 'administrator'): ?>
                <div class="action-card">
                    <h3>Staff Management</h3>
                    <p>Manage hospital staff accounts and their access roles.</p>
                    <a href="staff.php">Manage Staff</a>
                </div>
            <?php endif; ?>

            <?php if ($role === 'nurse'): ?>
                <div class="action-card">
                    <h3>Patient Observations</h3>
                    <p>Record patient observations and send patient files to doctors.</p>
                    <a href="observations.php">Record Observations</a>
                </div>
            <?php endif; ?>

            <?php if ($role === 'doctor'): ?>
                <div class="action-card">
                    <h3>Consultations</h3>
                    <p>Review patient records and document clinical consultations.</p>
                    <a href="consultations.php">Open Consultations</a>
                </div>
            <?php endif; ?>

            <?php if ($role === 'pharmacist'): ?>
                <div class="action-card">
                    <h3>Pharmacy Management</h3>
                    <p>Review approved prescriptions and manage medicine stock.</p>
                    <a href="prescriptions.php">View Prescriptions</a>
                </div>
            <?php endif; ?>

            <div class="action-card">
                <h3>My Account</h3>
                <p>You are signed in as <?php echo htmlspecialchars($staffRole); ?>.</p>
                <a href="logout.php">Log Out</a>
            </div>

        </section>

        <div class="footer">
            © <?php echo date("Y"); ?> CarePoint Hospital Management System.
            All rights reserved.
        </div>

    </main>

</div>

</body>
</html>
