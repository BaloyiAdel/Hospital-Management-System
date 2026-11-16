
<?php
session_start();

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$errorMessage = "";

if (isset($_GET["error"])) {
    if ($_GET["error"] === "invalid") {
        $errorMessage = "Incorrect email, password or selected role.";
    } elseif ($_GET["error"] === "empty") {
        $errorMessage = "Please complete all login fields.";
    } elseif ($_GET["error"] === "expired") {
        $errorMessage = "Your session expired. Please sign in again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>CarePoint | Hospital Management System</title>

    <link rel="stylesheet" href="style.css?v=11">
</head>

<body>

<!-- HEADER -->
<header class="header">
    <div class="header-container">

        <a href="#home" class="logo">
            <span class="logo-icon">+</span>
            <span>CarePoint</span>
        </a>

        <nav class="navigation">
            <a href="#home">Home</a>
            <a href="#about">About</a>
            <a href="#departments">Departments</a>
            <a href="#staff-login">Staff Login</a>
        </nav>

    </div>
</header>


<!-- HERO SECTION -->
<section class="hero" id="home">

    <div class="hero-content">

        <div class="hero-text">

            <span class="eyebrow">CAREPOINT HEALTHCARE</span>

            <h1>
                Quality Healthcare,
                <br>
                Connected Care.
            </h1>

            <p>
                A smarter way to manage patient information,
                healthcare teams and hospital services.
            </p>

            <a href="#staff-login" class="hero-button">
                Staff Sign In
            </a>

        </div>

        <div class="hero-image">
            <img
                src="images/HospitalPic.jpg"
                alt="Healthcare professionals at work"
            >
        </div>

    </div>

</section>


<!-- ABOUT SECTION -->
<section class="about" id="about">

    <div class="section-heading">

        <span>ABOUT CAREPOINT</span>

        <h2>Healthcare Management Made Easier</h2>

        <p>
            CarePoint connects hospital departments through
            organised patient records and efficient workflows.
        </p>

    </div>

</section>


<!-- DEPARTMENTS SECTION -->
<section class="departments" id="departments">

    <div class="section-heading">

        <span>OUR DEPARTMENTS</span>

        <h2>Working Together for Better Care</h2>

    </div>

    <div class="department-grid">

        <div class="department-card">
            <div class="department-icon">+</div>
            <h3>Administration</h3>
            <p>
                Staff management, patient registration
                and queue administration.
            </p>
        </div>

        <div class="department-card">
            <div class="department-icon">+</div>
            <h3>Nursing</h3>
            <p>
                Patient observations, nursing notes
                and patient care.
            </p>
        </div>

        <div class="department-card">
            <div class="department-icon">+</div>
            <h3>Doctors</h3>
            <p>
                Consultations, diagnoses
                and prescriptions.
            </p>
        </div>

        <div class="department-card">
            <div class="department-icon">+</div>
            <h3>Pharmacy</h3>
            <p>
                Medication management, stock
                and dispensing.
            </p>
        </div>

    </div>

</section>


<!-- STAFF LOGIN SECTION -->
<section class="staff-login" id="staff-login">

    <div class="login-container">

        <div class="login-heading">

            <span>SECURE STAFF PORTAL</span>

            <h2>Welcome Back</h2>

            <p>
                Select your department and sign in
                to your CarePoint account.
            </p>

        </div>


        <!-- ERROR MESSAGE -->
        <?php if ($errorMessage !== ""): ?>

            <div class="login-error">
                <?= htmlspecialchars($errorMessage) ?>
            </div>

        <?php endif; ?>


        <!-- LOGIN FORM -->
        <form
            action="login.php"
            method="POST"
            class="login-form"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
            >


            <!-- ROLE SELECTION FIRST -->
            <div class="form-group">

                <label class="role-heading">
                    Select Your Role
                </label>

                <div class="role-options">

                    <label class="role-option">

                        <input
                            type="radio"
                            name="role"
                            value="administrator"
                            required
                        >

                        <span>Admin</span>

                    </label>


                    <label class="role-option">

                        <input
                            type="radio"
                            name="role"
                            value="nurse"
                        >

                        <span>Nurse</span>

                    </label>


                    <label class="role-option">

                        <input
                            type="radio"
                            name="role"
                            value="doctor"
                        >

                        <span>Doctor</span>

                    </label>


                    <label class="role-option">

                        <input
                            type="radio"
                            name="role"
                            value="pharmacist"
                        >

                        <span>Pharmacy</span>

                    </label>

                </div>

            </div>


            <!-- EMAIL FIELD -->
            <div class="form-group">

                <label for="email">
                    Email Address
                </label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="Enter your staff email"
                    autocomplete="username"
                    required
                >

            </div>


            <!-- PASSWORD FIELD -->
            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter your password"
                    autocomplete="current-password"
                    required
                >

            </div>


            <!-- LOGIN BUTTON -->
            <button
                type="submit"
                class="login-button"
            >
                Sign In
            </button>

        </form>

    </div>

</section>


<!-- FOOTER -->
<footer class="footer">

    <div class="footer-logo">
        + CarePoint
    </div>

    <p>
        Connecting Healthcare. Improving Patient Care.
    </p>

    <p>
        &copy; <?= date("Y") ?> CarePoint Hospital Management System.
    </p>

</footer>

</body>
</html>
