<?php
// Back-compat shim: some scripts may include includes/db.php.
// Delegate to the real PDO connection defined in config/db.php.
require_once __DIR__ . '/../config/db.php';
