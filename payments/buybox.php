<?php
/**
 * Página de compra de boxes com Mercado Pago
 *
 * @name      buybox-mercadopago
 * @author    MyAAC Team
 * @copyright 2025 MyAAC
 */

// Incluir arquivos necessários do core antes de usar BASE_URL e $db
require_once '../common.php';
require_once '../system/functions.php';
require_once '../system/init.php';
require_once '../system/login.php';

require_once '../plugins/mercadopago/config.php';
require_once '../plugins/mercadopago/MercadoPagoPayment.php';

// Verificar se o usuário está logado
if (!$logged) {
    header('Location: ' . BASE_URL . '?subtopic=accountmanagement');
    exit;
}

$account_id = $account_logged->getId();

// Inicializar sistema de pagamento
$mpPayment = new MercadoPagoPayment($config, $db);

// Processar ações
$action = $_GET['action'] ?? '';
$message = '';
$messageType = '';

switch ($action) {
    case 'create_payment':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $boxId = $_POST['box_id'] ?? '';
                
                if (empty($boxId) || !isset($config['mercadoPago']['boxes'][$boxId])) {
                    throw new Exception('Box inválido.');
                }
                
                // Verificar se o usuário tem pelo menos um personagem
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM players WHERE account_id = ?");
                $stmt->execute([$account_id]);
                $playerCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
                
                if ($playerCount == 0) {
                    throw new Exception('Você precisa ter pelo menos um personagem para comprar boxes.');
                }
                
                // Obter dados do usuário
                $stmt = $db->prepare("SELECT email, name FROM accounts WHERE id = ?");
                $stmt->execute([$account_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    throw new Exception('Usuário não encontrado.');
                }
                
                // Criar preferência de pagamento
                $preference = $mpPayment->createBoxPreference(
                    $account_id,
                    $boxId,
                    $user['email'],
                    $user['name']
                );
                
                // Redirecionar para o Mercado Pago
                $initPoint = ($config['mercadoPago']['environment'] === 'sandbox') 
                    ? $preference['sandbox_init_point'] 
                    : $preference['init_point'];
                
                header('Location: ' . $initPoint);
                exit;
                
            } catch (Exception $e) {
                $message = 'Erro ao processar pagamento: ' . $e->getMessage();
                $messageType = 'error';
            }
        }
        break;
        
    case 'success':
        $message = 'Compra realizada com sucesso! Seu box será entregue em breve.';
        $messageType = 'success';
        break;
        
    case 'failure':
        $message = 'Pagamento não foi aprovado. Tente novamente ou entre em contato conosco.';
        $messageType = 'error';
        break;
        
    case 'pending':
        $message = 'Pagamento está sendo processado. Aguarde a confirmação.';
        $messageType = 'warning';
        break;
}

// Obter histórico de compras do usuário
$transactions = $mpPayment->getUserTransactions($account_id, 5);

// Obter configurações
$boxes = $config['mercadoPago']['boxes'];
$productName = $config['mercadoPago']['productName'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comprar Boxes - <?php echo $config['server_name']; ?></title>
    <style>
        .buybox-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .message {
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
            font-weight: bold;
        }
        
        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .message.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .boxes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 30px;
            margin: 30px 0;
        }
        
        .box-card {
            border: 2px solid #ddd;
            border-radius: 15px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            background: linear-gradient(135deg, #fff 0%, #f8f9fa 100%);
            position: relative;
            overflow: hidden;
        }
        
        .box-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg, transparent, rgba(255,255,255,0.1), transparent);
            transform: rotate(45deg);
            transition: all 0.5s;
            opacity: 0;
        }
        
        .box-card:hover::before {
            opacity: 1;
            animation: shine 1s ease-in-out;
        }
        
        .box-card:hover {
            border-color: #007bff;
            box-shadow: 0 10px 30px rgba(0,123,255,0.3);
            transform: translateY(-5px);
        }
        
        @keyframes shine {
            0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
            100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
        }
        
        .box-title {
            font-size: 28px;
            font-weight: bold;
            color: #333;
            margin-bottom: 15px;
        }
        
        .box-price {
            font-size: 42px;
            font-weight: bold;
            color: #007bff;
            margin: 20px 0;
        }
        
        .box-description {
            font-size: 16px;
            color: #666;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        
        .box-items {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .box-items h4 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .item-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .item-list li {
            padding: 8px 0;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .item-list li:last-child {
            border-bottom: none;
        }
        
        .item-name {
            font-weight: 500;
            color: #495057;
        }
        
        .item-count {
            background: #007bff;
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .buy-button {
            background: linear-gradient(45deg, #007bff, #0056b3);
            color: white;
            border: none;
            padding: 18px 35px;
            border-radius: 8px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .buy-button:hover {
            background: linear-gradient(45deg, #0056b3, #004085);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,123,255,0.4);
        }
        
        .buy-button:active {
            transform: translateY(0);
        }
        
        .player-info {
            background: #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .player-info h3 {
            color: #495057;
            margin-bottom: 15px;
        }
        
        .players-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .player-card {
            background: white;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }
        
        .player-name {
            font-weight: bold;
            color: #333;
        }
        
        .player-details {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        .transactions-section {
            margin-top: 50px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .transactions-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .transactions-table th,
        .transactions-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .transactions-table th {
            background: #007bff;
            color: white;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-failed {
            background: #f8d7da;
            color: #721c24;
        }
        
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="buybox-container">
        <h1>Comprar Boxes - <?php echo htmlspecialchars($productName); ?></h1>
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (empty($players)): ?>
            <div class="warning-box">
                <strong>Atenção:</strong> Você precisa ter pelo menos um personagem para comprar boxes. 
                <a href="<?php echo BASE_URL; ?>?subtopic=createcharacter">Criar personagem</a>
            </div>
        <?php else: ?>
            <div class="player-info">
                <h3>Seus Personagens</h3>
                <p>Os itens serão entregues ao seu personagem de maior nível.</p>
                <div class="players-list">
                    <?php foreach ($players as $player): ?>
                        <div class="player-card">
                            <div class="player-name"><?php echo htmlspecialchars($player['name']); ?></div>
                            <div class="player-details">
                                Nível <?php echo $player['level']; ?> - 
                                <?php
                                $vocations = ['None', 'Sorcerer', 'Druid', 'Paladin', 'Knight'];
                                echo $vocations[$player['vocation']] ?? 'Unknown';
                                ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="boxes-grid">
            <?php foreach ($boxes as $boxId => $box): ?>
                <div class="box-card">
                    <div class="box-title"><?php echo htmlspecialchars($box['name']); ?></div>
                    <div class="box-price">R$ <?php echo number_format($box['value'], 2, ',', '.'); ?></div>
                    <div class="box-description"><?php echo htmlspecialchars($box['description']); ?></div>
                    
                    <div class="box-items">
                        <h4>🎁 Conteúdo da Box:</h4>
                        <ul class="item-list">
                            <?php foreach ($box['items'] as $item): ?>
                                <li>
                                    <span class="item-name"><?php echo htmlspecialchars(getItemName($item['id'])); ?></span>
                                    <span class="item-count"><?php echo $item['count']; ?>x</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    
                    <?php if (!empty($players)): ?>
                        <form method="POST" action="?action=create_payment">
                            <input type="hidden" name="box_id" value="<?php echo $boxId; ?>">
                            <button type="submit" class="buy-button">
                                🛒 Comprar Agora
                            </button>
                        </form>
                    <?php else: ?>
                        <button class="buy-button" disabled style="opacity: 0.5; cursor: not-allowed;">
                            Criar personagem primeiro
                        </button>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (!empty($transactions)): ?>
            <div class="transactions-section">
                <h2>Suas Últimas Compras de Boxes</h2>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Box</th>
                            <th>Valor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($transaction['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($boxes[$transaction['package_id']]['name'] ?? $transaction['package_id']); ?></td>
                                <td>R$ <?php echo number_format($transaction['amount'], 2, ',', '.'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                        <?php
                                        $statusLabels = [
                                            'completed' => 'Entregue',
                                            'pending' => 'Pendente',
                                            'processing' => 'Processando',
                                            'failed' => 'Falhou',
                                            'cancelled' => 'Cancelado'
                                        ];
                                        echo $statusLabels[$transaction['status']] ?? $transaction['status'];
                                        ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 40px; padding: 20px; background: #e9ecef; border-radius: 10px;">
            <h3>Informações Importantes</h3>
            <ul>
                <li>Os itens são entregues automaticamente ao seu personagem de maior nível.</li>
                <li>A entrega pode levar até 5 minutos após a confirmação do pagamento.</li>
                <li>Certifique-se de que seu personagem tem espaço no inventário.</li>
                <li>Em caso de problemas, entre em contato conosco.</li>
                <li>Pagamentos são processados com segurança pelo Mercado Pago.</li>
            </ul>
        </div>
    </div>
</body>
</html>