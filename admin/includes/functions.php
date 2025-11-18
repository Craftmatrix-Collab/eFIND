<?php
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}
// You can add more helper functions here
?>