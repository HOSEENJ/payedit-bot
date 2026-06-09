<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';

/**
 * Tetraminator Payment Callback Handler
 * 
 * This script handles payment callbacks from Tetraminator gateway
 * Endpoint: https://yourdomain.com/payment/tetraminator.php?invoice_id=XXX
 * 
 * Tetraminator will send a GET request with the invoice_id parameter
 * to confirm that payment has been completed
 */

$invoice_id = htmlspecialchars($_GET['invoice_id'] ?? '', ENT_QUOTES, 'UTF-8');
$setting = select("setting", "*");
$textbotlang = languagechange();

// Validate invoice_id parameter exists
if (empty($invoice_id)) {
    http_response_code(400);
    error_log("[TETRAMINATOR] Missing invoice_id parameter");
    die(json_encode(['error' => 'Missing invoice_id parameter']));
}

// Get payment report from database
$Payment_report = select("Payment_report", "*", "id_order", $invoice_id, "select");

if (!$Payment_report) {
    http_response_code(404);
    error_log("[TETRAMINATOR] Invoice not found: $invoice_id");
    die(json_encode(['error' => 'Invoice not found']));
}

// Check if payment is already marked as paid
if ($Payment_report['payment_Status'] == 'paid') {
    http_response_code(200);
    error_log("[TETRAMINATOR] Payment already processed for invoice: $invoice_id");
    echo json_encode(['status' => 'already_paid']);
    exit;
}

try {
    // Mark payment as confirmed and update user balance
    $textbotlang = languagechange();
    DirectPayment($invoice_id, "../images.jpg");
    
    // Get cashback setting for Tetraminator
    $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbacktetraminator", "select")['ValuePay'] ?? "0";
    
    // Get user information
    $Balance_id = select("user", "*", "id", $Payment_report['id_user'], "select");
    
    if (!$Balance_id) {
        http_response_code(404);
        error_log("[TETRAMINATOR] User not found for payment: $invoice_id");
        die(json_encode(['error' => 'User not found']));
    }

    // Apply cashback if configured
    if ($pricecashback != "0") {
        $cashback_amount = ($Payment_report['price'] * $pricecashback) / 100;
        $new_balance = intval($Balance_id['Balance']) + $cashback_amount;
        update("user", "Balance", $new_balance, "id", $Balance_id['id']);
        
        $cashback_formatted = number_format($cashback_amount);
        $text_report = sprintf($textbotlang['hardcoded']['giftDepositNotice'] ?? 'Gift: %s Toman', $cashback_formatted);
        sendmessage($Balance_id['id'], $text_report, null, 'HTML');
    }

    // Send success message to user
    $user_message = sprintf(
        "✅ پرداخت با موفقیت انجام شد\n\n💰 مبلغ: %s تومان\n🔢 شناسه تراکنش: %s\n\nمبلغ به کیف پول شما واریز شد.",
        number_format($Payment_report['price']),
        $invoice_id
    );
    sendmessage($Payment_report['id_user'], $user_message, null, 'HTML');

    // Log payment success
    error_log("[TETRAMINATOR] Payment confirmed for invoice: $invoice_id, user: " . $Payment_report['id_user']);

    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => 'Payment confirmed']);
    
} catch (Exception $e) {
    error_log("[TETRAMINATOR] Error processing callback for invoice: $invoice_id - " . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Processing error', 'message' => $e->getMessage()]));
}
?>
