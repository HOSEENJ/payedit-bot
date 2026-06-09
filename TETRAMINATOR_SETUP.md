# Tetraminator Payment Gateway Integration

Complete guide to integrate Tetraminator payment gateway into your payment system.

## Overview

Tetraminator is a cryptocurrency payment gateway that accepts USDT, USDC, and other stablecoins. This integration includes:

- ✅ Payment invoice creation
- ✅ Webhook callback handling
- ✅ Automatic status polling
- ✅ User balance updates
- ✅ Cashback/commission support

## Installation Steps

### 1. Database Configuration

Add Tetraminator settings to your `PaySetting` table:

```sql
-- API Configuration
INSERT INTO PaySetting (NamePay, ValuePay) VALUES 
('tetraminator_api_key', 'your_api_key_here'),
('tetraminator_api_url', 'http://136.244.104.77:5000/api/v1'),
('chashbacktetraminator', '0');  -- Cashback percentage (0 = disabled)
```

### 2. Update Payment Method Selection

Add Tetraminator to your payment method keyboard in your bot code:

```php
$keyboard = json_encode([
    'inline_keyboard' => [
        [['text' => '💵 Tetraminator (USDT)', 'callback_data' => 'pay_tetraminator']],
        [['text' => '💳 Other Method', 'callback_data' => 'pay_other']],
    ]
]);
```

### 3. Add Cron Job

Add the polling job to your `activecron()` function in `function.php`:

```php
function activecron()
{
    global $domainhosts;
    
    $cronCommands = [
        // ... existing crons ...
        "*/1 * * * * curl https://$domainhosts/cronbot/tetraminator.php",  // Check every minute
    ];
    
    addCronIfNotExists($cronCommands);
}
```

## Usage

### Creating Payment Invoice

```php
// In your payment initiation code
function createPaymentTetraminator($amount, $order_id)
{
    global $domainhosts;
    
    $api_key = select("PaySetting", "ValuePay", "NamePay", "tetraminator_api_key", "select")['ValuePay'];
    $api_url = select("PaySetting", "ValuePay", "NamePay", "tetraminator_api_url", "select")['ValuePay'];
    
    $curl = curl_init();
    
    $data = [
        "amount" => $amount,
        "currency" => "USD",
        "order_id" => $order_id,
        "callback_url" => "https://$domainhosts/payment/tetraminator.php?invoice_id=$order_id",
        "return_url" => "https://$domainhosts/payment/success.php",
    ];
    
    curl_setopt_array($curl, array(
        CURLOPT_URL => $api_url . '/invoice/create',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'X-API-KEY: ' . $api_key
        ),
    ));
    
    $response = curl_exec($curl);
    curl_close($curl);
    
    return json_decode($response, true);
}
```

### Handling Payment Selection

Add this to your bot command handler:

```php
if ($callback_data == 'pay_tetraminator') {
    $amount = // get amount from user input
    $order_id = generateUUID();  // or use any unique ID
    
    $payment_response = createPaymentTetraminator($amount, $order_id);
    
    if (isset($payment_response['payment_url'])) {
        $keyboard = json_encode([
            'inline_keyboard' => [
                [['text' => '💳 Pay Now', 'url' => $payment_response['payment_url']]],
            ]
        ]);
        
        sendmessage($from_id, "💰 Payment Invoice Created\n\n" . 
            "🔢 Invoice: $order_id\n" .
            "💵 Amount: $amount USD", $keyboard, 'HTML');
        
        // Store payment report
        update("Payment_report", "Payment_Method", "Tetraminator", "id_order", $order_id);
    }
}
```

## File Structure

```
payment/
├── tetraminator.php          # Callback handler
└── [other gateway files]

cronbot/
├── tetraminator.php          # Status polling job
└── [other cron jobs]
```

## Webhook Flow

### 1. User Initiates Payment
- Bot creates invoice via `createPaymentTetraminator()`
- User receives payment link
- User completes payment on Tetraminator

### 2. Callback Received
- Tetraminator sends GET request to `/payment/tetraminator.php?invoice_id=XXX`
- Script validates payment
- Updates user balance
- Sends confirmation message

### 3. Fallback Polling
- Cron job runs every minute via `/cronbot/tetraminator.php`
- Queries API for pending payment status
- Updates database if payment confirmed
- Handles missed webhooks

## Payment Status Flow

```
Pending → Processing → Paid ✅
       ↓
     Rejected ❌
```

## Configuration Options

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| tetraminator_api_key | String | Required | Your Tetraminator API key |
| tetraminator_api_url | String | http://136.244.104.77:5000/api/v1 | Tetraminator API endpoint |
| chashbacktetraminator | Number | 0 | Cashback percentage (0-100) |

## Error Handling

The integration includes comprehensive error logging:

```
error_log "[TETRAMINATOR] Missing invoice_id parameter"
error_log "[TETRAMINATOR] Invoice not found"
error_log "[TETRAMINATOR] Payment already processed"
error_log "[TETRAMINATOR] Payment confirmed: invoice=xxx, user=xxx"
error_log "[TETRAMINATOR-CRON] API key not configured"
error_log "[TETRAMINATOR-CRON] Status check failed"
```

Check your `error_log` file for debugging.

## Security Considerations

1. **API Key Protection**
   - Store API key in database, never in code
   - Use environment variables for production
   
2. **Request Validation**
   - Validate invoice_id format
   - Check payment_Status before processing
   - Verify user ownership of payment

3. **Double-Spend Prevention**
   - Check if payment already marked as "paid"
   - Use database transaction locks
   - Log all payment confirmations

## Testing

### Manual Testing

1. Create test payment:
```php
$test = createPaymentTetraminator(100, "test_123");
var_dump($test);
```

2. Simulate webhook:
```
curl "https://yourdomain.com/payment/tetraminator.php?invoice_id=test_123"
```

3. Check logs:
```bash
tail -f error_log | grep TETRAMINATOR
```

### Database Verification

```sql
SELECT * FROM Payment_report WHERE Payment_Method = 'Tetraminator';
SELECT id, Balance FROM user WHERE id = [user_id];
```

## Troubleshooting

### Issue: "API key not configured"
- **Solution**: Verify tetraminator_api_key exists in PaySetting table

### Issue: Payment not confirming
- **Check**: 
  - Is cron job running? (`*/1 * * * *` every minute)
  - Is API URL correct?
  - Is invoice_id matching database records?

### Issue: User not receiving notification
- **Check**:
  - Is user ID correct in Payment_report?
  - Is sendmessage() function working?
  - Check Telegram bot API logs

## Support

For integration issues:
1. Check `error_log` for detailed messages
2. Verify database PaySetting configuration
3. Test API connectivity manually
4. Review webhook callback payload

## Files Modified

- ✅ `payment/tetraminator.php` - NEW
- ✅ `cronbot/tetraminator.php` - NEW
- ⚠️ `function.php` - Add createPaymentTetraminator() function
- ⚠️ Bot handler - Add Tetraminator payment option

## Next Steps

1. ✅ Add configuration to database
2. ✅ Set up cron job
3. ⚠️ Implement payment method handler in your bot
4. ⚠️ Add Tetraminator button to payment keyboard
5. ⚠️ Test with sample payments
6. ⚠️ Monitor logs for issues
