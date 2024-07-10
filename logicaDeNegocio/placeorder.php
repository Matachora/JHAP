<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
// Elimina todos los productos del carrito, la variable ya no es necesaria ya que el pedido ha sido procesado
if (isset($_SESSION['cart'])) {
    unset($_SESSION['cart']);
}

// Eliminar código de descuento
if (isset($_SESSION['discount'])) {
    unset($_SESSION['discount']);
}
?>
<?=template_header('Place Order')?>

<div class="placeorder content-wrapper">

    <h1 class="page-title">Su pedido ha sido ordenada</h1>

    <p>¡Gracias por realizar tu pedido con nosotros! Nos comunicaremos con usted por correo electrónico con los detalles de su pedido.</p>

</div>

<?=template_footer()?>