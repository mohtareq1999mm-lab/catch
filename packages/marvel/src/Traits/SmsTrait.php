<?php

namespace Marvel\Traits;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Marvel\Database\Models\Profile;
use Marvel\Database\Models\User;
use Marvel\Enums\EventType;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Otp\Gateways\OtpGateway;

trait SmsTrait
{

    public function sendSmsOnRefund($smsArray)
    {
        try {
            $order = $smsArray['order'];
            $smsGateway = $this->getOtpGateway();
            $userType = $this->getWhichUserWillGetSms($smsArray['smsEventName'], $smsArray['language']);
            if ($userType['customer'] == true) {
                $smsGateway->sendSms($order->customer_contact, $smsArray['customerMessage']);
            }

            if ($userType['admin'] == true) {

                $adminList = $this->adminList();


                foreach ($adminList as $admin) {
                    $adminProfile = $admin->profile;
                    if ($adminProfile) $smsGateway->sendSms($adminProfile->contact, $smsArray['adminMessage']);
                }
            }
        } catch (Exception $e) {
            // SMS failures must be observable. Never log recipients or message
            // bodies (PII) — only the failure context.
            Log::error('SMS dispatch failed on refund event', [
                'sms_event' => $smsArray['smsEventName'] ?? null,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
    }


    /**
     * @param $data
     * @return array
     */

    public function sendSmsOnOrderEvent($smsArray, $shouldSendToChildOrder = true): void
    {


        try {
            $order = $smsArray['order'];
            $smsGateway = $this->getOtpGateway();
            $userType = $this->getWhichUserWillGetSms($smsArray['smsEventName'], $smsArray['language']);

            if ($userType['customer'] && $order->parent_id == null) {
                $smsGateway->sendSms($order->customer_contact, $smsArray['customerMessage']);
                /* $customer = $order->customer;
                 if ($customer && $customer->profile && $customer->profile->contact) {
                     $smsGateway->sendSms($customer->profile->contact, $smsArray['customerMessage']);
                 }*/
            }
            if ($userType['admin']) {

                $adminList = $this->adminList();


                foreach ($adminList as $admin) {
                    $adminProfile = $admin->profile;
                    if ($adminProfile) $smsGateway->sendSms($adminProfile->contact, $smsArray['adminMessage']);
                }
            }
            if ($userType['vendor']) {
                $message = $smsArray['storeOwnerMessage'];
                if ($order->parent_id == null) {
                    if (!$shouldSendToChildOrder) {
                        return;
                    }
                    $childOrders = $order->children;


                    foreach ($childOrders as $childOrder) {
                        $storeOwner = $childOrder->shop->owner;
                        $shopOwnerProfile = Profile::where('customer_id', $storeOwner->id)->firstOrFail();

                        if ($shopOwnerProfile)
                            $smsGateway->sendSms($shopOwnerProfile->contact, str_replace(':ORDER_TRACKING_NUMBER', $childOrder->tracking_number, $message));
                    }
                } else {
                    $storeOwner = $order->shop->owner;
                    $storeOwnerProfile = $storeOwner->profile;
                    if ($storeOwnerProfile && $storeOwnerProfile->contact)
                        $smsGateway->sendSms($storeOwnerProfile->contact, str_replace(':ORDER_TRACKING_NUMBER', $order->tracking_number, $message));
                }
            }
        } catch (Exception $e) {
            // SMS failures must be observable. Never log recipients or message
            // bodies (PII) — only the failure context.
            Log::error('SMS dispatch failed on order event', [
                'sms_event' => $smsArray['smsEventName'] ?? null,
                'order_id' => $order->id ?? null,
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get OTP gateway
     *
     * @return OtpGateway
     */
    protected function getOtpGateway()
    {
        $gateway = config('auth.active_otp_gateway');
        $gateWayClass = "Marvel\\Otp\\Gateways\\" . ucfirst($gateway) . 'Gateway';
        return new OtpGateway(new $gateWayClass());
    }

    /**
     * Get which user will get sms
     *
     * @param string $smsEventName
     * @param string $language
     * @return mixed
     */

    public function getWhichUserWillGetSms(string $smsEventName, string $language): array
    {
        return $this->getWhichUserWillGetEventSmsOrEmail($smsEventName, 'smsEvent', $language);
    }

    /**
     * Get admin List
     * @return Collection
     */
    public function adminList(): Collection
    {
        return User::role(Role::SUPER_ADMIN)->get();
    }

    public function getWhichUserWillGetEmail($emailEventName, $language): array
    {
        return $this->getWhichUserWillGetEventSmsOrEmail($emailEventName, 'emailEvent', $language);
    }

    public function getWhichUserWillGetEventSmsOrEmail(string $eventName, string $eventType, string $language): array
    {
        $orderStatusChangeArray = [
            EventType::ORDER_CANCELLED, EventType::ORDER_DELIVERED, EventType::ORDER_CREATED, EventType::ORDER_STATUS_CHANGED
        ];
        if (in_array($eventName, $orderStatusChangeArray)) {
            $eventName = EventType::ORDER_STATUS_CHANGED;
        }
        if (in_array($eventName, [EventType::ORDER_PAYMENT_FAILED, EventType::ORDER_PAYMENT_SUCCESS])) {
            $eventName = EventType::ORDER_PAYMENT;
        }
        return ['customer' => true, 'admin' => true, 'vendor' => true];
    }
}
