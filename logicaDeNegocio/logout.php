<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
// comprobar si el cliente ha iniciado sesión...
if (isset($_SESSION['account_loggedin'])) {
    // Elimina las variables de sesión
    unset($_SESSION['account_loggedin']);
    unset($_SESSION['account_id']);
    unset($_SESSION['account_role']);
    unset($_SESSION['account_name']);
}
// Redirigir a la página de inicio
header('Location: ' . url('index.php'));
?>
