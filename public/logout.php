<?php
// /htdocs/it_repair/public/logout.php
session_start();
require_once __DIR__.'/../includes/functions.php';
require_once __DIR__.'/../includes/auth.php';
auth_logout();
redirect(base_url('login.php'));
