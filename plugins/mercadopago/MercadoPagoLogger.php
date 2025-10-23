<?php
/**
 * Logger para transações do Mercado Pago
 */
class MercadoPagoLogger
{
    private $logDir;
    private $enabled;

    public function __construct($logDir = null, $enabled = true)
    {
        $this->logDir = $logDir ?: __DIR__ . '/logs';
        $this->enabled = $enabled;
        // Garantir diretório válido e gravável
        $this->ensureLogDir();
    }

    private function ensureLogDir()
    {
        if (!$this->enabled) {
            return;
        }
        $dir = $this->logDir;
        if (!is_dir($dir)) {
            // tenta criar, incluindo pais
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                // fallback para diretório temporário
                $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mercadopago_logs';
                if (!is_dir($fallback)) {
                    @mkdir($fallback, 0755, true);
                }
                if (is_dir($fallback) && is_writable($fallback)) {
                    $this->logDir = $fallback;
                } else {
                    $this->enabled = false;
                    error_log('[MercadoPagoLogger] Não foi possível criar diretório de logs: ' . $dir . ' (fallback: ' . $fallback . ')');
                    return;
                }
            }
        }
        // tentar ajustar permissões se não gravável
        if (!is_writable($this->logDir)) {
            @chmod($this->logDir, 0755);
            if (!is_writable($this->logDir)) {
                $this->enabled = false;
                error_log('[MercadoPagoLogger] Diretório de logs não gravável: ' . $this->logDir);
            }
        }
    }

    /**
     * Log de transação
     */
    public function logTransaction($type, $data, $level = 'INFO')
    {
        if (!$this->enabled) {
            return;
        }
        $this->ensureLogDir();
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'type' => $type,
            'data' => $data,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ];
        $logFile = $this->logDir . '/transactions_' . date('Y-m-d') . '.log';
        $logLine = json_encode($logData) . PHP_EOL;
        if (false === @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX)) {
            error_log('[MercadoPagoLogger] Falha ao escrever log: ' . $logFile);
        }
    }
    
    /**
     * Log de webhook
     */
    public function logWebhook($payload, $headers = [], $level = 'INFO')
    {
        if (!$this->enabled) {
            return;
        }
        $this->ensureLogDir();
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'type' => 'webhook',
            'payload' => $payload,
            'headers' => $headers,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ];
        $logFile = $this->logDir . '/webhooks_' . date('Y-m-d') . '.log';
        $logLine = json_encode($logData) . PHP_EOL;
        if (false === @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX)) {
            error_log('[MercadoPagoLogger] Falha ao escrever log: ' . $logFile);
        }
    }
    
    /**
     * Log de erro
     */
    public function logError($message, $context = [])
    {
        if (!$this->enabled) {
            return;
        }
        $this->ensureLogDir();
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'ERROR',
            'message' => $message,
            'context' => $context,
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ];
        $logFile = $this->logDir . '/errors_' . date('Y-m-d') . '.log';
        $logLine = json_encode($logData) . PHP_EOL;
        if (false === @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX)) {
            error_log('[MercadoPagoLogger] Falha ao escrever log: ' . $logFile);
        }
    }
    
    /**
     * Log de debug
     */
    public function logDebug($message, $data = [])
    {
        if (!$this->enabled) {
            return;
        }
        $this->ensureLogDir();
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => 'DEBUG',
            'message' => $message,
            'data' => $data
        ];
        $logFile = $this->logDir . '/debug_' . date('Y-m-d') . '.log';
        $logLine = json_encode($logData) . PHP_EOL;
        if (false === @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX)) {
            error_log('[MercadoPagoLogger] Falha ao escrever log: ' . $logFile);
        }
    }
    
    /**
     * Obter logs por data
     */
    public function getLogs($date = null, $type = 'transactions')
    {
        if (!$this->enabled) {
            return [];
        }
        
        $date = $date ?: date('Y-m-d');
        $logFile = $this->logDir . '/' . $type . '_' . $date . '.log';
        
        if (!file_exists($logFile)) {
            return [];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = [];
        
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if ($decoded) {
                $logs[] = $decoded;
            }
        }
        
        return array_reverse($logs); // Mais recentes primeiro
    }
    
    /**
     * Limpar logs antigos
     */
    public function cleanOldLogs($daysToKeep = 30)
    {
        if (!$this->enabled || !is_dir($this->logDir)) {
            return;
        }
        
        $cutoffDate = strtotime('-' . $daysToKeep . ' days');
        $files = glob($this->logDir . '/*.log');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffDate) {
                unlink($file);
            }
        }
    }
    
    /**
     * Obter estatísticas dos logs
     */
    public function getLogStats($date = null)
    {
        if (!$this->enabled) {
            return [];
        }
        
        $date = $date ?: date('Y-m-d');
        $logs = $this->getLogs($date, 'transactions');
        
        $stats = [
            'total' => count($logs),
            'by_type' => [],
            'by_level' => [],
            'by_hour' => []
        ];
        
        foreach ($logs as $log) {
            // Por tipo
            $type = $log['type'] ?? 'unknown';
            $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + 1;
            
            // Por nível
            $level = $log['level'] ?? 'unknown';
            $stats['by_level'][$level] = ($stats['by_level'][$level] ?? 0) + 1;
            
            // Por hora
            $hour = date('H', strtotime($log['timestamp']));
            $stats['by_hour'][$hour] = ($stats['by_hour'][$hour] ?? 0) + 1;
        }
        
        return $stats;
    }
}
?>