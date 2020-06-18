<?php
/*_______________________________________________
|                                                |
|    ©2012 Element Technologie - openElement     |
|________________________________________________|

	ATTENTION! SAVE IN UTF8 WITHOUT BOM, DO NOT EDIT/SAVE IN VISUAL STUDIO
	
	Dynamic/DB tools - class of utility functions
	
*/

	// Prevent timezone warnings/errors on using date() etc. in later php versions:
	$currER = error_reporting(E_ERROR); // disable warnings
	try {
		$script_tz = date_default_timezone_get(); $ini_tz = ini_get('date.timezone'); //echo "TIME ZONES INIT: $ini_tz::$script_tz";
		if (!$script_tz) $script_tz = 'UTC'; if (!$ini_tz) $ini_tz = $script_tz;
		date_default_timezone_set($script_tz); ini_set('date.timezone', $ini_tz); //echo "TIME ZONES MODIF: $ini_tz::$script_tz";
	} catch(Exception $ex) {;}
	error_reporting($currER); // restore previous report settings
	

$dir_OEDynUtilsphp = dirname(__FILE__);


class OEDynUtils {

	public function __construct() {
	
	}
	
	public static function decodeJSON($jsonSt, $asArray = true) {
		if (!$jsonSt) return null;
		$r = json_decode($jsonSt, $asArray);
		return $r;
	}
	
	public static function encodeJSON($o) {
		if (!$o) return null;
		$r = json_encode($o);
		return $r;
	}
	
	//private static $cntrFindEndBlock;
	public static function FindEndBlock($st, $posStart, $tagStartBlock, $tagEndBlock, $tagAltEnd = '', $recursionLevel = 0) { 
		// Recursive. Example for IF blocks: Find {ELSE} ($tagAltEnd = "{ELSE}") or {ENDIF} corresponding to this IF (considering possible subblocks)
		// ATTENTION: $posStart must be AFTER the start of the current block (after pos of first character of $tagStartBlock opening current block)
		$recursionLevel++;
		//if ($recursionOn) OEDynUtils::$cntrFindEndBlock++;
		if ($recursionLevel > 200) { // too long recursion, error
			OEDynUtils::OutputStop('Error in Page Dynamic Structure - probably no matching {ENDIF} in format:<br/>'.$st);
			//echo '<br><br>string:'.substr($st, $posStart).'<br><br>';
		}
		
		$posEND = strpos($st, $tagEndBlock, $posStart);
		$posAltEND = (!$tagAltEnd) ? $posEND : strpos($st, $tagAltEnd, $posStart);
		if ($posEND === false) return -1; // error in format
		if ($posAltEND === false || $posAltEND > $posEND) $posAltEND = $posEND; // endif is found first, means $tagAltEnd is absent until the end of block
		//echo 'end block:'.substr($st, $posAltEND).'<br/>';
		
		$posSubBlock = strpos($st, $tagStartBlock, $posStart); 					// find first subblock, if any,..
		if ($posSubBlock !== false && $posAltEND < $posSubBlock) $posSubBlock = false; 	// .. only if before end of current block
		
		if ($posSubBlock === false)// no subblocks util end of block
			return $posAltEND; // found the end
		else { // subblock found
			$posEndSub = OEDynUtils::FindEndBlock($st, $posSubBlock+1, $tagStartBlock, $tagEndBlock, '', $recursionLevel); // find end of subblock (it may contain its own subblocks)
			if ($posEndSub === false) return -1; // error in format
			$recursionLevel--;
			return OEDynUtils::FindEndBlock($st, $posEndSub+1, $tagStartBlock, $tagEndBlock, $tagAltEnd, $recursionLevel); // recursive-continue searching from end of subblock (there may be several subblocks on the same level)
		}
	}
	
	public static function RemoveBlocksFromString($st, $tagStartBlock, $tagEndBlock, $posStart = 0) {
		// Remove all blocks/substrings starting with $tagStartBlock and ending with $tagEndBlock, ex. "<div" and "</div>" removes all divs from html code
		if (empty($st)) return $st;
		$r = "";
		$posLastEnd = $posStart;
		while (true) {
			// find bounds of next block:
			$posBlock = strpos($st, $tagStartBlock, $posLastEnd);
			$addstr = ($posBlock !== false) ? substr($st, $posLastEnd, $posBlock-$posLastEnd) : substr($st, $posLastEnd);
			$r .= $addstr; // add to result the substring before block (starts from 0 or from end of last block) or remaining part after all blocks
			if ($posBlock === false) break; // no more blocks

			$posLastEnd = OEDynUtils::FindEndBlock($st, $posBlock+1, $tagStartBlock, $tagEndBlock); // end of this block
			if ($posLastEnd < 0) return $st; // error, non-terminated block
			$posLastEnd += strlen($tagEndBlock); // first character after this block
		}		
		return $r; // = $st with all blocks removed	
	}
	
//____________________________________________________
// = Array Tools =====================================
	
	public static function _FindByName(&$ar, $name) {
		if (!$ar /*|| !is_array($ar)*/) return null;
		$count = count($ar);
		for ($i=0; $i < $count; $i++) {
			if (isset($ar[$i]['Name']) && $ar[$i]['Name'] === $name) return $ar[$i];
		}
		return null;
	}

	public static function _FindIndexByName(&$ar, $name) {
		if (!$ar /*|| !is_array($ar)*/) return -1;
		$count = count($ar);
		for ($i=0; $i < $count; $i++) {
			if (isset($ar[$i]["Name"]) && $ar[$i]["Name"] === $name) return $i;
		}
		return -1;
	}
	
	
	public static function _FindInArray(&$ar, $name) {
		if (!isset($ar[$name])) return null; else return $ar[$name];
	}
	
	
	public static function RandomVal($UC, $lc, $digits, $len, $avoidSimilarChars = false) {
		//echo ' RandomVal: ';var_dump($UC);var_dump($lc);var_dump($digits);var_dump($len);var_dump($avoidSimilarChars);
		if ($len < 1) return '';
		
		$r = '';
		for ($i=0; $i<$len; $i++) {
			$range = 0;
			if ($UC) 		$range += 26;
			if ($lc) 		$range += 26;
			if ($digits) 	$range += 10;

			if ($range <= 0) { return mt_rand(0, 1); } // corresponds to boolean-like result
			
			// Get random character:
			$c = '';
			while (true) { // for $avoidSimilarChars test
				$rVal = mt_rand(0, $range-1);
				if (!$UC) $rVal += 26;
				if (!$lc) $rVal += 26;
				// Now, uppercase chars: 0-25, lowercase: 26-51, digits: 52-61
			
				if ($rVal < 26) // A-Z
					$c = chr($rVal + 65);
				else if ($rVal < 52) // a-z
					$c = chr($rVal-26 + 97);
				else // 0-9
					$c = chr($rVal-52 + 48);
					
				if ($avoidSimilarChars) { // if one of hard-to-identify characters, try again
					if ($UC && $digits && ($c === '0' || $c === 'O')) continue;
					if (($UC || $lc)) {
						if ($c === 'l' || $c === 'I') continue;
						if ($digits && ($c === '1'))  continue;
					}
				}
				break;
			}
			
			$r .= $c;
		}
		//echo "  RANDOM: $r<br/>";
		return $r;
	}
	
	public static function Redirect($location) {
		try { 
			header("Location: $location"); 
		} catch(Exception $ex) {;}
		exit; // must stop script immediately
		//echo '<br>REDIRECTION TO '.$cParams."<br/><br/>";					
	}
	
//____________________________________________________
// = Captcha and SendMail ============================

	// Captcha test - can search for captcha element and user-entered code automatically if necessary
	public static function IsCaptchaOk($dynJSONData = null, $enteredCode = null, $captchaElID = '', $oeCaptchaVersion = '') {
		//!!keep updated in sync with Captcha functionality
		if (!function_exists('WECaptchaCheck')) {
			global $dir_OEDynUtilsphp;
			require_once dirname(dirname($dir_OEDynUtilsphp)).'/openElement.php';
		}
		if (!function_exists('WECaptchaCheck')) return true; // failed to find function, consider ok

		if (!$captchaElID) {
			global $OEConfWECaptcha;
			if (!isset($OEConfWECaptcha) || empty($OEConfWECaptcha)) return true; // can't auto-find captcha
			$pos1 = strpos($OEConfWECaptcha, '"'); $pos2 = strpos($OEConfWECaptcha, '"', $pos1+1);
			if ($pos1 < 0 || $pos2 < $pos1) return true; // not expected json string
			$captchaElID = substr($OEConfWECaptcha, $pos1+1, $pos2-$pos1-1);
			if (strlen($captchaElID) < 2) return true; // can't auto-find captcha
		}

		if ($enteredCode === null) {
			if (!isset($_POST[$captchaElID])) return true; // can't auto-find entered code in POST data
			$enteredCode = $_POST[$captchaElID];
		}
		
		if (!$enteredCode) return false; // user didn't enter captcha code
		
		if (!$oeCaptchaVersion) {
			/*
			if (!$dynJSONData) {
				global $OEConfDynamic; if (!isset($OEConfDynamic)) return true;
				$dynJSONData = self::decodeJSON($OEConfDynamic);
			}
			if (!isset($dynJSONData['OEVersion'])) return true; // version unknown
			$oeCaptchaVersion = $dynJSONData['OEVersion'];*/
			if (!isset($_SESSION['OEVersion'])) return true; // no captcha initialized
			$oeCaptchaVersion = $_SESSION['OEVersion']; // see WECaptcha.php
		}
		
		//echo "WECaptchaCheck: $enteredCode, $captchaElID, $oeCaptchaVersion<br/>";
		return WECaptchaCheck($enteredCode, $captchaElID, $oeCaptchaVersion);
		
	}
	
	// security token - hidden form element
	public static function AddHiddenSecurityToken(&$html, $pageSerial) {
		if (!isset($html)) $html = '';
		$token = substr(md5(uniqid(rand())), 0, 16);
		$_SESSION['oeSecurToken'] = $token; // new token
		if ($pageSerial) { // also store for this page
			if (!isset($_SESSION['oeSecurToken_pages'])) $_SESSION['oeSecurToken_pages'] = array();
			//echo ' - - - -'.$pageSerial.'<br>';
			$_SESSION['oeSecurToken_pages'][$pageSerial] = $token;
		}
		$tokenHTML = "<div id=\"oeSecurToken\" style=\"display:none;\"><input type=\"hidden\" name=\"oeSecurToken\" value=\"$token\" /></div>";
		$html .= $tokenHTML;
	}
	
	public static function IsSecurPostTokenOk($pageSerial) {
		if (!isset($_POST['oeSecurToken'])) return false;
		$r = (isset($_SESSION['oeSecurToken']) && $_POST['oeSecurToken'] === $_SESSION['oeSecurToken']); // compare with last-stored token
		if (!$r && $pageSerial) { // last-stored token may come from another site page, check in array of tokens the value corresopnding to this page
			$r = (isset($_SESSION['oeSecurToken_pages'][$pageSerial]) && $_POST['oeSecurToken'] === $_SESSION['oeSecurToken_pages'][$pageSerial]);
		}
		return $r;
	}
	
	public static function SendEmailSimple($culture = 'DEFAULT', $ConfSendMail = null, $from = '', $to = '', $cc = '', $subject = '', $body = '', $isHTML = true) {
		// returns error description in case of failure
		// when $ConfSendMail = null, at least one SendMail element should be on the page, to use its configuration like send method etc.
		
		//echo '<br/>SendEmailSimple<br/>';
		
		if (!class_exists('OEMail')) {
			global $dir_OEDynUtilsphp;
			require_once dirname(dirname($dir_OEDynUtilsphp)).'openElement.php';
		}
		
		if (!class_exists('OEMail') || !class_exists('OEDataLocalizableString')) return false;
		//if (!$to || !$from || (!$subject && !$body)) return false;
		
		if (!isset($ConfSendMail)) { // try to get it from SendMail var data
			global $OEConfWESendMail; if (!isset($OEConfWESendMail)) return false; // no mail elements found in var file
			$confMail = self::decodeJSON($OEConfWESendMail);

			// find first SendMail element and use its configuration
			foreach ($confMail as $key=>$value) { $ConfSendMailID = $key; break; }
			if (!$ConfSendMailID) return false;

			if (!isset($confMail[$ConfSendMailID]['ConfSendMail'])) return false;
			$ConfSendMail = $confMail[$ConfSendMailID]['ConfSendMail'];
		}
		
		if (!isset($ConfSendMail->TypeStr)) { // config stored in array form, conver to object form:
			$ConfSendMail = json_decode(json_encode($ConfSendMail), FALSE);
		}
	
		$LocalizableString= new OEDataLocalizableString;
		
		if (!$to) {
		
		}
		
		$mail = new OEMail(!!$isHTML, $ConfSendMail);
		$mail->contact = ''; // $contact
		$mail->from = $from; // Adresse email de l'expediteur (optionnel)	
		$mail->to = $to;
		$mail->subject = $subject;
		$mail->body = $body; // Corps du message
		
		//echo 'SendMail:';var_dump($mail);
		
		$r = $mail->send();
		
		//echo 'SendMail result::';var_dump($r);echo '::';
		
		if ($r && self::isLocalWebServer()) { // emulate mail:
			self::_EmulateSendMail(null, $from, $to, $subject, $body);
			return null;
		}
		
		return ($r) ? $r : null;
	}
	
	public static function isLocalWebServer() { 
		$urlStart = $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"]; //echo $urlStart;
		//return (strpos(strtolower($urlStart), 'localhost:8086') === 0);//!check, update if changed
		return (preg_match('/localhost:80(8[6789]|9[0-9])/i', $urlStart) // check ports 8086-8099, in case of several OE instances
			 && strpos(strtolower($urlStart), 'localhost:') === 0);
	}
	
	private static function _ReadDebugMail() {
		$mailFile = dirname(__FILE__).'/../debug_mail_emulation.txt'; if (!file_exists($mailFile)) return null;
		$mail = file_get_contents($mailFile); if (!$mail) return null;
		$mail = self::decodeJSON($mail);
		if (!isset($mail['Messages'])) return null;
		$mg =& $mail['Messages'];
		while (count($mg) > 5) { array_shift($mg); } // keep limited amount of last messages
		return $mail;
	}
	
	private static function _WriteDebugMail($mail) {
		if (!$mail || count($mail) < 1) return;
		$mail = self::encodeJSON($mail); if (!$mail) return;
		$mailFile = dirname(__FILE__).'/../debug_mail_emulation.txt';
		//echo '<br/>Writing to '.$mailFile.': '.$mail.'<br>';
		file_put_contents($mailFile, $mail);
	}
	
	private static function _EmulateSendMail($mail, $from, $to, $subj, $body) {
		if (!$from || !$to) return;
		if (!$subj) $subj = '';
		if (!$body) $body = '';
		$writeAtTheEnd = !$mail;
		if (!$mail) $mail = self::_ReadDebugMail(); 
		if (!$mail) $mail = array();
		
		$message = array();
		$message['from'] = $from;
		$message['to'] = $to;
		$message['subj'] = $subj;
		$message['body'] = $body;
		$errLevel = error_reporting(0);
		$message['date'] = date('m/d/Y h:i:s a').' UTC';
		error_reporting($errLevel);
		$mail['Messages'][] =& $message;		
		//echo 'Mail emulation:';var_dump($mail);
		
		if ($writeAtTheEnd) self::_WriteDebugMail($mail);		
	}
	
	public static function DebugMailEmulation() {
		if (!self::isLocalWebServer()) return '';
		$mail = self::_ReadDebugMail(); if (!$mail || !isset($mail['Messages'])) return '';
		
		$r = '<div id="oeDebugMailEmulation" style="font-size:10px;">';
		$r.= '(Message file: <b>'.dirname(__FILE__).'/../debug_mail_emulation.txt'.'</b>)<br/>';
		for ($i=count($mail['Messages'])-1; $i>=0; $i--) {
			$message = $mail['Messages'][$i];
			$r .= '<br/><hr><br/>';
			$r .= $message['date'].': From '.$message['from'].' to '.$message['to'].'<br/>';
			$r .= 'Subject: '.$message['subj'].'<br/>';
			$r .= $message['body'].'<br/><br/>';
		}
		
		$r .= '<hr></div>';
		return $r;
	}
	
	public static function OutputStop($message, $wrapPageCode = true) { // stop php and show only output/error message on the page
		global $oephpDebugMode;
		if (!$oephpDebugMode) {
			while (ob_get_level() > 0) ob_end_clean(); // stop buffering without showing the output
		}
		if ($wrapPageCode) {
			;echo <<<HDEND
<!DOCTYPE HTML>
<html><head><meta http-equiv="content-type" content="text/html; charset=UTF-8" /></head><body>
$message
</body></html>
HDEND;
		} else {
			;echo $message;
		}
		
		exit(-1);
	}
	
}
// $OEDynUtils = new OEDynUtilsClass();
