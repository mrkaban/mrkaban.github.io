<?php
/*_______________________________________________
|                                                |
|    ©2012 Element Technologie - openElement     |
|________________________________________________|

	ATTENTION! SAVE IN UTF8 WITHOUT BOM, DO NOT EDIT/SAVE IN VISUAL STUDIO
	
	Dynamic/DB tools
	Included directly in site's php pages to get all dynamic content that is requested on load (the rest will be requested by AJAX)
	Runs all actions&requests for all OnLoad events, sets variables used "inline" in HTML code etc.
	Takes info from <pagename>(var).php
*/
	//echo sha1('1234'); //7110eda4d09e062aa5e4a390b0a572ac0d2c0220
	//Notepad++ find regexp: [(\s|\t)](var_dump|echo)

	$oeScriptStartTime = microtime(true);
	
	
	// first, verify if PHP is 5+
	__testphpversion();
	
	// mode and output control //////////////////////////////
	if (!isset($oephpDebugMode)) 
		$oephpDebugMode 		= false;//!!todo handle mode
	$oephpDebugModeShowExpanded = !true;
	$oephpOutputToBuffer 		= true;

	// init /////////////////////////////////////////////////
	__init1();

//  __________________________
//	== Includes =================================
	
					$a_tm['before Includes'] = (microtime(true) - $tm_start)*1000;
					$tm_start = microtime(true);	
	$dir_OEDBphp = dirname(__FILE__); 							// folder containing this php script
	$dir_OEDBphp_to_tools = dirname($dir_OEDBphp); 				// corresponds to "../"

	$oeRequireControl_NoMail = true; // don't include mail-related code, for now
	require_once $dir_OEDBphp_to_tools.'/openElement.php'; 		// general tools including JSON
					//$a_tm['require openElement            '] = (microtime(true) - $tm_start)*1000;
	require_once $dir_OEDBphp.'/DBTools/OEDynUtils.php';		// utility functions
					//$a_tm['require OEDynUtils             '] = (microtime(true) - $tm_start)*1000;
	require_once $dir_OEDBphp.'/DBTools/OEDataContainers.php'; 	// data containers
					//$a_tm['require OEDataContainers       '] = (microtime(true) - $tm_start)*1000;
	require_once $dir_OEDBphp.'/DBTools/OEDBRequest.php'; 		// db queries
					//$a_tm['require OEDBRequest            '] = (microtime(true) - $tm_start)*1000;
	require_once $dir_OEDBphp.'/OEDBClasses.php'; 				// classes for objects of dynamic page model
					//$a_tm['require OEDBClasses            '] = (microtime(true) - $tm_start)*1000;
					$a_tm['require All DynEngine'] = (microtime(true) - $tm_start)*1000;
			
	__init2();
	
//  __________________________	
//	== All page load operations =================
	
	$oeHeaderInlineCode = ''; // Code to write into html header section
	
	$dynManager = new OEDynManager($OEConfDynamic); if (!$dynManager->Valid()) exit;
					$a_tm['Initialize manager'] = (microtime(true) - $tm_start)*1000;
	
	// Initialise data containers known at startup, like constant values etc. passed through $OEConfDynamic, session values, page parameters; initialise DBValues
	$dynManager->InitStartupData();
					$a_tm['after init startup data'] = (microtime(true) - $tm_start)*1000;
	
	// Run all OnLoad events: each event contains a set of Actions
	$dynManager->RunEvents('OnLoad');
					$a_tm['after events'] = (microtime(true) - $tm_start)*1000;
	//  after events' actions are processed, DBValues that were their "targets" can reference respective Action's results (in action's result data container)
	
	// Find value of every HTMLModifier of every element on the page, and set these values to corresponding inline variables (see function comments)
	$oei = $dynManager->ApplyHTMLModifiersToInlineVars();
					$a_tm['after inlines'] = (microtime(true) - $tm_start)*1000;
	
	$oeIter = $dynManager->InlineIterators();
					$a_tm['after iterators'] = (microtime(true) - $tm_start)*1000;
	
	//echo "Inline output:";var_dump($oei);
	//echo "==".$dynManager->EvaluateFormattedItem('FIFilterModels')."==<br/>";
	if ($oephpDebugMode) {
		echo '<br>Session:';var_dump($_SESSION);
		echo '<br>Cookies:';var_dump($_COOKIE);
		//echo 'Cookies:';var_dump($_COOKIE);//var_dump($_POST);//echo OEDynUtils::IsCaptchaOk();
		echo '<br>POST:';foreach($_POST as $p) { echo htmlspecialchars($p, ENT_QUOTES, 'UTF-8').'<br>'; }	
	}
	
	__final();
	


	
//  ______________________________
//	== Init and finalize functions ==============

function __testphpversion() {
	$phpvers = phpversion();
	if (phpversion() < '5') { // stop
		;echo <<<HDEND
<!DOCTYPE HTML><html><head><meta http-equiv="content-type" content="text/html; charset=UTF-8" /></head><body><span style='color:red;'>PHP Support Error</span>:<br/><br/>
<i>English</i><br/>&nbsp;&nbsp;<b>PHP 5 required</b>; the server runs PHP $phpvers at the moment. Activate PHP5 by default, for example by adding one or both of the lines below to .htaccess file:<br/><br/>
<i>Français</i><br/>&nbsp;&nbsp;<b>PHP 5 exigé</b>; le serveur utilise PHP $phpvers actuellement. Activer PHP5 par défaut, par exemple en ajoutant une ou les deux lignes ci-dessous au fichier .htaccess:<br/><br/>
<pre>AddHandler application/x-httpd-php5 .php<br/>SetEnv PHP_VER 5<br/></pre></body></html>
HDEND;
		exit;
	}
	
}
	
function __init1() {

	global 	$tm_start, $a_tm, $tm_start_all,
			$oephpDebugMode, $oephpOutputToBuffer; 

	if ($oephpDebugMode) error_reporting(E_ALL); 	// ^ E_NOTICE
					else error_reporting(0); 		// don't show errors in production

	if ($oephpOutputToBuffer || !$oephpDebugMode) {	ob_start();	}

	// performance measurement //////////////////////////////
	$tm_start = microtime(true); $tm_start_all = $tm_start;	$a_tm = array();
	
	session_start();

	;echo '<div id="oephpDebugDiv">';
	
	//!!todo in openElement.php or elsewhere // fix SERVER keys to uppercase - happens with local webserver
		$_SERVER_UC = array();
		foreach ($_SERVER as $key=>$val) {
			$key_uc = strtoupper($key);
			if ($key_uc != $key) $_SERVER_UC[$key_uc] = $val;
		}
		$_SERVER = array_merge($_SERVER, $_SERVER_UC);
		//var_dump($_SERVER);echo '<br>';
	
}


function __init2() {
	
	// consider magic_quotes_gpc:
	if (get_magic_quotes_runtime()) set_magic_quotes_runtime(false);
	if (get_magic_quotes_gpc()) {
		OEDataContainer::$magicQuotesOn = true;
	}
	
	global 	$tm_start, $a_tm,
			$OEConfDynamic;

					$a_tm['after Includes'] = (microtime(true) - $tm_start)*1000;
					$tm_start = microtime(true);
					//echo "TIME::".((microtime(true) - $tm_start)*1000)." after requires<br/>";

	// global $oedbAjaxMode;
	$oedbAjaxMode = false;

	if (!isset($OEConfDynamic)) {
		if (isset($_POST['json'])) $oedbAjaxMode = true; else exit;
	}


	if ($oedbAjaxMode) {
		$ajax = OEDynUtils::decodeJson($_POST['json']); if (!$ajax) exit;
		$htmlVals 	= $ajax['FormInputs'];
		$relPath 	= $ajax['RelPagePath'];
		
		$varPHP = $dir_OEDBphp.'/'.$relPath.'(var).php';
		include_once $varPHP;
		;echo $varPHP;
		
		if (!isset($OEConfDynamic)) exit;
		//var_dump($OEConfDynamic);
		//var_dump($_GET);
		;var_dump($_POST);
		exit;
	}

	
}


function __final() { // mostly debug output
	
	global 	$a_tm, $tm_start_all, $oeScriptStartTime,
			$oephpOutputToBuffer, $oephpDebugMode, $oephpDebugModeShowExpanded,
			$dynManager, $oeLoginSQLite_LocalWebServer, $oeHeaderInlineCode, $oeStartBodyInlineCode;

					// show time performance:
					$a_tm['====ALL'] = round((microtime(true) - $tm_start_all)*1000);
					foreach ($a_tm as $key=>$value) { $a_tm[$key] = round($a_tm[$key]); }
					;echo '<span style="font-size:7px;">';var_dump($a_tm);echo '</span>';

	// add security token - hidden form element
	$pageSerial = $dynManager->GetDataByFullID('AutoVals.PageID', 0);
	OEDynUtils::AddHiddenSecurityToken($oeStartBodyInlineCode, $pageSerial);
	$oeStartBodyInlineCode .= "\n";
		
	$oeDBAdminToolCode = '';
	
	// Link to db editor - only in local mode:
	if (OESQLBase::isLocalSQLite() && isset($dynManager->JSData['PageRelPath'])) {
		$paregelpath = $dynManager->JSData['PageRelPath'];
		$dbadminerlink = $paregelpath.'WEFiles/Server/DB/dbadmin/phpliteadmin.php'; // sqlite administrator
		$oeDBAdminToolCode = "DB admin tool (for local test DB only!): <a href='$dbadminerlink?db=../../../{$oeLoginSQLite_LocalWebServer['dbname']}' target='_blanc'>PHPLiteAdmin</a><br/>";
		;echo '<br/>'.$oeDBAdminToolCode;
	}

	$oeMailEmulationCode = OEDynUtils::DebugMailEmulation();
	if ($oeMailEmulationCode) { ;echo $oeMailEmulationCode; }
	
	;echo '</div>';

	if ($oephpOutputToBuffer || !$oephpDebugMode) {
		$oephpDebugBuff = '';
		if ($oephpDebugMode) { // debug mode - keep all output
			$oephpDebugBuff = ob_get_contents();
		} else if (OESQLBase::isLocalSQLite()) {	// local previsualisation mode - keep only tool output
			if ($oeDBAdminToolCode) { $oephpDebugBuff .= '<div id="oephpDebugDiv">'.$oeDBAdminToolCode.'</div>'; }
			if ($oeMailEmulationCode) { $oephpDebugBuff .= $oeMailEmulationCode; }
		}
		
		if ($oephpDebugBuff) {
			$oeStartBodyInlineCode = (!isset($oeStartBodyInlineCode)) ? $oephpDebugBuff : $oeStartBodyInlineCode.$oephpDebugBuff;
			$oeDebugJSCode = '';

			$oeDebugJSCode .= "<script> $(document).ready(function() { \n";
				
			// style='text-decoration:blink;-webkit-animation: blink 1s steps(5, start) infinite;'
			if ($oeMailEmulationCode) {
				$oeDebugJSCode .= <<<HDEND
					$('body').prepend("<div id='oephpMailEmulationClick' style='cursor:pointer;background-color:#D0E8FF;color:black;font-size:12px;position:absolute;z-index:10000000;top:16px;width:100%;'>Local Debug (dbl-click to expand): <b>Mailbox (Boite Mail)</b> <b>emulation</b></div>");
					$('#oephpMailEmulationClick').prepend($('#oeDebugMailEmulation'));
					$('#oephpMailEmulationClick').dblclick(function(){ $('#oeDebugMailEmulation').toggle(); });
					$('#oeDebugMailEmulation').hide();
HDEND;
			}
			$oeScriptTime = round((microtime(true) - $oeScriptStartTime)*1000);
			$oeScriptTime = $a_tm['require All DynEngine'].'+'.($oeScriptTime - $a_tm['require All DynEngine']).'='.$oeScriptTime;
			$oeDebugJSCode .= <<<HDEND
					$('body').prepend("<div id='oephpDebugClick' style='cursor:pointer;background-color:#F0F0F0;color:black;font-size:12px;position:absolute;z-index:10000001;top:0px;width:100%'>Local Debug (dbl-click to expand): <b style='text-decoration:none;'>PHP + DB</b>&nbsp;&nbsp;<i>(script load+run time: <b>{$oeScriptTime}</b>ms)</i></div>");
					$('#oephpDebugClick').prepend($('#oephpDebugDiv'));
					$('#oephpDebugClick').dblclick(function(){ $('#oephpDebugDiv').toggle(); });
					$('#oephpDebugClick').fadeTo('slow', 0.25).fadeTo('slow', 1.0);
					$('#oephpMailEmulationClick').fadeTo('slow', 0.25).fadeTo('slow', 1.0);
HDEND;
			if (!$oephpDebugModeShowExpanded) $oeDebugJSCode .= 
					"\n $('#oephpDebugDiv').hide(); \n";

			$oeDebugJSCode .= "\n});</script>";


			$oeHeaderInlineCode = (!isset($oeHeaderInlineCode)) ? $oeDebugJSCode : $oeDebugJSCode.$oeHeaderInlineCode;

		}
		ob_end_clean(); // clear all php output
	}

}


	
 // __________________________________________________________________________________________ \\
//	== Manager of page's dynamic data ========================================================  \\

class OEDynManager {
	
	var $modeAJAX; 		// true when page is requested in AJAX mode
	
	var $JSData; 		// Deserialised $OEConfDynamic - page dynamic model: elements, events, actions etc.
	var $dataContainers; // All data containers, including special containers and results of all requests
	
	var $currLang;		// page culture/language, DEFAULT if unknown
	
	var $mngModifiers; 		// Manage of HTML modifiers - OEHTMLModifierManager class
	var $mngFormattedItems; // Manager of FormattedItem/Output object
	var $mngConditions; 	// Manager of conditions (mostly used in IF actions)
	var $DBValueManager; 	// Manager of DBValues - pointers to data sources (see comments in OEDBClasses.php)
	var $mngIterators;		// Manager of iterators and iteration zones - OEDynIteratorManager class
					
	var $ResultCommands; // commands returned by actions, to be execute after action pack is processed, ex. redirect to URL
	
	var $tm_start; // performance test
		
// = Constructor ==================================

	function __construct($jsonPageVars) {
		$this->tm_start =  microtime(true);
		$this->JSData = null;
		if (!$jsonPageVars) return;
		
		$this->currLang = $this->_getLangFromPageURL(); // detect current language //!!improve

		// Deserialise:
		
		;echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $this->tm_start)*10000)*0.1)." before decodeJSON<br/>";
		$this->JSData = OEDynUtils::decodeJson($jsonPageVars); if (!$this->JSData) return;
		//var_dump($this->JSData);
		;echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $this->tm_start)*10000)*0.1)." after Decode JSON<br/>";
		//var_dump($this->pageVars["DynEvents"]);
		if (!is_array($this->JSData) || empty($this->JSData["DynEvents"])) return; // no data or events
		
		$this->dataContainers = array();
		$this->ResultCommands = array();
		
		//echo "Init Ok"."<br/>";
	}
	
	function Valid() { return ($this->JSData != null); }

	function _getLangFromPageURL() {
		// Detect current language from page name - ex. *.en.php* means language is "en"
		$phpself = $_SERVER['PHP_SELF'];
		if ($phpself) {
			$posExtention = strpos($phpself, '.php');
			if ($posExtention > 4) {
				$posLang = strrpos(substr($phpself, 0, $posExtention), '.'); //var_dump($posLang);
				if ($posLang !== false && $posExtention - $posLang < 8) { // code peut etre >2chars, ex "en-us"
					$lang = substr($phpself, $posLang+1, ($posExtention-$posLang-1)); // ex. "en"
					return ($lang) ? strtoupper($lang) : 'DEFAULT';
				}
			}
		}
		return 'DEFAULT';
	}
	
//____________________________________________________
// = Top-level functions ==================================

	function InitStartupData() {
		
		// obsolete, remove later:
		$this->_AddSpecialDataContainer('ConstVals'); 		// Container of constants
		$this->_AddSpecialDataContainer('FormInputs'); 		// Container of HTML inputs
		
		$this->AddAllSpecialcontainers(); // Constants, HTML Inputs, Formatted Items, Event Parameters - all special (non-action-generated) data containers
		
		$this->mngFormattedItems = 	new OEDynFormattedItemManager($this); // Manager of formatted items/outputs (used mostly in elements' HTML modifiers)
		$this->mngModifiers = 		new OEHTMLModifierManager($this); 	// don't put before mngFormattedItems!
		$this->mngConditions = 		new OEDynConditionManager($this); 	// Manager of conditions (used in IF actions and in FormattedItems' formats)
		$this->mngIterators = 		new OEDynIteratorManager($this); 	// Manager of iterators and iteration zones
		
		// All value pointers/references, aka DBValues
		$this->DBValueManager = new OEDBValueManager($this);
		//var_dump($this->JSData["DynValues"]);
		//echo $this->mngFormattedItems->_IFTags('Before{IF Condition2}If11{IF Condition3}If21{ELSE}Else21{ENDIF}If12{ELSE}Else1{ENDIF}After{IF Condition3}2If1{ENDIF}')."<br/>;";
		;echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $this->tm_start)*10000)*0.1)." after Init Containers<br/>";
				
	}

	function RunEvents($type = '') {
		// Basic alg scheme: RunEvent() -> RunAction() for each action -> update action's target values -> 
		// -> set all "HTML modifiers" that use these values -> copy HTML modifiers to $oei array that is used inline in html code
		
		//return;
		// Run each OnLoad event:
		$ddEvents = $this->JSData['DynEvents'];
		// $count = count($ddEvents);
		foreach ($ddEvents as $ev) {
			if (empty($ev)) continue;
			//var_dump($ev);
			if ($type === '' || strpos($ev["Type"], $type) !== false) {
				$this->RunEvent($ev);
			}
		}
	}
	
//____________________________________________________
// = Events and actions ==================================
	
	function RunEvent(&$ev) {//___________________________
		if (!$ev) return;
		$actionPackNames = $ev['ActionPacks']; if (!$actionPackNames) return; // list of action packs in this event
		$actionPacks = $this->JSData['DynActionPacks']; if (!$actionPacks) return; // all action packs of this page
		$count = count($actionPackNames);
		foreach ($actionPackNames as $apName) {
			$a = OEDynUtils::_FindByName($actionPacks, $apName);
			$this->RunActionPack($a);
			;echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $this->tm_start)*10000)*0.1)." after APack {$a["Name"]}<br/>";
		}
	}
	
	function RunActionPack(&$ap) {//___________________________
		if (!$ap) return;
		$actionNames = $ap['Actions']; if (!$actionNames) return; // list of action in this pack
		$actions = $this->JSData['DynActions']; if (!$actions) return; // all action of this page
		
		$flowStatus = array(); 	// changed by flow control operations like IF, ELSE, STOP etc., 
								// can go to any number of sublevels like in IF .. { IF .. ELSE .. { IF .. ENDIF } ENDIF } .. ELSE .. ENDIF
		
		$count = count($actionNames);
		foreach ($actionNames as $aName) {
			$a = OEDynUtils::_FindByName($actions, $aName);
			$aRslt = $this->RunAction($a, $flowStatus);
			;echo "==<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $this->tm_start)*10000)*0.1)." after Action $aName<br/>";
			if ($aRslt === 'DBAFC_STOP') break; // end action pack
		}
		//var_dump($this->ResultCommands);
		$this->ExecuteCommans(); //!!improve if several packs
	}

	function RunAction($a, &$flow) {//___________________________
		//echo "Action: $a <br/>";
		if (!$a) return;
		
		$action = new OEDynAction($this, $a);
		
		// in case this is a flow-control action like IF, STOP etc.
		$aFlow = $action->FlowControl($flow); // if current action is flow controller, compare it with current flow status, ex. to control IF - ELSE - ENDIF statements
		// $aFlow is current action's flow control value, if this action is flow controller
		// last item in $flow array is current flow status; in combination with $aFlow tells what actions to perform or ignore
		
		//echo "Executing action {$action->Type}<br/>";
		if ($aFlow) return $aFlow; 
		
		$aResult = $action->Run();
		return $aResult;
	}

// = Resulting commands ==================================

	function AddResultCommand($command) 
		{ $this->ResultCommands[] = $command; }
	
	function ExecuteCommans() {
		foreach ($this->ResultCommands as $key=>$command) {
			$pos = strpos($command, ':'); if (!$pos) continue;
			$cType = substr($command, 0, $pos); $cParams = substr($command, $pos+1);
			switch ($cType) {
				case 'Redirect':
					OEDynUtils::Redirect($cParams); // script stopped inside, unless changed for debugging
					break;
			}
			$this->ResultCommands[$key] = ''; // remove command from list
		}
		$this->ResultCommands = array(); // reset commands, so that next actionpacks can create their list
	}
	
// = Final page render ==================================

	function ApplyHTMLModifiersToInlineVars() {
	/*	Inline variables are written in page code as ex.echo $oei['WE12345678..OEDyn0.html'],
		where 'WE12345678..OEDyn0.html' is ID (name) of corresponding HTMLModifier - an object that allows to write (modify) html/css of an HTML tag
		ex. $htmlModifier = DynElements['WE12345678']['HtmlModifiers']['.OEDyn0.html1'] more or less corresponds to $("#WE12345678.OEDyn0").html() in jQuery
		
		Each HTMLModifier contains array of used element's dynamic Properties (ex. ["Image.Title","Image.URL"]),
		and optionally Format (ex. "<a href="{P1}">Image: <b>{P0}</b></a>") and Default value (ex. "Untitled image")
		
		Example of result: $oei['WE12345678..OEDyn0.html'] = "<a href="/Files/Image/image.jpg">Image: <b>My First Database Image</b></a>"
		* * * * * * * */
		
		// Array of inline values (inserted directly into HTML):
		$oei = $this->mngModifiers->ValuesOfAllHTMLModifiers(); // values (html code) of html mpodifiers, default when one or more of the used DBValues is missing
		//var_dump($oei);

		// Now, for each html modifier, its html value is stored into corresponding $oei
		return empty($oei) ? null : $oei;
	}
	
	function InlineIterators() {
	/*	Inline iteration zones are writte in page code like as ex.echo $oeIter['WE12345678.OEDyn0.0']
		where WE12345678 is element's ID, OEDyn0 is dynamic tag class name rather like for HTML modifiers (optional, needed for jQuery),
		and 0 is an index in element's IterationZones array.
		
		Each iteration zone may contain usual html code (static and/or dymamic) and several iterators that may contain subiterators.
		Iteration zone can't contain another iteration zone.
	* * * */
	
		$oeIter = $this->mngIterators->GenerateAllIterationZones();
		
		return empty($oeIter) ? null : $oeIter;
	}
	
//____________________________________________________
// = Data containers ==================================

	function &_AddDataContainerFromArray($name, &$ar) {
		$tr = null; 
		if (empty($name)) return $tr; // return $tr=null because function returns reference 
		// not that $ar can be null, meaning empty or virtual container
		
		if (strpos($name, 'FormInputs') !== false) { // special type of container
			$tr = new OEHTMLValsContainer($name);
			if (!empty($ar)) $tr->SetFromAssociativeArray($ar);
			$tr->ObtainPOSTValues();

		} else if (strpos($name, 'ConstVals') !== false) { // special type of container
			$tr = new OEConstSrcContainer($name);
			$tr->SetFromAssociativeArray($ar); 
			$tr->TranslateLocalizableValues($this->currLang);
			
		} else if (strpos($name, 'FormattedItems') !== false) { // special type of container
			$tr = new OEFormattedItemsContainer($name, $this);
			$tr->SetFromAssociativeArray($ar); 
			$tr->TranslateLocalizableValues($this->currLang);
			
		} else if (strpos($name, 'EventParams') !== false) { // special type of container
			$tr = new OEEventParamsContainer($name, $this); // virtual container, contains no physical data, instead gets value of DBValue used by element's property
			
		} else if (strpos($name, 'URLParams') !== false) { // special type of container
			$tr = new OEPageParamsContainer($name); // session values container
			
		} else if (strpos($name, 'Session') !== false) { // special type of container
			$tr = new OESessionContainer($name); // session values container
			
		} else if (strpos($name, 'Cookie') !== false) { // special type of container
			$tr = new OECookieContainer($name); // cookie values container
			
		} else if (strpos($name, 'AutoVals') !== false) { // special type of container
			$tr = new OEAutoContainer($name, $this); // special "auto" values container
			if (!empty($ar)) $tr->SetFromAssociativeArray($ar);
			$tr->InitValues($this);
			
		} else {
			if (empty($ar)) return $tr; // check
			$tr = new OEDataContainer($name); 
			$tr->SetFromAssociativeArray($ar); 
		}
		
		$this->AddContainer($tr );
		return $tr;
	}
	
	function &_AddSpecialDataContainer($name) { // partly obsolete, delete later
		$r = null;
		if (array_key_exists($name, $this->JSData)) {
			$r =& $this->_AddDataContainerFromArray($name, $this->JSData[$name]);
			// note: if container with the same name was already added before, it will be overwritten by this one (so it's safe to add same container several times)
		}
		return $r;
	}

	
	function AddAllSpecialcontainers() { // Constants, HTML Inputs, Formatted Items, Event Parameters - all special (non-action-generated) data containers
		if (!isset($this->JSData['Containers'])) return; // empty($this->JSData) || 
		$allContainers = $this->JSData['Containers'];
		
			// required special containers, even when not in JSON:
		$n = null;
		$this->_AddDataContainerFromArray('URLParams', $n);
			$this->_AddDataContainerFromArray('Session', $n);
			
		foreach($allContainers as $name=>$json) {
			$this->_AddDataContainerFromArray($name, $json);
		}
		
	}
		
	function AddContainer(&$container) {
		if (!$container) return;
		$name = $container->GetName(); 
		if ($name) {
			// if container with the same name already added:
			$count = count($this->dataContainers);
			for ($i=0; $i < $count; $i++) {
				if ($name === $this->dataContainers[$i]->GetName()) {
					$this->dataContainers[$i] =& $container; // replace old container with same name by new one
					return;
				}
			}
		}
		$this->dataContainers[] =& $container; // add new container
		return;
	}
	
	function AddContainers(&$arContainers) {
		if (empty($arContainers))
			return; 
		else {
			$count = count($arContainers);
			for ($i=0; $i<$count; $i++) $this->AddContainer($arContainers[$i]);		
		}
	}
	
	function GetContainerByName($name) {
		return $this->GetContainerRefByName($name);
	}

	// Same as GetContainerByName() but returns a reference to actual container instead of a copy
	function &GetContainerRefByName($name) {
		//var_dump($this->dataContainers);
		$rnull = null;
		if (!$name) return $rnull;
		
		// if $name is full name/ID of data, ex "B:FormattedItems.FItem1", use only part corresponding to container name, ex "B:FormattedItems":
		$pos = strpos($name, '.'); if ($pos > 0) $name = substr($name, 0, $pos);
		
		foreach($this->dataContainers as $i=>$container) {
			if ($container->Name === $name) return $this->dataContainers[$i];
		}
		return $rnull;
	}
	
//____________________________________________________
//	== Values - "source" data and references/pointers (DBValues) ===========================
// See OEDBClasses.php => OEDBValueManager class
	function _GetPropertyValueIndex(&$el, $propertyName) {
		// example: $propertyName = "Image.URL": get $el's property "Image.URL" and return index of value (in DBValues array) linked to it
		if (!$propertyName || !$el || empty($el['Properties'])) return -1;
		$property = OEDynUtils::_FindInArray($el['Properties'], $propertyName); if ($property === null) return -1;
		return intval($property); // index in DBValues array
	}
	
	function GetDBValue($vInd, $iRow = -1, $pKeyValue = false) {
		if (is_int($vInd)) // normal mode
			return $this->DBValueManager->GetDBValue($vInd, $iRow, $pKeyValue);
		else // special suffix instruction added, like "4.SHA" to return SHA(DBValues[4])
			return $this->DBValueManager->GetDBValueWithSuffix($vInd, $iRow, $pKeyValue);
	}
	
	// Given $fullID = "ContainerName.DataColumnName", gets data item from ContainerName container
	function GetDataByFullID($fullID, $iRow=-1) {
		if (!$fullID) return null;
		$pos = strpos($fullID, '.'); if (!($pos > 0)) return null;
		$containerName = substr($fullID, 0, $pos); // container name
		$dataID = substr($fullID, $pos+1); // data column name
		
		//echo "GetDataByFullID from $containerName => $dataID<br/>";
		$container =& $this->GetContainerRefByName($containerName); if (empty($container)) return null;
		//echo 'Container:';var_dump($container);
		$r = $container->GetValue($dataID, $iRow);
		//echo '<br>Value:';var_dump($r);
		return $r;
				
	}

	function GetIterRowByFullID($fullID) { // get iteration row for given data, from its data container
		if (!$fullID) return -1;
		$pos = strpos($fullID, '.'); if (!($pos > 0)) return -1;
		$containerName = substr($fullID, 0, $pos); // container name
		$container =& $this->GetContainerRefByName($containerName); if (empty($container)) return -1;
		$dataID = substr($fullID, $pos+1); // data column name
		
		if (isset($container->iRows[$dataID])) return $container->iRows[$dataID];
		return (isset($container->iRow)) ? $container->iRow : -1;
	}

	
//____________________________________________________
//	== HTML modifiers ===========================================
// HTML modifier ends up as a dynamic part of page html code, usually represented by inline php code like ?php_echo(oei[modifierFullName]); ?

	function EvaluateHTMLModifier($mFullName) {
		return $this->mngModifiers->GetValue($mFullName);
	}

//____________________________________________________	
//	== Formatted items, Conditions (bool expressions) ============================

	function EvaluateFormattedItem($fiName, $nRow = 0, $modeGetNumRows = false) {
		return $this->mngFormattedItems->GetFIValue($fiName, $nRow, $modeGetNumRows);
	}

	function EvaluateConditionByInd($ind) {
		if (!$this->mngConditions) return false; else return $this->mngConditions->EvaluateCondition($ind);
	}
	
	function EvaluateCondition($cName) {
		if (!$this->mngConditions) return false; else return $this->mngConditions->EvaluateCondition($cName);
	}
	

} // end class OEDynManager


