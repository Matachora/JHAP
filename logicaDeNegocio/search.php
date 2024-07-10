<?php
// Impedir el acceso directo al archivo
defined('shoppingcart') or exit;
//Buscar consulta de búsqueda
if (isset($_GET['query']) && $_GET['query'] != '') {
    $search_query = htmlspecialchars($_GET['query'], ENT_QUOTES);
    // Seleccionar productos ordenados por fecha agregada
    $stmt = $pdo->prepare('SELECT p.*, (SELECT m.full_path FROM products_media pm JOIN media m ON m.id = pm.media_id WHERE pm.product_id = p.id ORDER BY pm.position ASC LIMIT 1) AS img FROM products p WHERE p.product_status = 1 AND p.title LIKE ? ORDER BY p.created DESC');
    $stmt->execute(['%' . $search_query . '%']);
    // Busca los productos de la base de datos 
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Obtener el número total de productos
    $total_products = count($products);
} else {
    $error = '¡No se especificó una búsqueda!';
}
?>
<?=template_header('Search')?>

<?php if ($error): ?>

<p class="content-wrapper error"><?=$error?></p>

<?php else: ?>

<div class="products content-wrapper">

    <h1 class="page-title">Resultados de la búsqueda para "<?=$search_query?>"</h1>

    <p><?=$total_products?> Producto<?=$total_products!=1?'s':''?></p>

    <div class="products-wrapper">
        <?php foreach ($products as $product): ?>
        <a href="<?=url('index.php?page=product&id=' . ($product['url_slug'] ? $product['url_slug']  : $product['id']))?>" class="product<?=$product['quantity']==0?' no-stock':''?>">
            <?php if (!empty($product['img']) && file_exists($product['img'])): ?>
            <div class="img">
                <img src="<?=base_url?><?=$product['img']?>" width="200" height="200" alt="<?=$product['title']?>">
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

<?php endif; ?>

<?=template_footer()?>