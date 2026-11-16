<?php

session_start();

date_default_timezone_set('Africa/Johannesburg');



require_once "db.php";



// ==========================================

// 1. LOGIN AND ROLE SECURITY

// ==========================================



if (

    !isset($_SESSION["logged_in"]) ||

    $_SESSION["logged_in"] !== true

) {

    header("Location: index.php");

    exit();

}



$role = strtolower($_SESSION["staff_role"] ?? "");



// Administrator, Nurse and Doctor may register and search patients

$allowedRoles = ["administrator", "nurse", "doctor"];



if (!in_array($role, $allowedRoles, true)) {

    http_response_code(403);

    exit("Access denied. You are not authorised to access patient records.");

}



$staffId = (int)($_SESSION["staff_id"] ?? 0);



// ==========================================

// 2. HELPERS

// ==========================================



if (empty($_SESSION["csrf_token"])) {

    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

}



function e($value) {

    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");

}



function selected($current, $option) {

    return $current === $option ? " selected" : "";

}



// Records an important action (user, action, record affected, outcome)

function audit_log(PDO $pdo, int $userId, string $action, string $entityType, int $entityId, string $outcome = "success"): void {

    try {

        $log = $pdo->prepare("

            INSERT INTO audit_log (user_id, action, entity_type, entity_id, outcome)

            VALUES (?, ?, ?, ?, ?)

        ");

        $log->execute([$userId, $action, $entityType, $entityId, $outcome]);

    } catch (PDOException $e) {

        error_log("Audit log error: " . $e->getMessage());

    }

}



$validGenders = [

    "male" => "Male",

    "female" => "Female",

    "other" => "Other",

    "prefer_not_to_say" => "Prefer not to say"

];



$validProvinces = [

    "Eastern Cape",

    "Free State",

    "Gauteng",

    "KwaZulu-Natal",

    "Limpopo",

    "Mpumalanga",

    "Northern Cape",

    "North West",

    "Western Cape"

];



$validRelationships = [

    "Parent",

    "Spouse",

    "Child",

    "Sibling",

    "Guardian",

    "Relative",

    "Friend",

    "Other"

];



// One-time message shown after a successful registration

$message = "";

$messageType = "";



if (!empty($_SESSION["flash"])) {

    $message = $_SESSION["flash"]["message"];

    $messageType = $_SESSION["flash"]["type"];

    unset($_SESSION["flash"]);

}



$search = trim($_GET["search"] ?? "");



// Form values (kept on screen if registration fails)

$old = [

    "national_id" => "",

    "first_name" => "",

    "last_name" => "",

    "date_of_birth" => "",

    "gender" => "",

    "phone" => "",

    "email" => "",

    "address" => "",

    "suburb" => "",

    "city" => "",

    "province" => "",

    "postal_code" => "",

    "next_of_kin_name" => "",

    "next_of_kin_relationship" => "",

    "next_of_kin_phone" => "",

    "next_of_kin_alternative_phone" => ""

];



// ==========================================

// 3. REGISTER A NEW PATIENT

// ==========================================



if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if ($role !== "administrator") {
        http_response_code(403);
        exit("Access denied. Only administrators can register patients.");
    }



    $token = (string)($_POST["csrf_token"] ?? "");



    if (!hash_equals($_SESSION["csrf_token"], $token)) {

        http_response_code(403);

        exit("Invalid request. Please refresh the page and try again.");

    }



    foreach ($old as $field => $default) {

        $old[$field] = trim($_POST[$field] ?? "");

    }



    $phonePattern = '/^[0-9+()\s\\-]{7,30}$/';



    // Validate input

    if (!preg_match('/^[0-9]{13}$/', $old["national_id"])) {

        $message = "Please enter a valid 13-digit South African ID number.";



    } elseif ($old["first_name"] === "" || $old["last_name"] === "") {

        $message = "First name and last name are required.";



    } elseif ($old["gender"] !== "" && !array_key_exists($old["gender"], $validGenders)) {

        $message = "Please select a valid gender.";



    } elseif ($old["province"] !== "" && !in_array($old["province"], $validProvinces, true)) {

        $message = "Please select a valid province.";



    } elseif (

        $old["next_of_kin_relationship"] !== "" &&

        !in_array($old["next_of_kin_relationship"], $validRelationships, true)

    ) {

        $message = "Please select a valid next-of-kin relationship.";



    } elseif ($old["email"] !== "" && !filter_var($old["email"], FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";



    } elseif ($old["phone"] !== "" && !preg_match($phonePattern, $old["phone"])) {

        $message = "Please enter a valid patient contact number.";



    } elseif ($old["next_of_kin_phone"] !== "" && !preg_match($phonePattern, $old["next_of_kin_phone"])) {

        $message = "Please enter a valid next-of-kin contact number.";



    } elseif (

        $old["next_of_kin_alternative_phone"] !== "" &&

        !preg_match($phonePattern, $old["next_of_kin_alternative_phone"])

    ) {

        $message = "Please enter a valid alternative contact number.";



    } elseif (

        $old["date_of_birth"] !== "" &&

        (

            !preg_match('/^\d{4}-\d{2}-\d{2}$/', $old["date_of_birth"]) ||

            !checkdate(

                (int)substr($old["date_of_birth"], 5, 2),

                (int)substr($old["date_of_birth"], 8, 2),

                (int)substr($old["date_of_birth"], 0, 4)

            ) ||

            $old["date_of_birth"] > date("Y-m-d")

        )

    ) {

        $message = "Please enter a valid date of birth.";



    } else {



        try {



            // Prevent duplicate registrations

            $check = $pdo->prepare("

                SELECT patient_id

                FROM patients

                WHERE national_id = :national_id

                LIMIT 1

            ");



            $check->execute(["national_id" => $old["national_id"]]);



            if ($check->fetch()) {



                $message = "This ID number is already registered. Please search for the existing patient instead.";



            } else {



                // Generate a unique CarePoint reference number

                do {

                    $patientNumber = "CP-" . date("Y") . "-" . strtoupper(bin2hex(random_bytes(3)));



                    $checkNumber = $pdo->prepare("

                        SELECT patient_id

                        FROM patients

                        WHERE patient_number = :patient_number

                    ");



                    $checkNumber->execute(["patient_number" => $patientNumber]);



                } while ($checkNumber->fetch());



                $nullable = function ($value) {

                    return $value !== "" ? $value : null;

                };



                $stmt = $pdo->prepare("

                    INSERT INTO patients (

                        patient_number,

                        national_id,

                        first_name,

                        last_name,

                        date_of_birth,

                        gender,

                        phone,

                        email,

                        address,

                        suburb,

                        city,

                        province,

                        postal_code,

                        next_of_kin_name,

                        next_of_kin_relationship,

                        next_of_kin_phone,

                        next_of_kin_alternative_phone,

                        created_by

                    ) VALUES (

                        :patient_number,

                        :national_id,

                        :first_name,

                        :last_name,

                        :dob,

                        :gender,

                        :phone,

                        :email,

                        :address,

                        :suburb,

                        :city,

                        :province,

                        :postal_code,

                        :kin_name,

                        :kin_relationship,

                        :kin_phone,

                        :kin_alt_phone,

                        :created_by

                    )

                ");



                $stmt->execute([

                    "patient_number" => $patientNumber,

                    "national_id"    => $old["national_id"],

                    "first_name"     => $old["first_name"],

                    "last_name"      => $old["last_name"],

                    "dob"            => $nullable($old["date_of_birth"]),

                    "gender"         => $nullable($old["gender"]),

                    "phone"          => $nullable($old["phone"]),

                    "email"          => $nullable($old["email"]),

                    "address"        => $nullable($old["address"]),

                    "suburb"         => $nullable($old["suburb"]),

                    "city"           => $nullable($old["city"]),

                    "province"       => $nullable($old["province"]),

                    "postal_code"    => $nullable($old["postal_code"]),

                    "kin_name"       => $nullable($old["next_of_kin_name"]),

                    "kin_relationship" => $nullable($old["next_of_kin_relationship"]),

                    "kin_phone"      => $nullable($old["next_of_kin_phone"]),

                    "kin_alt_phone"  => $nullable($old["next_of_kin_alternative_phone"]),

                    "created_by"     => $staffId

                ]);



                audit_log($pdo, $staffId, "patient_registered", "patient", (int)$pdo->lastInsertId());



                // Redirect so refreshing the page does not resubmit the form

                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                $_SESSION["flash"] = [

                    "message" => "Patient registered successfully. CarePoint reference: " . $patientNumber,

                    "type" => "success"

                ];



                header("Location: patients.php");

                exit();

            }



        } catch (PDOException $e) {



            error_log("Patient registration error: " . $e->getMessage());



            if ($e->getCode() === "23000") {

                $message = "This patient is already registered. Please search for the existing record.";

            } else {

                $message = "Unable to register patient. Please try again.";

            }



            audit_log($pdo, $staffId, "patient_registered", "patient", 0, "failure");

        }

    }



    $messageType = "error";

}



// ==========================================

// 4. SEARCH PATIENT RECORDS

// ==========================================



$patients = [];



try {



    $columns = "

        patient_id,

        patient_number,

        national_id,

        first_name,

        last_name,

        date_of_birth,

        gender,

        phone,

        city,

        next_of_kin_name,

        next_of_kin_phone

    ";



    if ($search !== "") {



        $stmt = $pdo->prepare("

            SELECT $columns

            FROM patients

            WHERE national_id LIKE :national_id

               OR patient_number LIKE :patient_number

               OR first_name LIKE :first_name

               OR last_name LIKE :last_name

               OR CONCAT(first_name, ' ', last_name) LIKE :full_name

               OR phone LIKE :phone

            ORDER BY created_at DESC

            LIMIT 50

        ");



        // Treat % and _ typed by the user as normal characters

        $term = "%" . addcslashes($search, "%_\\\\") . "%";



        $stmt->execute([

            "national_id"    => $term,

            "patient_number" => $term,

            "first_name"     => $term,

            "last_name"      => $term,

            "full_name"      => $term,

            "phone"          => $term

        ]);



    } else {



        $stmt = $pdo->query("

            SELECT $columns

            FROM patients

            ORDER BY created_at DESC

            LIMIT 50

        ");

    }



    $patients = $stmt->fetchAll();



} catch (PDOException $e) {



    error_log("Patient search error: " . $e->getMessage());



    $message = "Unable to load patient records. Please try again.";

    $messageType = "error";

}



?>

<!DOCTYPE html>

<html lang="en">



<head>



    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">



    <title>Patient Management | CarePoint</title>



    <link rel="stylesheet" href="dashboard.css?v=5">

    <link rel="stylesheet" href="patients.css?v=5">



</head>



<body>



<div class="dashboard">



    <!-- SIDEBAR -->



    <aside class="sidebar">



        <div class="logo">

            <span class="logo-symbol">✚</span>

            <span>CarePoint</span>

        </div>



        <p class="sidebar-label">MAIN MENU</p>



        <nav class="sidebar-nav">



            <a href="dashboard.php">Dashboard</a>



            <a href="patients.php" class="active">Patients</a>



            <?php if ($role === "administrator"): ?>



                <a href="staff.php">Staff Management</a>

                <a href="queue.php">Patient Queue</a>



            <?php elseif ($role === "nurse"): ?>



                <a href="queue.php">Patient Queue</a>

                <a href="nurse_inbox.php">Nurse Inbox</a>

                <a href="observations.php">Observations</a>



            <?php elseif ($role === "doctor"): ?>



                <a href="consultations.php">Consultations</a>

                <a href="prescriptions.php">Prescriptions</a>



            <?php endif; ?>



        </nav>



        <div class="sidebar-bottom">

            <a href="logout.php" class="logout-link">Log Out</a>

        </div>



    </aside>



    <!-- MAIN CONTENT -->



    <main class="main-content">



        <header class="topbar">



            <div>

                <h2>Patient Management</h2>

                <p><?= $role === "administrator" ? "Register and manage patient records" : "Search and view patient records" ?></p>

            </div>



            <div class="profile">



                <div class="profile-avatar">

                    <?= e(strtoupper(substr($_SESSION["staff_name"] ?? "U", 0, 1))) ?>

                </div>



                <div class="profile-details">

                    <strong><?= e($_SESSION["staff_name"] ?? "Staff") ?></strong>

                    <span><?= e(ucfirst($role)) ?></span>

                </div>



            </div>



        </header>



        <!-- PAGE TITLE -->



        <section class="patients-heading">



            <h1><?= $role === "administrator" ? "Patient Management" : "Patient Records" ?></h1>



            <p>

                Search for the patient first to avoid duplicates, then register

                them using their South African ID number.

            </p>



        </section>



        <!-- NOTIFICATIONS -->



        <?php if ($message !== ""): ?>



            <div class="notice <?= e($messageType) ?>">

                <?= e($message) ?>

            </div>



        <?php endif; ?>



<?php if ($role === "administrator"): ?>
        <!-- REGISTRATION FORM -->



        <section class="patient-panel">



            <form method="POST" action="patients.php" class="patient-form">



                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">



                <!-- PERSONAL INFORMATION -->



                <div class="form-section-heading">

                    <h2>01. Personal Information</h2>

                    <p>Basic details used to identify the patient. Fields marked \* are required.</p>

                </div>



                <div class="form-grid">



                    <div class="form-group">

                        <label for="national_id">South African ID Number \*</label>

                        <input

                            type="text"

                            id="national_id"

                            name="national_id"

                            maxlength="13"

                            minlength="13"

                            pattern="[0-9]{13}"

                            inputmode="numeric"

                            placeholder="Enter 13-digit ID number"

                            value="<?= e($old["national_id"]) ?>"

                            required

                        >

                    </div>



                    <div class="form-group">

                        <label for="first_name">First Name \*</label>

                        <input

                            type="text"

                            id="first_name"

                            name="first_name"

                            maxlength="100"

                            value="<?= e($old["first_name"]) ?>"

                            required

                        >

                    </div>



                    <div class="form-group">

                        <label for="last_name">Last Name \*</label>

                        <input

                            type="text"

                            id="last_name"

                            name="last_name"

                            maxlength="100"

                            value="<?= e($old["last_name"]) ?>"

                            required

                        >

                    </div>



                    <div class="form-group">

                        <label for="date_of_birth">Date of Birth</label>

                        <input

                            type="date"

                            id="date_of_birth"

                            name="date_of_birth"

                            max="<?= date("Y-m-d") ?>"

                            value="<?= e($old["date_of_birth"]) ?>"

                        >

                    </div>



                    <div class="form-group">

                        <label for="gender">Gender</label>

                        <select id="gender" name="gender">

                            <option value="">Select an option</option>

                            <?php foreach ($validGenders as $value => $label): ?>

                                <option value="<?= e($value) ?>"<?= selected($old["gender"], $value) ?>><?= e($label) ?></option>

                            <?php endforeach; ?>

                        </select>

                    </div>



                    <div class="form-group">

                        <label for="phone">Patient Contact Number</label>

                        <input

                            type="tel"

                            id="phone"

                            name="phone"

                            maxlength="30"

                            placeholder="Patient phone number"

                            value="<?= e($old["phone"]) ?>"

                        >

                    </div>



                    <div class="form-group full-width">

                        <label for="email">Email Address</label>

                        <input

                            type="email"

                            id="email"

                            name="email"

                            maxlength="150"

                            placeholder="example@email.com"

                            value="<?= e($old["email"]) ?>"

                        >

                    </div>



                </div>



                <!-- RESIDENTIAL ADDRESS -->



                <div class="form-section-heading">

                    <h2>02. Residential Address</h2>

                    <p>Where the patient currently lives.</p>

                </div>



                <div class="form-grid">



                    <div class="form-group full-width">

                        <label for="address">Street Address / House Number</label>

                        <input

                            type="text"

                            id="address"

                            name="address"

                            maxlength="255"

                            placeholder="Street name and house number"

                            value="<?= e($old["address"]) ?>"

                        >

                    </div>



                    <div class="form-group">

                        <label for="suburb">Suburb / Township</label>

                        <input

                            type="text"

                            id="suburb"

                            name="suburb"

                            maxlength="100"

                            value="<?= e($old["suburb"]) ?>"

                        >

                    </div>



                    <div class="form-group">

                        <label for="city">City / Town</label>

                        <input

                            type="text"

                            id="city"

                            name="city"

                            maxlength="100"

                            value="<?= e($old["city"]) ?>"

                        >

                    </div>



                    <div class="form-group">

                        <label for="province">Province</label>

                        <select id="province" name="province">

                            <option value="">Select province</option>

                            <?php foreach ($validProvinces as $province): ?>

                                <option value="<?= e($province) ?>"<?= selected($old["province"], $province) ?>><?= e($province) ?></option>

                            <?php endforeach; ?>

                        </select>

                    </div>



                    <div class="form-group">

                        <label for="postal_code">Postal Code</label>

                        <input

                            type="text"

                            id="postal_code"

                            name="postal_code"

                            maxlength="20"

                            value="<?= e($old["postal_code"]) ?>"

                        >

                    </div>



                </div>



                <!-- NEXT OF KIN -->



                <div class="form-section-heading">

                    <h2>03. Next of Kin / Emergency Contact</h2>

                    <p>Contact details of a person who can be contacted when necessary.</p>

                </div>



                <div class="form-grid">



                    <div class="form-group">

                        <label for="next_of_kin_name">Full Name</label>

                        <input

                            type="text"

                            id="next_of_kin_name"

                            name="next_of_kin_name"

                            maxlength="150"

                            value="<?= e($old["next_of_kin_name"]) ?>"

                        >

                    </div>



                    <div class="form-group">

                        <label for="next_of_kin_relationship">Relationship to Patient</label>

                        <select id="next_of_kin_relationship" name="next_of_kin_relationship">

                            <option value="">Select relationship</option>

                            <?php foreach ($validRelationships as $relationship): ?>

                                <option value="<?= e($relationship) ?>"<?= selected($old["next_of_kin_relationship"], $relationship) ?>><?= e($relationship) ?></option>

                            <?php endforeach; ?>

                        </select>

                    </div>



                    <div class="form-group">

                        <label for="next_of_kin_phone">Contact Number</label>

                        <input

                            type="tel"

                            id="next_of_kin_phone"

                            name="next_of_kin_phone"

                            maxlength="30"

                            value="<?= e($old["next_of_kin_phone"]) ?>"

                        >

                    </div>



                    <div class="form-group">

                        <label for="next_of_kin_alternative_phone">Alternative Contact Number</label>

                        <input

                            type="tel"

                            id="next_of_kin_alternative_phone"

                            name="next_of_kin_alternative_phone"

                            maxlength="30"

                            value="<?= e($old["next_of_kin_alternative_phone"]) ?>"

                        >

                    </div>



                </div>



                <!-- BUTTONS -->



                <div class="form-actions">



                    <a href="patients.php" class="secondary-button">Clear Form</a>



                    <button type="submit" class="primary-button">Register Patient</button>



                </div>



            </form>



        </section>



<?php else: ?>
        <section class="patient-panel access-note">
            <h2>Patient Registration</h2>
            <p>Patient registration is managed by the Administrator. You can search for and view existing patient records below.</p>
        </section>
<?php endif; ?>

        <!-- PATIENT RECORDS -->



        <section class="patient-panel records-panel">



            <div class="panel-heading">

                <h2>Patient Records</h2>

                <p>

                    Search using the patient's South African ID number,

                    CarePoint reference, name or contact number.

                </p>

            </div>



            <form method="GET" action="patients.php" class="search-form">



                <input

                    type="search"

                    name="search"

                    placeholder="Search by ID number, reference, name or phone..."

                    value="<?= e($search) ?>"

                >



                <button type="submit" class="primary-button">Search Patient</button>



                <?php if ($search !== ""): ?>

                    <a href="patients.php" class="clear-search">Clear Search</a>

                <?php endif; ?>



            </form>



            <div class="table-wrap">



                <table class="patient-table">



                    <thead>

                        <tr>

                            <th>CarePoint Ref</th>

                            <th>South African ID</th>

                            <th>Patient Name</th>

                            <th>Date of Birth</th>

                            <th>Phone</th>

                            <th>City / Town</th>

                            <th>Next of Kin</th>

                            <th>Next of Kin Phone</th>

                        </tr>

                    </thead>



                    <tbody>



                    <?php if (count($patients) > 0): ?>



                        <?php foreach ($patients as $patient): ?>



                            <tr>

                                <td class="nowrap"><?= e($patient["patient_number"]) ?></td>

                                <td class="nowrap"><strong><?= e($patient["national_id"]) ?></strong></td>

                                <td><?= e($patient["first_name"] . " " . $patient["last_name"]) ?></td>

                                <td class="nowrap"><?= e($patient["date_of_birth"] ?: "Not provided") ?></td>

                                <td class="nowrap"><?= e($patient["phone"] ?: "Not provided") ?></td>

                                <td><?= e($patient["city"] ?: "Not provided") ?></td>

                                <td><?= e($patient["next_of_kin_name"] ?: "Not provided") ?></td>

                                <td class="nowrap"><?= e($patient["next_of_kin_phone"] ?: "Not provided") ?></td>

                            </tr>



                        <?php endforeach; ?>



                    <?php else: ?>



                        <tr>

                            <td colspan="8" class="empty-records">No patient records found.</td>

                        </tr>



                    <?php endif; ?>



                    </tbody>



                </table>



            </div>



            <p class="record-count">

                Showing <?= count($patients) ?> record(s). Maximum 50.

            </p>



        </section>



    </main>



</div>



</body>

</html>
