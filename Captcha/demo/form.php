<?php
require_once __DIR__.'/../vendor/autoload.php';
use Gregwar\Captcha\PhraseBuilder;

// We need the session to check the phrase after submitting
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Captcha Form</title>
</head>
<body>
    <?php
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            if (isset($_SESSION['phrase']) && PhraseBuilder::comparePhrases($_SESSION['phrase'], $_POST['phrase'])) {
                echo "<h1>Captcha is valid !</h1>";
            } else {
                echo "<h1>Captcha is not valid!</h1>";
            }
            unset($_SESSION['phrase']);
        }
    ?>
    <form method="post">
        Copy the CAPTCHA:
        <img src="session.php" alt="CAPTCHA" />
        <input type="text" name="phrase" />
        <input type="submit" />
    </form>
</body>
</html>
