<?php


namespace ROOTS\Config;

use ROOTS\Config\EnvLoader;

class DatabaseInitializer
{
    public static function init(): void
    {
        // Ensure EnvLoader is loaded
        EnvLoader::load();

        $password = $_ENV["SECRET"] ?? '';
        // Using raw connection here as in the original init_db.php
        // to avoid dependency loops if Database class does something complex,
        // though ideally we should use Database class.
        // Original init_db.php used root directly.
        $db = \mysqli_connect("localhost", "root", $password, "users_app");

        if ($db) {
            $createTable = "CREATE TABLE IF NOT EXISTS points_transactions (
                id int(11) NOT NULL AUTO_INCREMENT,
                username varchar(255) NOT NULL,
                points_added int(11) NOT NULL,
                bonus_points int(11) NOT NULL,
                transaction_hash varchar(255) NOT NULL,
                transaction_date datetime NOT NULL,
                status enum('pending','completed','failed') NOT NULL DEFAULT 'completed',
                PRIMARY KEY (id),
                KEY username (username),
                KEY transaction_hash (transaction_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            \mysqli_query($db, $createTable);
            \mysqli_close($db);
        }
    }
}
