<?php
// CarePoint secure logout
session_start();

// Remove all session data.
$_SESSION = [];

// Remove the session cookie from the browser.
if (ini_get("session.use_cookies")) {
    $cookieParams = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $cookieParams["path"],
        $cookieParams["domain"],
        $cookieParams["secure"],
        $cookieParams["httponly"]
    );
}

// Destroy the session on the server.
session_destroy();

// Return the user to the login page.
header("Location: index.php");
exit();
?>
