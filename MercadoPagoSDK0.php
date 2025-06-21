<?php
// lib/MercadoPagoSDK.php
// Versión manual simplificada de la librería de Mercado Pago para evitar problemas con Composer.

namespace MercadoPago;

class SDK {
    private static $access_token;

    public static function setAccessToken($token) {
        self::$access_token = $token;
    }

    public static function getAccessToken() {
        return self::$access_token;
    }
}

class Preference {
    public $items = [];
    public $back_urls = [];
    public $notification_url;
    public $external_reference;
    public $auto_return;
    public $init_point; // URL de pago

    public function save() {
        $data = [
            "items" => $this->items,
            "back_urls" => $this->back_urls,
            "notification_url" => $this->notification_url,
            "external_reference" => $this->external_reference,
            "auto_return" => $this->auto_return
        ];

        // Convertir los items a arrays si son objetos
        foreach ($data['items'] as $key => $item) {
            if (is_object($item)) {
                $data['items'][$key] = (array)$item;
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.mercadopago.com/checkout/preferences');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . SDK::getAccessToken()
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

class Item {
    public $title;
    public $quantity;
    public $unit_price;
    public $currency_id;
}
?>
