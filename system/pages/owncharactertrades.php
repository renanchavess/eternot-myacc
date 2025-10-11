<?php
/**
 *
 * Char Bazaar
 *
 */
defined('MYAAC') or die('Direct access not allowed!');
$title = 'My Auctions';

if ($logged) {
    require SYSTEM . 'pages/char_bazaar/coins_balance.php';
}

// // =====================================================
// // EXECUTA O PROCESSAMENTO SEMIAUTOMÁTICO DO BAZAAR
require SYSTEM . 'pages/char_bazaar/semifinishauction.php';
// // =====================================================

// Cancelamento da auction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? null) === 'cancel') {
    $idAuctionCancel = (int)$_POST['auction_cancel_id'];

    // Consulta a auction especificada acima no banco de dados
    $getAuctionCancel = $db->query("SELECT `id`, `price`, `account_old`, `player_id`, `bid_account`, `bid_price`, `date_end` FROM `myaac_charbazaar` WHERE `id` = {$idAuctionCancel}");
    $getAuctionCancel = $getAuctionCancel->fetch();
    // Reembolso da última bid ativa
    $getAccountOldBid = $db->query("SELECT `coins_transferable` FROM `accounts` WHERE `id` = {$db->quote($getAuctionCancel['bid_account'])}");
    $getAccountOldBid = $getAccountOldBid->fetch();
    $SomaCoinsOldBid = $getAccountOldBid['coins_transferable'] + $getAuctionCancel['bid_price'];

    // Dados do componente (alguns úteis para eventual debug)
    $precoAuctionCancel = $getAuctionCancel['price'];
    $idCharAuctionCancel = $getAuctionCancel['player_id'];
    $idDonoAuctionCancel = $getAuctionCancel['account_old'];
    $dataCancelamento = date('Y-m-d H:i:s');
    $dataFimAuction = $getAuctionCancel['date_end'];
    $precoAuctionCreate = $config['bazaar_create'];

    if ($account_logged->getId() != $idDonoAuctionCancel) {
        echo <<<HTML
            <div class="SmallBox">
                <div class="MessageContainer">
                    <div class="BoxFrameHorizontal" style="background-image:url(templates/tibiacom/images/global/content/box-frame-horizontal.gif);"></div>
                    <div class="BoxFrameEdgeLeftTop" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                    <div class="BoxFrameEdgeRightTop" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                    <div class="Message">
                    <div class="BoxFrameVerticalLeft" style="background-image:url(templates/tibiacom/images/global/content/box-frame-vertical.gif);"></div>
                    <div class="BoxFrameVerticalRight" style="background-image:url(templates/tibiacom/images/global/content/box-frame-vertical.gif);"></div>
                    <table class="HintBox">
                        <tbody>
                        <tr>
                        <td>
                            <p style="color: #b32d2d; font-weight: bold; text-align: center; margin: 0;">
                            You can only cancel your own auctions.            </p>
                        </td>
                        </tr>
                        </tbody>
                    </table>
                    </div>
                    <div class="BoxFrameHorizontal" style="background-image:url(templates/tibiacom/images/global/content/box-frame-horizontal.gif);"></div>
                    <div class="BoxFrameEdgeRightBottom" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                    <div class="BoxFrameEdgeLeftBottom" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                </div>
            </div>
            <br>
        HTML;
        } else {
        echo <<<HTML
            <div class="SmallBox">
        <div class="MessageContainer">
            <div class="BoxFrameHorizontal" style="background-image:url(templates/tibiacom/images/global/content/box-frame-horizontal.gif);"></div>
            <div class="BoxFrameEdgeLeftTop" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
            <div class="BoxFrameEdgeRightTop" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
            <div class="Message">
                <div class="BoxFrameVerticalLeft" style="background-image:url(templates/tibiacom/images/global/content/box-frame-vertical.gif);"></div>
                <div class="BoxFrameVerticalRight" style="background-image:url(templates/tibiacom/images/global/content/box-frame-vertical.gif);"></div>
                <table class="HintBox">
                    <tbody>
                    <tr>
                    <td>
                        <p style="color: #2f7a2f; font-weight: bold; text-align: center; margin: 0;">
                        Auction cancelled successfully. Your character has been returned and 50 Tibia Coins have been refunded.            </p>
                    </td>
                    </tr>
                    </tbody>
                </table>
            </div>
                <div class="BoxFrameHorizontal" style="background-image:url(templates/tibiacom/images/global/content/box-frame-horizontal.gif);"></div>
                <div class="BoxFrameEdgeRightBottom" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                <div class="BoxFrameEdgeLeftBottom" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
            </div>
        </div>
        <br>
        HTML;
        
        // ######## Ações no banco de dados ########
         // muda status da auction para finalizado e a encerra com a data atual durante o cancelamento
         $db->exec("UPDATE `myaac_charbazaar` SET `status` = 2, `date_end` = '$dataCancelamento', `account_new` = $idDonoAuctionCancel WHERE `id` = {$idAuctionCancel}");
         // reembolsa o valor da última bid para a conta-alvo
         $db->exec('UPDATE `accounts` SET `coins_transferable` = ' . $db->quote($SomaCoinsOldBid) . ' WHERE `id` = ' . $db->quote($getAuctionCancel['bid_account']));
         // reembolsa o valor da criação da auction para a conta original
         $db->exec("UPDATE `accounts` SET `coins_transferable` = `coins_transferable` + $precoAuctionCreate WHERE `id` = {$idDonoAuctionCancel}");  
         // devolve player para a conta original
         $db->exec("UPDATE `players` SET `account_id` = $idDonoAuctionCancel WHERE `id` = {$idCharAuctionCancel}");
        
        }
    }

    // Alerta caso esteja deslogado
    if ($logged == 0) {
        echo <<<HTML
                <div class="SmallBox">
                    <div class="MessageContainer">
                        <div class="BoxFrameHorizontal" style="background-image:url(templates/tibiacom/images/global/content/box-frame-horizontal.gif);"></div>
                        <div class="BoxFrameEdgeLeftTop" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                        <div class="BoxFrameEdgeRightTop" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                        <div class="Message">
                        <div class="BoxFrameVerticalLeft" style="background-image:url(templates/tibiacom/images/global/content/box-frame-vertical.gif);"></div>
                        <div class="BoxFrameVerticalRight" style="background-image:url(templates/tibiacom/images/global/content/box-frame-vertical.gif);"></div>
                        <table class="HintBox">
                            <tbody>
                            <tr>
                            <td>
                                <p style="color: #b32d2d; font-weight: bold; text-align: center; margin: 0;">
                                You must be logged in to view your auctions.            </p>
                            </td>
                            </tr>
                            </tbody>
                        </table>
                        </div>
                        <div class="BoxFrameHorizontal" style="background-image:url(templates/tibiacom/images/global/content/box-frame-horizontal.gif);"></div>
                        <div class="BoxFrameEdgeRightBottom" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                        <div class="BoxFrameEdgeLeftBottom" style="background-image:url(templates/tibiacom/images/global/content/box-frame-edge.gif);"></div>
                    </div>
                </div>
                <br>
            HTML;
    }
?>




<!-- Início do html -->
<div class="SmallBox">
    <div class="MessageContainer">
        <div class="BoxFrameHorizontal"
             style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-horizontal.gif);"></div>
        <div class="BoxFrameEdgeLeftTop"
             style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></div>
        <div class="BoxFrameEdgeRightTop"
             style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></div>
        <div class="Message">
            <div class="BoxFrameVerticalLeft"
                 style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-vertical.gif);"></div>
            <div class="BoxFrameVerticalRight"
                 style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-vertical.gif);"></div>
            <table class="HintBox">
                <tbody>
                <tr>
                    <td>
                        <div style="float: right;"></div>
                        <p>Here you find <b>your character auctions</b> that:</p>
                        <ul>
                            <li>are about to start</li>
                            <li>are currently running</li>
                            <li>were recently successfully completed</li>
                            <li>were recently cancelled</li>
                        </ul>
                        <p>Check out your Tibia Coins History to see <b>all characters you have sold</b> successfully.
                        </p></td>
                </tr>
                </tbody>
            </table>
        </div>
        <div class="BoxFrameHorizontal"
             style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-horizontal.gif);"></div>
        <div class="BoxFrameEdgeRightBottom"
             style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></div>
        <div class="BoxFrameEdgeLeftBottom"
             style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></div>
    </div>
</div>
<br>
<div class="TableContainer">
    <div class="CaptionContainer">
        <div class="CaptionInnerContainer">
            <span class="CaptionEdgeLeftTop"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></span>
            <span class="CaptionEdgeRightTop"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></span>
            <span class="CaptionBorderTop"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/table-headline-border.gif);"></span>
            <span class="CaptionVerticalLeft"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-vertical.gif);"></span>
            <div class="Text">My Auctions</div>
            <span class="CaptionVerticalRight"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-vertical.gif);"></span>
            <span class="CaptionBorderBottom"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/table-headline-border.gif);"></span>
            <span class="CaptionEdgeLeftBottom"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></span>
            <span class="CaptionEdgeRightBottom"
                  style="background-image:url(<?= $template_path; ?>/images/global/content/box-frame-edge.gif);"></span>
        </div>
    </div>
    <table class="Table3" cellpadding="0" cellspacing="0">
        <tbody>
        <tr>
            <td>
                <div class="InnerTableContainer">
                    <table style="width:100%;">
                        <tbody>
                        <tr>
                            <td>
                                <div class="TableContentContainer">
                                    <table class="TableContent" width="100%" style="border:1px solid #faf0d7;">
                                        <tbody>
                                        <tr class="Odd">
                                            <td class="LabelV">Name</td>
                                            <td class="LabelV">Start</td>
                                            <td class="LabelV">End</td>
                                            <td class="LabelV">Current Bid</td>
                                            <td class="LabelV">Options</td>
                                            <td class="LabelV">Status</td>
                                        </tr>
                                        <?php
                                        $getAuctionsbyAccount = [];
                                        if ($logged) {
                                            $getAuctionsbyAccount = $db->query(
                                                "SELECT `id`, `account_old`, `account_new`, `player_id`, `price`, `date_end`, `date_start`, `bid_account`, `bid_price`, `status` 
                                                FROM `myaac_charbazaar` 
                                                WHERE `account_old` = {$account_logged->getId()}
                                                ORDER BY `date_start` DESC");
                                            $i_bg = 0;
                                            foreach ($getAuctionsbyAccount as $Auction) {
                                                $i_bg = $i_bg + 1;

                                                $getCharacterbyAccount = $db->query("SELECT `id`, `name`, `level` FROM `players` WHERE `id` = {$Auction['player_id']}");
                                                $getCharacterbyAccount = $getCharacterbyAccount->fetch();

                                                $Hoje = date('Y-m-d H:i:s');
                                                $End = date('Y-m-d H:i:s', strtotime($Auction['date_end']));
                                                if ($Auction['status'] == 1) {
                                                    $bg_DateEnd = (strtotime($End) > strtotime($Hoje)) ? '' : 'green';
                                                } elseif ($Auction['status'] == 2) {
                                                    $bg_DateEnd = (strtotime($End) > strtotime($Hoje)) ? '' : 'red';
                                                }
                                                ?>
                                                <tr bgcolor="<?= getStyle($i_bg); ?>">
                                                    <td style="color: <?= $bg_DateEnd ?>;"><?= $getCharacterbyAccount['name'] ?></td>
                                                    <td style="color: <?= $bg_DateEnd ?>;"><?= date('d M Y', strtotime($Auction['date_start'])); ?></td>
                                                    <td style="color: <?= $bg_DateEnd ?>;"><?= date('d M Y', strtotime($Auction['date_end'])); ?></td>
                                                    <td style="color: <?= $bg_DateEnd ?>;"><?= number_format($Auction['bid_price'], 0, ',', ','); ?>
                                                        <img
                                                            src="<?= $template_path; ?>/images//account/icon-tibiacointrusted.png"
                                                            class="VSCCoinImages" title="Transferable Tibia Coins"></td>
                                                    <?php if (strtotime($End) > strtotime($Hoje) && $Auction['status'] == 0) { ?>
                                                        <td>
                                                            <a href="?subtopic=currentcharactertrades&details=<?= $Auction['id'] ?>">
                                                                <div class="BigButton"
                                                                     style="background-image:url(<?= $template_path; ?>/images/global/buttons/sbutton_green.gif); display: inline-block; margin-right:4px;">
                                                                    <div onmouseover="MouseOverBigButton(this);"
                                                                         onmouseout="MouseOutBigButton(this);">
                                                                        <div class="BigButtonOver"
                                                                             style="background-image: url(<?= $template_path; ?>/images/global/buttons/sbutton_green_over.gif); visibility: hidden;"></div>
                                                                        <input name="auction_confirm"
                                                                               class="BigButtonText"
                                                                               type="button" value="Access">
                                                                    </div>
                                                                </div>
                                                            </a>
                                                            <form method="post" action="?subtopic=owncharactertrades&action=cancel"
                                                            style="display:inline-block; margin: 0;">    
                                                                <div class="BigButton"
                                                                        style="background-image:url(<?= $template_path; ?>/images/global/buttons/sbutton_red.gif); margin: 0px;display: inline-block;">
                                                                        <div onmouseover="MouseOverBigButton(this);"
                                                                            onmouseout="MouseOutBigButton(this);">
                                                                            <div class="BigButtonOver"
                                                                                style="background-image: url(<?= $template_path; ?>/images/global/buttons/sbutton_red_over.gif); visibility: hidden;"></div>
                                                                            <input type="hidden" name="auction_cancel_id" value="<?= $Auction['id'] ?>">
                                                                            <input
                                                                                class="BigButtonText"
                                                                                type="submit"
                                                                                value="Cancel"
                                                                                onclick="return confirm('Are you sure you want to cancel this auction?');">
                                                                        </div>
                                                                </div>
                                                            </form>
                                                        </td>
                                                            

                                                    <?php } else { 
                                                        ?>
                                                        <td>
                                                            <a href="?subtopic=pastcharactertrades&details=<?= $Auction['id'] ?>">
                                                                <div class="BigButton"
                                                                     style="background-image:url(<?= $template_path; ?>/images/global/buttons/sbutton_red.gif)">
                                                                    <div onmouseover="MouseOverBigButton(this);"
                                                                         onmouseout="MouseOutBigButton(this);">
                                                                        <div class="BigButtonOver"
                                                                             style="background-image: url(<?= $template_path; ?>/images/global/buttons/sbutton_red_over.gif); visibility: hidden;"></div>
                                                                        <input name="auction_confirm"
                                                                               class="BigButtonText"
                                                                               type="button" value="Finished">
                                                                    </div>
                                                                </div>
                                                            </a></td>
                                                    <?php 
                                                } ?>
                                                <?php if ($Auction['status']== 1) { 
                                                    echo <<<HTML
                                                        <td>Finished</td>
                                                        HTML;
                                                    } elseif ($Auction['status'] == 2) {
                                                    echo <<<HTML
                                                    <td>Cancelled</td>
                                                    HTML;
                                                    } else{
                                                        echo <<<HTML
                                                        <td>Open</td>
                                                        HTML;
                                                    }
                                                    ?>
                                                </tr>
                                                <?php
                                            }
                                        }
                                        ?>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </td>
        </tr>
        </tbody>
    </table>
</div>
