<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <title>Captchas Gallery</title>
</head>
<body>
    <h1>Captchas gallery</h1>
    <?php for ($x=0; $x<8; $x++) { ?>
        <?php for ($y=0; $y<5; $y++) { ?>
            <img src="output.php?n=<?php echo 5*$x+$y; ?>" alt="CAPTCHA example" />
        <?php } ?>
        <br />
    <?php } ?>
</body>
</html>
