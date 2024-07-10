<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
// Variable de error de validación
$validation_error = '';
// Verifique id 
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE product_status = 1 AND (BINARY id = ? OR url_slug = ?)');
    $stmt->execute([ $_GET['id'], $_GET['id'] ]);
    // Busca el producto de la base de datos
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    // Comprobar si el producto existe 
    if (!$product) {
        // arreglar un error
        http_response_code(404);
        exit('Product does not exist!');
    }
    // Seleccione media
    $stmt = $pdo->prepare('SELECT m.*, pm.position FROM products_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = ? ORDER BY pm.position ASC');
    $stmt->execute([ $product['id'] ]);
    // Busca las imágenes del producto de la base de datos 
    $product_media = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $pdo->prepare('SELECT CONCAT(option_name, "::", option_type, "::", required) AS k, option_value, quantity, price, price_modifier, weight, weight_modifier, option_type, required FROM products_options WHERE product_id = ? ORDER BY position ASC, id');
    $stmt->execute([ $product['id'] ]);
    // Obtiene las opciones del producto de la base de datos 
    $product_options = $stmt->fetchAll(PDO::FETCH_GROUP);
    //Comprueba si el producto está en la lista de deseos
    $on_wishlist = false;
    // Comprobar si el usuario ha iniciado sesión
    if (isset($_SESSION['account_loggedin'])) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM wishlist WHERE product_id = ? AND account_id = ?');
        $stmt->execute([ $product['id'], $_SESSION['account_id'] ]);
        $on_wishlist = $stmt->fetchColumn() > 0 ? true : false;
    }
    $meta = '
        <meta property="og:url" content="' . url('index.php?page=product&id=' . ($product['url_slug'] ? $product['url_slug']  : $product['id'])) . '">
        <meta property="og:title" content="' . $product['title'] . '">
    ';
    if (isset($product_media[0]) && file_exists($product_media[0]['full_path'])) {
        $meta .= '<meta property="og:image" content="' . base_url . $product_media[0]['full_path'] . '">';
    }
    // Compruebe si el usuario hizo clic en el botón Agregar a la lista de deseos
    if (isset($_POST['add_to_wishlist'])) {
        // Comprobar si el usuario ha iniciado sesión
        if (isset($_SESSION['account_loggedin'])) {
            $stmt = $pdo->prepare('SELECT * FROM wishlist WHERE product_id = ? AND account_id = ?');
            $stmt->execute([ $product['id'], $_SESSION['account_id'] ]);
            // Busca el producto de la base de datos
            $wishlist_item = $stmt->fetch(PDO::FETCH_ASSOC);
            //Comprueba si el producto ya está en la lista de deseos
            if ($wishlist_item) {
                // El producto ya está en la lista de deseos
                $validation_error = '¡El producto ya está en tu lista de deseos!';
            } else {
                // El producto no está en la lista de deseos
                $stmt = $pdo->prepare('INSERT INTO wishlist (product_id, account_id, created) VALUES (?, ?, ?)');
                $stmt->execute([ $product['id'], $_SESSION['account_id'], date('Y-m-d H:i:s') ]);
                header('Location: ' . url('index.php?page=product&id=' . ($product['url_slug'] ? $product['url_slug']  : $product['id'])));
                exit;
            }
        } else {
            header('Location: ' . url('index.php?page=myaccount'));
            exit;
        }
    // Comprobar si el usuario hizo clic en el botón Eliminar de la lista de deseos
    } else if (isset($_POST['remove_from_wishlist'])) {
        // Comprobar si el usuario ha iniciado sesión
        if (isset($_SESSION['account_loggedin'])) {
            $stmt = $pdo->prepare('DELETE FROM wishlist WHERE product_id = ? AND account_id = ?');
            $stmt->execute([ $product['id'], $_SESSION['account_id'] ]);
            header('Location: ' . url('index.php?page=product&id=' . ($product['url_slug'] ? $product['url_slug']  : $product['id'])));
            exit;
        } else {
            header('Location: ' . url('index.php?page=myaccount'));
            exit;
        }
    // Si el usuario hizo clic en el botón Agregar al carrito
    } else if ($_POST) {
        $quantity = isset($_POST['quantity']) && is_numeric($_POST['quantity']) ? abs((int)$_POST['quantity']) : 1;
        // Obtener opciones de productos
        $options = '';
        $options_price = (float)$product['price'];
        $options_weight = (float)$product['weight'];
        // Iterar datos de publicación
        foreach ($_POST as $k => $v) {
            // Validar opciones
            if (strpos($k, 'option-') !== false) {
                if (is_array($v)) {
                    foreach ($v as $vv) {
                        if (empty($vv)) continue;
                        // Reemplazar guiones bajos con espacios y eliminar opción-prefijo
                        $options .= str_replace(['_', 'option-'], [' ', ''], $k) . '-' . $vv . ',';
                        //Obtiene la opción de la base de datos
                        $stmt = $pdo->prepare('SELECT * FROM products_options WHERE option_name = ? AND option_value = ? AND product_id = ?');
                        $stmt->execute([ str_replace(['_', 'option-'], [' ', ''], $k), $vv, $product['id'] ]);
                        $option = $stmt->fetch(PDO::FETCH_ASSOC);
                        // Obtener cantidad de opción de carrito
                        $cart_option_quantity = get_cart_option_quantity($product['id'], str_replace(['_', 'option-'], [' ', ''], $k) . '-' . $vv);
                        //Comprueba si la opción existe y la cantidad está disponible
                        if ($option && ($option['quantity'] == -1 || $option['quantity']-$quantity-$cart_option_quantity >= 0)) {
                            $options_price = $option['price_modifier'] == 'add' ? $options_price + $option['price'] : $options_price - $option['price'];
                            $options_weight = $option['weight_modifier'] == 'add' ? $options_weight + $option['weight'] : $options_weight - $option['weight'];
                        } else {
                            $validation_error = 'The ' . htmlspecialchars(str_replace(['_', 'option-'], [' ', ''], $k) . '-' . $vv, ENT_QUOTES) . ' ¡La opción ya no está disponible!';
                        }
                    }
                } else {
                    if (empty($v)) continue;
                    // Reemplazar guiones bajos con espacios y eliminar opción-prefijo
                    $options .= str_replace(['_', 'option-'], [' ', ''], $k) . '-' . $v . ',';
                    //Obtiene la opción de la base de datos
                    $stmt = $pdo->prepare('SELECT * FROM products_options WHERE option_name = ? AND option_value = ? AND product_id = ?');
                    $stmt->execute([ str_replace(['_', 'option-'], [' ', ''], $k), $v, $product['id'] ]);
                    $option = $stmt->fetch(PDO::FETCH_ASSOC);
                    // Obtener cantidad de opción de carrito
                    $cart_option_quantity = get_cart_option_quantity($product['id'], str_replace(['_', 'option-'], [' ', ''], $k) . '-' . $v);
                    //Comprueba si la opción existe y la cantidad está disponible
                    if (!$option) {
                        // La opción es texto o elemento de fecha y hora
                        $stmt = $pdo->prepare('SELECT * FROM products_options WHERE option_name = ? AND product_id = ?');
                        $stmt->execute([ str_replace(['_', 'option-'], [' ', ''], $k), $product['id'] ]);
                        $option = $stmt->fetch(PDO::FETCH_ASSOC);                              
                    }
                    if ($option && ($option['quantity'] == -1 || $option['quantity']-$quantity-$cart_option_quantity >= 0)) {
                        $options_price = $option['price_modifier'] == 'add' ? $options_price + $option['price'] : $options_price - $option['price'];
                        $options_weight = $option['weight_modifier'] == 'add' ? $options_weight + $option['weight'] : $options_weight - $option['weight'];
                    } else {
                        $validation_error = 'The ' . htmlspecialchars(str_replace(['_', 'option-'], [' ', ''], $k) . '-' . $v, ENT_QUOTES) . ' ¡La opción ya no está disponible!';
                    }
                }
            }
        }
        //Verificar cantidad de producto
        $cart_product_quantity = get_cart_product_quantity($product['id']);
        if ($product['quantity'] != -1 && $product['quantity']-$quantity-$cart_product_quantity < 0) {
            $validation_error = '¡El producto está agotado o has alcanzado la cantidad máxima!';
        }
        // Si no hay errores
        if (!$validation_error) {
            // Establece el precio de las opciones en 0 si es menor que 0
            $options_price = $options_price < 0 ? 0 : $options_price;
            $options = rtrim($options, ',');
            // Comprobar si el producto existe
            if ($quantity > 0) {
                if (isset($_POST['paypal_subscribe']) || isset($_POST['stripe_subscribe'])) {
                    $_SESSION['sub'] = [
                        'id' => $product['id'],
                        'quantity' => $quantity,
                        'options' => $options,
                        'options_price' => $options_price,
                        'options_weight' => $options_weight
                    ];
                }
                // Si el usuario hizo clic en el botón de suscripción de paypal
                if (isset($_POST['paypal_subscribe'])) {
                    header('Location: ' . url('index.php?page=subscribe&method=paypal'));
                    exit;
                }
                if (isset($_POST['stripe_subscribe'])) {
                    header('Location: ' . url('index.php?page=subscribe&method=stripe'));
                    exit;
                }
                // El producto existe en la base de datos, ahora podemos crear/actualizar la variable de sesión para el carrito
                if (!isset($_SESSION['cart'])) {
                    // La variable de sesión del carrito de compras no existe, créala
                    $_SESSION['cart'] = [];
                }
                $cart_product = &get_cart_product($product['id'], $options);
                if ($cart_product) {
                    // El producto existe en el carrito, actualiza la cantidad
                    $cart_product['quantity'] += $quantity;
                } else {
                    // El producto no está en el carrito
                    $_SESSION['cart'][] = [
                        'id' => $product['id'],
                        'quantity' => $quantity,
                        'options' => $options,
                        'options_price' => $options_price,
                        'options_weight' => $options_weight,
                        'shipping_price' => 0.00
                    ];
                }
            }
            // Evitar el reenvío del formulario...
            header('Location: ' . url('index.php?page=cart'));
            exit;
        }
    }
} else {
    http_response_code(404);
    exit('EL producto no existe');
}
?>
<?=template_header($product['title'], $meta)?>

<?php if ($error): ?>

<p class="content-wrapper error"><?=$error?></p>

<?php else: ?>

<div class="product content-wrapper">

    <?php if ($product_media): ?>
    <div class="product-imgs">

        <?php if (file_exists($product_media[0]['full_path'])): ?>
        <div class="product-img-large">
            <img src="<?=base_url . $product_media[0]['full_path']?>" alt="<?=$product_media[0]['caption']?>">
        </div>
        <?php endif; ?>

        <div class="product-small-imgs">
            <?php foreach ($product_media as $media): ?>
            <div class="product-img-small<?=$media['position']==1?' selected':''?>">
                <img src="<?=base_url . $media['full_path'] ?>" width="150" height="150" alt="<?=$media['caption']?>">
            </div>
            <?php endforeach; ?>
        </div>

    </div>
    <?php endif; ?>

    <div class="product-wrapper">

        <h1 class="name"><?=$product['title']?></h1>

        <div class="prices">
            <span class="price" data-price="<?=$product['price']?>"><?=currency_code?><?=number_format($product['price'],2)?></span>
            <?php if ($product['rrp'] > 0): ?>
            <span class="rrp"><?=currency_code?><?=number_format($product['rrp'],2)?></span>
            <?php endif; ?>
            <?php if ($product['subscription']): ?>
            <span class="sub-period-type mar-left-2 mar-top-1">/ <?=ucwords($product['subscription_period_type'])?></span>
            <?php endif; ?>
        </div>

        <form class="product-form form" action="" method="post">
            <?php foreach ($product_options as $id => $option): ?>
            <?php $id = explode('::', $id); ?>
            <?php if ($id[1] == 'select'): ?>
            <label for="<?=$id[0]?>" class="form-label"><?=$id[0]?></label>
            <select id="<?=$id[0]?>" class="form-input option select" name="option-<?=$id[0]?>"<?=$id[2] ? ' required' : ''?>>
                <option value="" selected disabled style="display:none"><?=$id[0]?></option>
                <?php foreach ($option as $option_value): ?>
                <option value="<?=$option_value['option_value']?>" data-price="<?=$option_value['price']?>" data-modifier="<?=$option_value['price_modifier']?>" data-quantity="<?=$option_value['quantity']?>"<?=$option_value['quantity']==0?' disabled':''?>><?=$option_value['option_value']?></option>
                <?php endforeach; ?>
            </select>
            <?php elseif ($id[1] == 'radio'): ?>
            <label for="<?=$id[0]?>" class="form-label-2"><?=$id[0]?></label>
            <div class="form-radio-checkbox">
                <?php foreach ($option as $n => $option_value): ?>
                <label>
                    <input <?=$n == 0 ? 'id="' . $id[0] . '" ' : ''?>class="option radio" value="<?=$option_value['option_value']?>" name="option-<?=$id[0]?>" type="radio" data-price="<?=$option_value['price']?>" data-modifier="<?=$option_value['price_modifier']?>" data-quantity="<?=$option_value['quantity']?>"<?=$id[2] && $n == 0 ? ' required' : ''?><?=$option_value['quantity']==0?' disabled':''?>><?=$option_value['option_value']?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php elseif ($id[1] == 'checkbox'): ?>
            <label for="<?=$id[0]?>" class="form-label-2"><?=$id[0]?></label>
            <div class="form-radio-checkbox">
                <?php foreach ($option as $n => $option_value): ?>
                <label>
                    <input <?=$n == 0 ? 'id="' . $id[0] . '" ' : ''?>class="option checkbox" value="<?=$option_value['option_value']?>" name="option-<?=$id[0]?>[]" type="checkbox" data-price="<?=$option_value['price']?>" data-modifier="<?=$option_value['price_modifier']?>" data-quantity="<?=$option_value['quantity']?>"<?=$id[2] && $n == 0 ? ' required' : ''?><?=$option_value['quantity']==0?' disabled':''?>><?=$option_value['option_value']?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php elseif ($id[1] == 'text'): ?>
            <?php foreach ($option as $option_value): ?>
            <label for="<?=$id[0]?>" class="form-label"><?=$id[0]?></label>
            <input id="<?=$id[0]?>" class="form-input option text" name="option-<?=$id[0]?>" type="text" placeholder="<?=$id[0]?>"<?=!empty($option_value['option_value'])?' value="' . $option_value['option_value'] . '"':''?> data-price="<?=$option_value['price']?>" data-modifier="<?=$option_value['price_modifier']?>" data-quantity="<?=$option_value['quantity']?>"<?=$id[2] ? ' required' : ''?><?=$option_value['quantity']==0?' disabled':''?>>
            <?php endforeach; ?>
            <?php elseif ($id[1] == 'datetime'): ?>
            <?php foreach ($option as $option_value): ?>
            <label for="<?=$id[0]?>" class="form-label"><?=$id[0]?></label>
            <input id="<?=$id[0]?>" class="form-input option datetime" name="option-<?=$id[0]?>" type="datetime-local"<?=$option_value['option_value'] ? 'value="' . date('Y-m-d\TH:i', strtotime($option_value['option_value'])) . '" ' : ''?> data-price="<?=$option_value['price']?>" data-modifier="<?=$option_value['price_modifier']?>" data-quantity="<?=$option_value['quantity']?>"<?=$id[2] ? ' required' : ''?><?=$option_value['quantity']==0?' disabled':''?>>
            <?php endforeach; ?>          
            <?php endif; ?>
            <?php endforeach; ?>

            <?php if (!$product['subscription']): ?>
            <label for="quantity" class="form-label">Cantidad</label>
            <input id="quantity" class="form-input" type="number" name="quantity" value="1" min="1" data-quantity="<?=$product['quantity']?>"<?php if ($product['quantity'] != -1): ?> max="<?=$product['quantity']?>"<?php endif; ?> placeholder="Quantity" required>
            <?php endif; ?>

            <?php if (!$on_wishlist): ?>
            <button type="submit" name="add_to_wishlist" class="add-to-wishlist" formnovalidate>
                <svg width="16" height="16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12.1,18.55L12,18.65L11.89,18.55C7.14,14.24 4,11.39 4,8.5C4,6.5 5.5,5 7.5,5C9.04,5 10.54,6 11.07,7.36H12.93C13.46,6 14.96,5 16.5,5C18.5,5 20,6.5 20,8.5C20,11.39 16.86,14.24 12.1,18.55M16.5,3C14.76,3 13.09,3.81 12,5.08C10.91,3.81 9.24,3 7.5,3C4.42,3 2,5.41 2,8.5C2,12.27 5.4,15.36 10.55,20.03L12,21.35L13.45,20.03C18.6,15.36 22,12.27 22,8.5C22,5.41 19.58,3 16.5,3Z" /></svg>
                Añadir a la lista de deseos
            </button>
            <?php else: ?>
            <button type="submit" name="remove_from_wishlist" class="added-to-wishlist" formnovalidate>
                <svg width="16" height="16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z" /></svg>
                Añadido a la lista de deseos
            </button>
            <?php endif; ?>

            <?php if ($product['quantity'] == 0): ?>
            <button type="submit" class="btn" disabled>Agotado</button>
            <?php else: ?>
            <?php if ($product['subscription']): ?>
            <?php if (paypal_enabled): ?>
            <button type="submit" class="btn paypal mar-bot-1" name="paypal_subscribe">Suscríbete con PayPal</button>
            <?php endif; ?>
            <?php if (stripe_enabled): ?>
            <button type="submit" class="btn stripe" name="stripe_subscribe">Suscríbete con Stripe</button>
            <?php endif; ?>
            <?php else: ?>
            <button type="submit" class="btn" name="add_to_cart">Añadir al carrito</button>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($validation_error): ?>
            <p class="error"><?=$validation_error?></p>
            <?php endif; ?>

        </form>

    </div>

</div>

<?php if (!empty($product['description'])): ?>
<div class="product-details content-wrapper">

    <div class="description-title">
        <h2>Descripción del Producto</h2>
    </div>

    <div class="description-content">
        <?=$product['description']?>
    </div>

</div>
<?php endif; ?>

<?php endif; ?>

<?=template_footer()?>