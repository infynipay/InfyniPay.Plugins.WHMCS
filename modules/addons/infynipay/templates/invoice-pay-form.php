<?php
// Variables: $formAction (string), $invoiceId (string), $gateway (string)
?>
<div id="infynipay-section" style="display:none">
    <div id="infynipay-checkout-message" class="infynipay-checkout-message" style="display:none"></div>
    <div id="infynipay-profiles-container" class="infynipay-profiles-container">
        <div class="infynipay-profiles-loading">Loading payment methods...</div>
    </div>

</div>
<form id="infynipay-form" method="post" action="<?= htmlspecialchars($formAction) ?>" style="margin-top:15px;">
    <input type="hidden" name="invoiceid" value="<?= htmlspecialchars($invoiceId) ?>">
    <input type="hidden" name="paymentmethod" value="<?= htmlspecialchars($gateway) ?>">
    <button type="submit" class="btn btn-success btn-block">Pay Now with InfyniPay</button>
</form>
