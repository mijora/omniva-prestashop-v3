<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Mijora\Omniva\OmnivaException;
use Mijora\Omniva\ServicePackageHelper\ServicePackageHelper;
use Mijora\Omniva\ServicePackageHelper\PackageItem;
use Mijora\Omniva\Shipment\Shipment;
use Mijora\Omniva\Shipment\ShipmentHeader;
use Mijora\Omniva\Shipment\Package\ServicePackage;
use Mijora\Omniva\Shipment\Package\Package;
use Mijora\Omniva\Shipment\Package\Measures;
use Mijora\Omniva\Shipment\Package\Address;
use Mijora\Omniva\Shipment\Package\Contact;

class OmnivaApiInternational extends OmnivaApi
{
    /*======================== API Library Data Retrieval ========================*/

    public static function getUnits(): object
    {
        return (object) ['weight' => 'kg', 'dimensions' => 'm'];
    }

    public static function getAllServices(): ?array
    {
        return ServicePackageHelper::getServices();
    }

    public static function getCountryData(string $country_code): array
    {
        return ServicePackageHelper::getCountryOptions($country_code) ?: [];
    }

    public static function getPackageCode(string $package_key): ?string
    {
        return ServicePackageHelper::getServicePackageCode($package_key);
    }

    public static function getAvailablePackages(): array
    {
        $packages = [];
        $all_services = self::getAllServices();
        if (!is_array($all_services)) {
            return $packages;
        }

        foreach ($all_services as $country_code => $service) {
            if (!isset($service['package']) || !is_array($service['package'])) {
                continue;
            }
            foreach ($service['package'] as $package_key => $package_data) {
                if (!isset($packages[$package_key])) {
                    $packages[$package_key] = [];
                }
                $region = $service['eu'] ? 'eu' : 'non';
                $packages[$package_key][$region][] = $service['iso'] ?? $country_code;
            }
        }

        return $packages;
    }

    public static function getAllAvailableCountries(): array
    {
        $countries = [];
        foreach (self::getAvailablePackages() as $regions) {
            foreach ($regions as $region_countries) {
                foreach ($region_countries as $country) {
                    if (!in_array($country, $countries)) {
                        $countries[] = $country;
                    }
                }
            }
        }
        return $countries;
    }

    public static function getCountryPackageData(string $country, string $package_key)
    {
        if (empty($country)) {
            return false;
        }

        $country_data = self::getCountryData($country);
        if (empty($country_data['package']) || !isset($country_data['package'][$package_key])) {
            return false;
        }

        $pkg = $country_data['package'][$package_key];
        return [
            'max_weight' => $pkg['maxWeightKg'] ?? false,
            'longest_side' => $pkg['maxDimensionsM']['longestSide'] ?? false,
            'max_perimeter' => $pkg['maxDimensionsM']['total'] ?? false,
            'insurance' => $pkg['insurance'] ?? false,
        ];
    }

    public static function isPackageAvailableForItems(string $package_key, string $country_code, array $items): bool
    {
        $package_code = self::getPackageCode($package_key);
        if (!$package_code || empty(self::getCountryData($country_code)) || empty($items)) {
            return false;
        }

        $units = self::getUnits();
        $package_items = array_map(
            fn($item) => new PackageItem(
                OmnivaHelper::convertWeightUnit((float) $item['weight'], $units->weight),
                OmnivaHelper::convertDimensionsUnit((float) $item['length'], $units->dimensions),
                OmnivaHelper::convertDimensionsUnit((float) $item['width'], $units->dimensions),
                OmnivaHelper::convertDimensionsUnit((float) $item['height'], $units->dimensions)
            ),
            $items
        );

        $available_packages = ServicePackageHelper::getAvailablePackages($country_code, $package_items);
        return in_array($package_code, $available_packages);
    }

    /*======================== Module Functions ========================*/

    public static function getPackageKeyFromMethodKey(string $method_key): string
    {
        return str_replace(['omnivalt_', 'int_'], '', $method_key);
    }

    public static function isOmnivaMethodAllowed(array $keys, string $receiver_country): bool
    {
        if (parent::isOmnivaMethodAllowed($keys, $receiver_country)) {
            return true;
        }

        if (!isset($keys['method'])) {
            return false;
        }

        $package_key = self::getPackageKeyFromMethodKey($keys['method']);
        return array_key_exists($package_key, self::getAvailablePackages());
    }

    public static function isInternationalMethod(string $method_key): bool
    {
        if (!str_contains($method_key, 'int_')) {
            return false;
        }

        $package_key = self::getPackageKeyFromMethodKey($method_key);
        return array_key_exists($package_key, self::getAvailablePackages());
    }

    /*======================== Shipment Override ========================*/

    public function createShipment(int $id_order): array
    {
        try {
            $orderObjs = OmnivaData::getOrderObjects($id_order);
            $omnivaObjs = OmnivaData::getOmnivaObjects($orderObjs->order);

            $method_key = OmnivaCarrier::getCarrierMethodKey(
                (int) $orderObjs->carrier->id,
                (int) $orderObjs->carrier->id_reference
            );

            if (!self::isInternationalMethod($method_key)) {
                return parent::createShipment($id_order);
            }

            $package_key = self::getPackageKeyFromMethodKey($method_key);
            $country_iso = OmnivaData::getCountryIso($orderObjs->address);
            $receiver_data = OmnivaData::getReceiverData($orderObjs->address, $orderObjs->customer);
            $products_data = self::getOrderProductsData($id_order);
            $sender_data = $this->getSenderData($orderObjs->order);
        } catch (\Exception $e) {
            return ['msg' => OmnivaHelper::buildExceptionMessage($e, 'Failed to get Order data')];
        }

        try {
            $shipment = new Shipment();
            $shipment->setComment($this->getLabelComment($orderObjs->order));

            $shipmentHeader = new ShipmentHeader();
            $shipmentHeader->setSenderCd($this->username)->setFileId(date('Ymdhis'));
            $shipment->setShipmentHeader($shipmentHeader);

            $servicePackage = new ServicePackage(self::getPackageCode($package_key));
            $package_weight = $this->getPackageWeight($omnivaObjs->order);

            $packages = [];
            for ($i = 0; $i < $omnivaObjs->order->packs; $i++) {
                $package_id = (string) $id_order;
                if ($omnivaObjs->order->packs > 1) {
                    $package_id .= '_' . ($i + 1);
                }

                $package = new Package();
                $package
                    ->setId($package_id)
                    ->setService($this->getShipmentTypeCode('parcel'), $this->getShipmentChannelCode('courier'))
                    ->setReturnAllowed($this->shouldSendReturnCode())
                    ->setServicePackage($servicePackage)
                    ->setContentDescription($this->prepareShipmentContentDescription($products_data));

                $measures = new Measures();
                $measures->setWeight($package_weight);
                $package->setMeasures($measures);

                // Receiver
                $receiverAddress = new Address();
                $receiverAddress
                    ->setCountry($receiver_data->country)
                    ->setPostcode($receiver_data->postcode)
                    ->setDeliverypoint($receiver_data->city)
                    ->setStreet($receiver_data->street);

                $receiverContact = new Contact();
                $receiverContact
                    ->setAddress($receiverAddress)
                    ->setPersonName($receiver_data->name)
                    ->setPhone($receiver_data->phone)
                    ->setMobile($receiver_data->mobile);
                if (Configuration::get('send_delivery_email')) {
                    $receiverContact->setEmail($receiver_data->email);
                }

                $package->setReceiverContact($receiverContact);
                $package->setSenderContact($this->getSenderContact($sender_data));

                $packages[] = $package;
            }

            $shipment->setPackages($packages);
            $this->setAuth($shipment);

            return $shipment->registerShipment();
        } catch (OmnivaException $e) {
            return ['msg' => $e->getMessage()];
        }
    }

    private function prepareShipmentContentDescription(array $products_data): string
    {
        $names = array_map(function ($prod) {
            $qty = $prod['quantity'] ?? 1;
            $name = mb_substr(trim($prod['name'] ?? 'Unknown product'), 0, 31, 'UTF-8');
            return $qty . '×' . $name;
        }, $products_data);

        return implode('; ', $names);
    }
}
