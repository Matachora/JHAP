<?php
define('shoppingcart', true);
// Determinar la URL base
$base_url = isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 'https' : 'http';
$base_url .= '://' . rtrim($_SERVER['HTTP_HOST'], '/');
$base_url .= $_SERVER['SERVER_PORT'] == 80 || $_SERVER['SERVER_PORT'] == 443 || strpos($_SERVER['HTTP_HOST'], ':') !== false ? '' : ':' . $_SERVER['SERVER_PORT'];
$base_url .= '/' . ltrim(substr(str_replace('\\', '/', realpath(__DIR__)), strlen($_SERVER['DOCUMENT_ROOT'])), '/');
define('base_url', rtrim($base_url, '/') . '/');

// Inicializa una nueva sesión
session_start();
// Incluya el archivo de configuración, este contiene configuraciones que puede cambiar
include 'config.php';
// Incluir funciones y conectarnos a la base de datos
include 'functions.php';
// Conectarse a la base de datos MySQL
$pdo = pdo_connect_mysql();
// variable de error de salida
$error = '';
// Definir todas las rutas para todas las páginas.
$url = routes([
    '/' => 'index.php?page=home',
    '/home' => 'index.php?page=home',
    '/product/{id}' => 'index.php?page=product&id={id}',
    '/products' => 'index.php?page=products',
    '/myaccount' => 'index.php?page=myaccount',
    '/myaccount/{tab}' => 'index.php?page=myaccount&tab={tab}',
    '/download/{id}' => 'index.php?page=download&id={id}',
    '/cart' => 'index.php?page=cart',
    '/checkout' => 'index.php?page=checkout',
    '/subscribe/{method}' => 'index.php?page=subscribe&method={method}',
    '/placeorder' => 'index.php?page=placeorder',
    '/search/{query}' => 'index.php?page=search&query={query}',
    '/logout' => 'index.php?page=logout'
]);
// Comprobar si existe la ruta
if ($url) {
    include $url;
} else {
//la página que verá a entrar
    $page = isset($_GET['page']) && file_exists($_GET['page'] . '.php') ? $_GET['page'] : 'home';
    // Incluir la página solicitada
    include $page . '.php';
}
?>