const products = [
    {
      id: 0,
      image: 'img/imagen0.jpg',
      title: 'Sudadera de bts',
      price: 120,
    },
    {
      id: 1,
      image: 'img/imagen1.jpg',
      title: 'Bolsa Dior',
      price: 560,
    },
    {
      id: 2,
      image: 'img/imagen2.jpg',
      title: 'Gafas protectoras',
      price: 230,
    },
    {
      id: 3,
      image: 'img/imagen3.jpg',
      title: 'Gafas de sol',
      price: 100,
    },
  ];
  
  function displayProducts() {
    const productContainer = document.getElementById('root');
    productContainer.innerHTML = products.map((product) => {
      const { image, title, price } = product;
      return (
        `<div class="box">
          <div class="img-box">
            <img class="images" src="${image}" alt="${title}"/> </div>
          <div class="bottom">
            <p>${title}</p>
            <h2>$ ${price}.00</h2>
            <button onclick="addToCart(${product.id})">Agregar al carrito</button>
          </div>
        </div>`
      );
    }).join('');
  }
  
  displayProducts();
  
  let cart = [];
  
  function addToCart(productId) {
    const productToAdd = products.find((product) => product.id === productId);
    if (productToAdd) {
      cart.push({ ...productToAdd }); // Destructuring to avoid reference issues
    } else {
      console.error(`Product with ID ${productId} not found.`);
    }
    displayCart();
  }
  
  function displayCart() {
    const cartContainer = document.getElementById('cartItem');
    const totalCount = document.getElementById('count');
    const totalPrice = document.getElementById('total');
  
    if (cart.length === 0) {
      cartContainer.innerHTML = 'Tu carrito está vacío';
      totalCount.innerHTML = 0;
      totalPrice.innerHTML = '$ 0.00';
    } else {
      let total = 0;
      cartContainer.innerHTML = cart.map((cartItem, index) => {
        const { image, title, price } = cartItem;
        total += price;
        return (
          `<div class="cart-item">
            <div class="row-img">
              <img class="rowimg" src="${image}" alt="${title}">
            </div>
            <p style="font-size: 12px;">${title}</p>
            <h2 style="font-size: 15px;">$ ${price}.00</h2>
            <i class="fa-solid fa-trash" onclick="delElement(${index})"></i>
          </div>`
        );
      }).join('');
      totalCount.innerHTML = cart.length;
      totalPrice.innerHTML = `$ ${total}.00`;
    }
  }
  
  function delElement(index) {
    cart.splice(index, 1);
    displayCart();
  }
  