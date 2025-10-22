<?php
/**
 * Interface de Administração - Mercado Pago
 *
 * @name      MercadoPago Admin
 * @author    MyAAC Team
 * @copyright 2025 MyAAC
 */

if(!admin())
    exit();

require_once PLUGINS . 'mercadopago/config.php';
require_once PLUGINS . 'mercadopago/MercadoPagoAudit.php';
require_once PLUGINS . 'mercadopago/MercadoPagoLogger.php';

$audit = new MercadoPagoAudit($db, $config);
$logger = new MercadoPagoLogger(PLUGINS . 'mercadopago/logs', $config['mercadoPago']['enable_logs']);

$action = $_GET['action'] ?? 'dashboard';
$message = '';
$error = '';

// Processar ações
switch($action) {
    case 'export_transactions':
        if ($_POST) {
            $startDate = $_POST['start_date'];
            $endDate = $_POST['end_date'];
            $type = $_POST['type'] ?? null;
            
            $report = $audit->generateTransactionReport($startDate, $endDate, $type);
            $filename = 'transactions_' . $startDate . '_to_' . $endDate . '.csv';
            
            if (!empty($report['general_stats'])) {
                $csvData = [];
                foreach ($report['daily_stats'] as $day) {
                    $csvData[] = $day;
                }
                
                $filepath = $audit->exportToCSV($csvData, $filename);
                $message = "Relatório exportado com sucesso: " . basename($filepath);
            }
        }
        break;
        
    case 'cleanup_data':
        if ($_POST && isset($_POST['confirm'])) {
            $days = (int)$_POST['days_to_keep'];
            $cleaned = $audit->cleanOldData($days);
            $message = "Limpeza concluída: " . json_encode($cleaned);
        }
        break;
        
    case 'check_integrity':
        $issues = $audit->checkDataIntegrity();
        if (empty($issues)) {
            $message = "Nenhum problema de integridade encontrado.";
        } else {
            $error = "Problemas encontrados: " . count($issues) . " tipos de problemas.";
        }
        break;
}

// Obter dados para dashboard
$healthCheck = $audit->checkSystemHealth();
$suspiciousTransactions = $audit->detectSuspiciousTransactions(7);

// Estatísticas gerais dos últimos 30 dias
$monthlyReport = $audit->generateTransactionReport(
    date('Y-m-d', strtotime('-30 days')),
    date('Y-m-d')
);

// Transações recentes
$stmt = $db->prepare("
    SELECT t.*, a.name as account_name
    FROM mercadopago_transactions t
    LEFT JOIN accounts a ON t.user_id = a.id
    ORDER BY t.created_at DESC
    LIMIT 20
");
$stmt->execute();
$recentTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="box">
    <div class="box-header">
        <h3 class="box-title">Administração - Mercado Pago</h3>
    </div>
    <div class="box-body">
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        
        <!-- Navegação -->
        <ul class="nav nav-tabs" role="tablist">
            <li class="<?= $action === 'dashboard' ? 'active' : '' ?>">
                <a href="?p=mercadopago&action=dashboard">Dashboard</a>
            </li>
            <li class="<?= $action === 'transactions' ? 'active' : '' ?>">
                <a href="?p=mercadopago&action=transactions">Transações</a>
            </li>
            <li class="<?= $action === 'reports' ? 'active' : '' ?>">
                <a href="?p=mercadopago&action=reports">Relatórios</a>
            </li>
            <li class="<?= $action === 'settings' ? 'active' : '' ?>">
                <a href="?p=mercadopago&action=settings">Configurações</a>
            </li>
            <li class="<?= $action === 'logs' ? 'active' : '' ?>">
                <a href="?p=mercadopago&action=logs">Logs</a>
            </li>
        </ul>
        
        <div class="tab-content">
            
            <?php if ($action === 'dashboard'): ?>
            <!-- Dashboard -->
            <div class="tab-pane active">
                <h4>Status do Sistema</h4>
                <div class="row">
                    <div class="col-md-3">
                        <div class="info-box bg-<?= $healthCheck['status'] === 'healthy' ? 'green' : ($healthCheck['status'] === 'warning' ? 'yellow' : 'red') ?>">
                            <span class="info-box-icon"><i class="fa fa-heartbeat"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Status</span>
                                <span class="info-box-number"><?= strtoupper($healthCheck['status']) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box bg-blue">
                            <span class="info-box-icon"><i class="fa fa-money"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Receita (30 dias)</span>
                                <span class="info-box-number">R$ <?= number_format($monthlyReport['general_stats']['total_revenue'], 2, ',', '.') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box bg-green">
                            <span class="info-box-icon"><i class="fa fa-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Transações Completas</span>
                                <span class="info-box-number"><?= $monthlyReport['general_stats']['completed_transactions'] ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="info-box bg-yellow">
                            <span class="info-box-icon"><i class="fa fa-clock-o"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Pendentes</span>
                                <span class="info-box-number"><?= $monthlyReport['general_stats']['pending_transactions'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($suspiciousTransactions)): ?>
                <div class="alert alert-warning">
                    <h4><i class="icon fa fa-warning"></i> Atividades Suspeitas Detectadas!</h4>
                    <ul>
                        <?php foreach ($suspiciousTransactions as $type => $data): ?>
                            <li><?= ucfirst(str_replace('_', ' ', $type)) ?>: <?= count($data) ?> ocorrências</li>
                        <?php endforeach; ?>
                    </ul>
                    <a href="?p=mercadopago&action=reports&tab=suspicious" class="btn btn-warning">Ver Detalhes</a>
                </div>
                <?php endif; ?>
                
                <h4>Verificações do Sistema</h4>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Verificação</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($healthCheck['checks'] as $check => $status): ?>
                        <tr>
                            <td><?= ucfirst(str_replace('_', ' ', $check)) ?></td>
                            <td>
                                <span class="label label-<?= $status === 'OK' ? 'success' : ($status === 'WARNING' ? 'warning' : 'danger') ?>">
                                    <?= $status ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <?php if (!empty($healthCheck['errors']) || !empty($healthCheck['warnings'])): ?>
                <h4>Problemas Detectados</h4>
                <?php if (!empty($healthCheck['errors'])): ?>
                    <div class="alert alert-danger">
                        <strong>Erros:</strong>
                        <ul>
                            <?php foreach ($healthCheck['errors'] as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($healthCheck['warnings'])): ?>
                    <div class="alert alert-warning">
                        <strong>Avisos:</strong>
                        <ul>
                            <?php foreach ($healthCheck['warnings'] as $warning): ?>
                                <li><?= htmlspecialchars($warning) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <?php elseif ($action === 'transactions'): ?>
            <!-- Transações -->
            <div class="tab-pane active">
                <h4>Transações Recentes</h4>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuário</th>
                            <th>Tipo</th>
                            <th>Valor</th>
                            <th>Status</th>
                            <th>Data</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTransactions as $transaction): ?>
                        <tr>
                            <td><?= $transaction['id'] ?></td>
                            <td><?= htmlspecialchars($transaction['account_name'] ?? 'N/A') ?></td>
                            <td><?= ucfirst($transaction['type']) ?></td>
                            <td>R$ <?= number_format($transaction['amount'], 2, ',', '.') ?></td>
                            <td>
                                <span class="label label-<?= $transaction['status'] === 'completed' ? 'success' : ($transaction['status'] === 'pending' ? 'warning' : 'danger') ?>">
                                    <?= ucfirst($transaction['status']) ?>
                                </span>
                            </td>
                            <td><?= date('d/m/Y H:i', strtotime($transaction['created_at'])) ?></td>
                            <td>
                                <a href="?p=mercadopago&action=transaction_details&id=<?= $transaction['id'] ?>" class="btn btn-xs btn-info">
                                    <i class="fa fa-eye"></i> Ver
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php elseif ($action === 'reports'): ?>
            <!-- Relatórios -->
            <div class="tab-pane active">
                <h4>Gerar Relatórios</h4>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="box box-primary">
                            <div class="box-header">
                                <h3 class="box-title">Exportar Transações</h3>
                            </div>
                            <form method="post" action="?p=mercadopago&action=export_transactions">
                                <div class="box-body">
                                    <div class="form-group">
                                        <label>Data Inicial:</label>
                                        <input type="date" name="start_date" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Data Final:</label>
                                        <input type="date" name="end_date" class="form-control" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Tipo:</label>
                                        <select name="type" class="form-control">
                                            <option value="">Todos</option>
                                            <option value="donation">Doações</option>
                                            <option value="box_purchase">Compra de Boxes</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="box-footer">
                                    <button type="submit" class="btn btn-primary">Exportar CSV</button>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="box box-warning">
                            <div class="box-header">
                                <h3 class="box-title">Verificar Integridade</h3>
                            </div>
                            <div class="box-body">
                                <p>Verifica a integridade dos dados e detecta possíveis inconsistências.</p>
                                <a href="?p=mercadopago&action=check_integrity" class="btn btn-warning">
                                    <i class="fa fa-search"></i> Verificar Agora
                                </a>
                            </div>
                        </div>
                        
                        <div class="box box-danger">
                            <div class="box-header">
                                <h3 class="box-title">Limpeza de Dados</h3>
                            </div>
                            <form method="post" action="?p=mercadopago&action=cleanup_data">
                                <div class="box-body">
                                    <div class="form-group">
                                        <label>Manter dados dos últimos (dias):</label>
                                        <input type="number" name="days_to_keep" class="form-control" value="365" min="30">
                                    </div>
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="confirm" required>
                                            Confirmo que desejo limpar dados antigos
                                        </label>
                                    </div>
                                </div>
                                <div class="box-footer">
                                    <button type="submit" class="btn btn-danger">
                                        <i class="fa fa-trash"></i> Limpar Dados
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <?php if (isset($issues) && !empty($issues)): ?>
                <h4>Problemas de Integridade Encontrados</h4>
                <?php foreach ($issues as $type => $data): ?>
                <div class="box box-danger">
                    <div class="box-header">
                        <h3 class="box-title"><?= ucfirst(str_replace('_', ' ', $type)) ?></h3>
                    </div>
                    <div class="box-body">
                        <pre><?= htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) ?></pre>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <?php elseif ($action === 'settings'): ?>
            <!-- Configurações -->
            <div class="tab-pane active">
                <h4>Configurações do Mercado Pago</h4>
                
                <div class="row">
                    <div class="col-md-8">
                        <table class="table table-striped">
                            <tr>
                                <th>Ambiente</th>
                                <td><?= $config['mercadoPago']['environment'] ?></td>
                            </tr>
                            <tr>
                                <th>Access Token</th>
                                <td><?= substr($config['mercadoPago']['access_token'][$config['mercadoPago']['environment']], 0, 20) ?>...</td>
                            </tr>
                            <tr>
                                <th>URL do Webhook</th>
                                <td><?= $config['mercadoPago']['webhook_url'] ?></td>
                            </tr>
                            <tr>
                                <th>URL de Retorno</th>
                                <td><?= $config['mercadoPago']['return_url'] ?></td>
                            </tr>
                            <tr>
                                <th>Moeda</th>
                                <td><?= $config['mercadoPago']['currency'] ?></td>
                            </tr>
                            <tr>
                                <th>Logs Habilitados</th>
                                <td><?= $config['mercadoPago']['enable_logs'] ? 'Sim' : 'Não' ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <h4>Pacotes de Doação</h4>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>Preço</th>
                            <th>Coins</th>
                            <th>Bônus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($config['mercadoPago']['donation_packages'] as $id => $package): ?>
                        <tr>
                            <td><?= $id ?></td>
                            <td><?= $package['name'] ?></td>
                            <td>R$ <?= number_format($package['price'], 2, ',', '.') ?></td>
                            <td><?= $package['coins'] ?></td>
                            <td><?= $package['bonus_coins'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php elseif ($action === 'logs'): ?>
            <!-- Logs -->
            <div class="tab-pane active">
                <h4>Logs do Sistema</h4>
                
                <?php
                $logFiles = glob(PLUGINS . 'mercadopago/logs/*.log');
                rsort($logFiles); // Mais recentes primeiro
                ?>
                
                <div class="row">
                    <div class="col-md-3">
                        <div class="box">
                            <div class="box-header">
                                <h3 class="box-title">Arquivos de Log</h3>
                            </div>
                            <div class="box-body">
                                <?php foreach (array_slice($logFiles, 0, 10) as $logFile): ?>
                                    <?php $filename = basename($logFile); ?>
                                    <a href="?p=mercadopago&action=logs&file=<?= urlencode($filename) ?>" 
                                       class="btn btn-block btn-sm <?= ($_GET['file'] ?? '') === $filename ? 'btn-primary' : 'btn-default' ?>">
                                        <?= $filename ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-9">
                        <?php if (isset($_GET['file']) && $_GET['file']): ?>
                            <?php 
                            $selectedFile = PLUGINS . 'mercadopago/logs/' . basename($_GET['file']);
                            if (file_exists($selectedFile)):
                                $logContent = file_get_contents($selectedFile);
                                $lines = array_reverse(explode("\n", $logContent));
                                $lines = array_slice($lines, 0, 100); // Últimas 100 linhas
                            ?>
                            <div class="box">
                                <div class="box-header">
                                    <h3 class="box-title"><?= basename($_GET['file']) ?></h3>
                                </div>
                                <div class="box-body">
                                    <pre style="max-height: 500px; overflow-y: auto;"><?= htmlspecialchars(implode("\n", $lines)) ?></pre>
                                </div>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="box">
                                <div class="box-body">
                                    <p>Selecione um arquivo de log para visualizar.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php endif; ?>
            
        </div>
    </div>
</div>

<style>
.info-box {
    display: block;
    min-height: 90px;
    background: #fff;
    width: 100%;
    box-shadow: 0 1px 1px rgba(0,0,0,0.1);
    border-radius: 2px;
    margin-bottom: 15px;
}

.info-box-icon {
    border-top-left-radius: 2px;
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
    border-bottom-left-radius: 2px;
    display: block;
    float: left;
    height: 90px;
    width: 90px;
    text-align: center;
    font-size: 45px;
    line-height: 90px;
    background: rgba(0,0,0,0.2);
}

.info-box-content {
    padding: 5px 10px;
    margin-left: 90px;
}

.info-box-text {
    text-transform: uppercase;
    font-weight: bold;
    font-size: 13px;
}

.info-box-number {
    display: block;
    font-weight: bold;
    font-size: 18px;
}

.bg-blue { background-color: #3c8dbc !important; color: #fff; }
.bg-green { background-color: #00a65a !important; color: #fff; }
.bg-yellow { background-color: #f39c12 !important; color: #fff; }
.bg-red { background-color: #dd4b39 !important; color: #fff; }
</style>