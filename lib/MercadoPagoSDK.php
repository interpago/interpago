<?php
// lib/MercadoPagoSDK.php
// Versión manual simplificada y SIN NAMESPACE para máxima compatibilidad.

class MercadoPago_SDK {
    private static $access_token;
    public static function setAccessToken($token) { self::$access_token = $token; }
    public static function getAccessToken() { return self::$access_token; }
}

class MercadoPago_Preference {
    public $items = [];
    public $back_urls = [];
    public $notification_url;
    public $external_reference;
    public $auto_return;
    public $init_point;

    public function save() {
        $data = [
            "items" => [],
            "back_urls" => $this->back_urls,
            "notification_url" => $this->notification_url,
            "external_reference" => $this->external_reference,
            "auto_return" => $this->auto_return
        ];
        foreach ($this->items as $item) { $data['items'][] = (array)$item; }

        $ch = curl_init('https://api.mercadopago.com/checkout/preferences');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . MercadoPago_SDK::getAccessToken()]
        ]);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code != 201) {
            throw new \Exception("Error al crear preferencia de pago: " . $response);
        }
        $responseData = json_decode($response, true);
        $this->init_point = $responseData['init_point'] ?? null;
        return true;
    }
}

class MercadoPago_Item {
    public $title;
    public $quantity;
    public $unit_price;
    public $currency_id;
}

class MercadoPago_Payment {
    public $status;
    public $external_reference;

    public static function find_by_id($payment_id) {
        if (empty($payment_id)) { return null; }

        $ch = curl_init('https://api.mercadopago.com/v1/payments/' . $payment_id);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . MercadoPago_SDK::getAccessToken()]
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] != 404) {
            $payment = new self();
            $payment->status = $data['status'];
            $payment->external_reference = $data['external_reference'] ?? null;
            return $payment;
        }
        return null;
    }
}
?>
