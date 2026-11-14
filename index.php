
<?php
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CarePoint | Hospital Management System</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>

<!-- NAVIGATION -->
<header class="navbar">
    <a href="#home" class="brand">
        <span class="brand-icon">
            <svg viewBox="0 0 48 48" aria-hidden="true">
                <path d="M18 4h12v14h14v12H30v14H18V30H4V18h14z" fill="currentColor"/>
            </svg>
        </span>

        <span class="brand-text">
            <strong>CarePoint</strong>
            <small>Hospital Management System</small>
        </span>
    </a>

    <nav class="nav-links">
        <a href="#home">
            <svg viewBox="0 0 24 24"><path d="m3 11 9-8 9 8v10h-6v-7H9v7H3z"/></svg>
            Home
        </a>

        <a href="#about">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-9h.01"/></svg>
            About
        </a>

        <a href="#departments">
            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Departments
        </a>

        <a href="#staff-login">
            <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/><path d="m9 12 2 2 4-4"/></svg>
            Staff Login
        </a>
    </nav>
</header>

<main>

    <!-- HERO / WELCOME SECTION -->
    <section class="hero" id="home">

        <div class="hero-content">
            <span class="eyebrow">CAREPOINT HOSPITAL MANAGEMENT SYSTEM</span>

            <h1>
                Better care starts with
                <span>better management.</span>
            </h1>

            <p>
                A modern hospital management system designed to help
                healthcare teams organise patient information, coordinate
                departments and deliver more efficient care.
            </p>

            <div class="hero-buttons">
                <a href="#staff-login" class="primary-button">
                    <svg viewBox="0 0 24 24">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M20 8v6m-3-3h6"/>
                    </svg>
                    Staff Login
                </a>

                <a href="#about" class="secondary-button">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 11v5m0-9h.01"/>
                    </svg>
                    Learn More
                </a>
            </div>
        </div>

        <div class="hero-image">
            <img
                src="images/HospitalPic.jpg"
                alt="Healthcare team at a modern hospital"
            >
            <div class="image-caption">
                <span class="caption-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/>
                        <path d="m9 12 2 2 4-4"/>
                    </svg>
                </span>
                <span>
                    <strong>Connected healthcare</strong>
                    <small>Care. Connect. Coordinate.</small>
                </span>
            </div>
        </div>

    </section>

    <!-- ABOUT SECTION -->
    <section class="about-section" id="about">
        <div class="section-heading">
            <span class="eyebrow">ABOUT CAREPOINT</span>
            <h2>Making hospital work more connected</h2>
            <p>
                CarePoint brings essential hospital activities together
                in one organised system. It helps hospital staff access
                information and perform their responsibilities according
                to their assigned roles.
            </p>
        </div>

        <div class="about-features">
            <div class="feature">
                <svg viewBox="0 0 24 24">
                    <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
                <h3>Organised Information</h3>
                <p>Keep hospital records organised and accessible to authorised staff.</p>
            </div>

            <div class="feature">
                <svg viewBox="0 0 24 24">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                </svg>
                <h3>Staff Coordination</h3>
                <p>Support communication and coordination between departments.</p>
            </div>

            <div class="feature">
                <svg viewBox="0 0 24 24">
                    <path d="M3 12h4l3-8 4 16 3-8h4"/>
                </svg>
                <h3>Better Workflows</h3>
                <p>Help staff manage daily hospital activities more efficiently.</p>
            </div>
        </div>
    </section>

    <!-- DEPARTMENTS -->
    <section class="departments-section" id="departments">

        <div class="section-heading">
            <span class="eyebrow">OUR DEPARTMENTS</span>
            <h2>One system. Four essential roles.</h2>
            <p>Each department has its own responsibilities and access.</p>
        </div>

        <div class="department-grid">

            <!-- ADMINISTRATION -->
            <article class="department-card">
                <div class="department-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M3 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/>
                        <path d="M17 10h4v11h-4M7 7h2m4 0h2M7 11h2m4 0h2M7 15h2m4 0h2M9 21v-3h4v3"/>
                    </svg>
                </div>
                <h3>Administration</h3>
                <p>
                    Manage staff accounts, patient registration
                    and hospital administration.
                </p>
            </article>

            <!-- NURSING -->
            <article class="department-card">
                <div class="department-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M12 3v8m-4-4h8"/>
                        <path d="M5 13a7 7 0 0 0 14 0"/>
                        <path d="M3 21a9 9 0 0 1 18 0"/>
                        <path d="M9 16v2m6-2v2"/>
                    </svg>
                </div>
                <h3>Nursing</h3>
                <p>
                    Record patient observations and coordinate
                    patient care activities.
                </p>
            </article>

            <!-- DOCTORS -->
            <article class="department-card">
                <div class="department-icon">
                    <svg viewBox="0 0 24 24">
                        <circle cx="12" cy="7" r="4"/>
                        <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                        <path d="M9 13v2a3 3 0 0 0 6 0v-2"/>
                        <path d="M12 15v4m-2-2h4"/>
                    </svg>
                </div>
                <h3>Doctors</h3>
                <p>
                    Review patient information, record diagnoses
                    and manage prescriptions.
                </p>
            </article>

            <!-- PHARMACY -->
            <article class="department-card">
                <div class="department-icon">
                    <svg viewBox="0 0 24 24">
                        <path d="M10.5 3.5a6 6 0 0 1 8.5 8.5l-7 7a6 6 0 0 1-8.5-8.5z"/>
                        <path d="m8 16 8-8"/>
                    </svg>
                </div>
                <h3>Pharmacy</h3>
                <p>
                    Manage medicine stock and process
                    approved prescriptions.
                </p>
            </article>

        </div>
    </section>

    <!-- STAFF LOGIN -->
    <section class="login-section" id="staff-login">

        <div class="login-panel">

            <div class="login-heading">
                <span class="eyebrow">SECURE STAFF PORTAL</span>
                <h2>Welcome back</h2>
                <p>Sign in with your individual staff account.</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="login-form">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                >

                <label for="email">Staff email address</label>

                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24">
                        <rect x="3" y="5" width="18" height="14" rx="2"/>
                        <path d="m3 7 9 6 9-6"/>
                    </svg>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Enter your email"
                        autocomplete="username"
                        required
                    >
                </div>

                <label for="password">Password</label>

                <div class="input-wrapper">
                    <svg viewBox="0 0 24 24">
                        <rect x="4" y="10" width="16" height="11" rx="2"/>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"/>
                        <path d="M12 14v3"/>
                    </svg>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="login-button">
                    Sign In
                    <svg viewBox="0 0 24 24">
                        <path d="M5 12h14m-7-7 7 7-7 7"/>
                    </svg>
                </button>

            </form>

            <div class="login-security">
                <svg viewBox="0 0 24 24">
                    <path d="M12 22s8-4 8-11V5l-8-3-8 3v6c0 7 8 11 8 11z"/>
                    <path d="m9 12 2 2 4-4"/>
                </svg>
                Authorised hospital staff only
            </div>

        </div>

        <div class="login-side">
            <div class="large-medical-icon">
                <svg viewBox="0 0 48 48">
                    <path d="M18 4h12v14h14v12H30v14H18V30H4V18h14z" fill="currentColor"/>
                </svg>
            </div>

            <h2>CarePoint HMS</h2>

            <p>
                One connected system. Better organised healthcare.
                Supporting every department, every step of the way.
            </p>

            <div class="login-side-line"></div>
            <span>CARE · CONNECT · COORDINATE</span>
        </div>

    </section>

</main>

<!-- FOOTER -->
<footer class="footer">
    <div class="footer-brand">
        <svg viewBox="0 0 48 48">
            <path d="M18 4h12v14h14v12H30v14H18V30H4V18h14z" fill="currentColor"/>
        </svg>
        <span>
            <strong>CarePoint</strong>
            <small>Hospital Management System</small>
        </span>
    </div>

    <p>© <?= date('Y') ?> CarePoint. All rights reserved.</p>

    <p class="footer-tagline">Care. Connect. Coordinate.</p>
</footer>

</body>
</html>
