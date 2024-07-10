<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
// Elimina el producto del carrito, verifica el parámetro de URL "eliminar", este es el ID del producto, asegúrate de que sea un número y verifica si está en el carrito
if (isset($_GET['remove']) && is_numeric($_GET['remove']) && isset($_SESSION['cart']) && isset($_SESSION['cart'][$_GET['remove']])) {
    //Retirar el producto del carrito de compras
    array_splice($_SESSION['cart'], $_GET['remove'], 1);
    header('Location: ' . url('index.php?page=cart'));
    exit;
}
// Vaciar el carrito
if (isset($_POST['emptycart']) && isset($_SESSION['cart'])) {
    //Eliminar todos los productos del carrito de compras
    unset($_SESSION['cart']);
    header('Location: ' . url('index.php?page=cart'));
    exit;
}
// Actualiza las cantidades de productos en el carrito si el usuario hace clic en el botón "Actualizar" en la página del carrito de compras
if ((isset($_POST['update']) || isset($_POST['checkout'])) && isset($_SESSION['cart'])) {
    // Iterar los datos de la publicación y actualizar las cantidades de cada producto en el carrito
    foreach ($_POST as $k => $v) {
        if (strpos($k, 'quantity') !== false && is_numeric($v)) {
            $id = str_replace('quantity-', '', $k);
            // la función abs() evitará la cantidad negativa y (int) garantizará que el valor sea un número entero (número)
            $quantity = abs((int)$v);
            // Siempre haz comprobaciones y validaciones
            if (is_numeric($id) && isset($_SESSION['cart'][$id]) && $quantity > 0) {
                // ¿Se puede actualizar la cantidad?
                $canUpdate = true;
                //Comprueba si el producto tiene opciones
                if ($_SESSION['cart'][$id]['options']) {
                    $options = explode(',', $_SESSION['cart'][$id]['options']);
                    foreach ($options as $opt) {
                        $option_name = explode('-', $opt)[0];
                        $option_value = explode('-', $opt)[1];
                        $stmt = $pdo->prepare('SELECT * FROM products_options WHERE option_name = ? AND (option_value = ? OR option_value = "") AND product_id = ?');   
                        $stmt->execute([ $option_name, $option_value, $_SESSION['cart'][$id]['id'] ]);
                        $option = $stmt->fetch(PDO::FETCH_ASSOC);   
                        // Obtener cantidad de opción de carrito
                        $cart_option_quantity = get_cart_option_quantity($_SESSION['cart'][$id]['id'], $opt);
                        //Comprueba si la opción existe y la cantidad está disponible
                        if (!$option) {
                            $canUpdate = false;
                        } elseif ($option['quantity'] != -1 && $option['quantity'] < ($cart_option_quantity-$_SESSION['cart'][$id]['quantity']) + $quantity) {
                            $canUpdate = false;
                        }
                    }
                }
                //Comprueba si la cantidad del producto está disponible
                $cart_product_quantity = get_cart_product_quantity($_SESSION['cart'][$id]['id']);
                // Obtener la cantidad de producto de la base de datos
                $stmt = $pdo->prepare('SELECT quantity FROM products WHERE id = ?');
                $stmt->execute([ $_SESSION['cart'][$id]['id'] ]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                //Comprueba si la cantidad del producto está disponible
                if ($product['quantity'] != -1 && $product['quantity'] < ($cart_product_quantity-$_SESSION['cart'][$id]['quantity']) + $quantity) {
                    $canUpdate = false;
                }
                //Actualiza la cantidad si puedes actualizar
                if ($canUpdate) {
                    $_SESSION['cart'][$id]['quantity'] = $quantity;
                }
            }
        }
    }
    // Envía al usuario a la página de realizar pedido si hace clic en el botón Realizar pedido; además, el carrito no debe estar vacío
    if (isset($_POST['checkout']) && !empty($_SESSION['cart'])) {
        header('Location: ' . url('index.php?page=checkout'));
        exit;
    }
    header('Location: ' . url('index.php?page=cart'));
    exit;
}
// Verifique la variable de sesión para los productos en el carrito
$products_in_cart = isset($_SESSION['cart']) ? $_SESSION['cart'] : [];
$subtotal = 0.00;
// Si hay productos en el carrito
if ($products_in_cart) {
    // Hay productos en el carrito por lo que debemos seleccionar esos productos de la base de datos
    $array_to_question_marks = implode(',', array_fill(0, count($products_in_cart), '?'));
    //Preparar sentencia SQL
    $stmt = $pdo->prepare('SELECT p.*, (SELECT m.full_path FROM products_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = p.id ORDER BY pm.position ASC LIMIT 1) AS img FROM products p WHERE p.id IN (' . $array_to_question_marks . ')');
    // Aprovecha la función array_column para recuperar solo los ID de los productos
    $stmt->execute(array_column($products_in_cart, 'id'));
    // Busca los productos de la base de datos y devuelve el resultado como una matriz
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Iterar los productos en el carrito y agregar los metadatos (nombre del producto, descripción, etc.)
    foreach ($products_in_cart as &$cart_product) {
        foreach ($products as $product) {
            if ($cart_product['id'] == $product['id']) {
                $cart_product['meta'] = $product;
                //Calcular el subtotal
                $subtotal += (float)$cart_product['options_price'] * (int)$cart_product['quantity'];
            }
        }
    }
}
?>
<?=template_header('Shopping Cart')?>

<div class="cart content-wrapper">

    <h1 class="page-title">Carrito</h1>

    <form action="" method="post" class="form">
        <table>
            <thead>
                <tr>
                    <td colspan="2">Producto</td>
                    <td class="rhide"></td>
                    <td class="rhide">Precio</td>
                    <td>Cantidad</td>
                    <td>Total</td>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products_in_cart)): ?>
                <tr>
                    <td colspan="20" class="no-results">La cesta esta vacía</td>
                </tr>
                <?php else: ?>
                <?php foreach ($products_in_cart as $num => $product): ?>
                <tr>
                    <td class="img">
                        <?php if (!empty($product['meta']['img']) && file_exists($product['meta']['img'])): ?>
                        <a href="<?=url('index.php?page=product&id=' . $product['id'])?>">
                            <img src="<?=base_url?><?=$product['meta']['img']?>" width="50" height="50" alt="<?=$product['meta']['title']?>">
                        </a>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?=url('index.php?page=product&id=' . $product['id'])?>"><?=$product['meta']['title']?></a>
                        <br>
                        <a href="<?=url('index.php?page=cart', ['remove' => $num])?>" class="remove">Eliminar</a>
                    </td>
                    <td class="options rhide">
                        <?=str_replace(',', '<br>', htmlspecialchars($product['options'], ENT_QUOTES))?>
                        <input type="hidden" name="options" value="<?=htmlspecialchars($product['options'], ENT_QUOTES)?>">
                    </td>
                    <td class="price rhide"><?=currency_code?><?=number_format($product['options_price'],2)?></td>
                    <td class="quantity">
                        <input type="number" class="ajax-update form-input" name="quantity-<?=$num?>" value="<?=$product['quantity']?>" min="1" <?php if ($product['meta']['quantity'] != -1): ?>max="<?=$product['meta']['quantity']?>"<?php endif; ?> placeholder="Quantity" required>
                    </td>
                    <td class="price product-total"><?=currency_code?><?=number_format($product['options_price'] * $product['quantity'],2)?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="total">
            <span class="text">Subtotal</span>
            <span class="price"><?=currency_code?><?=number_format($subtotal,2)?></span>
            <span class="note">El envio calculado al finalizar la compra</span>
        </div>

        <div class="buttons">
            <input type="submit" value="Actualizar" name="update" class="btn"<?=empty($products_in_cart)?' disabled':''?>>
            <input type="submit" value="Vaciar carrito" name="emptycart" class="btn"<?=empty($products_in_cart)?' disabled':''?>>
            <input type="submit" value="Pagar" name="checkout" class="btn"<?=empty($products_in_cart)?' disabled':''?>>
        </div>

    </form>

</div>

<?=template_footer()?>