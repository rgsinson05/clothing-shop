-- ============================================================
-- Clothing Shop Website
-- Database Schema
-- ============================================================
--
-- Business model:
-- Each product row represents one unique physical clothing item.
-- Example:
--   Product #1 = one specific shirt
--   Product #2 = another specific shirt
--
-- V1 product status:
--   AVAILABLE = can be purchased
--   SOLD      = already sold
--
-- Product variants are intentionally NOT used.
-- ============================================================


-- ============================================================
-- 1. CUSTOMERS
-- ============================================================

CREATE TABLE customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,

    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,

    phone VARCHAR(30),

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


-- ============================================================
-- 2. ADMINS
-- ============================================================

CREATE TABLE admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    name VARCHAR(150) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
);


-- ============================================================
-- 3. PRODUCTS
-- ============================================================
--
-- One row = one actual physical item.
--
-- Example:
-- A shirt priced at ₱150 is one product.
-- If there are three different shirts, they are three
-- separate product rows.
--
-- Quantity is therefore NOT stored here.
-- V1 assumes each unique item has a quantity of 1.
-- ============================================================

CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    category ENUM('SHIRTS','PANTS','SHORTS') NOT NULL,
    description TEXT,
    condition_label VARCHAR(50) NOT NULL,
    defects TEXT,
    price DECIMAL(10, 2) NOT NULL,
    size VARCHAR(20),
    color VARCHAR(50),
    status ENUM('AVAILABLE','SOLD') NOT NULL DEFAULT 'AVAILABLE',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


-- ============================================================
-- 4. PRODUCT IMAGES
-- ============================================================
--
-- A product can have one or multiple images.
--
-- product_id connects each image to its product.
--
-- sort_order determines which image appears first.
--
-- Example:
-- Product #10
--   image 1 -> front photo
--   image 2 -> back photo
--   image 3 -> defect/detail photo
-- ============================================================

CREATE TABLE product_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_images_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- 5. CARTS
-- ============================================================
--
-- Each customer has one active cart.
--
-- The cart itself does NOT reserve products.
--
-- An item remains AVAILABLE while it is inside someone's cart.
-- The server checks availability again when the customer
-- actually places the order.
-- ============================================================

CREATE TABLE carts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    customer_id INT UNSIGNED NOT NULL UNIQUE,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_carts_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- 6. CART ITEMS
-- ============================================================
--
-- Quantity is intentionally removed.
--
-- Since each product represents one unique physical item,
-- a cart can contain an item only once.
--
-- UNIQUE(cart_id, product_id) prevents duplicate entries.
-- ============================================================

CREATE TABLE cart_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    cart_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_cart_items_cart
        FOREIGN KEY (cart_id)
        REFERENCES carts(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_cart_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,

    CONSTRAINT uq_cart_product
        UNIQUE (cart_id, product_id)
);


-- ============================================================
-- 7. ORDERS
-- ============================================================
--
-- An order belongs to one customer.
--
-- subtotal:
--   Total price of products.
--
-- shipping_fee:
--   Shipping charge. The actual business rule will be finalized
--   after confirming the shop owner's shipping policy.
--
-- total_amount:
--   subtotal + shipping_fee.
--
-- Shipping information is stored with the order so that the
-- historical order keeps the information used at checkout,
-- even if the customer's account information changes later.
-- ============================================================

CREATE TABLE orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    customer_id INT UNSIGNED NOT NULL,

    status ENUM(
        'PENDING',
        'CONFIRMED',
        'PACKED',
        'SHIPPED',
        'DELIVERED',
        'CANCELLED'
    ) NOT NULL DEFAULT 'PENDING',

    subtotal DECIMAL(10, 2) NOT NULL,

    shipping_fee DECIMAL(10, 2) NOT NULL DEFAULT 0.00,

    total_amount DECIMAL(10, 2) NOT NULL,

    shipping_name VARCHAR(150) NOT NULL,
    shipping_phone VARCHAR(30) NOT NULL,
    shipping_address TEXT NOT NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id)
        REFERENCES customers(id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
);


-- ============================================================
-- 8. ORDER ITEMS
-- ============================================================
--
-- Order items contain SNAPSHOT information.
--
-- This is important.
--
-- Example:
-- A customer buys a shirt for ₱150.
-- Later, the admin edits the product price to ₱200.
--
-- The old order must still show:
--   Shirt
--   ₱150
--
-- Therefore product_name, size, color, and unit_price are
-- copied into the order item when the order is created.
--
-- product_id is kept as a reference to the original product
-- when possible.
--
-- product_id can become NULL if the original product is removed.
-- ============================================================

CREATE TABLE order_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL,

    product_id INT UNSIGNED NULL,

    product_name VARCHAR(255) NOT NULL,

    size VARCHAR(20),
    color VARCHAR(50),

    unit_price DECIMAL(10, 2) NOT NULL,

    quantity INT UNSIGNED NOT NULL DEFAULT 1,

    subtotal DECIMAL(10, 2) NOT NULL,

    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id)
        REFERENCES products(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
);


-- ============================================================
-- 9. PAYMENTS
-- ============================================================
--
-- V1 supported payment methods:
--   COD
--   GCash
--   Card
--
-- Actual payment gateway integration can be added later.
-- ============================================================

CREATE TABLE payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL UNIQUE,

    payment_method ENUM(
        'COD',
        'GCASH',
        'CARD'
    ) NOT NULL,

    payment_status ENUM(
        'PENDING',
        'PAID',
        'FAILED',
        'REFUNDED'
    ) NOT NULL DEFAULT 'PENDING',

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_payments_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- 10. SHIPMENTS
-- ============================================================
--
-- Each order has one shipment record.
--
-- V1 does NOT use a courier API.
--
-- The admin can manually enter the J&T tracking number
-- and update shipment/order status.
-- ============================================================

CREATE TABLE shipments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    order_id INT UNSIGNED NOT NULL UNIQUE,

    shipment_status ENUM(
        'NOT_SHIPPED',
        'READY_TO_SHIP',
        'SHIPPED',
        'IN_TRANSIT',
        'OUT_FOR_DELIVERY',
        'DELIVERED'
    ) NOT NULL DEFAULT 'NOT_SHIPPED',

    tracking_number VARCHAR(100),

    shipped_at DATETIME NULL,
    delivered_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_shipments_order
        FOREIGN KEY (order_id)
        REFERENCES orders(id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);


-- ============================================================
-- END OF SCHEMA
-- ============================================================