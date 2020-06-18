<?php
/*_______________________________________________
|                                                |
|    ©2012 Element Technologie - openElement     |
|________________________________________________|
*/

error_reporting (E_ALL);
ini_set("display_errors",1);

$dir_openElementphp = dirname(__FILE__);

fixSERVERLowerCaseKeys();

if (!isset($oeRequireControl_NoMail)) { // to significantly reduce loading time when no need for mailing functionality
	require_once $dir_openElementphp."/class/class-phpmailer.php"; //phpmailer
	require_once $dir_openElementphp."/class/class-smtp.php";  //phpmailer
	// ! attention - if changing includes above, update includes in Mail class below
}

function fixSERVERLowerCaseKeys() {
	// sometimes (ex. for local webserver), keys in $_SERVER are lowercase; in this case add uppercase versions with same values
	if ((!isset($_SERVER['PATH']) && isset($_SERVER['path'])) ||
		(!isset($_SERVER['SERVER_NAME']) && isset($_SERVER['server_name']))) {
		
		$_SERVER_UC = array();
		foreach ($_SERVER as $key=>$val) {
			$key_uc = strtoupper($key);
			if ($key_uc != $key) $_SERVER_UC[$key_uc] = $val;
		}
		$_SERVER = array_merge($_SERVER, $_SERVER_UC);
	}
}

function detectBrowserLanguage() {
  if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) return '';
  $langcode = explode(";", $_SERVER['HTTP_ACCEPT_LANGUAGE']);
  $langcode = explode(",", $langcode['0']);
  return strtolower($langcode['0']);
}

// A garder cet include APRES detectBrowserLanguage() / Keep this include AFTER detectBrowserLanguage():
include_once $dir_openElementphp."/VarText.php"; // MC&DD browser-language-dependent string constants


function GetErrMessage($_legacy,$WEInfoPage,$name) {
	//Deserialisation des message d'erreurs
	if (empty( $WEInfoPage )) 
	{
		$jsonErr = ""; 
	} else {
		$jsonErr = $WEInfoPage;	
	}
	$MsgErr = json_decode($jsonErr);
	
	return $MsgErr->$name;
	
}



function WECaptchaCheck($code,$IdElem,$OEVersion) {


	if (strlen($OEVersion) > 10) die('Invalid version string');
	if (!$IdElem) $IdElem = 0;

	if (!isset($_SESSION['WECaptchaPage']) || !isset($_SESSION['WECaptchaConfigfile'])) return true; // default to positive if captcha info not written in session

	$cryptinstall="WECaptcha/WECaptcha.fct-v".$OEVersion.".php";
	include_once $cryptinstall; 

	$reslt = chk_crypt(strip_tags($code), $IdElem);

	
	
	if ($reslt) {
		return true;
	} else {
		return false;
	}

}



 //Type de donnée générique OE : FormLinks
 // renvoie la liste des éléments  (OEDataFormLinks) dans $TabElementsID
 class OEDataFormLinks {
 	//proprietes
	var $TabElementsID;
	
	// constructeur
	public function __construct($JsonData) {
		$this->TabElementsID = array();
		foreach ($JsonData->ElementsID as $ID => $Type){
			 array_push($this->TabElementsID, new OEDataFormLinksElement($ID,$Type));
		}
	}
	
	//Methodes
}

//Element de OEDataFormLinks
class OEDataFormLinksElement {
	var $ID;
	var $Type;
	
	//Constructeur
	public function __construct($ID,$Type) {
		$this->ID=$ID;
		$this->Type=$Type;
	}
}
  
 //Type de donnée générique OE : LocalizableString
class OEDataLocalizableString {
	//recuperation de la valeur par rapport Ã  une donnée json et de la culture
	function Get($JsonData, $Culture) {

		if (isset($JsonData->Items->$Culture)) {
			return $JsonData->Items->$Culture;
		} else {
			return $JsonData->Items->DEFAULT;
		}
	}
}
  
  
 class OELinks {
	function Get($JsonData, $Culture) {
		$OEDataLocalizableString= new OEDataLocalizableString;
		return $OEDataLocalizableString->Get($JsonData->Links,$Culture);
		}
}


//Type de donnée générique OE : CssUnit
class OECssUnit {
	//Recupération de la valeur (sans unité)
	function GetValue($JsonData) {
		return str_replace ("px","",$JsonData);
	}

}
  
//Encodage et Decodage du JSON
class OEJSON {
	function Encode($obj) {
		return json_encode($obj);
	}
	function Decode($json, $toAssocArray = false) {
		return json_decode($json, $toAssocArray);
	}
}


//Gestion des retour
class OEReturn
{
	var $State;
	var $ErrorDescription;

	// constructeur
	public function __construct($s, $errDesc) {
		$this->State = $s;
		$this->ErrorDescription = $errDesc;
	}
}


 //Type de donnée générique OE : FormLinks
 // renvoie la liste des éléments  (OEDataFormLinks) dans $TabElementsID
class OEUploadFile {
 	//proprietes
	var $allowedTypesExt; // tableau des extension autorisées
	var $allowedMaxSize; //Taille de fichier maxila autorisée
	var $dir_extract; //Repertoire de destination
	var $DestFileNames; //Noms du fichiers de destination finals (array!)
	
	// constructeur
	public function __construct($allowedTypesExt, $allowedMaxSize, $dir_extract)	{
		$this->allowedTypesExt = $allowedTypesExt;
		$this->allowedMaxSize = $allowedMaxSize;
		$this->dir_extract = $dir_extract;
	}
	
	//Methodes
	//Verification et uload du fichier 
	//$FieldName : $_FILES['FieldName']
	////$DestFileName : nom du fichier final souhaité, si vide alors le nom du fichier sera le nom du fichier + un guid (si le nom existe deja)
	//$this->DestFileNames : noms du fichier final
	//Renvoi : OEReturn (error ou success)
	function GetFiles($FieldName, $WEInfoPage) {

		//if (!isset ($_FILES[$FieldName])) { return new OEReturn ("error","Erreur de téléchargement - Fichier introuvable.");}
		if (!isset($_FILES[$FieldName]['name'])) return false; // no files
		
		$m = $multiple = is_array($_FILES[$FieldName]['name']);
		$numFiles = (!$m) ? 1 : count($_FILES[$FieldName]['name']); // number of files sent through this element
		
		for ($indFile=0; $indFile<$numFiles; $indFile++) {
			
			$FileNameTmp=$_FILES[$FieldName]['tmp_name']; if ($m) $FileNameTmp=$FileNameTmp[$indFile];
			$FileName=$_FILES[$FieldName]['name']; if ($m) $FileName=$FileName[$indFile];
			
			if (empty($FileName)) { return false; } // new OEReturn ("success",""); //champ vide
			
			$expl = explode(".", $FileName); $endexpl = end($expl); // to fix strict rule violation in later PHP versions - end() takes parameter by reference
			$FileExt=".".strtolower($endexpl);
			$BaseFileName = preg_replace('/\.[^.]*$/', '', basename($FileName));
			$ErrorDescription="";
			
			$FileSize = $_FILES[$FieldName]["size"]; if ($m) $FileSize=$FileSize[$indFile];
			if ($FileSize == 0) { continue; } // exit(0) // empty file?
			
			//Verification des extensions
			if (count($FileExt)>0) {

				if ( !in_array($FileExt, $this->allowedTypesExt)) {$ErrorDescription="Format du fichier interdit.";}//GetErrMessage($objJson,$WEInfoPage,"ErrorExtension");}
			}

			//Verification des extensions interdites
			$ForbidedTypesEx= array(".php",".cgi",".exe",".js",".asp",".aspx",".ini",".php",".php3",".php4",".ph",".ph4",".phtml",".jscript",".javascript",".cp",".cpp",".c++",".cc",".cxx",".c","pl");
			if (in_array($FileExt, $ForbidedTypesEx)) { $ErrorDescription="Format du fichier incorrect. Les fichiers executables sont interdits." ;}//GetErrMessage($objJson,$WEInfoPage,"ErrorExtension");}
			
			//Verification de la taille
			if ($this->allowedMaxSize>0) {
				if ($FileSize > $this->allowedMaxSize) { $ErrorDescription="Taille du fichier trop grande.";}//GetErrMessage($objJson,$WEInfoPage,"ErrorSize");} //"Taille du fichier incorrecte."
			}

			if ($ErrorDescription!="") return new OEReturn ("error",$ErrorDescription);
			
			/*création du répertoire de destination*/
			if (!(file_exists($this->dir_extract))) {
				mkdir($this->dir_extract);
			}
		
			
			// Upload du fichier
			//if (empty($DestFileName)) {
				$DestFileName = $this->dir_extract."/".$FileName;
				$i=1;
				while (file_exists($DestFileName)) {
					$DestFileName=$BaseFileName."_".$i.$FileExt;
					
					$i++;
				}
			//} else { $DestFileName = $this->dir_extract."/".$DestFileName; }

			if (!isset($this->DestFileNames)) $this->DestFileNames = array();
			$this->DestFileNames[] = $DestFileName;

			if (!move_uploaded_file($FileNameTmp, $DestFileName)) 
			{
				return new OEReturn ("error",GetErrMessage(null,$WEInfoPage,"NoUploadRight"));
			}
			
		}
		
		return new OEReturn ("success","");
	
	}
	
}

 //Envoi d'email
class OEMail {

	//var $parts;
	var $to;
	var $from;
	var $headers;
	var $subject;
	var $body;
	var $contact;
	var $cc; 
	var $cci; 
	var $html;
	//var $boundary;
	//var $TextUTF8;
	//var $BodyContentType;*/
	var $PHPMailer;
	
	// constructeur
	//$TextUTF8 : True ou false  :  indique si les textes sont deja en utf-8 ou non
	//$IsHTML : Format du texte du message : "HTML" =true ou "Text"=false
	//$ConfSendMail : objet de configuration de l'envoi d'email.
	public function __construct($IsHTML,$ConfSendMail)
	{

		$this->parts = array();
		$this->to = "";
		$this->from = "";
		$this->subject = "";
		$this->body = "";
		$this->contact = "";
		$this->cc=""; 
		$this->cci="";
		
		if (!class_exists('PHPMailer')) {
			global $dir_openElementphp;
			require_once $dir_openElementphp."/class/class-phpmailer.php"; //phpmailer
			require_once $dir_openElementphp."/class/class-smtp.php";  //phpmailer
		}
		
		$this->PHPMailer = new PHPMailer();
		
		if (function_exists('detectBrowserLanguage')) {
			$_browserLanguage = detectBrowserLanguage();
			if ($_browserLanguage && strlen($_browserLanguage) > 1) {
				$_browserLanguage = substr($_browserLanguage, 0, 2);
				//if ($_browserLanguage === 'en' || $_browserLanguage === 'fr')
				$this->PHPMailer->SetLanguage($_browserLanguage);
			}
		}
		
		$this->html=$IsHTML;
		$this->PHPMailer->IsHTML($IsHTML);
		$this->SetSendMethode($ConfSendMail);
		$this->PHPMailer->CharSet="UTF-8";
	}
	
	function SetSendMethode($ConfSendMail) {
		if (!isset($ConfSendMail->TypeStr)) {$this->PHPMailer->IsMail(); return;}

		switch ($ConfSendMail->TypeStr) {
			case "Mail":
				$this->PHPMailer->IsMail();
				break;
			case "SendMail":
				$this->PHPMailer->IsSendmail();
				break;
			case "Qmail":
				$this->PHPMailer->IsQmail();
				break;
			case "SMTP":
				if (!$ConfSendMail->SMTPHost /*|| !$ConfSendMail->SMTPUser || !$ConfSendMail->SMTPPassWrd*/) { // missing parameters, default to simple Mail:
					$this->PHPMailer->IsMail();
				} else {
					$this->PHPMailer->IsSMTP();

					$this->PHPMailer->Host       = $ConfSendMail->SMTPHost;
					if ($ConfSendMail->SMTPSSL && !strpos($this->PHPMailer->Host, '://')) 
						$this->PHPMailer->Host = 'ssl://'.$this->PHPMailer->Host;

					$this->PHPMailer->Port       = $ConfSendMail->SMTPPort;
					$this->PHPMailer->Username   = $ConfSendMail->SMTPUser;
					$this->PHPMailer->Password   = $ConfSendMail->SMTPPassWrd;   
					if ($this->PHPMailer->Password) $this->PHPMailer->SMTPAuth = true;
				}
				break;
			default:
				$this->PHPMailer->IsMail();
				

				break;
		}
	
	}
	
	
	//Fonction d'envoi
	//return "message d'erreur" : Erreur
	//		 "" : Reussite
	function send() {
	

		$this->PHPMailer->From       = $this->from;
		$this->PHPMailer->FromName   = $this->contact;

		$this->PHPMailer->Subject    = $this->subject;
		$this->PHPMailer->AddAddress($this->to , $this->to);
		if (!empty($this->cc))  $this->PHPMailer->AddCC($this->cc);
		if (!empty($this->cci)) $this->PHPMailer->AddBCC($this->cci);
		
		$this->PHPMailer->AltBody    = strip_tags($this->body); 
		
		if ($this->html && $this->body) { // if it's plain text and we are in html mode, replace line break by <br/>
			$this->body = str_replace("\n", "<br/>", str_replace("\n\r", "<br/>", str_replace("\r\n", "<br/>", $this->body)));
		}

		$this->PHPMailer->MsgHTML($this->body);

		$this->PHPMailer->AddCustomHeader("Return-path:".$this->from); //Pour certain hebergement


		
		for($i = sizeof($this->parts) - 1; $i >= 0; $i--) {
			$FilePath=$this->parts[$i];
			if (file_exists($FilePath)) {
				$this->PHPMailer->AddAttachment($FilePath);
			}
		}

		$result = @$this->PHPMailer->Send();
		if(! $result) {
		  return $this->PHPMailer->ErrorInfo;
		} else {
		  return "";
		}
			
	
	}
	
	function CheckEmail($val) {
		$Syntaxe='#^[\w.-]+@[\w.-]+\.[a-zA-Z]{2,6}$#'; 
		if(preg_match($Syntaxe,$val)) 
			return true; 
		else 
			return false; 
	}
	
}

// Security ///////////////////////////////////////////////////

/* examples of use (don't forget "global" if use inside functions!!): 
  if (!$Security->linkIntPageOk($PagePath)) exit;
or
  $Security->linkIntPageOk($PagePath) or exit;
  
  Note: quick fix for compatibility with php4 - changed Static approach by global variable :(
*/

class COESecurity {
	
	var $wl_basic = 'A-Za-z0-9_\-';
	var $wl_intpages = '\(\)+\.\s';
	var $wl_int_mail = '\(\)+\.'; // 01.01.17 removed \s for security reasons
	var $wl_int_path = '\/';
	var $wl_int_path_extended = '~#@!\$&\'*+="%';
	var $wl_ext_url = ':\?\[\],;';
	var $wl_backslash = '\\\\';

	// check link to OE page, used ex. for [name](var).php
	function linkIntPageOk($name) {
		$wl = '/[^'.$this->wl_basic.$this->wl_intpages.$this->wl_int_path.$this->wl_int_path_extended.']/'; // whitelist
		return !(preg_match($wl, $name)); //  || preg_match($wl.'u', $name)
	}

	function emailOk($name) {
		$wl = '/[^'.$this->wl_basic.$this->wl_intpages.$this->wl_int_path_extended.$this->wl_ext_url.']/'; // whitelist
		return !(preg_match($wl, $name));
	}

	function simpleClean($elem) {
		if(!is_array($elem))
			$elem = htmlentities($elem, ENT_QUOTES, "UTF-8");
		else {
			foreach ($elem as $key => $value)
				$elem[$key] = $this->clean($value);
		}
		return $elem;
	}

}

$OESecurity = new COESecurity();
$Security =& $OESecurity; // remove later $Security from all scripts and from here

?>