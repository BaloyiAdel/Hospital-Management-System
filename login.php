
<?php
session_start();

require_once "db.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {
        header("Location: index.php?error=empty");
        exit();
    }

    try {
        $sql = "SELECT staff_id, full_name, email, password_hash, role, status
                FROM staff
                WHERE email = :email
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(["email" => $email]);

        $staff = $stmt->fetch();

        if (
            $staff &&
            $staff["status"] === "active" &&
            password_verify($password, $staff["password_hash"])
        ) {

            session_regenerate_id(true);

            $_SESSION["logged_in"] = true;
            $_SESSION["staff_id"] = $staff["staff_id"];
            $_SESSION["staff_name"] = $staff["full_name"];
            $_SESSION["staff_role"] = $staff["role"];

            header("Location: dashboard.php");
            exit();

        } else {
            header("Location: index.php?error=invalid");
            exit();
        }

    } catch (PDOException $e) {
        error_log("Login error: " . $e->getMessage());
        die("Unable to process login. Please try again.");
    }

} else {
    header("Location: index.php");
    exit();
}
