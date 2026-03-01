# SumUp Payment Module for WiseCP

## Description
This module integrates SumUp payment processing with WiseCP, allowing customers to pay with credit cards directly on your website.

## Features
- Credit card payments via SumUp API
- 3D Secure support (automatic redirect when required)
- Refund support
- Multi-language support (English, Portuguese)

## Requirements
- WiseCP 3.1.9.7 or higher
- SumUp merchant account
- SumUp API Key
- PHP 8.2+ with cURL extension

## Installation

1. Upload the `SumUp` folder to:
   ```
   /coremio/modules/Payment/
   ```

2. The final structure should be:
   ```
   /coremio/modules/Payment/SumUp/
   ├── config.php
   ├── SumUp.php
   ├── index.html
   ├── README.md
   └── lang/
       ├── en.php
       ├── pt.php
       └── index.html
   ```

3. Go to WiseCP Admin Panel → Settings → Payment Gateways

4. Find "SumUp" and click "Configure"

## Configuration

### Getting Your SumUp API Key

1. Log in to your [SumUp Dashboard](https://me.sumup.com/)
2. Go to **Developer** → **API Keys**
3. Click **Create API Key**
4. Give it a name and select the required scopes:
   - `payments` (for processing payments)
   - `transactions.history` (for refunds)
5. Copy the generated API key (starts with `sup_sk_`)

### Getting Your Merchant Code

1. In the SumUp Dashboard, go to **Account** → **Account Details**
2. Your Merchant Code is displayed (e.g., `MH4H92C7`)

### Module Settings in WiseCP

- **API Key**: Your SumUp API key (e.g., `sup_sk_xxx...`)
- **Merchant Code**: Your SumUp merchant code (e.g., `MH4H92C7`)
- **Commission Rate (%)**: Optional - add a percentage fee to transactions

## How It Works

1. Customer selects "Pay by Credit Card" at checkout
2. Customer enters their card details in the WiseCP payment form
3. The module creates a checkout in SumUp
4. The module processes the payment with the card details
5. If 3D Secure is required, the customer is redirected for verification
6. Payment result is returned and the order is processed

## Supported Currencies

SumUp supports the following currencies:
- EUR, GBP, USD, CHF, PLN, SEK, DKK, NOK, HUF, CZK, BGN, BRL, CLP, COP

## Troubleshooting

### Payment fails immediately
- Check that your API Key is correct
- Verify the Merchant Code is correct
- Ensure your SumUp account is active and verified

### 3D Secure redirect issues
- Ensure your website has a valid SSL certificate
- Check that the callback URL is accessible

### Refunds not working
- Verify your API key has the `transactions.history` scope
- Check that the transaction exists in your SumUp account

## Support

For SumUp API documentation, visit:
https://developer.sumup.com/api

For WiseCP module development documentation:
https://docs.wisecp.com/en/kb/payment-gateways-getting-started

## Changelog

### Version 1.0
- Initial release
- Credit card payments
- 3D Secure support
- Refund functionality
- English and Portuguese translations
