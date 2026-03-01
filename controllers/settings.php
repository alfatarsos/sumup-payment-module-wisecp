<?php
    if(!defined("CORE_FOLDER")) die();

    $lang   = $module->lang;
    $config = $module->config;

    // Get POST values - use less restrictive filters for API credentials
    $api_key         = Filter::POST("api_key");
    $merchant_code   = Filter::POST("merchant_code");
    $currency        = Filter::init("POST/currency", "letters");
    $commission_rate = Filter::init("POST/commission_rate", "amount");
    $commission_rate = str_replace(",", ".", $commission_rate);

    // Clean the values manually (trim whitespace)
    $api_key       = trim($api_key);
    $merchant_code = trim($merchant_code);
    $currency      = strtoupper(trim($currency));

    // Validate currency
    $valid_currencies = ['EUR', 'GBP', 'USD', 'CHF', 'PLN', 'SEK', 'DKK', 'NOK', 'HUF', 'CZK', 'BGN', 'BRL'];
    if(!in_array($currency, $valid_currencies)) {
        $currency = 'EUR';
    }

    // Always update settings
    $sets = $config;
    $sets["settings"]["api_key"]         = $api_key;
    $sets["settings"]["merchant_code"]   = $merchant_code;
    $sets["settings"]["currency"]        = $currency;
    $sets["settings"]["commission_rate"] = $commission_rate ? $commission_rate : 0;

    // Save configuration
    $config_result = array_replace_recursive($config, $sets);
    $array_export  = Utility::array_export($config_result, ['pwith' => true]);
    
    $file  = dirname(__DIR__) . DS . "config.php";
    $write = FileManager::file_write($file, $array_export);

    if($write === false) {
        echo Utility::jencode([
            'status'  => "error",
            'message' => "Failed to save configuration. Please check file permissions.",
        ]);
        return;
    }

    // Log admin action
    $adata = UserManager::LoginData("admin");
    if($adata) {
        User::addAction($adata["id"], "alteration", "changed-payment-module-settings", [
            'module' => $config["meta"]["name"],
            'name'   => $lang["name"],
        ]);
    }

    echo Utility::jencode([
        'status'  => "successful",
        'message' => $lang["success1"],
    ]);
