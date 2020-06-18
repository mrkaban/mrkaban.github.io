<?php // ! GARDER ENCODING UTF8 WITHOUT BOM ! (do NOT save in Visual Studio)

// Default language (French? later change to English?)
// Attention: 
// 1) ESCAPE ALL APOSTROPHES: inside strings, change all ' into \'
// 2) While changing or adding lines, make sure to keep <'.> (to do PHP string concatenation) at the end of the lines except for the last one, and <'> at the beginning!

$WEInfoPage = 
'{"ExecutionOnlyOnline":"Unable to perform this action from openElement.\r\nYou must publish your page online.",'.
'"NoResponse":"An error occurred while sending email! Please try again later or contact the server administrator.",'.
'"NoPHP":"Unable to perform this action.\r\nPHP is not installed on your web server. \r\nPlease contact your host to activate it.\r\n\r\nNote: If uploading outside of openElement, enter your PHP version in Preferences,  \'Advanced\' tab. Then re-save this page before uploading it.",'.
'"NoMailFunction":"The form was not sent! The PHP function \'mail()\' is not active on this web server. Please contact your host to activate it.",'.
'"NoMailSend":"The form was not sent\r\n Contact the server administrator: Function PHP Mail() failed\r\n",'.
'"FormNotConfigure":"Attention, the element &quot;SendMail&quot; has not been configured in openElement.\r\nYou must configure it to enable email form submission.",'.
'"NoUploadRight":"Unable to move imported file.\r\nThe upload folder cannot be created or does not have write permissions.\r\nYou can configure this folder in your upload settings or contact your web host to modify permissions for this folder.",'.
'"NoAcknowledgment":"Unable to send return receipt: incorrect e-mail address",'.
'"CaptchaError":"Captcha error!",'.
'"RecaptchaError":"Recaptcha error - please reload (refresh) the page and try again",'.
'"CounterError":"Unable to retrieve counter data!",'.
'"ErrorSize":"Incorrect file size.",'.
'"ErrorExtension":"Invalid file format. Executable files are not allowed."}';


if (function_exists('detectBrowserLanguage')) {
	$_browserLanguage = detectBrowserLanguage();
	if ($_browserLanguage && strlen($_browserLanguage) > 1) {
		$_browserLanguage = substr($_browserLanguage, 0, 2);
		
		if ($_browserLanguage == 'fr') { // traduction francais 
// A VERIFIER TRADUCTION!
$WEInfoPage = 
'{"ExecutionOnlyOnline":"Impossible d\'ex&#233;cuter cette action depuis openElement.\r\nVous devez mettre en ligne votre page.",'.
'"NoResponse":"Une erreur est survenue lors de l\'envoi de l\'e-mail! Veuillez r&#233;essayer ult&#233;rieurement ou contacter l\'administrateur du serveur.",'.
'"NoPHP":"Impossible d\'ex&#233;cuter cette action.\r\nLe PHP n\'est pas install&#233; sur votre h&#233;bergement. \r\nVeuillez contacter votre h&#233;bergeur pour l\'activer.\r\n\r\nRemarque : En cas de mise en ligne hors openElement, saisissez votre version de PHP dans les pr&#233;f&#233;rences, onglet avanc&#233;es. Puis r&#233;enregistrez cette page avant de la remettre en ligne.",'.
'"NoMailFunction":"Le formulaire n\'a pas pu &#234;tre envoy&#233; La fonction PHP \'mail()\' n\'est pas active sur cet h&#233;bergement. Veuillez contacter votre h&#233;bergeur pour l\'activer.",'.
'"NoMailSend":"Le formulaire n\'a pas &#233;t&#233; envoy&#233;\r\n Contacter l\'administrateur du serveur : Echec de la fonction PHP Mail()\r\n",'.
'"FormNotConfigure":"Attention, L\'&#233;l&#233;ment SendMail n\'a pas &#233;t&#233; configur&#233; dans openElement.Vous devez le configurer pour permettre l\'envoi du formulaire par e-mail.",'.
'"NoUploadRight":"Impossible de d&#233;placer le fichier import&#233;.\r\nLe r&#233;pertoire d\'Upload ne peut pas &#234;tre cr&#233;e ou ne possède pas les droits d\'&#233;criture.\r\nVous pouvez modifier ce r&#233;peroire dans vos param&#232;tres de mise en ligne ou contacter votre h&#233;bergeur pour modifier les droits de ce r&#233;pertoire.",'.
'"NoAcknowledgment":"Impossible d\'envoyer l\'accusé de réception : e-mail incorrect",'.
'"CaptchaError":"Captcha incorrect!",'.
'"RecaptchaError":"Erreur Recaptcha - veuillez actualiser la page et r&#233;essayer",'.
'"CounterError":"Impossible de récupér les données des compteurs!",'.
'"ErrorSize":"Taille du fichier incorrecte.",'.
'"ErrorExtension":"Format du fichier incorrect. Les fichiers executables sont interdits."}';
		} else 
		
		if ($_browserLanguage == 'de') { // traduction allemande
// $WEInfoPage = "{....};" 
		}
		
	}
}

// pas de besoin de fermer tag php