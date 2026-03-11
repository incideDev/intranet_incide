<?php 
require_once("core/session.php"); 
$TempoResiduo = $database->LockedTime();
if($TempoResiduo===false){ header("Location: /"); } 
?>
<!doctype html>
<html lang="it">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title> </title>
        <!-- Bootstrap core CSS -->
         
        <!-- Favicons --> 
        <meta name="theme-color" content="#7952b3">
        <!-- Custom styles for this template -->         
    </head>
    <body class="text-center blu-bg" >
        <div class="form-signin">
 
            <img class="mb-4" src="/Libreries/img/logoIconBSS.png" alt="" width="72" height="72">
            <h1 class="h5 mb-3 fw-normal text-danger"><b>Impossibile effettuare il login</b></h1>
            <div class="form-floating">
            <?php
            if(isset($_SESSION['hardlock']) && $_SESSION['hardlock']==1)
            {?>
            Il sistema &egrave; stato bloccato per <?php echo MAX_CHANCE; ?> volte ripetutamente.<br>
            Non sar&agrave; pi&ugrave; possibile tentare il login dall'ip <b><?php echo $database->ip; ?></b> durante il blocco.<br><br>
            Il sistema si sbloccher&agrave; tra <?php echo $TempoResiduo; 
            }else if(isset($_SESSION['permalock']) && $_SESSION['permalock']==1){?>
            Sono stati esauriti tutti i tentativi possibili nell'arco delle <?php echo FULL_RESET_TIME/60/60; ?> ore per l'ip <b><?php echo $database->ip; ?>.<br>
            Il sistema è stato bloccato in modo permanente<br/>
            Per informazioni conttatare l'amministratore di sistema.
            <br><br>
            <?php }
            else
            {?>
            Per ragioni di sicurezza, dopo <?php echo SITE_MAX_CHANCE; ?> tentativi di login non riusciti il sitema si blocca.<br>
            Non sar&agrave; pi&ugrave; possibile tentare il login dall'ip <b><?php echo $database->ip; ?></b> durante il blocco.<br><br>
            Il sistema si sbloccher&agrave; tra <?php echo $TempoResiduo;
            }?>
            </div>

            <p class="mt-5 mb-0 text-muted text-center lh-1" ><font size="2">Copyright ©<script>document.write(new Date().getFullYear())</script>
            </p>
 
        </div>
    </body>
</html>