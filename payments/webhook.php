<?php
/**
 * Webhook do Mercado Pago para MyAAC
 * Processa notificações de pagamento do Mercado Pago
 *
 * @name      mercadopago-webhook
 * @author    MyAAC Team
 * @copyright 2025 MyAAC
 */

// Incluir arquivos necessários
require_once '../common.php';
require_once '../config.php';
require_once '../plugins/mercadopago/config.php';
require_once '../plugins/mercadopago/MercadoPagoSDK.php';
require_once '../plugins/mercadopago/MercadoPagoLogger.php';

// Configurar headers para resposta JSON
header('Content-Type: application/json');

// Inicializar logger
$logger = new MercadoPagoLogger(__DIR__ . '/../plugins/mercadopago/logs', $config['mercadoPago']['enable_logs']);

try {
    // Verificar método HTTP
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }
    
    // Obter dados do webhook
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Log da notificação recebida
    $logger->logWebhook($data, getallheaders());
    
    // Validar dados básicos
    if (!$data || !isset($data['type']) || !isset($data['data']['id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid notification data']);
        exit;
    }
    
    // Verificar IP (opcional - descomente se necessário)
    /*
    $clientIP = $_SERVER['REMOTE_ADDR'];
    $validIP = false;
    foreach ($config['mercadoPago']['ip_whitelist'] as $allowedIP) {
        if (strpos($allowedIP, '/') !== false) {
            // CIDR notation
            if (ipInRange($clientIP, $allowedIP)) {
                $validIP = true;
                break;
            }
        } else {
            // IP exato
            if ($clientIP === $allowedIP) {
                $validIP = true;
                break;
            }
        }
    }
    
    if (!$validIP) {
        $logger->logError('IP não autorizado: ' . $clientIP);
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    */
    
    // Inicializar SDK do Mercado Pago
    $accessToken = $config['mercadoPago']['access_token'][$config['mercadoPago']['environment']];
    $mp = new MercadoPagoSDK($accessToken, $config['mercadoPago']['environment']);
    
    // Processar notificação
    $notificationType = $data['type'];
    $resourceId = $data['data']['id'];
    
    $logger->logTransaction('webhook_received', [
        'type' => $notificationType,
        'resource_id' => $resourceId
    ]);
    
    switch ($notificationType) {
        case 'payment':
            processPaymentNotification($mp, $resourceId, $logger, $config);
            break;
            
        case 'merchant_order':
            processMerchantOrderNotification($mp, $resourceId, $logger, $config);
            break;
            
        default:
            $logger->logError('Tipo de notificação não suportado: ' . $notificationType);
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported notification type']);
            exit;
    }
    
    // Resposta de sucesso
    http_response_code(200);
    echo json_encode(['status' => 'ok']);
    
} catch (Exception $e) {
    $logger->logError('Erro no webhook: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}

/**
 * Processar notificação de pagamento
 */
function processPaymentNotification($mp, $paymentId, $logger, $config)
{
    try {
        // Obter dados do pagamento
        $payment = $mp->getPayment($paymentId);
        
        $logger->logTransaction('payment_notification', $payment);
        
        // Verificar se o pagamento foi aprovado
        if ($payment['status'] === 'approved') {
            processApprovedPayment($payment, $logger, $config);
        } else {
            $logger->logTransaction('payment_not_approved', [
                'payment_id' => $paymentId,
                'status' => $payment['status'],
                'status_detail' => $payment['status_detail'] ?? null
            ]);
        }
        
    } catch (Exception $e) {
        $logger->logError('Erro ao processar notificação de pagamento: ' . $e->getMessage(), [
            'payment_id' => $paymentId
        ]);
        throw $e;
    }
}

/**
 * Processar notificação de merchant order
 */
function processMerchantOrderNotification($mp, $merchantOrderId, $logger, $config)
{
    try {
        // Obter dados da merchant order
        $merchantOrder = $mp->getMerchantOrder($merchantOrderId);
        
        $logger->logTransaction('merchant_order_notification', $merchantOrder);
        
        // Verificar se todos os pagamentos foram aprovados
        $totalPaid = 0;
        foreach ($merchantOrder['payments'] as $payment) {
            if ($payment['status'] === 'approved') {
                $totalPaid += $payment['transaction_amount'];
            }
        }
        
        if ($totalPaid >= $merchantOrder['total_amount']) {
            // Processar como pagamento aprovado
            $paymentData = [
                'id' => $merchantOrderId,
                'external_reference' => $merchantOrder['external_reference'],
                'transaction_amount' => $totalPaid,
                'status' => 'approved'
            ];
            
            processApprovedPayment($paymentData, $logger, $config);
        }
        
    } catch (Exception $e) {
        $logger->logError('Erro ao processar notificação de merchant order: ' . $e->getMessage(), [
            'merchant_order_id' => $merchantOrderId
        ]);
        throw $e;
    }
}

/**
 * Processar pagamento aprovado
 */
function processApprovedPayment($payment, $logger, $config)
{
    global $db;
    
    try {
        $externalReference = $payment['external_reference'] ?? null;
        
        if (!$externalReference) {
            $logger->logError('External reference não encontrada no pagamento', $payment);
            return;
        }
        
        // Decodificar external reference (formato: tipo_usuarioId_valor_timestamp)
        $refParts = explode('_', $externalReference);
        if (count($refParts) < 4) {
            $logger->logError('Formato de external reference inválido: ' . $externalReference);
            return;
        }
        
        $type = $refParts[0]; // 'donate' ou 'buybox'
        $userId = (int)$refParts[1];
        $packageId = $refParts[2];
        $timestamp = $refParts[3];
        
        // Verificar se a transação já foi processada
        $stmt = $db->prepare("SELECT id FROM mercadopago_transactions WHERE external_reference = ? AND status = 'completed'");
        $stmt->execute([$externalReference]);
        
        if ($stmt->fetch()) {
            $logger->logTransaction('payment_already_processed', [
                'external_reference' => $externalReference,
                'payment_id' => $payment['id']
            ]);
            return;
        }
        
        // Processar baseado no tipo
        if ($type === 'donate') {
            processDonation($userId, $packageId, $payment, $logger, $config);
        } elseif ($type === 'buybox') {
            processBuyBox($userId, $packageId, $payment, $logger, $config);
        } else {
            $logger->logError('Tipo de transação desconhecido: ' . $type);
            return;
        }
        
        // Registrar transação como concluída
        $stmt = $db->prepare("\n            INSERT INTO mercadopago_transactions \n            (external_reference, payment_id, user_id, type, package_id, amount, status, processed_at) \n            VALUES (?, ?, ?, ?, ?, ?, 'completed', NOW())\n        ");
        
        $stmt->execute([
            $externalReference,
            $payment['id'],
            $userId,
            $type,
            $packageId,
            $payment['transaction_amount']
        ]);
        
        $logger->logTransaction('payment_processed_successfully', [
            'external_reference' => $externalReference,
            'user_id' => $userId,
            'type' => $type,
            'package_id' => $packageId,
            'amount' => $payment['transaction_amount']
        ]);
        
    } catch (Exception $e) {
        $logger->logError('Erro ao processar pagamento aprovado: ' . $e->getMessage(), $payment);
        throw $e;
    }
}

/**
 * Processar doação
 */
function processDonation($userId, $packageId, $payment, $logger, $config)
{
    global $db;
    
    if (!isset($config['mercadoPago']['donates'][$packageId])) {
        throw new Exception('Pacote de doação não encontrado: ' . $packageId);
    }
    
    $package = $config['mercadoPago']['donates'][$packageId];
    $coins = $package['coins'] + $package['extra'];
    
    // Aplicar dobro de moedas se configurado
    if ($config['mercadoPago']['doubleCoins'] && $payment['transaction_amount'] >= $config['mercadoPago']['doubleCoinsStart']) {
        $coins *= 2;
    }
    
    // Adicionar moedas ao usuário
    $coinType = $config['mercadoPago']['donationType'] ?? 'coins_transferable';
    $stmt = $db->prepare("UPDATE accounts SET {$coinType} = {$coinType} + ? WHERE id = ?");
    $stmt->execute([$coins, $userId]);
    
    $logger->logTransaction('donation_processed', [
        'user_id' => $userId,
        'package_id' => $packageId,
        'coins_added' => $coins,
        'coin_type' => $coinType
    ]);
}

/**
 * Processar compra de box
 */
function processBuyBox($userId, $packageId, $payment, $logger, $config)
{
    global $db;
    
    if (!isset($config['mercadoPago']['boxes'][$packageId])) {
        throw new Exception('Box não encontrado: ' . $packageId);
    }
    
    $box = $config['mercadoPago']['boxes'][$packageId];
    
    // Obter personagem principal do usuário
    $stmt = $db->prepare("SELECT name FROM players WHERE account_id = ? ORDER BY level DESC LIMIT 1");
    $stmt->execute([$userId]);
    $player = $stmt->fetch();
    
    if (!$player) {
        throw new Exception('Nenhum personagem encontrado para o usuário: ' . $userId);
    }
    
    $playerName = $player['name'];
    
    // Adicionar itens ao personagem
    foreach ($box['items'] as $item) {
        $stmt = $db->prepare("\n            INSERT INTO player_items (player_id, itemtype, count, attributes) \n            SELECT id, ?, ?, '' FROM players WHERE name = ?\n        ");
        $stmt->execute([$item['id'], $item['count'], $playerName]);
    }
    
    $logger->logTransaction('buybox_processed', [
        'user_id' => $userId,
        'player_name' => $playerName,
        'package_id' => $packageId,
        'items' => $box['items']
    ]);
}

/**
 * Verificar se IP está em range CIDR
 */
function ipInRange($ip, $range)
{
    if (strpos($range, '/') === false) {
        return $ip === $range;
    }
    
    list($subnet, $bits) = explode('/', $range);
    $ip = ip2long($ip);
    $subnet = ip2long($subnet);
    $mask = -1 << (32 - $bits);
    $subnet &= $mask;
    
    return ($ip & $mask) === $subnet;
}
?>