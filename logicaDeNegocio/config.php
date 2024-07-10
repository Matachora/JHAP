<?php
define('db_host','localhost');
define('db_user','root');
define('db_pass','Cristel28');
define('db_name','mercado_libre');
define('site_name','Mercado Libre 2.0');
define('currency_code','$');
define('default_payment_status','Completo');
define('account_required',false);
define('weight_unit','kg');
define('rewrite_url',false);



//NO DISPONIBLE!!!!
//PROXIMAMENTE 

// Correo y server
define('mail_enabled',false);
define('mail_from','ejemplo@miau.com');
define('mail_name','Mercado Libre 2.0');
define('notifications_enabled',true);
define('notification_email','noti@ejemplo.com');
define('SMTP',false);
define('smtp_secure','ssl');
define('smtp_host','servidor.ejemplo.com');
define('smtp_port',465);
define('smtp_user','usuario@ejemplo.com');
define('smtp_pass','secret');

// Pagar cuando reciba
define('pay_on_delivery_enabled',true);

// PayPal
define('paypal_enabled',true);
define('paypal_email','payments@example.com');
define('paypal_testmode',true);
define('paypal_currency','MXN');
define('paypal_ipn_url','https://example.com/ipn/paypal.php');
define('paypal_cancel_url','https://example.com/index.php?page=cart');
define('paypal_return_url','https://example.com/index.php?page=placeorder');

// Stripe 
define('stripe_enabled',true);
define('stripe_publish_key','');
define('stripe_secret_key','');
define('stripe_currency','MXN');
define('stripe_ipn_url','https://example.com/ipn/stripe.php');
define('stripe_cancel_url','https://example.com/index.php?page=cart');
define('stripe_return_url','https://example.com/index.php?page=placeorder');
define('stripe_webhook_secret','');

// Coinbase
define('coinbase_enabled',false);
define('coinbase_key','');
define('coinbase_secret','');
define('coinbase_currency','MXN');
define('coinbase_cancel_url','https://example.com/index.php?page=cart');
define('coinbase_return_url','https://example.com/index.php?page=placeorder');


?>