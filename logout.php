<?php

declare(strict_types=1);

require_once __DIR__ . "/vendor/autoload.php";

use ROOTS\Auth\Session;

// Use the central session management to handle destruction
Session::start();
Session::destroy();

// PHP redirect as primary method (works even if JS fails)
if (!headers_sent()) {
    header("Location: /login.php");
}

// Clear localStorage on logout with JavaScript fallback
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="refresh" content="0;url=/login.php">
    <script>
        // Clear navbar visibility state on logout
        localStorage.removeItem('navbarHidden');
        // JavaScript redirect as fallback
        window.location.href = '/login.php';
    </script>
</head>
<body>
    <p>Redirecting to the login page...</p>
    <p><a href="/login.php">Click here if you are not automatically redirected</a></p>
</body>
</html>
<?php
exit;