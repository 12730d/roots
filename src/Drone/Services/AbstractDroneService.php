<?php

declare(strict_types=1);

namespace ROOTS\Drone\Services;

use mysqli;
use ROOTS\Config\Database;
use DroneServiceException;

/**
 * AbstractDroneService - Base class for drone service operations
 */
abstract class AbstractDroneService
{
    protected mysqli $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }
}
