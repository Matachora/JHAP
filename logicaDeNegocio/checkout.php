<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
// Valores predeterminados para los elementos del formulario de entrada
$account = [
    'first_name' => '',
    'last_name' => '',
    'address_street' => '',
    'address_city' => '',
    'address_state' => '',
    'address_zip' => '',
    'address_country' => 'United States',
    'role' => 'Member'
];
$errors = [];
// Redirigir al usuario si el carrito de compras está vacío
if (!isset($_SESSION['cart']) || empty($_SESSION['cart'])) {
    header('Location: ' . url('index.php?page=cart'));
    exit;
}
// Comprobar si el usuario ha iniciado sesión
if (isset($_SESSION['account_loggedin'])) {
    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $stmt->execute([ $_SESSION['account_id'] ]);
    // Recupera la cuenta de la base de datos y devuelve el resultado como una matriz
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
}
// Actualizar código de descuento
if (isset($_POST['discount_code']) && !empty($_POST['discount_code'])) {
    $_SESSION['discount'] = $_POST['discount_code'];
} else if (isset($_POST['discount_code'], $_SESSION['discount']) && empty($_POST['discount_code'])) {
    unset($_SESSION['discount']);
}
// Variables
$products_in_cart = $_SESSION['cart'];
$subtotal = 0.00;
$shipping_total = 0.00;
$discount = null;
$discount_total = 0.00;
$tax_total = 0.00;
$weight_total = 0;
$selected_country = isset($_POST['address_country']) ? $_POST['address_country'] : $account['address_country'];
$selected_shipping_method = isset($_POST['shipping_method']) ? $_POST['shipping_method'] : null;
$selected_shipping_method_name = '';
$shipping_methods_available = [];
// Si hay productos en el carrito
if ($products_in_cart) {
    // Hay productos en el carrito por lo que debemos seleccionar esos productos de la base de datos
    $array_to_question_marks = implode(',', array_fill(0, count($products_in_cart), '?'));
    $stmt = $pdo->prepare('SELECT p.*, (SELECT m.full_path FROM products_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = p.id ORDER BY pm.position ASC LIMIT 1) AS img, (SELECT GROUP_CONCAT(pc.category_id) FROM products_categories pc WHERE pc.product_id = p.id) AS categories FROM products p WHERE p.id IN (' . $array_to_question_marks . ')');
    // Usamos array_column para recuperar solo los ID de los productos
    $stmt->execute(array_column($products_in_cart, 'id'));
    // Busca los productos de la base de datos y devuelve el resultado como una matriz
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    //Recuperar el código de descuento
    if (isset($_SESSION['discount'])) {
        $stmt = $pdo->prepare('SELECT * FROM discounts WHERE discount_code = ?');
        $stmt->execute([ $_SESSION['discount'] ]);
        $discount = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    // Obtener impuestos
    $stmt = $pdo->prepare('SELECT * FROM taxes WHERE country = ?');
    $stmt->execute([ isset($_POST['address_country']) ? $_POST['address_country'] : $account['address_country'] ]);
    $tax = $stmt->fetch(PDO::FETCH_ASSOC);
    $tax_rate = $tax ? $tax['rate'] : 0.00;
    //Obtiene la fecha actual
    $current_date = strtotime((new DateTime())->format('Y-m-d H:i:s'));
    // Recuperar métodos de envío
    $shipping_methods = $pdo->query('SELECT * FROM shipping')->fetchAll(PDO::FETCH_ASSOC);
    // Iterar los productos en el carrito y agregar los metadatos (nombre del producto, descripción, etc.)
    foreach ($products_in_cart as &$cart_product) {
        foreach ($products as $product) {
            if ($cart_product['id'] == $product['id']) {
                // Si el producto ya no está en stock, prepárese para retirarlo
                if ((int)$product['quantity'] === 0) {
                    $cart_product['remove'] = 1;
                } else {
                    $cart_product['meta'] = $product;
                    // Evitar que la cantidad del carrito exceda la cantidad del producto
                    $cart_product['quantity'] = (int)$cart_product['quantity'] > (int)$product['quantity'] && (int)$product['quantity'] !== -1 ? (int)$product['quantity'] : (int)$cart_product['quantity'];
                    // Calcular el peso
                    $weight_total += (float)$cart_product['options_weight'] * $cart_product['quantity'];
                    //Calcular el subtotal
                    $product_price = (float)$cart_product['options_price'];
                    $subtotal += $product_price * $cart_product['quantity'];
                    //Calcular el precio final, que incluye impuestos
                    $cart_product['final_price'] = $product_price + round(($tax_rate / 100) * $product_price, 2);
                    $tax_total += round(($tax_rate / 100) * $product_price, 2) * (int)$cart_product['quantity'];
                    // Comprueba qué productos son elegibles para un descuento
                    if ($discount && $current_date >= strtotime($discount['start_date']) && $current_date <= strtotime($discount['end_date'])) {
                        // Comprobar si la lista de productos está vacía o si la identificación del producto está en la lista blanca
                        if (empty($discount['product_ids']) || in_array($product['id'], explode(',', $discount['product_ids']))) {
                            // Comprobar si la lista de categorías está vacía o si el ID de categoría está en la lista blanca
                            if (empty($discount['category_ids']) || array_intersect(explode(',', $product['categories']), explode(',', $discount['category_ids']))) {
                                $cart_product['discounted'] = true;
                            }
                        }
                    }
                }
            }
        }
    }
    //Eliminar productos que están agotados
    for ($i = 0; $i < count($products_in_cart); $i++) {
        if (isset($products_in_cart[$i]['remove'])) {
            unset($_SESSION['cart'][$i]);
            unset($products_in_cart[$i]);
        }
    }
    $_SESSION['cart'] = array_values($_SESSION['cart']);
    $products_in_cart = array_values($products_in_cart);
    // Redirigir al usuario si el carrito de compras está vacío
    if (empty($products_in_cart)) {
        header('Location: ' . url('index.php?page=cart'));
        exit;
    }
    // Calcular el envío
    foreach ($products_in_cart as &$cart_product) {
        foreach ($shipping_methods as $shipping_method) {
            // Peso del Producto
            $product_weight = $cart_product['options_weight'] && $shipping_method['shipping_type'] == 'Single Product' ? $cart_product['options_weight'] : $weight_total;
            // Determinar el precio
            $product_price = $shipping_method['shipping_type'] == 'Single Product' ? (float)$cart_product['options_price'] : $subtotal;
            // Verifique si no se requiere ningún país o si el método de envío solo está disponible en países específicos
            if (empty($shipping_method['countries']) || in_array($selected_country, explode(',', $shipping_method['countries']))) {
                // Compare el precio y el peso para cumplir con los requisitos del método de envío
                if ($shipping_method['id'] == $selected_shipping_method && $product_price >= $shipping_method['price_from'] && $product_price <= $shipping_method['price_to'] && $product_weight >= $shipping_method['weight_from'] && $product_weight <= $shipping_method['weight_to']) {
                    if ($shipping_method['shipping_type'] == 'Single Product') {
                        // Calcular el precio de un solo producto
                        $cart_product['shipping_price'] += (float)$shipping_method['price'] * (int)$cart_product['quantity'];
                        $shipping_total += $cart_product['shipping_price'];
                    } else {
                        // Calcular el precio total del pedido
                        $cart_product['shipping_price'] = (float)$shipping_method['price'] / count($products_in_cart);
                        $shipping_total = (float)$shipping_method['price'];
                    }
                    $shipping_methods_available[] = $shipping_method['id'];
                } else if ($product_price >= $shipping_method['price_from'] && $product_price <= $shipping_method['price_to'] && $product_weight >= $shipping_method['weight_from'] && $product_weight <= $shipping_method['weight_to']) {
                    // No se seleccionó ningún método, así que almacene todos los métodos disponibles
                    $shipping_methods_available[] = $shipping_method['id'];
                }
            }// Actualizar el nombre del método de envío seleccionado
            // Actualizar el nombre del método de envío seleccionado
            if ($shipping_method['id'] == $selected_shipping_method) {
                $selected_shipping_method_name = $shipping_method['title'];
            }
        }
    }
    // Número de productos con descuento
    $num_discounted_products = count(array_column($products_in_cart, 'discounted'));
    // Iterar los productos y actualizar el precio de los productos con descuento
    foreach ($products_in_cart as &$cart_product) {
        if (isset($cart_product['discounted']) && $cart_product['discounted'] && $discount) {
            $price = &$cart_product['final_price'];
            if ($discount['discount_type'] == 'Percentage') {
                $d = round((float)$price * ((float)$discount['discount_value'] / 100), 2);
                $price -= $d;
                $discount_total += $d * (int)$cart_product['quantity'];
            }
            if ($discount['discount_type'] == 'Fixed') {
                $d = (float)$discount['discount_value'] / $num_discounted_products;
                $price -= $d / (int)$cart_product['quantity'];
                $discount_total += $d;
            }
        }
    }
}
// Asegúrese de que cuando el usuario envíe el formulario se hayan enviado todos los datos y que el carrito de compras no esté vacío
if (isset($_POST['method'], $_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country']) && !isset($_POST['update'])) {
    // ID de la cuenta
    $account_id = null;
    // Si el usuario ya inició sesión, actualice los detalles del usuario o si el usuario no inició sesión, cree una nueva cuenta
    if (isset($_SESSION['account_loggedin'])) {
        // Cuenta iniciada, actualiza los detalles del usuario
        $stmt = $pdo->prepare('UPDATE accounts SET first_name = ?, last_name = ?, address_street = ?, address_city = ?, address_state = ?, address_zip = ?, address_country = ? WHERE id = ?');
        $stmt->execute([ $_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country'], $_SESSION['account_id'] ]);
        $account_id = $_SESSION['account_id'];
    } else if (isset($_POST['email'], $_POST['password'], $_POST['cpassword']) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) && !empty($_POST['password']) && !empty($_POST['cpassword'])) {
        // El usuario no ha iniciado sesión, verifique si la cuenta ya existe con el correo electrónico que envió
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE email = ?');
        $stmt->execute([ $_POST['email'] ]);
    	if ($stmt->fetchColumn() > 0) {
            // El correo electrónico existe, el usuario debe iniciar sesión en su lugar...
    		$errors[] = 'Account already exists with that email!';
        }
        if (strlen($_POST['password']) > 20 || strlen($_POST['password']) < 5) {
            // La contraseña debe tener entre 5 y 20 caracteres.
            $errors[] = 'Password must be between 5 and 20 characters long!';
    	}
        if ($_POST['password'] != $_POST['cpassword']) {
            // Los campos de contraseña y confirmación de contraseña no coinciden...
            $errors[] = 'Passwords do not match!';
        }
        if (!$errors) {
            // hash de la contraseña
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            //El correo electrónico no existe, crea una cuenta nueva.
            $stmt = $pdo->prepare('INSERT INTO accounts (email, password, first_name, last_name, address_street, address_city, address_state, address_zip, address_country) VALUES (?,?,?,?,?,?,?,?,?)');
            $stmt->execute([ $_POST['email'], $password, $_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country'] ]);
            $account_id = $pdo->lastInsertId();
            // Recupera la cuenta de la base de datos y devuelve el resultado como una matriz
            $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
            $stmt->execute([ $account_id ]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } else if (account_required) {
        $errors[] = 'Account creation required!';
    }
    // Procesar el pedido con los datos proporcionados
    if (!$errors && $products_in_cart) {
        // Inicia sesión como usuario con los detalles proporcionados
        if ($account_id != null && !isset($_SESSION['account_loggedin'])) {
            // Inicia sesión como usuario con los detalles proporcionados
            session_regenerate_id();
            $_SESSION['account_loggedin'] = TRUE;
            $_SESSION['account_id'] = $account_id;
            $_SESSION['account_role'] = $account['role'];
            $_SESSION['account_name'] = $account['first_name'] . ' ' . $account['last_name'];
        }
        // Procesar pago de Payment
        if (stripe_enabled && $_POST['method'] == 'stripe') {
            // Incluir la biblioteca de franjas
            require_once 'lib/stripe/init.php';
            $stripe = new \Stripe\StripeClient(stripe_secret_key);
            $line_items = [];
            // Iterar los productos en el carrito y agregar cada producto a la matriz de arriba
            for ($i = 0; $i < count($products_in_cart); $i++) {
                $line_items[] = [
                    'quantity' => $products_in_cart[$i]['quantity'],
                    'price_data' => [
                        'currency' => stripe_currency,
                        'unit_amount' => round($products_in_cart[$i]['final_price']*100),
                        'product_data' => [
                            'name' => $products_in_cart[$i]['meta']['title'],
                            'metadata' => [
                                'item_id' => $products_in_cart[$i]['id'],
                                'item_options' => $products_in_cart[$i]['options'],
                                'item_shipping' => $products_in_cart[$i]['shipping_price']
                            ]
                        ]
                    ]
                ];
            }
            //Añadir el envío
            $line_items[] = [
                'quantity' => 1,
                'price_data' => [
                    'currency' => stripe_currency,
                    'unit_amount' => round($shipping_total*100),
                    'product_data' => [
                        'name' => 'Shipping',
                        'description' => $selected_shipping_method_name,
                        'metadata' => [
                            'item_id' => 'shipping',
                            'shipping_method' => $selected_shipping_method_name
                        ]
                    ]
                ]
            ];      
            if (empty(stripe_webhook_secret)) {
                $contents = file_get_contents('config.php');
                if ($contents) {
                    $webhook = $stripe->webhookEndpoints->create([
                        'url' => stripe_ipn_url,
                        'description' => 'shoppingcart', // Feel free to change this
                        'enabled_events' => ['checkout.session.completed']
                    ]);
                    $contents = preg_replace('/define\(\'stripe_webhook_secret\'\, ?(.*?)\)/s', 'define(\'stripe_webhook_secret\',\'' . $webhook['secret'] . '\')', $contents);
                    if (!file_put_contents('config.php', $contents)) {
                        exit('error');
                    }
                } else {
                    exit('error');
                }
            }
            // Crea la sesión de pago de Stripe y redirige al cliente
            $session = $stripe->checkout->sessions->create([
                'success_url' => stripe_return_url,
                'cancel_url' => stripe_cancel_url,
                'payment_method_types' => ['card'],
                'line_items' => $line_items,
                'mode' => 'payment',
                'customer_email' => isset($account['email']) && !empty($account['email']) ? $account['email'] : $_POST['email'],
                'metadata' => [
                    'first_name' => $_POST['first_name'],
                    'last_name' => $_POST['last_name'],
                    'address_street' => $_POST['address_street'],
                    'address_city' => $_POST['address_city'],
                    'address_state' => $_POST['address_state'],
                    'address_zip' => $_POST['address_zip'],
                    'address_country' => $_POST['address_country'],
                    'account_id' => $account_id,
                    'discount_code' => $discount ? $discount['discount_code'] : ''
                ]
            ]);
            // Redirigir al pago de Stripe
            header('Location: ' . $session->url);
            exit;
        }
        // Procesar pago de PayPal
        if (paypal_enabled && $_POST['method'] == 'paypal') {
            // Procesar pago con PayPal
            $data = [];
            // Agrega todos los productos que están en el carrito de compras a la variable de matriz de datos
            for ($i = 0; $i < count($products_in_cart); $i++) {
                $data['item_number_' . ($i+1)] = $products_in_cart[$i]['id'];
                $data['item_name_' . ($i+1)] = $products_in_cart[$i]['meta']['title'];
                $data['quantity_' . ($i+1)] = $products_in_cart[$i]['quantity'];
                $data['amount_' . ($i+1)] = $products_in_cart[$i]['final_price'];
                $data['on0_' . ($i+1)] = 'Options';
                $data['os0_' . ($i+1)] = $products_in_cart[$i]['options'];
            }
            $metadata = [
                'account_id' => $account_id,
                'discount_code' => $discount ? $discount['discount_code'] : '',
                'shipping_method' => $selected_shipping_method_name
            ];
            // Variables que necesitamos pasar a paypal
            $data = $data + [
                'cmd'			=> '_cart',
                'charset'		=> 'UTF-8',
                'upload'        => '1',
                'custom'        => json_encode($metadata),
                'business' 		=> paypal_email,
                'cancel_return'	=> paypal_cancel_url,
                'notify_url'	=> paypal_ipn_url,
                'currency_code'	=> paypal_currency,
                'return'        => paypal_return_url,
                'shipping_1'    => $shipping_total,
                'address1'      => $_POST['address_street'],
                'city'          => $_POST['address_city'],
                'country'       => $_POST['address_country'],
                'state'         => $_POST['address_state'],
                'zip'           => $_POST['address_zip'],
                'first_name'    => $_POST['first_name'],
                'last_name'     => $_POST['last_name'],
                'email'         => isset($account['email']) && !empty($account['email']) ? $account['email'] : $_POST['email']
            ];
            // Redirige al usuario a la pantalla de pago de PayPal
            header('Location: ' . (paypal_testmode ? 'https://www.sandbox.paypal.com/cgi-bin/webscr' : 'https://www.paypal.com/cgi-bin/webscr') . '?' . http_build_query($data));
            // Finaliza el script, no es necesario ejecutar nada más
            exit;
        }
        // Procesar pago de Coinbase
        if (coinbase_enabled && $_POST['method'] == 'coinbase') {
            // Incluir la biblioteca coinbase
            require_once 'lib/vendor/autoload.php';
            $coinbase = CoinbaseCommerce\ApiClient::init(coinbase_key);
            // Variable que almacenará todos los detalles de todos los productos en el carrito de compras.
            $metadata = [];
            $description = '';
            // Agrega todos los productos que están en el carrito de compras a la variable de matriz de datos
            for ($i = 0; $i < count($products_in_cart); $i++) {
                // Agregar datos del producto a la matriz
                $metadata['item_' . ($i+1)] = $products_in_cart[$i]['id'];
                $metadata['item_name_' . ($i+1)] = $products_in_cart[$i]['meta']['title'];
                $metadata['qty_' . ($i+1)] = $products_in_cart[$i]['quantity'];
                $metadata['amount_' . ($i+1)] = $products_in_cart[$i]['final_price'];
                $metadata['option_' . ($i+1)] = $products_in_cart[$i]['options'];
                $description .= 'x' . $products_in_cart[$i]['quantity'] . ' ' . $products_in_cart[$i]['meta']['title'] . ', ';
            }
            // Agregar información del cliente
            $metadata['email'] = isset($account['email']) && !empty($account['email']) ? $account['email'] : $_POST['email'];
            $metadata['first_name'] = $_POST['first_name'];
            $metadata['last_name'] = $_POST['last_name'];
            $metadata['address_street'] = $_POST['address_street'];
            $metadata['address_city'] = $_POST['address_city'];
            $metadata['address_state'] = $_POST['address_state'];
            $metadata['address_zip'] = $_POST['address_zip'];
            $metadata['address_country'] = $_POST['address_country'];
            $metadata['account_id'] = $account_id;
            $metadata['discount_code'] = $discount ? $discount['discount_code'] : '';
            $metadata['shipping_method'] = $selected_shipping_method_name;
            // Agregar envío
            $metadata['shipping'] = $shipping_total;
            // Agregar número de artículos al carrito
            $metadata['num_cart_items'] = count($products_in_cart);
            // Datos
            $data = [
                'name' => count($products_in_cart) . ' Item' . (count($products_in_cart) > 1 ? 's' : ''),
                'description' => rtrim($description, ', '),
                'local_price' => [
                    'amount' => ($subtotal-$discount_total)+$shipping_total,
                    'currency' => coinbase_currency
                ],
                'metadata' => $metadata,
                'pricing_type' => 'fixed_price',
                'redirect_url' => coinbase_return_url,
                'cancel_url' => coinbase_cancel_url
            ];
            // crear cargo
            $charge = CoinbaseCommerce\Resources\Charge::create($data);
            // Redirigir a la página de pago alojada
            header('Location: ' . $charge->hosted_url);
            exit;
        }
        if (pay_on_delivery_enabled && $_POST['method'] == 'payondelivery') {
            // Procesar pago normal
            $transaction_id = strtoupper(uniqid('SC') . substr(md5(mt_rand()), 0, 5));
            // Correo electrónico del cliente
            $customer_email = isset($account['email']) && !empty($account['email']) ? $account['email'] : $_POST['email'];
            // Cantidad total
            $total = $subtotal-round($discount_total, 2)+$shipping_total+round($tax_total, 2);
            // Insertar transacción en la base de datos
            $stmt = $pdo->prepare('INSERT INTO transactions (txn_id, payment_amount, payment_status, created, payer_email, first_name, last_name, address_street, address_city, address_state, address_zip, address_country, account_id, payment_method, shipping_method, shipping_amount, discount_code) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $stmt->execute([ $transaction_id, $total, default_payment_status, date('Y-m-d H:i:s'), $customer_email, $_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country'], $account_id, 'website', $selected_shipping_method_name, $shipping_total, $discount ? $discount['discount_code'] : '']);
            // Obtener ID del pedido
            $order_id = $pdo->lastInsertId();
            // Iterar productos y deducir cantidades
            foreach ($products_in_cart as $product) {
                // Por cada producto del carrito de compras inserta una nueva transacción en nuestra base de datos
                $stmt = $pdo->prepare('INSERT INTO transactions_items (txn_id, item_id, item_price, item_quantity, item_options) VALUES (?,?,?,?,?)');
                $stmt->execute([ $transaction_id, $product['id'], $product['final_price'], $product['quantity'], $product['options'] ]);
                //Actualizar cantidad de producto en la tabla de productos
                $stmt = $pdo->prepare('UPDATE products SET quantity = GREATEST(quantity - ?, 0) WHERE quantity > 0 AND id = ?');
                $stmt->execute([ $product['quantity'], $product['id'] ]);
                // Deducir cantidades de opciones
                if ($product['options']) {
                    $options = explode(',', $product['options']);
                    foreach ($options as $opt) {
                        $option_name = explode('-', $opt)[0];
                        $option_value = explode('-', $opt)[1];
                        $stmt = $pdo->prepare('UPDATE products_options SET quantity = GREATEST(quantity - ?, 0) WHERE quantity > 0 AND option_name = ? AND (option_value = ? OR option_value = "") AND product_id = ?');
                        $stmt->execute([ $product['quantity'], $option_name, $option_value, $product['id'] ]);         
                    }
                }
            }
            // Enviar detalles del pedido a la dirección de correo electrónico especificada
            send_order_details_email($customer_email, $products_in_cart, $_POST['first_name'], $_POST['last_name'], $_POST['address_street'], $_POST['address_city'], $_POST['address_state'], $_POST['address_zip'], $_POST['address_country'], $total, $order_id);
            // Redirigir a la página de realizar pedido
            header('Location: ' . url('index.php?page=placeorder'));
            exit;
        }
    }
    // Preservar los detalles del formulario si el usuario encuentra un error
    $account = [
        'first_name' => $_POST['first_name'],
        'last_name' => $_POST['last_name'],
        'address_street' => $_POST['address_street'],
        'address_city' => $_POST['address_city'],
        'address_state' => $_POST['address_state'],
        'address_zip' => $_POST['address_zip'],
        'address_country' => $_POST['address_country']
    ];
}
?>
<?=template_header('Checkout')?>

<div class="checkout content-wrapper">

    <h1 class="page-title">Pagos</h1>

    <?php if ($errors): ?>
    <p class="error"><?=implode('<br>', $errors)?></p>
    <?php endif; ?>

    <?php if (!isset($_SESSION['account_loggedin'])): ?>
    <p>¿Ya tienes una cuenta? <a href="<?=url('index.php?page=myaccount')?>" class="link">Iniciar Sesión</a></p>
    <?php endif; ?>

    <form action="" method="post" class="form pad-top-2">

        <div class="container">

            <div class="shipping-details">

                <h2>Métodos de Pago</h2>

                <div class="payment-methods">
                    <?php if (pay_on_delivery_enabled): ?>
                    <input id="payondelivery" type="radio" name="method" value="payondelivery" checked>
                    <label for="payondelivery">Pagar en efectivo</label>
                    <?php endif; ?>

                    <?php if (paypal_enabled): ?>
                    <input id="paypal" type="radio" name="method" value="paypal">
                    <label for="paypal"><img src="data:image/png;base64,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" width="100" height="26" alt="PayPal"></label>
                    <?php endif; ?>

                    <?php if (stripe_enabled): ?>
                    <input id="stripe" type="radio" name="method" value="stripe">
                    <label for="stripe"><svg class="stripe-icon" width="60" height="60" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><!--!Font Awesome Free 6.5.1 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license/free Copyright 2024 Fonticons, Inc.--><path d="M165 144.7l-43.3 9.2-.2 142.4c0 26.3 19.8 43.3 46.1 43.3 14.6 0 25.3-2.7 31.2-5.9v-33.8c-5.7 2.3-33.7 10.5-33.7-15.7V221h33.7v-37.8h-33.7zm89.1 51.6l-2.7-13.1H213v153.2h44.3V233.3c10.5-13.8 28.2-11.1 33.9-9.3v-40.8c-6-2.1-26.7-6-37.1 13.1zm92.3-72.3l-44.6 9.5v36.2l44.6-9.5zM44.9 228.3c0-6.9 5.8-9.6 15.1-9.7 13.5 0 30.7 4.1 44.2 11.4v-41.8c-14.7-5.8-29.4-8.1-44.1-8.1-36 0-60 18.8-60 50.2 0 49.2 67.5 41.2 67.5 62.4 0 8.2-7.1 10.9-17 10.9-14.7 0-33.7-6.1-48.6-14.2v40c16.5 7.1 33.2 10.1 48.5 10.1 36.9 0 62.3-15.8 62.3-47.8 0-52.9-67.9-43.4-67.9-63.4zM640 261.6c0-45.5-22-81.4-64.2-81.4s-67.9 35.9-67.9 81.1c0 53.5 30.3 78.2 73.5 78.2 21.2 0 37.1-4.8 49.2-11.5v-33.4c-12.1 6.1-26 9.8-43.6 9.8-17.3 0-32.5-6.1-34.5-26.9h86.9c.2-2.3 .6-11.6 .6-15.9zm-87.9-16.8c0-20 12.3-28.4 23.4-28.4 10.9 0 22.5 8.4 22.5 28.4zm-112.9-64.6c-17.4 0-28.6 8.2-34.8 13.9l-2.3-11H363v204.8l44.4-9.4 .1-50.2c6.4 4.7 15.9 11.2 31.4 11.2 31.8 0 60.8-23.2 60.8-79.6 .1-51.6-29.3-79.7-60.5-79.7zm-10.6 122.5c-10.4 0-16.6-3.8-20.9-8.4l-.3-66c4.6-5.1 11-8.8 21.2-8.8 16.2 0 27.4 18.2 27.4 41.4 .1 23.9-10.9 41.8-27.4 41.8zm-126.7 33.7h44.6V183.2h-44.6z"/></svg></label>
                    <?php endif; ?>
                    
                    <?php if (coinbase_enabled): ?>
                    <input id="coinbase" type="radio" name="method" value="coinbase">
                    <label for="coinbase">c</label>
                    <?php endif; ?>
                </div>

                <?php if (!isset($_SESSION['account_loggedin'])): ?>
                <h2>Crear Cuenta<?php if (!account_required): ?> (opcional)<?php endif; ?></h2>

                <label for="email" class="form-label">Correo Electronico</label>
                <input type="email" name="email" id="email" placeholder="karime@ejemplo.com" class="form-input expand" required>

                <label for="password" class="form-label">Contraseña</label>
                <input type="password" name="password" id="password" placeholder="Contraseña" class="form-input expand" autocomplete="new-password">

                <label for="cpassword" class="form-label">Confirmar Contraseña</label>
                <input type="password" name="cpassword" id="cpassword" placeholder="Confirmar Contraseña" class="form-input expand" autocomplete="new-password">
                <?php endif; ?>

                <h2>Detalles de envío</h2>

                <div class="form-group">
                    <div class="col pad-right-2">
                        <label for="first_name" class="form-label">Nombre</label>
                        <input type="text" value="<?=htmlspecialchars($account['first_name'], ENT_QUOTES)?>" name="first_name" id="first_name" placeholder="Karime" class="form-input expand" required>
                    </div>
                    <div class="col pad-left-2">
                        <label for="last_name" class="form-label">Apellido</label>
                        <input type="text" value="<?=htmlspecialchars($account['last_name'], ENT_QUOTES)?>" name="last_name" id="last_name" placeholder="Sanchez" class="form-input expand" required>
                    </div>
                </div>

                <label for="address_street" class="form-label">Dirección</label>
                <input type="text" value="<?=htmlspecialchars($account['address_street'], ENT_QUOTES)?>" name="address_street" id="address_street" placeholder="Presidencial" class="form-input expand" required>

                <label for="address_city" class="form-label">Ciudad</label>
                <input type="text" value="<?=htmlspecialchars($account['address_city'], ENT_QUOTES)?>" name="address_city" id="address_city" placeholder="Lázaro Cárdenas" class="form-input expand" required>

                <div class="form-group">
                    <div class="col pad-right-2">
                        <label for="address_state" class="form-label">Estado</label>
                        <input type="text" value="<?=htmlspecialchars($account['address_state'], ENT_QUOTES)?>" name="address_state" id="address_state" placeholder="Michoácan" class="form-input expand" required>
                    </div>
                    <div class="col pad-left-2">
                        <label for="address_zip" class="form-label">Código Postal</label>
                        <input type="text" value="<?=htmlspecialchars($account['address_zip'], ENT_QUOTES)?>" name="address_zip" id="address_zip" placeholder="60990" class="form-input expand" required>
                    </div>
                </div>

                <label for="address_country" class="form-label">País</label>
                <select name="address_country" id="address_country" class="ajax-update form-input expand" required>
                    <?php foreach(get_countries() as $country): ?>
                    <option value="<?=$country?>"<?=$country==$account['address_country']?' selected':''?>><?=$country?></option>
                    <?php endforeach; ?>
                </select>

            </div>

            <div class="cart-details">
                    
                <h2>Carrito</h2>

                <table>
                    <?php foreach($products_in_cart as $product): ?>
                    <tr>
                        <td><img src="<?=$product['meta']['img']?>" width="35" height="35" alt="<?=$product['meta']['title']?>"></td>
                        <td><?=$product['quantity']?> x <?=$product['meta']['title']?></td>
                        <td class="price"><?=currency_code?><?=number_format($product['options_price'] * $product['quantity'],2)?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>

                <div class="discount-code">
                    <input type="text" class="ajax-update form-input expand" name="discount_code" placeholder="Código Descuento" value="<?=isset($_SESSION['discount']) ? htmlspecialchars($_SESSION['discount'], ENT_QUOTES) : ''?>">
                    <span class="result">
                        <?php if (isset($_SESSION['discount']) && !$discount): ?>
                            ¡Código de descuento incorrecto!
                        <?php elseif ($discount && $current_date < strtotime($discount['start_date'])): ?>
                            ¡Código de descuento incorrecto!
                        <?php elseif ($discount && $current_date > strtotime($discount['end_date'])): ?>
                            ¡El código de descuento expiró!
                        <?php elseif ($discount): ?>
                            ¡El código de descuento expiró!
                        <?php endif; ?>
                    </span>
                </div>

                <div class="shipping-methods-container">
                    <?php if ($shipping_methods_available): ?>
                    <div class="shipping-methods">
                        <h3>Método de envío</h3>
                        <?php foreach($shipping_methods as $k => $method): ?>
                        <?php if (!in_array($method['id'], $shipping_methods_available)) continue; ?>
                        <div class="shipping-method">
                            <input type="radio" class="ajax-update" id="sm<?=$k?>" name="shipping_method" value="<?=$method['id']?>" required<?=$selected_shipping_method==$method['id']?' checked':''?>>
                            <label for="sm<?=$k?>"><?=$method['title']?> (<?=currency_code?><?=number_format($method['price'], 2)?><?=$method['shipping_type']=='Single Product'?' per item':''?>)</label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="summary">
                    <div class="subtotal">
                        <span>Subtotal</span>
                        <span><?=currency_code?><?=number_format($subtotal,2)?></span>
                    </div>

                    <?php if ($tax): ?>
                    <div class="vat">
                        <span>IVA<span class="alt">(<?=$tax['rate']?>%)</span></span>
                        <span><?=currency_code?><?=number_format(round($tax_total, 2),2)?></span>
                    </div>
                    <?php endif; ?>

                    <div class="shipping">
                        <span>Envío</span>
                        <span><?=currency_code?><?=number_format($shipping_total,2)?></span>
                    </div>

                    <?php if ($discount_total > 0): ?>
                    <div class="discount">
                        <span>Descuento</span>
                        <span>-<?=currency_code?><?=number_format(round($discount_total, 2),2)?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="total">
                    <span>Total <span class="alt">(IVA Incluido)</span></span><span><?=currency_code?><?=number_format($subtotal-round($discount_total, 2)+$shipping_total+round($tax_total, 2),2)?></span>
                </div>

                <div class="buttons">
                    <button type="submit" name="checkout" class="btn">Pagar carrito</button>
                </div>

            </div>

        </div>

    </form>

</div>

<?=template_footer()?>