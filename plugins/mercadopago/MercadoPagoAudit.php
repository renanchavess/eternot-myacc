<?php
/**
 * Sistema de auditoria para transações do Mercado Pago
 *
 * @name      MercadoPagoAudit
 * @author    MyAAC Team
 * @copyright 2025 MyAAC
 */

require_once 'MercadoPagoLogger.php';

class MercadoPagoAudit
{
    private $db;
    private $logger;
    private $config;
    
    public function __construct($db, $config)
    {
        $this->db = $db;
        $this->config = $config;
        $this->logger = new MercadoPagoLogger(
            __DIR__ . '/logs',
            $config['mercadoPago']['enable_logs']
        );
    }
    
    /**
     * Gerar relatório de transações por período
     */
    public function generateTransactionReport($startDate, $endDate, $type = null)
    {
        $whereClause = "WHERE created_at BETWEEN ? AND ?";
        $params = [$startDate, $endDate];
        
        if ($type) {
            $whereClause .= " AND type = ?";
            $params[] = $type;
        }
        
        // Estatísticas gerais
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_transactions,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_transactions,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_transactions,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue,
                AVG(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as avg_transaction_value,
                MIN(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as min_transaction_value,
                MAX(CASE WHEN status = 'completed' THEN amount ELSE NULL END) as max_transaction_value,
                COUNT(DISTINCT user_id) as unique_users
            FROM mercadopago_transactions 
            {$whereClause}
        ");
        
        $stmt->execute($params);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Transações por dia
        $stmt = $this->db->prepare("
            SELECT 
                DATE(created_at) as date,
                COUNT(*) as transactions,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as revenue,
                COUNT(DISTINCT user_id) as unique_users
            FROM mercadopago_transactions 
            {$whereClause}
            GROUP BY DATE(created_at)
            ORDER BY date
        ");
        
        $stmt->execute($params);
        $dailyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top usuários por valor
        $stmt = $this->db->prepare("
            SELECT 
                t.user_id,
                a.name as account_name,
                a.email,
                COUNT(*) as transaction_count,
                SUM(CASE WHEN t.status = 'completed' THEN t.amount ELSE 0 END) as total_spent
            FROM mercadopago_transactions t
            LEFT JOIN accounts a ON t.user_id = a.id
            {$whereClause}
            GROUP BY t.user_id
            HAVING total_spent > 0
            ORDER BY total_spent DESC
            LIMIT 10
        ");
        
        $stmt->execute($params);
        $topUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Transações por pacote/box
        $stmt = $this->db->prepare("
            SELECT 
                package_id,
                type,
                COUNT(*) as transaction_count,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue
            FROM mercadopago_transactions 
            {$whereClause}
            GROUP BY package_id, type
            ORDER BY total_revenue DESC
        ");
        
        $stmt->execute($params);
        $packageStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
                'type' => $type
            ],
            'general_stats' => $stats,
            'daily_stats' => $dailyStats,
            'top_users' => $topUsers,
            'package_stats' => $packageStats,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Detectar transações suspeitas
     */
    public function detectSuspiciousTransactions($days = 7)
    {
        $suspicious = [];
        
        // Múltiplas transações do mesmo usuário em pouco tempo
        $stmt = $this->db->prepare("
            SELECT 
                user_id,
                COUNT(*) as transaction_count,
                MIN(created_at) as first_transaction,
                MAX(created_at) as last_transaction,
                SUM(amount) as total_amount
            FROM mercadopago_transactions 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND status = 'completed'
            GROUP BY user_id
            HAVING transaction_count >= 5 
            AND TIMESTAMPDIFF(HOUR, first_transaction, last_transaction) <= 1
        ");
        
        $stmt->execute([$days]);
        $rapidTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($rapidTransactions)) {
            $suspicious['rapid_transactions'] = $rapidTransactions;
        }
        
        // Transações com valores muito altos
        $stmt = $this->db->prepare("
            SELECT *
            FROM mercadopago_transactions 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND status = 'completed'
            AND amount > 1000
            ORDER BY amount DESC
        ");
        
        $stmt->execute([$days]);
        $highValueTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($highValueTransactions)) {
            $suspicious['high_value_transactions'] = $highValueTransactions;
        }
        
        // Transações falhadas repetidamente
        $stmt = $this->db->prepare("
            SELECT 
                user_id,
                COUNT(*) as failed_count,
                MAX(created_at) as last_attempt
            FROM mercadopago_transactions 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            AND status IN ('failed', 'cancelled')
            GROUP BY user_id
            HAVING failed_count >= 3
        ");
        
        $stmt->execute([$days]);
        $repeatedFailures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($repeatedFailures)) {
            $suspicious['repeated_failures'] = $repeatedFailures;
        }
        
        // Log das detecções
        if (!empty($suspicious)) {
            $this->logger->logTransaction('suspicious_activity_detected', $suspicious, 'WARNING');
        }
        
        return $suspicious;
    }
    
    /**
     * Verificar integridade dos dados
     */
    public function checkDataIntegrity()
    {
        $issues = [];
        
        // Verificar transações sem preferência correspondente
        $stmt = $this->db->prepare("
            SELECT t.id, t.external_reference
            FROM mercadopago_transactions t
            LEFT JOIN mercadopago_preferences p ON t.external_reference = p.external_reference
            WHERE p.id IS NULL
            AND t.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        $stmt->execute();
        $orphanTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($orphanTransactions)) {
            $issues['orphan_transactions'] = $orphanTransactions;
        }
        
        // Verificar preferências expiradas não marcadas
        $stmt = $this->db->prepare("
            SELECT id, preference_id, expires_at
            FROM mercadopago_preferences
            WHERE status = 'active'
            AND expires_at < NOW()
        ");
        
        $stmt->execute();
        $expiredPreferences = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($expiredPreferences)) {
            $issues['expired_preferences'] = $expiredPreferences;
            
            // Marcar como expiradas automaticamente
            $stmt = $this->db->prepare("
                UPDATE mercadopago_preferences 
                SET status = 'expired' 
                WHERE status = 'active' AND expires_at < NOW()
            ");
            $stmt->execute();
        }
        
        // Verificar transações duplicadas
        $stmt = $this->db->prepare("
            SELECT external_reference, COUNT(*) as count
            FROM mercadopago_transactions
            GROUP BY external_reference
            HAVING count > 1
        ");
        
        $stmt->execute();
        $duplicateTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($duplicateTransactions)) {
            $issues['duplicate_transactions'] = $duplicateTransactions;
        }
        
        return $issues;
    }
    
    /**
     * Gerar relatório de reconciliação
     */
    public function generateReconciliationReport($date)
    {
        // Transações do dia
        $stmt = $this->db->prepare("
            SELECT 
                external_reference,
                payment_id,
                amount,
                status,
                created_at,
                processed_at
            FROM mercadopago_transactions
            WHERE DATE(created_at) = ?
            ORDER BY created_at
        ");
        
        $stmt->execute([$date]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Resumo do dia
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_transactions,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM mercadopago_transactions
            WHERE DATE(created_at) = ?
        ");
        
        $stmt->execute([$date]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'date' => $date,
            'summary' => $summary,
            'transactions' => $transactions,
            'generated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Exportar dados para CSV
     */
    public function exportToCSV($data, $filename)
    {
        $filepath = __DIR__ . '/exports/' . $filename;
        
        // Criar diretório se não existir
        $dir = dirname($filepath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        $file = fopen($filepath, 'w');
        
        if (!empty($data)) {
            // Cabeçalhos
            fputcsv($file, array_keys($data[0]));
            
            // Dados
            foreach ($data as $row) {
                fputcsv($file, $row);
            }
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Limpar dados antigos
     */
    public function cleanOldData($daysToKeep = 365)
    {
        $cleaned = [];
        
        // Limpar logs antigos
        $this->logger->cleanOldLogs($daysToKeep);
        $cleaned['logs'] = 'Logs antigos removidos';
        
        // Limpar webhook logs antigos
        $stmt = $this->db->prepare("
            DELETE FROM mercadopago_webhook_logs 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysToKeep]);
        $cleaned['webhook_logs'] = $stmt->rowCount() . ' registros removidos';
        
        // Limpar preferências expiradas antigas
        $stmt = $this->db->prepare("
            DELETE FROM mercadopago_preferences 
            WHERE status = 'expired' 
            AND expires_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$daysToKeep]);
        $cleaned['expired_preferences'] = $stmt->rowCount() . ' registros removidos';
        
        $this->logger->logTransaction('data_cleanup', $cleaned);
        
        return $cleaned;
    }
    
    /**
     * Verificar saúde do sistema
     */
    public function checkSystemHealth()
    {
        $health = [
            'status' => 'healthy',
            'checks' => [],
            'warnings' => [],
            'errors' => []
        ];
        
        // Verificar configuração
        $configErrors = [];
        $environment = $this->config['mercadoPago']['environment'];
        
        if (empty($this->config['mercadoPago']['access_token'][$environment])) {
            $configErrors[] = 'Access Token não configurado';
        }
        
        if (empty($this->config['mercadoPago']['webhook_url'])) {
            $configErrors[] = 'URL do webhook não configurada';
        }
        
        if (!empty($configErrors)) {
            $health['errors'] = array_merge($health['errors'], $configErrors);
            $health['status'] = 'error';
        }
        
        $health['checks']['configuration'] = empty($configErrors) ? 'OK' : 'ERROR';
        
        // Verificar conectividade com banco
        try {
            $this->db->query("SELECT 1");
            $health['checks']['database'] = 'OK';
        } catch (Exception $e) {
            $health['errors'][] = 'Erro de conexão com banco de dados';
            $health['status'] = 'error';
            $health['checks']['database'] = 'ERROR';
        }
        
        // Verificar diretório de logs
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir) || !is_writable($logDir)) {
            $health['warnings'][] = 'Diretório de logs não está acessível';
            if ($health['status'] === 'healthy') {
                $health['status'] = 'warning';
            }
            $health['checks']['logs_directory'] = 'WARNING';
        } else {
            $health['checks']['logs_directory'] = 'OK';
        }
        
        // Verificar transações recentes
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as count 
            FROM mercadopago_transactions 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $recentTransactions = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $health['checks']['recent_activity'] = $recentTransactions > 0 ? 'ACTIVE' : 'QUIET';
        
        return $health;
    }
}
?>