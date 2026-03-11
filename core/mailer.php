<?php
/*
 * Verifica il file viene richiamato/includo dal file core principale
 */ 
if(!defined('HostDbDataConnector')) { header('HTTP/1.0 404 Not Found', true, 404); exit('Unauthorized'); }
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception; 
require_once (substr(dirname(__FILE__),0,strpos(dirname(__FILE__), '/core')).'/IntLibs/PHPMailer/src/Exception.php');
require_once (substr(dirname(__FILE__),0,strpos(dirname(__FILE__), '/core')).'/IntLibs/PHPMailer/src/PHPMailer.php');
require_once (substr(dirname(__FILE__),0,strpos(dirname(__FILE__), '/core')).'/IntLibs/PHPMailer/src/SMTP.php');

/*
$PhpMail->IsSMTP();                                      // Set mailer to use SMTP
$PhpMail->Host = 'smtp1.example.com;smtp2.example.com';  // Specify main and backup server
$PhpMail->SMTPAuth = true;                               // Enable SMTP authentication
$PhpMail->Username = 'jswan';                            // SMTP username
$PhpMail->Password = 'secret';                           // SMTP password
$PhpMail->SMTPSecure = 'tls';                            // Enable encryption, 'ssl' also accepted
*/
//Create a new PHPMailer instance
$PhpMail = new PHPMailer(); 
class Mailer {   
    function sendError($query, $error="", $filerow="" ) {
        global $PhpMail, $session;
        try{
            //Server settings
            $PhpMail->SMTPDebug = 0;               // Set mailer to use SMTP	
            $PhpMail->IsSMTP();  
            $PhpMail->Host       = $Session->config["debug_smtp"];      // sets GMAIL as the SMTP server
            $PhpMail->SMTPAuth   = true;     // enable SMTP authentication
            $PhpMail->Username   = $Session->config["debug_mail"];      //SMTP username
            $PhpMail->Password   = $Session->config["debug_smtp_psw"];  //SMTP password
            $PhpMail->SMTPSecure = $Session->config["debug_smtp_secure"];  // sets the prefix to the servier
            $PhpMail->Port       = $Session->config["debug_smtp_port"];  // set the SMTP port for the GMAIL server
            
            //Recipients
            $PhpMail->ClearAllRecipients(); 
            $PhpMail->ClearAddresses();  // each AddAddress add to list
            $PhpMail->ClearCCs();
            $PhpMail->ClearBCCs();
            $PhpMail->SetFrom($Session->config["debug_mail"], DB_NAME);		/*mail inviata da*/
            $PhpMail->AddReplyTo($Session->config["debug_mail"], ADMIN_MAIL);	/*mail di risposta*/
            $PhpMail->AddAddress($Session->config["debug_mail"]);
            
            //---------------
            $PhpMail->isHTML(true);/*mail a cui inviare la mail*/	
            $PhpMail->Subject = $Session->azienda['ragsoc']." - ERRORE"; /* soggetto della mail */
            $body = $this->htmlBody('sendError', $query, $error, $filerow);
            $PhpMail->MsgHTML($body['html']);		
            $PhpMail->AltBody = $body['txt'];
            $PhpMail->Send();
            return true;
        } catch (Exception $e) {
            echo $e->errorMessage();
            return false;
        }
    }
    
    function htmlBody($rif, $value="",$error="", $filerow="") {
        global $session;
        switch ($rif){ 			
            case "sendError":
                $body="
                <p>ERRORE DURANTE l'INTERROGAZIONE DEL DATABASE</p>
                <p><strong>DATA :</strong> ".date("d-m-Y H:i")."</p>
                <p><strong>UTENTE :</strong> ".$Session->nominativo." (".$Session->tableid.") sede->".$Session->sede."</p>"
                .($filerow!=""?"<p><strong>FILE=>RIGA :</strong> ".$filerow."</p>":"")."
                <p><strong>QUERY :</strong> ".$value."</p>
                <p><strong>ERRORE : </strong>".$error."</p>";
                $txt=$value['user'].",\n\n"
                 ."ERRORE DURANTE l'INTERROGAZIONE DEL DATABASE\n\n"
                 ."DATA: ".date("d-m-Y H:i")."\n"
                 ."UTENTE: ".$Session->nominativo." (".$Session->userid.")\n"
                 .($filerow!=""?"FILE=>RIGA : ".$filerow."\n\n":"")
                 ."QUERY: ".$value."\n\n"
                 ."ERRORE: ".$error."\n\n";
            break; 
            default:
                $body=nl2br($value);
                $txt=$value;
            break;
        }
        
        $bodyHeader='
        <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
            <html xmlns="http://www.w3.org/1999/xhtml">
            <head>
            <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
            <title>Email</title>
            <style type="text/css">
            html,body{background-color:#ffffff; margin:0px; padding:0px; }
            .testo_normale{ font-family: Arial, Helvetica, sans-serif; font-style:normal; font-weight:normal; margin:0px; padding:0px; color:#333; font-size:13px; line-height:22px}
            .testo_piccolo{font-family: Arial, Helvetica, sans-serif; margin:0; padding:0; font-weight:normal; font-style:normal;color:#333; font-size:11px; line-height:18px}
            a:link {color:#333;text-decoration: underline;}
            img{ border:0px}			
            .icon {cursor:pointer; width:14px; height:14px; padding-right:3px;}
            .rosso{background-color:rgb(255, 190, 190);}
            </style>
            </head>
            <body style="margin:0px;padding:0px">
            <table width="100%" border="0" cellpadding="0" cellspacing="0">
            <tr>
                <td style=" padding:5px; border-bottom:1px  solid #666">' . 
                    //'<a href="'.$Session->azienda['web_url'].'">'.(is_file(ROOT."/public/documenti/logo.png")?'<img src="cid:logo" width="240" height="65" />':'').'</a>' .
                '</td>
            </tr>
            <tr>
                <td valign="top" style="padding:15px" class="testo_normale">
            ';
            $bodyFooter='
                </td>
            </tr>
            <tr>
                <td style="padding:15px; border-top:1px solid #666 " class="testo_piccolo">
                <strong></strong>
                <br />Questo messaggio &egrave; rivolto unicamente al destinatario indicato e potrebbe contenere informazioni riservate o confidenziali. Se lo ha ricevuto per errore, ci scusiamo per l\'inconveniente e lo segnali cortesemente al mittente e distrugga subito l\'originale. Ogni altro utilizzo sar&agrave; considerato illegale.
            </td>
            </tr>
        </table>
        </body>
        </html>
        ';
            
        $corpo['html']=$bodyHeader.$body.$bodyFooter;
        $corpo['txt']=$txt;
        return $corpo;
        
    }
}