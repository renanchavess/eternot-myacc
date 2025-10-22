<?php
/**
 * Página de doações com Mercado Pago
 *
 * @name      donate-mercadopago
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
                $packageId = $_POST['package_id'] ?? '';
                
                if (empty($packageId) || !isset($config['mercadoPago']['donates'][$packageId])) {
                    throw new Exception('Pacote de doação inválido.');
                }
                
                // Obter dados do usuário
                $stmt = $db->prepare("SELECT email, name FROM accounts WHERE id = ?");
                $stmt->execute([$account_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user) {
                    throw new Exception('Usuário não encontrado.');
                }
                
                // Criar preferência de pagamento
                $preference = $mpPayment->createDonationPreference(
                    $account_id,
                    $packageId,
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
        $message = 'Pagamento realizado com sucesso! Suas moedas serão creditadas em breve.';
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

// Obter histórico de transações do usuário
$transactions = $mpPayment->getUserTransactions($account_id, 5);

// Obter configurações
$donates = $config['mercadoPago']['donates'];
$doubleCoins = $config['mercadoPago']['doubleCoins'];
$doubleCoinsStart = $config['mercadoPago']['doubleCoinsStart'];
$productName = $config['mercadoPago']['productName'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doações - <?php echo $config['server_name']; ?></title>
    <style>
        .donate-container {
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
        
        .packages-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }
        
        .package-card {
            border: 2px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            background: #fff;
        }
        
        .package-card:hover {
            border-color: #007bff;
            box-shadow: 0 5px 15px rgba(0,123,255,0.3);
        }
        
        .package-card.popular {
            border-color: #28a745;
            position: relative;
        }
        
        .package-card.popular::before {
            content: "MAIS POPULAR";
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .package-title {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        
        .package-price {
            font-size: 36px;
            font-weight: bold;
            color: #007bff;
            margin: 15px 0;
        }
        
        .package-coins {
            font-size: 18px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .package-bonus {
            color: #28a745;
            font-weight: bold;
            margin-bottom: 20px;
        }
        
        .buy-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            width: 100%;
            transition: background 0.3s ease;
        }
        
        .buy-button:hover {
            background: #0056b3;
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
        
        .promo-banner {
            background: linear-gradient(45deg, #ff6b6b, #feca57);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            margin-bottom: 30px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="donate-container">
        <h1>Doações - <?php echo htmlspecialchars($productName); ?></h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($doubleCoins): ?>
            <div class="promo-banner">
                🎉 PROMOÇÃO ATIVA! 🎉<br>
                Dobro de moedas em compras acima de R$ <?php echo number_format($doubleCoinsStart, 2, ',', '.'); ?>!
            </div>
        <?php endif; ?>
        
        <div class="packages-grid">
            <?php foreach ($donates as $packageId => $package): ?>
                <?php
                $totalCoins = $package['coins'] + $package['extra'];
                if ($doubleCoins && $package['value'] >= $doubleCoinsStart) {
                    $totalCoins *= 2;
                }
                $isPopular = $package['value'] == 50; // Marcar pacote de R$ 50 como popular
                ?>
                <div class="package-card <?php echo $isPopular ? 'popular' : ''; ?>">
                    <div class="package-title"><?php echo htmlspecialchars($package['name']); ?></div>
                    <div class="package-price">R$ <?php echo number_format($package['value'], 2, ',', '.'); ?></div>
                    <div class="package-coins"><?php echo number_format($totalCoins); ?> <?php echo htmlspecialchars($productName); ?></div>
                    
                    <?php if ($package['extra'] > 0): ?>
                        <div class="package-bonus">
                            +<?php echo $package['extra']; ?> moedas de bônus!
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($doubleCoins && $package['value'] >= $doubleCoinsStart): ?>
                        <div class="package-bonus">
                            🔥 DOBRO DE MOEDAS! 🔥
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" action="?action=create_payment">
                        <input type="hidden" name="package_id" value="<?php echo $packageId; ?>">
                        <button type="submit" class="buy-button">
                            Comprar Agora
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (!empty($transactions)): ?>
            <div class="transactions-section">
                <h2>Suas Últimas Transações</h2>
                <table class="transactions-table">
                    <thead>
                        <tr>
                            <th>Data</th>
                            <th>Tipo</th>
                            <th>Valor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($transaction['created_at'])); ?></td>
                                <td><?php echo ucfirst($transaction['type']); ?></td>
                                <td>R$ <?php echo number_format($transaction['amount'], 2, ',', '.'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                        <?php
                                        $statusLabels = [
                                            'completed' => 'Concluído',
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
                <li>As moedas são creditadas automaticamente após a confirmação do pagamento.</li>
                <li>O processamento pode levar até 5 minutos.</li>
                <li>Em caso de problemas, entre em contato conosco.</li>
                <li>Pagamentos são processados com segurança pelo Mercado Pago.</li>
            </ul>
        </div>
    </div>
</body>
</html>