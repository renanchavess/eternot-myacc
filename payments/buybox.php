<?php
/**
 * Página de compra de boxes com Mercado Pago
 */

require_once '../common.php';
require_once '../system/functions.php';
require_once '../system/init.php';
require_once '../system/login.php';

require_once '../plugins/mercadopago/config.php';
require_once '../plugins/mercadopago/MercadoPagoPayment.php';

// Corrigir BASE_URL quando rodando dentro de /payments
$BASE_URL_ROOT = preg_replace('#/payments/$#', '/', BASE_URL);

if (!$logged) {
    header('Location: ' . $BASE_URL_ROOT . '?subtopic=accountmanagement');
    exit;
}

$account_id = $account_logged->getId();
$mpPayment = new MercadoPagoPayment($config, $db);

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

                $stmt = $db->prepare("SELECT email, name FROM accounts WHERE id = ?");
                $stmt->execute([$account_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user) {
                    throw new Exception('Usuário não encontrado.');
                }

                $preference = $mpPayment->createBuyBoxPreference(
                    $account_id,
                    $boxId,
                    $user['email'],
                    $user['name']
                );

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
        $message = 'Pagamento realizado com sucesso! Seus itens serão entregues em breve.';
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

$boxes = $config['mercadoPago']['boxes'];
$productName = $config['mercadoPago']['productName'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boxes Shop - <?php echo $config['server_name']; ?></title>
</head>
<body>
    <div class="container">
        <h1>Boxes Shop - <?php echo htmlspecialchars($productName); ?></h1>
        <?php if ($message): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if (!$logged): ?>
            <p>Você precisa estar logado para comprar. <a href="<?php echo $BASE_URL_ROOT; ?>?subtopic=accountmanagement">Entrar</a></p>
        <?php else: ?>
            <form method="post" action="?action=create_payment">
                <label for="box_id">Escolha o box:</label>
                <select name="box_id" id="box_id" required>
                    <?php foreach ($boxes as $id => $box): ?>
                        <option value="<?php echo htmlspecialchars($id); ?>">
                            <?php echo htmlspecialchars($box['name']); ?> - R$ <?php echo htmlspecialchars(number_format($box['value'], 2, ',', '.')); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit">Comprar</button>
            </form>
        <?php endif; ?>

        <p>Não tem personagem ainda? <a href="<?php echo $BASE_URL_ROOT; ?>?subtopic=createcharacter">Crie agora</a></p>
    </div>
</body>
</html>