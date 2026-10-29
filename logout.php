<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
session_destroy();

// Always land somewhere human-readable — this used to print raw JSON when the
// "Sign out" links navigated here directly.
header('Location: /login');
exit;
