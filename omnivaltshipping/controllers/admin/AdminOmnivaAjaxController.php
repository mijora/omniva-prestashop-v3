<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminOmnivaAjaxController extends ModuleAdminController
{
    private array $_carrier_url_cache = [];

    public function __construct()
    {
        parent::__construct();

        if (!Context::getContext()->employee->isLoggedBack()) {
            exit('Restricted.');
        }

        $this->parseActions();
    }

    private function parseActions(): void
    {
        $action = Tools::getValue('action');

        switch ($action) {
            case 'saveOrderInfo':
                $this->saveOrderInfo();
                break;
            case 'generateLabels':
                $this->generateLabels();
                break;
            case 'printLabels':
                $this->printOrderLabels();
                break;
            case 'bulkPrintLabels':
                $this->printBulkLabels();
                break;
            case 'printManifest':
                $this->printManifest();
                break;
            case 'downloadManifest':
                $this->downloadManifest();
                break;
            case 'downloadManifestByNumber':
                $this->downloadManifestByNumber();
                break;
            case 'printAllManifests':
                $this->printBulkManifest();
                break;
        }
    }

    protected function saveOrderInfo(): void
    {
        if (!empty($this->module->warning)) {
            $this->ajaxDie(json_encode(['error' => 'Module not configured.']));
        }

        $id_order = (int) Tools::getValue('order_id');
        $omnivaOrder = new OmnivaOrder($id_order);

        $packs = Tools::getValue('packs', 1);
        $weight = Tools::getValue('weight', 0);
        $isCod = Tools::getValue('is_cod', 0);
        $codAmount = Tools::getValue('cod_amount', 0);
        $carrier = Tools::getValue('carrier', 0);

        // Validation
        if (!is_numeric($packs) || (int) $packs < 1) {
            $this->ajaxDie(json_encode(['error' => 'Bad packs number.']));
        }
        if (!Validate::isFloat($weight) || (float) $weight <= 0) {
            $this->ajaxDie(json_encode(['error' => 'Bad weight.']));
        }
        if (!in_array($isCod, ['0', '1'])) {
            $this->ajaxDie(json_encode(['error' => 'Bad COD value.']));
        }
        if ($isCod === '1' && (!Validate::isFloat($codAmount) || $codAmount === '')) {
            $this->ajaxDie(json_encode(['error' => 'Bad COD amount.']));
        }

        $order = new Order($id_order);
        if (!Validate::isLoadedObject($order)) {
            $this->ajaxDie(json_encode(['error' => 'Could not find order.']));
        }

        // Save terminal selection
        if (Tools::isSubmit('parcel_terminal') && ($id_terminal = Tools::getValue('parcel_terminal'))) {
            $this->saveTerminalSelection($order, pSQL($id_terminal));
        }

        // Save order info
        $add_order = !Validate::isLoadedObject($omnivaOrder);
        if ($add_order) {
            $omnivaOrder->force_id = true;
            $omnivaOrder->id = $order->id;
        }

        $omnivaOrder->packs = (int) $packs;
        $omnivaOrder->weight = (float) $weight;
        $omnivaOrder->cod = (int) $isCod;
        $omnivaOrder->cod_amount = (float) $codAmount;

        if ($add_order) {
            $result = $omnivaOrder->add();
            $history = new OmnivaOrderHistory();
            $history->id_order = $order->id;
            $history->manifest = 0;
            $history->add();
        } else {
            $result = $omnivaOrder->save();
        }

        // Update carrier if changed
        if ($result && $carrier) {
            $this->updateOrderCarrier($order, (int) $carrier);
        }

        $this->ajaxDie(json_encode(
            $result
                ? $this->module->translate('Order info successfully saved.', [], 'Modules.Omnivaltshipping.Admin')
                : ['error' => $this->module->translate('Order info could not be saved.', [], 'Modules.Omnivaltshipping.Admin')]
        ));
    }

    protected function generateLabels(?int $id_order = null, bool $return_on_die = false): ?array
    {
        if (!$id_order) {
            $id_order = (int) Tools::getValue('id_order');
            if (!$id_order) {
                $error = $this->module->translate('No order ID provided.', [], 'Modules.Omnivaltshipping.Admin');
                return $return_on_die ? ['error' => $error] : $this->ajaxDieError($error);
            }
        }

        $order = new Order($id_order);
        $omnivaOrder = new OmnivaOrder($id_order);
        if (!Validate::isLoadedObject($omnivaOrder)) {
            $error = $this->module->translate('Order info not saved. Please save before generating labels', [], 'Modules.Omnivaltshipping.Admin');
            return $return_on_die ? ['error' => $error] : $this->ajaxDieError($error);
        }

        $status = $this->module->api->createShipment($id_order);

        if (isset($status['barcodes']) && !empty($status['barcodes'])) {
            $order->setWsShippingNumber($status['barcodes'][0]);
            $order->update();
            $omnivaOrder->error = '';
            $omnivaOrder->tracking_numbers = json_encode($status['barcodes']);

            if ($omnivaOrder->update()) {
                $this->saveOrderHistory($omnivaOrder, $order, $status['barcodes']);
            }

            $this->changeOrderStatusAfterLabel($order, $id_order, $status['barcodes'][0]);

            if (Tools::getValue('redirect')) {
                Tools::redirectAdmin(Context::getContext()->link->getAdminLink(OmnivaltShipping::CONTROLLER_OMNIVA_ORDERS));
            }

            $msg = $this->module->translate('Label successfully generated', [], 'Modules.Omnivaltshipping.Admin');
            return $return_on_die ? ['success' => $msg] : $this->ajaxDie(json_encode(['success' => $msg]));
        }

        $error_msg = $status['msg'] ?? 'Unknown error';
        $omnivaOrder->error = $error_msg;
        $omnivaOrder->update();
        $this->module->changeOrderStatus($id_order, $this->module->getErrorOrderState());

        return $return_on_die ? ['error' => $error_msg] : $this->ajaxDieError($error_msg);
    }

    protected function printOrderLabels(): void
    {
        $tracking_numbers = '';

        if (Tools::getValue('history')) {
            $history = new OmnivaOrderHistory((int) Tools::getValue('history'));
            if (!Validate::isLoadedObject($history)) {
                $this->ajaxDie(json_encode(['error' => 'Could not load order info.']));
            }
            $tracking_numbers = $history->tracking_numbers;
        } elseif (Tools::getValue('id_order')) {
            $omnivaOrder = new OmnivaOrder((int) Tools::getValue('id_order'));
            if (!Validate::isLoadedObject($omnivaOrder)) {
                $this->ajaxDie(json_encode(['error' => 'Could not load order info.']));
            }
            $tracking_numbers = $omnivaOrder->tracking_numbers;
        }

        if (!$tracking_numbers) {
            $this->ajaxDie(json_encode(['error' => 'No tracking numbers were provided.']));
        }

        $this->module->api->getOrderLabels(json_decode($tracking_numbers, true));
    }

    protected function printBulkLabels(): void
    {
        $order_ids_raw = Tools::getValue('order_ids', '');
        $history_ids_raw = Tools::getValue('history_ids', '');

        $order_ids = $order_ids_raw ? explode(',', $order_ids_raw) : [];
        $history_ids = $history_ids_raw ? array_map('intval', explode(',', $history_ids_raw)) : [];

        if (empty($order_ids) && empty($history_ids)) {
            $this->ajaxDie(json_encode(['error' => $this->module->translate("No order ID's provided.", [], 'Modules.Omnivaltshipping.Admin')]));
        }

        $registered_ids = [];
        foreach ($order_ids as $id_order) {
            $omnivaOrder = new OmnivaOrder((int) $id_order);
            if (!Validate::isLoadedObject($omnivaOrder)) {
                continue;
            }
            if ($omnivaOrder->tracking_numbers) {
                $registered_ids[] = (int) $id_order;
                continue;
            }
            $result = $this->generateLabels((int) $id_order, true);
            if (isset($result['error'])) {
                continue;
            }
            $registered_ids[] = (int) $id_order;
        }

        if (empty($registered_ids) && empty($history_ids)) {
            $this->ajaxDie(json_encode(['error' => $this->module->translate('Could not get label information for some orders.', [], 'Modules.Omnivaltshipping.Admin')]));
        }

        $this->module->api->getBulkLabels($registered_ids, $history_ids);
    }

    protected function printManifest(): void
    {
        $order_ids = Tools::getValue('order_ids');
        $history_ids = Tools::getValue('history_ids');
        $ids = $order_ids ? explode(',', $order_ids) : false;
        $hist_ids = $history_ids ? array_map('intval', explode(',', $history_ids)) : [];
        $this->module->api->getManifest($ids ?: false, $hist_ids);
    }

    protected function downloadManifest(): void
    {
        $order_ids = Tools::getValue('order_ids');
        $history_ids = Tools::getValue('history_ids');
        $ids = $order_ids ? explode(',', $order_ids) : [];
        $hist_ids = $history_ids ? array_map('intval', explode(',', $history_ids)) : [];

        if (empty($ids) && empty($hist_ids)) {
            $this->ajaxDie(json_encode(['error' => 'No orders selected.']));
        }

        $this->module->api->downloadManifestOnly($ids, $hist_ids);
    }

    protected function printBulkManifest(): void
    {
        $this->module->api->getManifest(false);
    }

    protected function downloadManifestByNumber(): void
    {
        $manifestNum = (int) Tools::getValue('manifest_num');
        if ($manifestNum <= 0) {
            $this->ajaxDie(json_encode(['error' => 'Invalid manifest number.']));
        }

        $this->module->api->downloadManifestByNumber($manifestNum);
    }

    /*======================== Private Helpers ========================*/

    private function saveTerminalSelection(Order $order, string $id_terminal): void
    {
        $omnivaCartTerminal = new OmnivaCartTerminal($order->id_cart);
        $add_terminal = !Validate::isLoadedObject($omnivaCartTerminal);

        if ($add_terminal) {
            $omnivaCartTerminal->force_id = true;
            $omnivaCartTerminal->id = $order->id_cart;
        }

        $omnivaCartTerminal->id_terminal = $id_terminal;
        $add_terminal ? $omnivaCartTerminal->add() : $omnivaCartTerminal->save();

        OmnivaHelper::printToLog('Cart #' . $order->id_cart . ' Order #' . $order->id . '. Changed terminal to ' . $id_terminal, 'admin');
    }

    private function updateOrderCarrier(Order $order, int $carrier_id): void
    {
        $selected_carrier = new Carrier($carrier_id);
        $order_carrier = new OrderCarrier((int) $order->getIdOrderCarrier());

        if ((int) $selected_carrier->id !== (int) $order_carrier->id_carrier) {
            $order->id_carrier = (int) $selected_carrier->id;
            $order_carrier->id_carrier = (int) $selected_carrier->id;
            $order_carrier->update();
            $this->module->refreshShippingCost($order);
            $order->update();
        }
    }

    private function saveOrderHistory(OmnivaOrder $omnivaOrder, Order $order, array $barcodes): void
    {
        $carrier = OmnivaCarrier::getCarrierById((int) $order->id_carrier);
        $method_key = $carrier
            ? OmnivaCarrier::getCarrierMethodKey((int) $carrier->id, (int) $carrier->id_reference)
            : false;

        // Delete the empty placeholder record (created at order time) so that a fresh
        // record is always created — this ensures date_add reflects the actual label generation time.
        $omnivaOrderHistory = OmnivaOrderHistory::getLatestOrderHistory((int) $order->id);
        if ($omnivaOrderHistory && !$omnivaOrderHistory->tracking_numbers) {
            $omnivaOrderHistory->delete();
        }

        $omnivaOrderHistory = new OmnivaOrderHistory();
        $omnivaOrderHistory->id_order = $omnivaOrder->id;
        $omnivaOrderHistory->tracking_numbers = json_encode($barcodes);

        if ($method_key && OmnivaApiInternational::isInternationalMethod($method_key)) {
            $international_service = OmnivaApiInternational::getPackageCode(
                OmnivaApiInternational::getPackageKeyFromMethodKey($method_key)
            );
            $omnivaOrderHistory->service_code = '[INT] ' . $international_service;
        } else {
            $shipment_codes = $this->module->api->getShipmentCodes((int) $order->id_carrier);
            $omnivaOrderHistory->service_code = '[' . $shipment_codes->main_service . '] ' . $shipment_codes->delivery_service;
        }

        $omnivaOrderHistory->manifest = 0;
        $omnivaOrderHistory->save();
    }

    private function changeOrderStatusAfterLabel(Order $order, int $id_order, string $barcode): void
    {
        try {
            if (!isset($this->_carrier_url_cache[$order->id_carrier])) {
                $carrier = new Carrier($order->id_carrier);
                $this->_carrier_url_cache[$order->id_carrier] = $carrier->url;
            }
            $template_vars = ['{followup}' => str_replace('@', $barcode, $this->_carrier_url_cache[$order->id_carrier])];
            $this->module->changeOrderStatus($id_order, $this->module->getCustomOrderState(), $template_vars);
        } catch (\Throwable $th) {
            // Silence errors when changing order status
        }
    }

    private function ajaxDieError(string $error): never
    {
        $this->ajaxDie(json_encode(['error' => $error]));
        exit; // @phpstan-ignore-line
    }

    /**
     * Compatibility wrapper for ajaxDie which was removed in newer PrestaShop versions.
     */
    protected function ajaxDie($value = '', $controller = null, $method = null)
    {
        if (method_exists(get_parent_class($this), 'ajaxDie')) {
            parent::ajaxDie($value, $controller, $method);
        } else {
            header('Content-Type: application/json');
            die($value);
        }
    }
}
