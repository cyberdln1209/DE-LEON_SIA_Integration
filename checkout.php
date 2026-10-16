<?php
require __DIR__ . '/config.php';

function finish(string $title, string $message): never {
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title><body style="font:16px Arial;max-width:800px;margin:40px auto">';
    echo '<h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($message) . '</p><p><a href="index.php">Store</a> | <a href="cart.php">Cart</a> | <a href="orders.php">Orders</a></p></body></html>';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit; }

$customer = trim((string)($_POST['customer_name'] ?? ''));
$method = (string)($_POST['payment_method'] ?? 'CARD');
if ($customer === '') finish('Missing information', 'Enter a customer name.');
if (!in_array($method, ['CARD','EWALLET','BANK'], true)) $method = 'CARD';

$cart = $_SESSION['cart'] ?? [];
if (!$cart) finish('Empty cart', 'Add an item before checkout.');

$pdo = cart_db();
$ids = array_map('intval', array_keys($cart));
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare("SELECT id,name,price,stock FROM products WHERE id IN ($marks)");
$stmt->execute($ids);
$products = $stmt->fetchAll();
$total = 0.0; $validItems = [];
foreach ($products as $p) {
    $qty = min((int)$cart[$p['id']], (int)$p['stock']);
    if ($qty > 0) {
        $total += $qty * (float)$p['price'];
        $validItems[] = ['product'=>$p,'qty'=>$qty];
    }
}
if (!$validItems || $total <= 0) finish('Checkout error', 'No valid items were found.');

/* Persist order before calling the independent payment service. */
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("INSERT INTO orders (customer_name,total_amount,status) VALUES (?,?,'PENDING_PAYMENT')");
    $stmt->execute([$customer, number_format($total,2,'.','')]);
    $orderId = (int)$pdo->lastInsertId();
    $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id,product_id,product_name,quantity,unit_price) VALUES (?,?,?,?,?)');
    foreach ($validItems as $i) {
        $itemStmt->execute([$orderId,$i['product']['id'],$i['product']['name'],$i['qty'],$i['product']['price']]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    finish('Order error', 'Could not save the order.');
}



/*
 * STUDENT TASK: Integrate the shopping cart with the
 * independent payment gateway.
 *
 * Requirements:
 * 1. Prepare the payment request payload.
 * 2. Send an HTTP POST request with JSON using PHP cURL.
 * 3. Handle the gateway's HTTP response.
 * 4. Validate the response and match the order ID.
 * 5. Handle SUCCESS, FAILED, and PENDING outcomes.
 * 6. Handle timeouts and invalid responses safely.
 * 7. Save the gateway transaction ID and update the
 *    order status in the shopping cart database.
 * 8. Prevent duplicate payment requests using an
 *    idempotency key.
 *
 * Read payment-gateway/README-API.md for the API contract.
 */


/* Connect the shopping cart to the payment gateway. */

$apiUrl = 'http://localhost/payment-gateway/api/payments.php';

$payload = [
    'order_id' => $orderId,
    'amount' => round($total, 2),
    'customer_name' => $customer,
    'payment_method' => $method,
    'idempotency_key' => 'cart-order-' . $orderId,
    'outcome' => 'SUCCESS'
];

/* Send the payment request using cURL. */

$ch = curl_init($apiUrl);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 15
]);

$responseBody = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

/* Handle connection errors and invalid HTTP responses. */

if ($responseBody === false) {
    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'INTEGRATION_ERROR'
         WHERE id = ?"
    );
    $stmt->execute([$orderId]);

    finish(
        'Integration Error',
        'The payment gateway could not be reached. Please verify the transaction before retrying.'
    );
}

/* Decode and validate the gateway response. */

$data = json_decode($responseBody, true);

if (
    !is_array($data) ||
    ($data['success'] ?? false) !== true ||
    (int) ($data['order_id'] ?? 0) !== $orderId ||
    !is_numeric($data['amount'] ?? null) ||
    abs(round((float) $data['amount'], 2) - round($total, 2)) > 0.009 ||
    !in_array($data['status'] ?? '', ['SUCCESS', 'FAILED', 'PENDING'], true) ||
    !is_string($data['transaction_id'] ?? null) ||
    trim($data['transaction_id']) === ''
) {
    $stmt = $pdo->prepare(
        "UPDATE orders SET status = 'PAYMENT_PENDING' WHERE id = ?"
    );
    $stmt->execute([$orderId]);

    finish(
        'Payment Status Uncertain',
        'The gateway response was invalid or did not match the order. Verify the transaction before retrying.'
    );
}

/* Map the gateway status to the shopping cart status. */

$statusMap = [
    'SUCCESS' => 'PAID',
    'FAILED' => 'PAYMENT_FAILED',
    'PENDING' => 'PAYMENT_PENDING'
];

$orderStatus = $statusMap[$data['status']];

/* Save the transaction ID and updated order status. */

$stmt = $pdo->prepare(
    'UPDATE orders
     SET status = ?, gateway_transaction_id = ?
     WHERE id = ?'
);

$stmt->execute([
    $orderStatus,
    $data['transaction_id'],
    $orderId
]);

/* Clear the cart after receiving a valid gateway response. */

unset($_SESSION['cart']);

/* Display the checkout result. */

if ($data['status'] === 'SUCCESS') {
    finish(
        'Payment Successful',
        'Order #' . $orderId .
        ' has been paid. Transaction ID: ' .
        $data['transaction_id']
    );
}

if ($data['status'] === 'FAILED') {
    finish(
        'Payment Failed',
        'Order #' . $orderId .
        ' was not paid. Transaction ID: ' .
        $data['transaction_id']
    );
}

finish(
    'Payment Pending',
    'Order #' . $orderId .
    ' is awaiting confirmation. Transaction ID: ' .
    $data['transaction_id']
);

