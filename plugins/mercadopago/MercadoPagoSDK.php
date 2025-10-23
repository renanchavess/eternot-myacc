<?php
/**
 * Mercado Pago SDK simplificado para MyAAC
 *
 * @name      MercadoPagoSDK
 * @author    MyAAC Team
 * @copyright 2025 MyAAC
 */

class MercadoPagoSDK
{
    private $accessToken;
    private $environment;
    private $baseUrl;
    private $integratorId; // opcional
    
    const PRODUCTION_URL = 'https://api.mercadopago.com';
    
    public function __construct($accessToken, $environment = 'sandbox', $integratorId = null)
    {
        $this->accessToken = $accessToken;
        $this->environment = $environment;
        $this->integratorId = $integratorId;
        $this->baseUrl = self::PRODUCTION_URL; // mesmo endpoint para sandbox/production
    }
    
    public function createPreference($preferenceData)
    {
        $url = $this->baseUrl . '/checkout/preferences';
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        // incluir X-Integrator-Id apenas se configurado
        if (!empty($this->integratorId)) {
            $headers[] = 'X-Integrator-Id: ' . $this->integratorId;
        }
        
        return $this->makeRequest('POST', $url, $preferenceData, $headers);
    }
    
    public function getPayment($paymentId)
    {
        $url = $this->baseUrl . '/v1/payments/' . $paymentId;
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        
        return $this->makeRequest('GET', $url, null, $headers);
    }
    
    public function getPreference($preferenceId)
    {
        $url = $this->baseUrl . '/checkout/preferences/' . $preferenceId;
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        
        return $this->makeRequest('GET', $url, null, $headers);
    }
    
    public function processNotification($notificationData)
    {
        if (!isset($notificationData['type']) || !isset($notificationData['data']['id'])) {
            throw new Exception('Dados de notificação inválidos');
        }
        
        $type = $notificationData['type'];
        $resourceId = $notificationData['data']['id'];
        
        switch ($type) {
            case 'payment':
                return $this->getPayment($resourceId);
            case 'merchant_order':
                return $this->getMerchantOrder($resourceId);
            default:
                throw new Exception('Tipo de notificação não suportado: ' . $type);
        }
    }
    
    public function getMerchantOrder($merchantOrderId)
    {
        $url = $this->baseUrl . '/merchant_orders/' . $merchantOrderId;
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        
        return $this->makeRequest('GET', $url, null, $headers);
    }
    
    private function makeRequest($method, $url, $data = null, $headers = [])
    {
        $curl = curl_init();
        
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        
        if ($data && ($method === 'POST' || $method === 'PUT')) {
            $curlOptions[CURLOPT_POSTFIELDS] = json_encode($data);
        }
        
        curl_setopt_array($curl, $curlOptions);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        
        curl_close($curl);
        
        if ($error) {
            throw new Exception('Erro cURL: ' . $error);
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMessage = 'Erro HTTP ' . $httpCode;
            if (isset($decodedResponse['message'])) {
                $errorMessage .= ': ' . $decodedResponse['message'];
            }
            throw new Exception($errorMessage);
        }
        
        return $decodedResponse;
    }
    
    public function validateWebhookSignature($payload, $signature, $secret)
    {
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expectedSignature, $signature);
    }
    
    public function createDefaultPreference($items, $payer, $backUrls, $externalReference = null)
    {
        $preference = [
            'items' => $items,
            'payer' => $payer,
            'back_urls' => $backUrls,
            'auto_return' => 'approved',
            'payment_methods' => [
                'excluded_payment_methods' => [],
                'excluded_payment_types' => [],
                'installments' => 12
            ],
            'notification_url' => $backUrls['notification'] ?? null,
            'statement_descriptor' => 'MYAAC_PAYMENT'
        ];
        
        if ($externalReference) {
            $preference['external_reference'] = $externalReference;
        }
        
        return $preference;
    }
    
    public static function formatItem($id, $title, $description, $quantity, $unitPrice, $currencyId = 'BRL')
    {
        return [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'quantity' => (int)$quantity,
            'unit_price' => (float)$unitPrice,
            'currency_id' => $currencyId
        ];
    }
    
    public static function formatPayer($email, $name = null)
    {
        $payer = ['email' => $email];
        if (!empty($name)) {
            $payer['name'] = $name;
        }
        return $payer;
    }
}
?>