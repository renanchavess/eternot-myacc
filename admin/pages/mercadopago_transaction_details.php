<?php
/**
 * Detalhes da Transação - Mercado Pago
 *
 * @name      MercadoPago Transaction Details
 * @author    MyAAC Team
 * @copyright 2025 MyAAC
 */

if(!admin())
    exit();

require_once PLUGINS . 'mercadopago/config.php';
require_once PLUGINS . 'mercadopago/MercadoPagoSDK.php';

$transactionId = (int)($_GET['id'] ?? 0);

if (!$transactionId) {
    header('Location: ?p=mercadopago&action=transactions');
    exit();
}

// Buscar detalhes da transação
$stmt = $db->prepare("
    SELECT t.*, a.name as account_name, a.email as account_email
    FROM mercadopago_transactions t
    LEFT JOIN accounts a ON t.user_id = a.id
    WHERE t.id = ?
");
$stmt->execute([$transactionId]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    header('Location: ?p=mercadopago&action=transactions');
    exit();
}

// Buscar preferência relacionada
$preference = null;
if ($transaction['external_reference']) {
    $stmt = $db->prepare("
        SELECT * FROM mercadopago_preferences 
        WHERE external_reference = ?
    ");
    $stmt->execute([$transaction['external_reference']]);
    $preference = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Buscar logs relacionados
$stmt = $db->prepare("
    SELECT * FROM mercadopago_webhook_logs 
    WHERE payment_id = ? OR external_reference = ?
    ORDER BY created_at DESC
");
$stmt->execute([$transaction['payment_id'], $transaction['external_reference']]);
$webhookLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Buscar informações do Mercado Pago se necessário
$mpPayment = null;
if ($transaction['payment_id'] && $transaction['status'] !== 'failed') {
    try {
        $sdk = new MercadoPagoSDK($config['mercadoPago']);
        $mpPayment = $sdk->getPayment($transaction['payment_id']);
    } catch (Exception $e) {
        // Ignorar erros de API
    }
}

?>

<div class="box">
    <div class="box-header">
        <h3 class="box-title">Detalhes da Transação #<?= $transaction['id'] ?></h3>
        <div class="box-tools pull-right">
            <a href="?p=mercadopago&action=transactions" class="btn btn-default btn-sm">
                <i class="fa fa-arrow-left"></i> Voltar
            </a>
        </div>
    </div>
    <div class="box-body">
        
        <div class="row">
            <!-- Informações da Transação -->
            <div class="col-md-6">
                <div class="box box-primary">
                    <div class="box-header">
                        <h3 class="box-title">Informações da Transação</h3>
                    </div>
                    <div class="box-body">
                        <table class="table table-striped">
                            <tr>
                                <th>ID:</th>
                                <td><?= $transaction['id'] ?></td>
                            </tr>
                            <tr>
                                <th>Referência Externa:</th>
                                <td><?= htmlspecialchars($transaction['external_reference']) ?></td>
                            </tr>
                            <tr>
                                <th>Payment ID:</th>
                                <td><?= htmlspecialchars($transaction['payment_id'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <th>Tipo:</th>
                                <td>
                                    <span class="label label-info"><?= ucfirst($transaction['type']) ?></span>
                                </td>
                            </tr>
                            <tr>
                                <th>Status:</th>
                                <td>
                                    <span class="label label-<?= $transaction['status'] === 'completed' ? 'success' : ($transaction['status'] === 'pending' ? 'warning' : 'danger') ?>">
                                        <?= ucfirst($transaction['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <th>Valor:</th>
                                <td><strong>R$ <?= number_format($transaction['amount'], 2, ',', '.') ?></strong></td>
                            </tr>
                            <tr>
                                <th>Package ID:</th>
                                <td><?= $transaction['package_id'] ?? 'N/A' ?></td>
                            </tr>
                            <tr>
                                <th>Criado em:</th>
                                <td><?= date('d/m/Y H:i:s', strtotime($transaction['created_at'])) ?></td>
                            </tr>
                            <tr>
                                <th>Processado em:</th>
                                <td><?= $transaction['processed_at'] ? date('d/m/Y H:i:s', strtotime($transaction['processed_at'])) : 'N/A' ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Informações do Usuário -->
            <div class="col-md-6">
                <div class="box box-info">
                    <div class="box-header">
                        <h3 class="box-title">Informações do Usuário</h3>
                    </div>
                    <div class="box-body">
                        <table class="table table-striped">
                            <tr>
                                <th>ID do Usuário:</th>
                                <td><?= $transaction['user_id'] ?></td>
                            </tr>
                            <tr>
                                <th>Nome da Conta:</th>
                                <td><?= htmlspecialchars($transaction['account_name'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <th>Email:</th>
                                <td><?= htmlspecialchars($transaction['account_email'] ?? 'N/A') ?></td>
                            </tr>
                            <tr>
                                <th>IP:</th>
                                <td><?= htmlspecialchars($transaction['ip_address'] ?? 'N/A') ?></td>
                            </tr>
                        </table>
                        
                        <?php if ($transaction['user_id']): ?>
                        <div class="btn-group">
                            <a href="?p=accounts&action=edit&id=<?= $transaction['user_id'] ?>" class="btn btn-sm btn-info">
                                <i class="fa fa-user"></i> Ver Conta
                            </a>
                            <a href="?p=mercadopago&action=user_transactions&user_id=<?= $transaction['user_id'] ?>" class="btn btn-sm btn-default">
                                <i class="fa fa-list"></i> Outras Transações
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($preference): ?>
        <!-- Informações da Preferência -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-success">
                    <div class="box-header">
                        <h3 class="box-title">Preferência de Pagamento</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-striped">
                                    <tr>
                                        <th>Preference ID:</th>
                                        <td><?= htmlspecialchars($preference['preference_id']) ?></td>
                                    </tr>
                                    <tr>
                                        <th>Status:</th>
                                        <td>
                                            <span class="label label-<?= $preference['status'] === 'active' ? 'success' : 'default' ?>">
                                                <?= ucfirst($preference['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Expira em:</th>
                                        <td><?= date('d/m/Y H:i:s', strtotime($preference['expires_at'])) ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h5>Dados da Preferência:</h5>
                                <pre style="max-height: 200px; overflow-y: auto;"><?= htmlspecialchars(json_encode(json_decode($preference['preference_data']), JSON_PRETTY_PRINT)) ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($mpPayment): ?>
        <!-- Dados do Mercado Pago -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-warning">
                    <div class="box-header">
                        <h3 class="box-title">Dados do Mercado Pago</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-striped">
                                    <tr>
                                        <th>Status MP:</th>
                                        <td><?= htmlspecialchars($mpPayment['status'] ?? 'N/A') ?></td>
                                    </tr>
                                    <tr>
                                        <th>Status Detail:</th>
                                        <td><?= htmlspecialchars($mpPayment['status_detail'] ?? 'N/A') ?></td>
                                    </tr>
                                    <tr>
                                        <th>Método de Pagamento:</th>
                                        <td><?= htmlspecialchars($mpPayment['payment_method_id'] ?? 'N/A') ?></td>
                                    </tr>
                                    <tr>
                                        <th>Tipo de Pagamento:</th>
                                        <td><?= htmlspecialchars($mpPayment['payment_type_id'] ?? 'N/A') ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h5>Resposta Completa da API:</h5>
                                <pre style="max-height: 300px; overflow-y: auto;"><?= htmlspecialchars(json_encode($mpPayment, JSON_PRETTY_PRINT)) ?></pre>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($webhookLogs)): ?>
        <!-- Logs de Webhook -->
        <div class="row">
            <div class="col-md-12">
                <div class="box box-default">
                    <div class="box-header">
                        <h3 class="box-title">Logs de Webhook</h3>
                    </div>
                    <div class="box-body">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th>Tipo</th>
                                    <th>Action</th>
                                    <th>Status</th>
                                    <th>Dados</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($webhookLogs as $log): ?>
                                <tr>
                                    <td><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
                                    <td><?= htmlspecialchars($log['type']) ?></td>
                                    <td><?= htmlspecialchars($log['action']) ?></td>
                                    <td>
                                        <span class="label label-<?= $log['status'] === 'success' ? 'success' : 'danger' ?>">
                                            <?= ucfirst($log['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-xs btn-info" data-toggle="modal" data-target="#logModal<?= $log['id'] ?>">
                                            <i class="fa fa-eye"></i> Ver
                                        </button>
                                        
                                        <!-- Modal -->
                                        <div class="modal fade" id="logModal<?= $log['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                                                        <h4 class="modal-title">Log #<?= $log['id'] ?></h4>
                                                    </div>
                                                    <div class="modal-body">
                                                        <h5>Dados Recebidos:</h5>
                                                        <pre><?= htmlspecialchars(json_encode(json_decode($log['webhook_data']), JSON_PRETTY_PRINT)) ?></pre>
                                                        
                                                        <?php if ($log['response_data']): ?>
                                                        <h5>Resposta:</h5>
                                                        <pre><?= htmlspecialchars(json_encode(json_decode($log['response_data']), JSON_PRETTY_PRINT)) ?></pre>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($log['error_message']): ?>
                                                        <h5>Erro:</h5>
                                                        <div class="alert alert-danger">
                                                            <?= htmlspecialchars($log['error_message']) ?>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-default" data-dismiss="modal">Fechar</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</div>