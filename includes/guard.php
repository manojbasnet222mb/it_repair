<?php
function require_login(){
  if (empty($_SESSION['user'])) {
    $to = base_url('login.php');
    redirect($to);
  }
}

function require_role(string ...$roles){
  require_login();
  $u = $_SESSION['user'];
  if (!in_array($u['role'], $roles, true)) {
    redirect(base_url('index.php'));
  }
}
