<?php
    if(!defined("CORE_FOLDER")) die();
    $LANG       = $module->lang;
    $CONFIG     = $module->config;
    $callback_url = Controllers::$init->CRLink("payment", ['SumUp', $module->get_auth_token(), 'callback'], "none");
?>

<form action="<?php echo Controllers::$init->getData("links")["controller"]; ?>" method="post" id="SumUp">
    <input type="hidden" name="operation" value="module_controller">
    <input type="hidden" name="module" value="SumUp">
    <input type="hidden" name="controller" value="settings">

    <div class="blue-info" style="margin-bottom:20px;">
        <div class="padding15">
            <i class="fa fa-info-circle" aria-hidden="true"></i>
            <p><?php echo $LANG["description"]; ?></p>
        </div>
    </div>

    <div class="formcon">
        <div class="yuzde30"><?php echo $LANG["api-key"]; ?></div>
        <div class="yuzde70">
            <input type="text" name="api_key" value="<?php echo htmlspecialchars($CONFIG["settings"]["api_key"] ?? ''); ?>" placeholder="sup_sk_...">
            <span class="kinfo"><?php echo $LANG["api-key-desc"]; ?></span>
        </div>
    </div>

    <div class="formcon">
        <div class="yuzde30"><?php echo $LANG["merchant-code"]; ?></div>
        <div class="yuzde70">
            <input type="text" name="merchant_code" value="<?php echo htmlspecialchars($CONFIG["settings"]["merchant_code"] ?? ''); ?>" placeholder="MH4H92C7">
            <span class="kinfo"><?php echo $LANG["merchant-code-desc"]; ?></span>
        </div>
    </div>

    <div class="formcon">
        <div class="yuzde30"><?php echo $LANG["currency"] ?? 'Currency'; ?></div>
        <div class="yuzde70">
            <?php $selected_currency = $CONFIG["settings"]["currency"] ?? 'EUR'; ?>
            <select name="currency" style="width: 150px;">
                <option value="EUR"<?php echo $selected_currency == 'EUR' ? ' selected' : ''; ?>>EUR - Euro</option>
                <option value="GBP"<?php echo $selected_currency == 'GBP' ? ' selected' : ''; ?>>GBP - British Pound</option>
                <option value="USD"<?php echo $selected_currency == 'USD' ? ' selected' : ''; ?>>USD - US Dollar</option>
                <option value="CHF"<?php echo $selected_currency == 'CHF' ? ' selected' : ''; ?>>CHF - Swiss Franc</option>
                <option value="PLN"<?php echo $selected_currency == 'PLN' ? ' selected' : ''; ?>>PLN - Polish Zloty</option>
                <option value="SEK"<?php echo $selected_currency == 'SEK' ? ' selected' : ''; ?>>SEK - Swedish Krona</option>
                <option value="DKK"<?php echo $selected_currency == 'DKK' ? ' selected' : ''; ?>>DKK - Danish Krone</option>
                <option value="NOK"<?php echo $selected_currency == 'NOK' ? ' selected' : ''; ?>>NOK - Norwegian Krone</option>
                <option value="HUF"<?php echo $selected_currency == 'HUF' ? ' selected' : ''; ?>>HUF - Hungarian Forint</option>
                <option value="CZK"<?php echo $selected_currency == 'CZK' ? ' selected' : ''; ?>>CZK - Czech Koruna</option>
                <option value="BGN"<?php echo $selected_currency == 'BGN' ? ' selected' : ''; ?>>BGN - Bulgarian Lev</option>
                <option value="BRL"<?php echo $selected_currency == 'BRL' ? ' selected' : ''; ?>>BRL - Brazilian Real</option>
            </select>
            <span class="kinfo"><?php echo $LANG["currency-desc"] ?? 'Select the currency of your SumUp merchant account.'; ?></span>
        </div>
    </div>

    <div class="formcon">
        <div class="yuzde30"><?php echo $LANG["commission-rate"]; ?></div>
        <div class="yuzde70">
            <input type="text" name="commission_rate" value="<?php echo $CONFIG["settings"]["commission_rate"] ?? 0; ?>" style="width: 80px;">
            <span class="kinfo"><?php echo $LANG["commission-rate-desc"]; ?></span>
        </div>
    </div>

    <div class="formcon">
        <div class="yuzde30" style="color: #2196F3;"><?php echo $LANG["callback-url"] ?? 'Callback URL'; ?></div>
        <div class="yuzde70">
            <span class="selectalltext" style="font-size:13px;font-weight:600;color: #2196F3;"><?php echo $callback_url; ?></span>
            <div class="clear"></div>
            <span class="kinfo"><?php echo $LANG["callback-url-desc"] ?? 'This URL is used for 3D Secure callbacks. You may need to configure this in your SumUp dashboard.'; ?></span>
        </div>
    </div>

    <div style="float:right;margin-top:25px;" class="guncellebtn yuzde30">
        <a id="SumUp_submit" href="javascript:void(0);" class="yesilbtn gonderbtn"><?php echo $LANG["save-button"]; ?></a>
    </div>

</form>

<script type="text/javascript">
    $(document).ready(function(){
        $("#SumUp_submit").click(function(){
            MioAjaxElement($(this),{
                waiting_text: waiting_text,
                progress_text: progress_text,
                result: "SumUp_handler",
            });
        });
    });

    function SumUp_handler(result){
        if(result != ''){
            var solve = getJson(result);
            if(solve !== false){
                if(solve.status == "error"){
                    if(solve.for != undefined && solve.for != ''){
                        $("#SumUp "+solve.for).focus();
                        $("#SumUp "+solve.for).attr("style","border-bottom:2px solid red; color:red;");
                        $("#SumUp "+solve.for).change(function(){
                            $(this).removeAttr("style");
                        });
                    }
                    if(solve.message != undefined && solve.message != '')
                        alert_error(solve.message,{timer:5000});
                }else if(solve.status == "successful"){
                    alert_success(solve.message,{timer:2500});
                }
            }else
                console.log(result);
        }
    }
</script>
