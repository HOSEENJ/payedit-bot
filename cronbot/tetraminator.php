<?php
ini_set('error_log', 'error_log');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';

/**
 * Tetraminator Payment Status Polling Cron Job
 * 
 * This script runs every minute to check pending payments
 * Queries Tetraminator API to verify payment status
 * Updates database and notifies users of successful payments
 */

$tetraminator_api_key = select("PaySetting", "ValuePay", "NamePay", "tetraminator_api_key", "select")['ValuePay'] ?? '';
$tetraminator_api_url = select("PaySetting", "ValuePay", "NamePay", "tetraminator_api_url", "select")['ValuePay'] ?? 'http://136.244.104.77:5000/api/v1';

if (empty($tetraminator_api_key)) {
    error_log("[TETRAMINATOR-CRON] API key not configured");
    exit;
}

// Get all pending payments from last 7 days
$pending_payments = $connect->query("
    SELECT * FROM Payment_report 
    WHERE Payment_Method = 'Tetraminator' 
    AND payment_Status NOT IN ('paid', 'reject')
    AND DATEDIFF(NOW(), date_Payment) <= 7
    ORDER BY date_Payment DESC
");

if (!$pending_payments) {
    error_log("[TETRAMINATOR-CRON] Database query error: " . $connect->error);
    exit;
}

$textbotlang = languagechange();
$processed_count = 0;

while ($Payment_report = mysqli_fetch_assoc($pending_payments)) {
    $invoice_id = $Payment_report['id_order'];
    $user_id = $Payment_report['id_user'];
    
    try {
        // Query payment status from Tetraminator API
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $tetraminator_api_url . '/invoice/status',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-API-KEY: ' . $tetraminator_api_key
            ),
            CURLOPT_POSTFIELDS => json_encode(['invoice_id' => $invoice_id])
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code != 200) {
            error_log("[TETRAMINATOR-CRON] Status check failed for invoice $invoice_id: HTTP $http_code");
            continue;
        }

        $response_data = json_decode($response, true);

        if (!isset($response_data['status'])) {
            error_log("[TETRAMINATOR-CRON] Invalid response for invoice $invoice_id");
            continue;
        }

        // If payment is confirmed
        if ($response_data['status'] === 'paid' || $response_data['status'] === 'completed') {
            
            // Check if already processed
            if ($Payment_report['payment_Status'] == 'paid') {
                continue;
            }

            // Process successful payment
            DirectPayment($invoice_id);
            
            // Get user info
            $Balance_id = select("user", "*", "id", $user_id, "select");
            
            if ($Balance_id) {
                // Apply cashback
                $pricecashback = select("PaySetting", "ValuePay", "NamePay", "chashbacktetraminator", "select")['ValuePay'] ?? "0";
                
                if ($pricecashback != "0") {
                    $cashback_amount = ($Payment_report['price'] * $pricecashback) / 100;
                    $new_balance = intval($Balance_id['Balance']) + $cashback_amount;
                    update("user", "Balance", $new_balance, "id", $Balance_id['id']);
                    
                    $text_report = sprintf($textbotlang['hardcoded']['giftDepositNotice'] ?? 'Gift: %s Toman', number_format($cashback_amount));
                    sendmessage($Balance_id['id'], $text_report, null, 'HTML');
                }

                // Notify user
                $user_message = sprintf(
                    "✅ پرداخت شما تایید شد\n\n💰 مبلغ: %s تومان\n🔢 شناسه: %s",
                    number_format($Payment_report['price']),
                    $invoice_id
                );
                sendmessage($user_id, $user_message, null, 'HTML');
            }

            error_log("[TETRAMINATOR-CRON] Payment confirmed: invoice=$invoice_id, user=$user_id");
            $processed_count++;
        }

    } catch (Exception $e) {
        error_log("[TETRAMINATOR-CRON] Error processing invoice $invoice_id: " . $e->getMessage());
        continue;
    }
}

error_log("[TETRAMINATOR-CRON] Completed. Processed: $processed_count payments");
?>
