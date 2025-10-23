<?php
// Configurações do Mercado Pago

// Fallback seguro para BASE_URL caso common.php ainda não tenha sido carregado
if (!defined('BASE_URL')) {
    // Tenta construir BASE_URL a partir do servidor atual
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    define('BASE_URL', $scheme . '://' . $host . $basePath . '/');
}

// Garantir que URLs apontem para a raiz do site (não /payments)
$ROOT_BASE_URL = preg_replace('#/payments/$#', '/', BASE_URL);

// Observação: A classe MercadoPagoPayment espera chaves específicas em $config['mercadoPago']
// - access_token: array com ['sandbox'] e ['production']
// - success_url, failure_url, pending_url, webhook_url
// - enable_logs: boolean
// - donates: pacotes com 'name', 'value', 'coins', 'extra', 'description'
// - boxes: pacotes com 'name', 'value', 'description'

$config['mercadoPago'] = [
    // Use a mesma variável para sandbox/production, se não tiver ambas
    'access_token' => [
        'sandbox' => getenv('MERCADO_PAGO_ACCESS_TOKEN') ?: '',
        'production' => getenv('MERCADO_PAGO_ACCESS_TOKEN') ?: ''
    ],
    'public_key' => getenv('MERCADO_PAGO_PUBLIC_KEY') ?: '',
    'environment' => (getenv('MERCADO_PAGO_ENV') ?: 'sandbox'), // 'sandbox' ou 'production'

    'currency' => 'BRL',
    'productName' => 'Premium Coins',

    // URLs de retorno e webhook no formato esperado pela classe
    'success_url' => $ROOT_BASE_URL . 'payments/donate.php?action=success',
    'failure_url' => $ROOT_BASE_URL . 'payments/donate.php?action=failure',
    'pending_url' => $ROOT_BASE_URL . 'payments/donate.php?action=pending',
    'webhook_url' => $ROOT_BASE_URL . 'payments/webhook.php',

    // Logs
    'enable_logs' => true,

    // Defaults de promoção (evita avisos de índices indefinidos)
    'doubleCoins' => false,
    'doubleCoinsStart' => 0,

    // Pacotes de doação
    'donates' => [
        'donate_10' => [ 'name' => 'Pacote Bronze',  'value' => 10,  'coins' => 500,  'extra' => 0,    'description' => 'Créditos para coins' ],
        'donate_20' => [ 'name' => 'Pacote Prata',   'value' => 20,  'coins' => 1100, 'extra' => 100,  'description' => 'Créditos para coins' ],
        'donate_50' => [ 'name' => 'Pacote Ouro',    'value' => 50,  'coins' => 3000, 'extra' => 500,  'description' => 'Créditos para coins' ],
        'donate_100'=> [ 'name' => 'Pacote Diamante','value' => 100, 'coins' => 7000, 'extra' => 1500, 'description' => 'Créditos para coins' ],
    ],

    // Boxes (opcional, para buybox)
    'boxes' => [
        'box_small'  => [ 'name' => 'Box Pequena',  'value' => 5,  'description' => 'Itens iniciais' ],
        'box_medium' => [ 'name' => 'Box Média',    'value' => 15, 'description' => 'Itens úteis' ],
        'box_large'  => [ 'name' => 'Box Grande',   'value' => 30, 'description' => 'Itens raros' ],
    ],
];

// Tentar carregar promoções do banco, se disponíveis
if (isset($db) && method_exists($db, 'hasTable') && $db->hasTable('mercadopago_settings')) {
    try {
        $stmt = $db->query("SELECT setting_key, setting_value FROM mercadopago_settings WHERE setting_key IN ('double_coins_enabled','double_coins_minimum')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($settings['double_coins_enabled'])) {
            $config['mercadoPago']['doubleCoins'] = (bool) (int) $settings['double_coins_enabled'];
        }
        if (isset($settings['double_coins_minimum'])) {
            $config['mercadoPago']['doubleCoinsStart'] = (float) $settings['double_coins_minimum'];
        }
    } catch (Exception $e) {
        // Falha silenciosa: mantém defaults
    }
}

// Validações básicas
if (empty($config['mercadoPago']['access_token']['sandbox']) && empty($config['mercadoPago']['access_token']['production'])) {
    // Aviso suave: deixa funcional para desenvolvimento, mas recomenda configurar .env
    error_log('[MercadoPago] Credenciais não configuradas. Defina MERCADO_PAGO_ACCESS_TOKEN no ambiente (.env).');
}

?>