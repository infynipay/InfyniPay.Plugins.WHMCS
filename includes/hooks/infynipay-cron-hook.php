<?php
use WHMCS\Database\Capsule;
require_once __DIR__ . '/../../modules/addons/infynipay/infynipayhandler.php';

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        InfyniPayHandler::safeLogModuleCall('AfterCronJob', [], [], 'Cron executed');
        // Process unpaid invoices
        InfyniPayHandler::processUnpaidInvoices();

        // Log execution
    } catch (\Throwable $ex) {
        InfyniPayHandler::safeLogModuleCall('AfterCronJob Exception', [], [], $ex->getMessage());
    }
});