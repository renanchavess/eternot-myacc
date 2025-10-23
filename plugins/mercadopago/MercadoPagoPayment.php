<?php
/**
 * Classe para gerenciar pagamentos do Mercado Pago
 *
 * @name      MercadoPagoPayment
 * @author    MyAAC Team
 * @copyright 2025 MyAAC
 */

require_once 'MercadoPagoSDK.php';
require_once 'MercadoPagoLogger.php';

class MercadoPagoPayment
{
    private $sdk;
    private $logger;
    private $config;
    private $db;
    
    public function __construct($config, $db)
    {
        $this->config = $config;
        $this->db = $db;
        
        // Ambiente
        $environment = isset($config['mercadoPago']['environment']) ? $config['mercadoPago']['environment'] : 'sandbox';
        
        // Access Token: prioridade para variável de ambiente; depois config (array por ambiente ou string)
        $accessToken = getenv('MERCADO_PAGO_ACCESS_TOKEN');
        if (empty($accessToken)) {
            if (isset($config['mercadoPago']['access_token'])) {
                if (is_array($config['mercadoPago']['access_token'])) {
                    $accessToken = $config['mercadoPago']['access_token'][$environment] ?? null;
                } else {
                    $accessToken = $config['mercadoPago']['access_token'];
                }
            }
        }
        
        if (empty($accessToken)) {
            throw new Exception('Mercado Pago access token não configurado. Defina MERCADO_PAGO_ACCESS_TOKEN no ambiente ou em config.php.');
        }
        
        // Integrator ID opcional (não enviar por padrão)
        $integratorId = isset($config['mercadoPago']['integrator_id']) ? $config['mercadoPago']['integrator_id'] : null;
        
        $this->sdk = new MercadoPagoSDK($accessToken, $environment, $integratorId);
        
        $this->logger = new MercadoPagoLogger(
            __DIR__ . '/logs',
            $config['mercadoPago']['enable_logs'] ?? false
        );
    }
    
    /**
     * Criar preferência de doação
     */
    public function createDonationPreference($userId, $packageId, $userEmail, $userName = null)
    {
        try {
            // Validar pacote
            if (!isset($this->config['mercadoPago']['donates'][$packageId])) {
                throw new Exception('Pacote de doação não encontrado: ' . $packageId);
            }
            
            $package = $this->config['mercadoPago']['donates'][$packageId];
            
            // Gerar referência externa única
            $externalReference = 'donate_' . $userId . '_' . $packageId . '_' . time();
            
            // Preparar item
            $coins = $package['coins'] + ($package['extra'] ?? 0);
            if (!empty($this->config['mercadoPago']['doubleCoins']) && $package['value'] >= ($this->config['mercadoPago']['doubleCoinsStart'] ?? 0)) {
                $coins *= 2;
            }
            
            $item = MercadoPagoSDK::formatItem(
                $packageId,
                $package['name'],
                $package['description'] . ' (' . $coins . ' moedas)',
                1,
                $package['value'],
                $this->config['mercadoPago']['currency'] ?? 'BRL'
            );
            
            // Preparar pagador
            $payer = MercadoPagoSDK::formatPayer($userEmail, $userName);
            
            // URLs de retorno
            $backUrls = [
                'success' => $this->config['mercadoPago']['success_url'],
                'failure' => $this->config['mercadoPago']['failure_url'],
                'pending' => $this->config['mercadoPago']['pending_url'],
                'notification' => $this->config['mercadoPago']['webhook_url']
            ];
            
            // Criar preferência
            $preferenceData = $this->sdk->createDefaultPreference(
                [$item],
                $payer,
                $backUrls,
                $externalReference
            );
            
            // Adicionar configurações específicas
            $preferenceData['expires'] = true;
            $preferenceData['expiration_date_from'] = date('c');
            $preferenceData['expiration_date_to'] = date('c', strtotime('+1 hour'));
            
            // Criar preferência no Mercado Pago
            $response = $this->sdk->createPreference($preferenceData);
            
            // Salvar no banco de dados
            $this->savePreference(
                $response['id'],
                $externalReference,
                $userId,
                'donate',
                $packageId,
                $package['value'],
                $this->config['mercadoPago']['currency'] ?? 'BRL'
            );
            
            $this->logger->logTransaction('donation_preference_created', [
                'user_id' => $userId,
                'package_id' => $packageId,
                'preference_id' => $response['id'],
                'external_reference' => $externalReference,
                'amount' => $package['value']
            ]);
            
            return [
                'preference_id' => $response['id'],
                'init_point' => $response['init_point'],
                'sandbox_init_point' => $response['sandbox_init_point'] ?? null,
                'external_reference' => $externalReference
            ];
            
        } catch (Exception $e) {
            $this->logger->logError('Erro ao criar preferência de doação: ' . $e->getMessage(), [
                'user_id' => $userId,
                'package_id' => $packageId
            ]);
            throw $e;
        }
    }
    
    /**
     * Criar preferência de compra de box
     */
    public function createBuyBoxPreference($userId, $boxId, $userEmail, $userName = null)
    {
        try {
            // Validar box
            if (!isset($this->config['mercadoPago']['boxes'][$boxId])) {
                throw new Exception('Box não encontrado: ' . $boxId);
            }
            
            $box = $this->config['mercadoPago']['boxes'][$boxId];
            
            // Gerar referência externa única
            $externalReference = 'buybox_' . $userId . '_' . $boxId . '_' . time();
            
            // Preparar item
            $item = MercadoPagoSDK::formatItem(
                $boxId,
                $box['name'],
                $box['description'],
                1,
                $box['value'],
                $this->config['mercadoPago']['currency'] ?? 'BRL'
            );
            
            // Preparar pagador
            $payer = MercadoPagoSDK::formatPayer($userEmail, $userName);
            
            // URLs de retorno
            $backUrls = [
                'success' => $this->config['mercadoPago']['success_url'],
                'failure' => $this->config['mercadoPago']['failure_url'],
                'pending' => $this->config['mercadoPago']['pending_url'],
                'notification' => $this->config['mercadoPago']['webhook_url'] ?? null,
            ];
            
            // Criar preferência
            $preferenceData = $this->sdk->createDefaultPreference(
                [$item],
                $payer,
                $backUrls,
                $externalReference
            );
            
            // Adicionar configurações específicas
            $preferenceData['expires'] = true;
            $preferenceData['expiration_date_from'] = date('c');
            $preferenceData['expiration_date_to'] = date('c', strtotime('+1 hour'));
            
            // Criar preferência no Mercado Pago
            $response = $this->sdk->createPreference($preferenceData);
            
            // Salvar no banco de dados
            $this->savePreference(
                $response['id'],
                $externalReference,
                $userId,
                'buybox',
                $boxId,
                $box['value'],
                $this->config['mercadoPago']['currency'] ?? 'BRL'
            );
            
            $this->logger->logTransaction('buybox_preference_created', [
                'user_id' => $userId,
                'box_id' => $boxId,
                'preference_id' => $response['id'],
                'external_reference' => $externalReference,
                'amount' => $box['value']
            ]);
            
            return [
                'preference_id' => $response['id'],
                'init_point' => $response['init_point'],
                'sandbox_init_point' => $response['sandbox_init_point'] ?? null,
                'external_reference' => $externalReference
            ];
            
        } catch (Exception $e) {
            $this->logger->logError('Erro ao criar preferência de box: ' . $e->getMessage(), [
                'user_id' => $userId,
                'box_id' => $boxId
            ]);
            throw $e;
        }
    }
    
    /**
     * Salvar preferência no banco de dados
     */
    private function savePreference($preferenceId, $externalReference, $userId, $type, $packageId, $amount, $currency)
    {
        $stmt = $this->db->prepare(
            "\n            INSERT INTO mercadopago_preferences \n            (preference_id, external_reference, user_id, type, package_id, amount, currency, expires_at) \n            VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))\n        "
        );
        
        $stmt->execute([
            $preferenceId,
            $externalReference,
            $userId,
            $type,
            $packageId,
            $amount,
            $currency
        ]);
    }
    
    /**
     * Obter preferência por ID
     */
    public function getPreference($preferenceId)
    {
        $stmt = $this->db->prepare("SELECT * FROM mercadopago_preferences WHERE preference_id = ?");
        $stmt->execute([$preferenceId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter transação por referência externa
     */
    public function getTransactionByReference($externalReference)
    {
        $stmt = $this->db->prepare("SELECT * FROM mercadopago_transactions WHERE external_reference = ?");
        $stmt->execute([$externalReference]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter transações do usuário
     */
    public function getUserTransactions($userId, $limit = 10, $offset = 0)
    {
        // Sanitizar e garantir números válidos para LIMIT/OFFSET
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);

        // MySQL não aceita parâmetros vinculados para LIMIT/OFFSET em todos os modos;
        // injetamos valores inteiros já sanitizados.
        $sql = "\n            SELECT * FROM mercadopago_transactions \n            WHERE user_id = ? \n            ORDER BY created_at DESC \n            LIMIT $limit OFFSET $offset\n        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Marcar preferência como usada
     */
    public function markPreferenceAsUsed($preferenceId)
    {
        $stmt = $this->db->prepare("UPDATE mercadopago_preferences SET status = 'used' WHERE preference_id = ?");
        $stmt->execute([$preferenceId]);
    }
    
    /**
     * Limpar preferências expiradas
     */
    public function cleanExpiredPreferences()
    {
        $stmt = $this->db->prepare(
            "\n            UPDATE mercadopago_preferences \n            SET status = 'expired' \n            WHERE status = 'active' AND expires_at < NOW()\n        "
        );
        $stmt->execute();
        
        return $stmt->rowCount();
    }
    
    /**
     * Obter estatísticas de transações
     */
    public function getTransactionStats($startDate = null, $endDate = null)
    {
        $whereClause = "WHERE 1=1";
        $params = [];
        
        if ($startDate) {
            $whereClause .= " AND created_at >= ?";
            $params[] = $startDate;
        }
        
        if ($endDate) {
            $whereClause .= " AND created_at <= ?";
            $params[] = $endDate;
        }
        
        $stmt = $this->db->prepare(
            "\n            SELECT \n                COUNT(*) as total_transactions,\n                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_transactions,\n                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue,\n                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_transaction_value,\n                COUNT(DISTINCT user_id) as unique_users\n        "
        );
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>