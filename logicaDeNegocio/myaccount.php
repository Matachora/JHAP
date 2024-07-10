<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
// El usuario hizo clic en el botón iniciar sesion
//verifica los datos y valida el correo electrónico
if (isset($_POST['login'], $_POST['email'], $_POST['password']) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
    //Comprueba si la cuenta existe
    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE email = ?');
    $stmt->execute([ $_POST['email'] ]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    // Si la cuenta existe verifica la contraseña
    if ($account && password_verify($_POST['password'], $account['password'])) {
        // El usuario ha iniciado sesión, crea datos de sesión
        session_regenerate_id();
        $_SESSION['account_loggedin'] = TRUE;
        $_SESSION['account_id'] = $account['id'];
        $_SESSION['account_role'] = $account['role'];
        $_SESSION['account_name'] = !empty($account['first_name']) ? $account['first_name'] : explode('@', $account['email'])[0];
        
        if (isset($_SESSION['cart']) && $_SESSION['cart']) {
            // El usuario tiene productos en el carrito manda a pagar
            header('Location: ' . url('index.php?page=checkout'));
        } else {
            // Historial de pedidos
            header('Location: ' . url('index.php?page=myaccount'));
        }
        exit;
    } else {
        $error = 'Su correo electrónico o contraseña están incorrectos';
    }
}
$register_error = '';
// El usuario hizo clic en el botón para registrarse
if (isset($_POST['register'], $_POST['email'], $_POST['password'], $_POST['cpassword']) && filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
    //Comprueba si la cuenta existe
    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE email = ?');
    $stmt->execute([ $_POST['email'] ]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($account) {
        $register_error = '¡La cuenta ya existe con ese correo electrónico!';
    } else if ($_POST['cpassword'] != $_POST['password']) {
        $register_error = '¡No coinciden las contraseñas!';
    } else if (strlen($_POST['password']) > 20 || strlen($_POST['password']) < 5) {
        $register_error = '¡La contraseña debe tener entre 5 y 20 caracteres!';
    } else {
        // hash de la contraseña
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        //La cuenta no existe, crea una cuenta nueva
        $stmt = $pdo->prepare('INSERT INTO accounts (email, password, first_name, last_name, address_street, address_city, address_state, address_zip, address_country) VALUES (?,?,"","","","","","","")');
        $stmt->execute([ $_POST['email'], $password ]);
        $account_id = $pdo->lastInsertId();
        // Inicia sesión automáticamente el usuario
        session_regenerate_id();
        $_SESSION['account_loggedin'] = TRUE;
        $_SESSION['account_id'] = $account_id;
        $_SESSION['account_role'] = 'Member';
        $_SESSION['account_name'] = explode('@', $_POST['email'])[0];
        if (isset($_SESSION['cart']) && $_SESSION['cart']) {
            // El usuario tiene productos en el carrito se paga
            header('Location: ' . url('index.php?page=checkout'));
        } else {
            // Historial de pedidos
            header('Location: ' . url('index.php?page=myaccount'));
        }
        exit;
    }
}
// Determinar la pestaña actual
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'orders';
// Si el usuario ha iniciado sesión
if (isset($_SESSION['account_loggedin'])) {
// Determinar el filtro de fecha actual
    $date = isset($_GET['date']) ? $_GET['date'] : 'all';
    $date_sql = '';
    if ($date == 'last30days') {
        $date_sql = 'AND created >= DATE_SUB("' . date('Y-m-d') . '", INTERVAL 30 DAY)';
    } else if ($date == 'last6months') {
        $date_sql = 'AND created >= DATE_SUB("' . date('Y-m-d') . '", INTERVAL 6 MONTH)';
    } else if (substr($date, 0, 4) == 'year' && is_numeric(substr($date, 4))) {
        $date_sql = 'AND YEAR(created) = :yr';
    }
    // Determinar el filtro de estado actual
    $status = isset($_GET['status']) ? $_GET['status'] : 'all';
    $status_sql = '';
    if ($status != 'all') {
        $status_sql = 'AND payment_status = :status';
    }
    // Selecciona todas las transacciones de los usuarios para los pedidos
    $stmt = $pdo->prepare('SELECT * FROM transactions  WHERE account_id = :account_id ' . $date_sql . ' ' . $status_sql . ' ORDER BY created DESC');
    $params = [ 'account_id' => $_SESSION['account_id'] ];
    if (substr($date, 0, 4) == 'year' && is_numeric(substr($date, 4))) {
        $params['yr'] = substr($date, 4);
    }
    if ($status != 'all') {
        $params['status'] = $status;
    }
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT
        p.title,
        p.id AS product_id,
        t.txn_id,
        t.payment_status,
        t.created AS transaction_date,
        ti.item_price AS price,
        ti.item_quantity AS quantity,
        ti.item_id,
        ti.item_options,
        (SELECT m.full_path FROM products_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = p.id ORDER BY pm.position ASC LIMIT 1) AS img 
        FROM transactions t
        JOIN transactions_items ti ON ti.txn_id = t.txn_id
        JOIN accounts a ON a.id = t.account_id
        JOIN products p ON p.id = ti.item_id
        WHERE t.account_id = ?
        ORDER BY t.created DESC');
    $stmt->execute([ $_SESSION['account_id'] ]);
    $transactions_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Recuperar las descargas
    $transactions_ids = array_column($transactions_items, 'product_id');
    $downloads = [];
    if ($transactions_ids) {
        $stmt = $pdo->prepare('SELECT product_id, file_path, id FROM products_downloads WHERE product_id IN (' . trim(str_repeat('?,',count($transactions_ids)),',') . ') ORDER BY position ASC');
        $stmt->execute($transactions_ids);
        $downloads = $stmt->fetchAll(PDO::FETCH_GROUP);
    }
    // Recuperar detalles de la cuenta
    $stmt = $pdo->prepare('SELECT * FROM accounts WHERE id = ?');
    $stmt->execute([ $_SESSION['account_id'] ]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    // Ajustes de actualización
    if (isset($_POST['save_details'], $_POST['email'], $_POST['password'])) {
        // Asignar y validar datos de entrada
        $first_name = isset($_POST['first_name']) ? $_POST['first_name'] : '';
        $last_name = isset($_POST['last_name']) ? $_POST['last_name'] : '';
        $address_street = isset($_POST['address_street']) ? $_POST['address_street'] : '';
        $address_city = isset($_POST['address_city']) ? $_POST['address_city'] : '';
        $address_state = isset($_POST['address_state']) ? $_POST['address_state'] : '';
        $address_zip = isset($_POST['address_zip']) ? $_POST['address_zip'] : '';
        $address_country = isset($_POST['address_country']) ? $_POST['address_country'] : '';
        // Comprobar si existe una cuenta con el email
        $stmt = $pdo->prepare('SELECT * FROM accounts WHERE email = ?');
        $stmt->execute([ $_POST['email'] ]);
        if ($_POST['email'] != $account['email'] && $stmt->fetch(PDO::FETCH_ASSOC)) {
            $error = 'Account already exists with that email!';
        } else if ($_POST['password'] && (strlen($_POST['password']) > 20 || strlen($_POST['password']) < 5)) {
            $error = 'Password must be between 5 and 20 characters long!';
        } else {
            //Actualizar detalles de la cuenta en la base de datos
            $password = $_POST['password'] ? password_hash($_POST['password'], PASSWORD_DEFAULT) : $account['password'];
            $stmt = $pdo->prepare('UPDATE accounts SET email = ?, password = ?, first_name = ?, last_name = ?, address_street = ?, address_city = ?, address_state = ?, address_zip = ?, address_country = ? WHERE id = ?');
            $stmt->execute([ $_POST['email'], $password, $first_name, $last_name, $address_street, $address_city, $address_state, $address_zip, $address_country, $_SESSION['account_id'] ]);
            // mandar a ajustes
            header('Location: ' . url('index.php?page=myaccount&tab=settings'));
            exit;           
        }
    }
    // numero de productos en la lista de deseos
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM wishlist WHERE account_id = ?');
    $stmt->execute([ $_SESSION['account_id'] ]);
    $wishlist_count = $stmt->fetchColumn(); 
    // Si el usuario está viendo su lista de deseos
    if ($tab == 'wishlist') {
        $stmt = $pdo->prepare('SELECT p.id, p.title, p.price, p.rrp, p.url_slug, (SELECT m.full_path FROM products_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = p.id ORDER BY pm.position ASC LIMIT 1) AS img FROM wishlist w JOIN products p ON p.id = w.product_id WHERE w.account_id = ?');
        $stmt->execute([ $_SESSION['account_id'] ]);
        $wishlist = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<?=template_header('My Account')?>

<div class="myaccount content-wrapper">

    <?php if (!isset($_SESSION['account_loggedin'])): ?>

    <div class="login-register">

        <div class="login">

            <h1 class="page-title">Iniciar Sesión</h1>

            <form action="" method="post" class="form">

                <label for="email" class="form-label">Correo Electrónico</label>
                <input type="email" name="email" id="email" placeholder="mime@ejemplo.com" class="form-input expand" required>

                <label for="password" class="form-label">Contraseña</label>
                <input type="password" name="password" id="password" placeholder="Contraseña" class="form-input expand" required>

                <button name="login" type="submit" class="btn">Iniciar Sesión</button>

            </form>

            <?php if ($error): ?>
            <p class="error pad-top-2"><?=$error?></p>
            <?php endif; ?>

        </div>

        <div class="register">

            <h1 class="page-title">Crear Cuenta</h1>

            <form action="" method="post" autocomplete="off" class="form">

                <label for="remail" class="form-label">Correo Electrónico</label>
                <input type="email" name="email" id="remail" placeholder="gatitos@ejemplo.com" required class="form-input expand">

                <label for="rpassword" class="form-label">Contraseña</label>
                <input type="password" name="password" id="rpassword" placeholder="Contraseña" required class="form-input expand" autocomplete="new-password">

                <label for="cpassword" class="form-label">Confirmar Contraseña</label>
                <input type="password" name="cpassword" id="cpassword" placeholder="Confirmar Contraseña" required class="form-input expand" autocomplete="new-password">

                <button name="register" type="submit" class="btn">Registrarse</button>

            </form>

            <?php if ($register_error): ?>
            <p class="error pad-top-2"><?=$register_error?></p>
            <?php endif; ?>

        </div>

    </div>

    <?php else: ?>

    <h1 class="page-title">Mi Cuenta</h1>

    <div class="menu">

        <h2>Menu</h2>
        
        <div class="menu-items">
            <a href="<?=url('index.php?page=myaccount')?>">Pedidos</a>
            <a href="<?=url('index.php?page=myaccount&tab=downloads')?>">Descargas (<?=count($downloads)?>)</a>
            <a href="<?=url('index.php?page=myaccount&tab=wishlist')?>">Lista de deseos (<?=$wishlist_count?>)</a>
            <a href="<?=url('index.php?page=myaccount&tab=settings')?>">Ajustes</a>
        </div>

    </div>

    <?php if ($tab == 'orders'): ?>
    <div class="myorders">

        <h2>Mis Pedidos</h2>

        <form action="" method="get" class="form pad-top-2">
            <?php if (!rewrite_url): ?>
            <input type="hidden" name="page" value="myaccount">
            <input type="hidden" name="tab" value="orders">
            <?php endif; ?>
            <label class="form-select mar-right-2" for="status">
                Estado:
                <select name="status" id="status" onchange="this.form.submit()">
                    <option value="all"<?=($status == 'all' ? ' selected' : '')?>>Todas las órdenes</option>
                    <option value="Completed"<?=$status=='Completed'?' selected':''?>>Terminado</option>
                    <option value="Pending"<?=$status=='Pending'?' selected':''?>>Pendiente</option>
                    <option value="Failed"<?=$status=='Failed'?' selected':''?>>Fallido</option>
                    <option value="Cancelled"<?=$status=='Cancelled'?' selected':''?>>Cancelado</option>
                    <option value="Refunded"<?=$status=='Refunded'?' selected':''?>>Reintegrado</option>
                    <option value="Shipped"<?=$status=='Shipped'?' selected':''?>>Enviado</option>
                    <option value="Subscribed"<?=$status=='Subscribed'?' selected':''?>>Suscrito</option>
                    <option value="Unsubscribed"<?=$status=='Unsubscribed'?' selected':''?>>Cancelar suscripción</option>
                </select>
            </label>
            <label class="form-select" for="date">
            Fecha:
                <select name="date" id="date" onchange="this.form.submit()">
                    <option value="all"<?=($date == 'all' ? ' selected' : '')?>>En cualquier momento</option>
                    <option value="last30days"<?=($date == 'last30days' ? ' selected' : '')?>>Últimos 30 días</option>
                    <option value="last6months"<?=($date == 'last6months' ? ' selected' : '')?>>Últimos 6 meses</option>
                    <option value="year<?=date('Y')?>"<?=($date == 'year' . date('Y') ? ' selected' : '')?>><?=date('Y')?></option>
                    <option value="year<?=date('Y')-1?>"<?=($date == 'year' . (date('Y')-1) ? ' selected' : '')?>><?=date('Y')-1?></option>
                    <option value="year<?=date('Y')-2?>"<?=($date == 'year' . (date('Y')-2) ? ' selected' : '')?>><?=date('Y')-2?></option>
                </select>
            </label>
        </form>

        <?php if (empty($transactions)): ?>
        <p class="pad-y-5">No tienes ordenes.</p>
        <?php endif; ?>

        <?php foreach ($transactions as $transaction): ?>
        <div class="order">
            <div class="order-header">
                <div>
                    <div><span>Pedido</span># <?=$transaction['id']?></div>
                    <div class="rhide"><span>Fecha</span><?=date('F j, Y', strtotime($transaction['created']))?></div>
                    <div><span>Estado</span><?=$transaction['payment_status']?></div>
                </div>
                <div>
                    <div class="rhide"><span>Envío</span><?=currency_code?><?=number_format($transaction['shipping_amount'],2)?></div>
                    <div><span>Total</span><?=currency_code?><?=number_format($transaction['payment_amount'],2)?></div>
                </div>
            </div>
            <div class="order-items">
                <table>
                    <tbody>
                        <?php foreach ($transactions_items as $transaction_item): ?>
                        <?php if ($transaction_item['txn_id'] != $transaction['txn_id']) continue; ?>
                        <tr>
                            <td class="img">
                                <?php if (!empty($transaction_item['img']) && file_exists($transaction_item['img'])): ?>
                                <img src="<?=base_url?><?=$transaction_item['img']?>" width="50" height="50" alt="<?=$transaction_item['title']?>">
                                <?php endif; ?>
                            </td>
                            <td class="name">
                                <?=$transaction_item['quantity']?> x <?=$transaction_item['title']?><br>
                                <?php if ($transaction_item['item_options']): ?>
                                <span class="options"><?=str_replace(',', '<br>', htmlspecialchars($transaction_item['item_options'], ENT_QUOTES))?></span>
                                <?php endif; ?>
                            </td>
                            <td class="price"><?=currency_code?><?=number_format($transaction_item['price'] * $transaction_item['quantity'],2)?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>                
            </div>
        </div>
        <?php endforeach; ?>

    </div>
    <?php elseif ($tab == 'downloads'): ?>
    <div class="mydownloads">

        <h2>Mis descargas</h2>

        <?php if (empty($downloads)): ?>
        <p class="pad-y-5">No tienes descargas</p>
        <?php endif; ?>

        <?php if ($downloads): ?>
        <table>
            <thead>
                <tr>
                    <td colspan="2">Producto</td>
                    <td></td>
                </tr>
            </thead>
            <tbody>
                <?php $download_products_ids = []; ?>
                <?php foreach ($transactions_items as $item): ?>
                <?php if (isset($downloads[$item['product_id']]) && !in_array($item['product_id'], $download_products_ids)): ?>
                <tr>
                    <td class="img">
                        <?php if (!empty($item['img']) && file_exists($item['img'])): ?>
                        <img src="<?=base_url?><?=$item['img']?>" width="50" height="50" alt="<?=$item['title']?>">
                        <?php endif; ?>
                    </td>
                    <td class="name"><?=$item['title']?></td>
                    <td>
                        <?php foreach ($downloads[$item['product_id']] as $download): ?>
                        <a href="<?=url('index.php?page=download&id=' . md5($item['txn_id'] . $download['id']))?>" download>
                            <div class="icon">
                                <svg width="16" height="16" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5,20H19V18H5M19,9H15V3H9V9H5L12,16L19,9Z" /></svg>
                            </div>
                            <?=basename($download['file_path'])?>
                        </a>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <?php $download_products_ids[] = $item['product_id']; ?>
                <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

    </div>
    <?php elseif ($tab == 'wishlist'): ?>
    <div class="wishlist">

        <h2>Lista de deseos</h2>

        <?php if (empty($wishlist)): ?>
        <p class="pad-y-5">No tienes artículos en tu lista de deseos.</p>
        <?php endif; ?>

        <div class="products">
            <div class="products-wrapper">
                <?php foreach ($wishlist as $product): ?>
                <a href="<?=url('index.php?page=product&id=' . ($product['url_slug'] ? $product['url_slug']  : $product['id']))?>" class="product">
                    <?php if (!empty($product['img']) && file_exists($product['img'])): ?>
                    <div class="img small">
                        <img src="<?=base_url?><?=$product['img']?>" width="150" height="150" alt="<?=$product['title']?>">
                    </div>
                    <?php endif; ?>
                    <span class="name"><?=$product['title']?></span>
                    <span class="price">
                        <?=currency_code?><?=number_format($product['price'],2)?>
                        <?php if ($product['rrp'] > 0): ?>
                        <span class="rrp"><?=currency_code?><?=number_format($product['rrp'],2)?></span>
                        <?php endif; ?>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

    </div>
    <?php elseif ($tab == 'settings'): ?>
    <div class="settings">

        <h2>Ajustes</h2>

        <form action="" method="post" class="form">

            <label for="email" class="form-label">Correo Electrónico</label>
            <input id="email" type="email" name="email" placeholder="Correo Electrónico" value="<?=htmlspecialchars($account['email'], ENT_QUOTES)?>" class="form-input expand" required>

            <label for="password" class="form-label">Nueva Contraseña</label>
            <input type="password" id="password" name="password" placeholder="Nueva Contraseña" value="" autocomplete="new-password" class="form-input expand">

            <div class="form-group">
                <div class="col pad-right-2">
                    <label for="first_name" class="form-label">Nombre</label>
                    <input id="first_name" type="text" name="first_name" placeholder="" value="<?=htmlspecialchars($account['first_name'], ENT_QUOTES)?>" class="form-input expand">
                </div>
                <div class="col pad-left-2">
                    <label for="last_name" class="form-label">Apellido</label>
                     <input id="last_name" type="text" name="last_name" placeholder="" value="<?=htmlspecialchars($account['last_name'], ENT_QUOTES)?>" class="form-input expand">
                </div>
            </div>

            <label for="address_street" class="form-label">Dirección</label>
            <input id="address_street" type="text" name="address_street" placeholder="" value="<?=htmlspecialchars($account['address_street'], ENT_QUOTES)?>" class="form-input expand">

            <label for="address_city" class="form-label">Ciudad</label>
            <input id="address_city" type="text" name="address_city" placeholder="" value="<?=htmlspecialchars($account['address_city'], ENT_QUOTES)?>" class="form-input expand">

            <div class="form-group">
                <div class="col pad-right-2">
                    <label for="address_state" class="form-label">Estado</label>
                    <input id="address_state" type="text" name="address_state" placeholder="" value="<?=htmlspecialchars($account['address_state'], ENT_QUOTES)?>" class="form-input expand">
                </div>
                <div class="col pad-left-2">
                    <label for="address_zip" class="form-label">Código Postal</label>
                    <input id="address_zip" type="text" name="address_zip" placeholder="" value="<?=htmlspecialchars($account['address_zip'], ENT_QUOTES)?>" class="form-input expand">
                </div>
            </div>

            <label for="address_country" class="form-label">País</label>
            <select id="address_country" name="address_country" required class="form-input expand">
                <?php foreach(get_countries() as $country): ?>
                <option value="<?=$country?>"<?=$country==$account['address_country']?' selected':''?>><?=$country?></option>
                <?php endforeach; ?>
            </select>

            <button name="save_details" type="submit" class="btn">Guardar</button>

            <?php if ($error): ?>
            <p class="error pad-top-2"><?=$error?></p>
            <?php endif; ?>

        </form>

    </div>

    <?php endif; ?>

    <?php endif; ?>

</div>

<?=template_footer()?>