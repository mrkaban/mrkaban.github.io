<?php
/*_______________________________________________
|                                                |
|    ©2012 Element Technologie - openElement     |
|________________________________________________|

	ATTENTION! SAVE IN UTF8 WITHOUT BOM, DO NOT EDIT/SAVE IN VISUAL STUDIO
	
	Dynamic/DB tools
	Classes used in OEDB.php
*/


 // .......................................................................................... \\
//	== Page's dynamic action =================================================================  \\
class OEDynAction {
	
	var $Manager; // parent OEDynManager object
	var $JSData; // element of deserialised array of action data (from $jsonPageVars)
	
	var $Name;
	var $Type;
	var $Params;
	var $AltResultName;
	
	var $flowController;
	
	var $RequestPack;
	
	var $ResultContainer; // for actions that provide result output
	
	function __construct(&$manager, $action) { // constructor
		if (!$action || !$manager) return;
		$this->Manager =& $manager;
		$this->JSData = $action;

		//var_dump($a);
		$this->Name = $action['Name'];
		$this->Type = $action['Type'];
		$this->Params = $action['Params'];
		
		$this->AltResultName = $this->_GetParamSimpleValue('AltResultAction');
		
		$this->Prepare();
	}
	
	// Action parameters and output ///////////////////////////
	
	function _GetParamSimpleValue($pName) {
		$params = $this->Params; if (!isset($params[$pName])) return null;
		return $params[$pName];
	}
	
	function _GetParamDBValue($pName) {
		$params = $this->Params; if (!isset($params[$pName])) return null;
		$targetValInd = intval($params[$pName]);
		return $this->Manager->DBValueManager->GetDBValue($targetValInd, 0);	
	}
	
	function _AddOutput($name, $val) {
		if (!isset($this->ResultContainer))
			$this->ResultContainer = new OEDataContainer($this->_ResultName());
		$this->ResultContainer->Values[$name][] = $val;
	}
	
	function _ResultName() { // name of result container, usually = action name
		$r = (isset($this->AltResultName)) ? $this->AltResultName : $this->Name;
		return $r;
	}
	
	function _StoreResultContainer() {
		if (!empty($this->ResultContainer)) { // see _AddOutput()
			$this->Manager->addContainer($this->ResultContainer);
		}
	}
	
	// Run action /////////////////////////////////////////////
	
	
	function Run() {
	
		//echo "Requesting {$this->Name} type {$this->Type} <br/>";
		
		if ($this->flowController) {
			return null; // ex DBA_IF, STOP etc.
		}
		
		// condition for running this action, true by default
		$conditionOk = (isset($this->Params['Condition'])) ? $this->Manager->EvaluateCondition($this->Params['Condition']) : true;
		$conditionNOT= (isset($this->Params['ConditionNOT'])) ? $this->Params['ConditionNOT'] : false;
		if ($conditionNOT) $conditionOk = !$conditionOk; // invert condition result
		if (!$conditionOk) return null; // condition of running this action evaluated to false
			
		$r = null;
		$handled = true;
		
		echo "Executing <span style='color:#008020'>{$this->Name}</span> type {$this->Type} <br/>";
		switch ($this->Type) {
			case 'DBSessionWrite':
				$r = $this->__WriteToSession(); break;
			case 'DBCookieWrite':
				$r = $this->__WriteToCookie(); break;
			case 'DBPHPCustom':
				$r = $this->__RunPHPCustomCode(); break;
			case 'DBARandomValue':
				$r = $this->__RandomVal(); break;
			case 'DBFlag':
				$r = $this->__SetFlag(); break;
			case 'DBA_Redirect':
				$r = $this->__Redirect(); break;
			case 'DBA_SendMail':
				$r = $this->__SendMail(); break;
			case 'DBA_ErrorStop': // error or output + stop the script
				$r = $this->__OutputStop(); break;
			default:
				$handled = false;
		}
		
		if ($handled) { // action completed
			if (!empty($this->ResultContainer)) { // see _AddOutput()
				$this->Manager->addContainer($this->ResultContainer);
			}
			return $r;
		}
		
		// DB actions ///////////////////////////
		
		//var_dump($this);
		//$tm_start2 = microtime(true);
		if ($this->Type !== 'DBGet' && $this->Type !== 'DBModify' && $this->Type !== 'DBDelete' && $this->Type !== 'DBStruct') return null; //!!temp
		if (!$this->RequestPack) return null; //!!
		$this->RunDBRequests();
		//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm_start2)*10000)*0.1)." for RunDBRequests<br/>";
		
		// $this->_LinkTargetValues(); not used atm
		
	}
	
	function FlowControl(&$flow) { 
	// Affects flow control. Returns either null (means current action is not flow controller and needs to be run) or flow modification type; 
	// can modify flow status held in $flow array
		
		// This operation's flow modifier type:
		
		$currFlow = (count($flow)) ? end($flow) : null; // current flow control status if any, ex. "jump to endif"
		$currSkipping = ($currFlow) ? (substr($currFlow, 0, 6) === 'JUMPTO') : false; // true if currently skipping actions until certain action like Else or EndIf
		$aFlow = null; // current action's flow control type
		
		switch ($this->Type) {
			case 'DBAFC_IF': // evaluate IF condition to choose what part to perform - before or after ELSE
				if ($currSkipping) {
					$currFlow = $flow[] = $aFlow = 'JUMPTOENDIF'; // as this action should be ignored, skip everything until ENDIF corresponding to this IF
				} else {
					echo "Action IF : <span style='color:#F08000'>{$this->Name}</span>:: ";
					$conditionName = (isset($this->Params['Condition'])) ? $this->Params['Condition'] : '';
					$conditionOk = $this->Manager->EvaluateCondition($conditionName);
					$conditionNOT= (isset($this->Params['ConditionNOT'])) ? $this->Params['ConditionNOT'] : false;
					if ($conditionNOT) $conditionOk = !$conditionOk; // invert condition result
					if ($conditionOk) $aFlow = 'SKIPELSE'; else $aFlow = 'JUMPTOELSE';
					$currFlow = $flow[] = $aFlow; // start of new IF block, push the status at the end of flow array
				}
				break;
			case 'DBAFC_ELSE':
			case 'DBAFC_ENDIF':
			case 'DBAFC_STOP':
				$aFlow = $this->Type;
				break;
			default:
		}
		
		// Combine action's flow modifier with current flow status to modify the flow status:
		
		//echo "<br/> == Action {$this->Type}: aFlow={$aFlow} <br/>";//var_dump($flow);
		
		if ($currFlow) { // we are inside the IF/ELSE block
			if ($aFlow === 'DBAFC_ENDIF') { // end of the IF block - return to higher level no matter what current level's flow status is
				//echo "End block lvl ".count($flow)."<br/>";				
				array_pop($flow); // return to flow status of upper level
				return 'ENDBLOCK';
			}
			
			switch ($currFlow) { // according to current block's flow status, sometimes need to skip certain actions or change flow control status
				case 'SKIPELSE': // happens after IF action with condition evaluated True
					if ($aFlow === 'DBAFC_ELSE') { // skip entire ELSE block
						$flow[count($flow)-1] = 'JUMPTOENDIF';
						return 'JUMPTOENDIF'; // skip all action until ENDIF
					}
					break; // otherwise continue performing this action

				case 'JUMPTOELSE': // happens after IF action with condition evaluated False
					if ($aFlow !== 'DBAFC_ELSE') return 'SKIPACTION'; // do not perform this action as it is before ELSE //  || $aFlow !== 'DBAFC_ENDIF'
					$flow[count($flow)-1] = 'NORMAL'; // proceed normally
					return 'NORMAL'; // reached ELSE or ENDIF, continue executing next actions normally
				case 'JUMPTOENDIF': // happens after reaching ELSE block when previous IF evaluated to True
					// following shoudl always happen - see code before Switch
					if ($aFlow !== 'DBAFC_ENDIF') return 'SKIPACTION'; // do not perform this action as it is before ENDIF
					break;
					
				// following should never happen:
				case 'DBAFC_ENDIF':
				case 'DBAFC_ELSE':
				case 'DBAFC_STOP':
					;echo '!!Error, flow combination '.$flow.':'.$aFlow;
					break;
				default:
			}
		}
		
		return $aFlow;
	}
	
	function Prepare() {
		
		$this->flowController = ($this->Type && substr($this->Type, 0, 6) === 'DBAFC_');
		if ($this->flowController) return;
		
		if ($this->Params && !empty($this->Params['Src'])) $this->_PrepareSQL(); //  && $this->Params['Src']
	}
	
	function _PrepareSQL() {
		
		// Replace all "placeholder values" in this request pack that are linked to a DBValues item by current value/result of the value:
		// ex. replace "__VAL1" by "Yes" when DBValues[1] = "FormInputs.WExx.radio.WExx" (it means linked to a specified html radiogroup) 
		// and current (ex. default) value/result for this radiogroup = "Yes"
		
		// In special cases, a corresponding primary key value may be requested instead of the value itself, ex. "__PK1" instead of "__VAL1"
		
		// When values are provided directly (not as linked placeholder), strings are put into "", so there's no conflict possible with special prefixes above
		
		$this->RequestPack = null;
		
		if (!isset($this->Params) || !isset($this->Params['Src'])) return; //!!
		$aSQL = $this->Params['Src']; // pack of sql requests in json format
		if (substr($aSQL, -1) !== '}' && substr($aSQL, -1) !== ']') return; //!!
		$this->RequestPack = OEDynUtils::decodeJson($aSQL, false); // decode as Object (not Array)
		if (empty($this->RequestPack)) return;

		$sqlStringQuote = OESQLBase::SQLInstance()->StringQuoteChar(); if (!$sqlStringQuote) $sqlStringQuote = '"';
		
		
		//var_dump($this->RequestPack);
		
		$valPref = '__VAL'; // same as DBHelp.SQLPackLinkValue in VB code
		$pkeyPref = '__PK'; // same as DBHelp.SQLPackLinkPKey in VB code
		foreach ($this->RequestPack as &$rq) {
			// $rType = $rq->Type;
			// $rCode = $rq->Code;
			$rValues =& $rq->Values;
			$rVTypes =& $rq->ValueTypes; // string, ex 'issd'
			
			// security for non-prepared mode - normally not needed but kept for future modifications or special cases:
			$rq->ValuesSecured = array();
			$rValuesSecured =& $rq->ValuesSecured;
			
			// replace placeholders corresponding to DBValue indexes with respective values
			$rValCount = (empty($rValues)) ? 0 : count($rValues);
			for ($i=0; $i<$rValCount; $i++) { // ex. $rValues[$i] = "__VAL4.SHA" means sha1(DBValues[4])
				
				if (strpos($rValues[$i], $valPref) === 0) { // this placeholder corresponds to one of DBValues items
					// ex. "__VAL4.SHA" => "4.SHA" => use DBValues[4] and hash it
				
					$valInd = substr($rValues[$i], strlen($valPref)); // ex. 4
					// replace index "link" by current value of DBValues[$valInd]:
					$rValues[$i] = $this->Manager->DBValueManager->GetDBValueWithSuffix($valInd); 
					// replace with actual value: get first data row, or in case of iterators the row currently iterated
					// ex. $rValues[$i] = "7110eda4d09e062aa5e4a390b0a572ac0d2c0220"
					
					// for non=prepared mode (remove later?):
					
					// type - string or numeric:
					$isString = false;
					if (isset($rVTypes[$i])) { // consider value type if it is passed through JSON
						if ($rVTypes[$i] === 's') $isString = true;
					}
					
					$rValuesSecured[$i] = OESQLBase::SQLInstance()->secureSQLValue($rValues[$i], !$isString); // basic protection against injections, depend on string or numeric type
					//echo "SQL value $i:";var_dump($rValues[$i]);
					if ($rValuesSecured[$i] === null) {
						$rValuesSecured[$i] = 'NULL'; //!!check for prepared statements
						//echo ' - SQL value '.$valInd.' is NULL - ';
					} else if ($isString) {
						$rValuesSecured[$i] = $sqlStringQuote.$rValuesSecured[$i].$sqlStringQuote; // if it's string, wrap quotes ""
					}
					
				} else if (strpos($rValues[$i], $pkeyPref) === 0) { // this placeholder links to Primary Key corresponding to container+row where DBValue-pointed data is, rather than to this data itself
					// ex. "__PK4" => use primary key value of the result row containing DBValues[4]
					$valInd = substr($rValues[$i], strlen($pkeyPref));
					$rValues[$i] = $this->Manager->DBValueManager->GetDBValueWithSuffix($valInd, 0, true); //!!iterators!!  // use primary key value
					if (isset($rVTypes[$i])) { // force value type to int
						$rVTypes[$i] = 'i';
					}
					
					$rValuesSecured[$i] = OESQLBase::SQLInstance()->secureSQLValue($rValues[$i], true); // basic protectyino against injections, true to treat value as numeric
					// primary key value considered integer, don't put into "" //!!check !!improve for custom keys

					//echo "with value[$valInd]={$rValues[$i]}";
				}
			}	
		}
		//echo "Updated request pack:"."<br/>";var_dump($this->RequestPack);//var_dump($this->Manager->DBValueManager->DBValues);
		
	}
	
	function RunDBRequests() {
		//echo "SQL request pack:";var_dump($this->RequestPack);
		//var_dump(OESQLBase::SQLInstance());
		//$tm = microtime(true);
		$arResults = OESQLBase::SQLInstance()->runSQLPack($this->RequestPack); //!!fix same name ??
		//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for runSQLPack<br/>";
		$fusionContainer = null;
		$cName = $this->_ResultName();
		if (!empty($arResults)) {
			foreach ($arResults as &$container) { 
				//var_dump($container);
				$container->SetName($cName);
				// Manage primary keys columns with non-standard names:
				if (isset($this->Params['PKeys'])) {
					foreach($this->Params['PKeys'] as $pkey) {
						$container->SetPrimaryKey($pkey);
					}	
				}
				
				if (!$fusionContainer) 
					$fusionContainer =& $container;
				else { // merge containers into one:
					$fusionContainer->AddFromContainer($container);
				}
				//break;//!! now only one container per action //!!fix merge several containers
			}
			unset($container); // break the reference (see php manual)
		}
		
		//echo $fusionContainer->Name.':';var_dump($fusionContainer->Values);
		
		//foreach ($arResults as $tr) {echo $tr->toJSON(); }
	
		$this->Manager->addContainer($fusionContainer);
		//echo "Request completed, results:";//var_dump($this->Manager->dataContainers[3]);
	}
	
	// Target values are "output" of this action: DBValues updated by action's results (ex. pack of db queries)
	function _LinkTargetValues() {
	if (empty($this->JSData['TargetValues'])) return;
		$aVals = $this->JSData['TargetValues']; // ex. [[0, “TableUsers.Image_URL”], [1, “TableUsers.Image_Title”]]
		// each element in TargetValues array is itself an aray of 2 items, [0] = value index, [1] = full address/name of source
		//if (empty($aVals)) return;
		foreach ($aVals as $pair_v_s) {
			// $pair_v_s is a pair of DBValue index and source id, ex. [0, “TableUsers.Image_URL”]
			//var_dump($pair_v_s);
			$iVal = $pair_v_s[0];  // ex. 0
			$src = $this->JSData['Name'].'.'.$pair_v_s[1]; // ex. "DBGet0.TableUsers.Image_URL"
			$this->Manager->DBValueManager->DBValues[$iVal] = $src;
			
		}
	}
	
	function __Redirect() {
		//echo '__Redirect';
		$targetURL = $this->_GetParamDBValue('URL'); 
		if (!$targetURL) {
			// return null; 
			ob_end_clean();
			echo 'Error OED01 - redirection url not set in action '.$this->Name;
			exit(-1); // too dangerous to proceed
		}
		
		$passURLParams = $this->_GetParamSimpleValue('PassURLParams');
		if ($passURLParams) { // include/add current URL params to the target URL
			$currentURLParams = $_SERVER['QUERY_STRING']; // ex p1=1&p2=2
			if ($currentURLParams) { // add parameters, after params already in targetURL if any
				$stAdd = ((strpos($targetURL, '?')) ? '&' : '?').$currentURLParams;
				// temporarily remove anchor/fragment - the part after #
				$parsedTargetURL = parse_url($targetURL);
				if (isset($parsedTargetURL['fragment'])) { // but BEFORE the fragment #, ex www.target.com?p=0<&p1=1&p2=2>#anchor
					$targetURL = str_replace('#'.$parsedTargetURL['fragment'], '', $targetURL).stAdd.$parsedTargetURL['fragment'];
				} else // no #
					$targetURL .= $stAdd;
			}
		}
		
		$this->Manager->AddResultCommand('Redirect:'.$targetURL); return 'DBAFC_STOP'; // add redirect command and stop executing action pack
		//echo "Redirect disabled to: <a href='$targetURL'>$targetURL</a><br/>";
	}
	
	function __SendMail() {
		//echo '__SendMail';
		
		$to 	= $this->_GetParamDBValue('To');
		$from 	= $this->_GetParamDBValue('From');
		$subj 	= $this->_GetParamDBValue('Subject');
		$body 	= $this->_GetParamDBValue('Body');
		$isHTML = $this->_GetParamSimpleValue('IsHTML');
		
		$mailConf = $this->_GetParamSimpleValue('ConfSendMail');

		//echo "$from $to $mailConf";
		if (!$from || !$to || !$mailConf) return null; // missing addresses or mail configuration
		
		if (!isset($subj)) $subj = ''; if (!isset($body)) $body = '';
		if ($subj.$body === '') return null; // neither subject nor body specified
		
		$errorMsg = OEDynUtils::SendEmailSimple('DEFAULT', $mailConf, $from, $to, '', $subj, $body, $isHTML);
		if ($errorMsg) {
			$this->_AddOutput('_ErrorMessage', $errorMsg);
			echo "Mail error: $errorMsg<br/>";
		}
		
		return null;
	}
	
	function __WriteToSession() {
		try {
			$params = $this->Params; if (!isset($params['Source']) || !isset($params['ValName'])) return null;
			$valueToWrite = $this->_GetParamDBValue('Source');
			$valName = $params['ValName'];
			$_SESSION[$valName] = $valueToWrite;
			//echo 'Session:';var_dump($_SESSION);
		} catch (Exception $ex) {}
		return null;
	}
	
	function __WriteToCookie() {
		try {

			$params = $this->Params; if (!isset($params['ValName'])) return null;
			
			$valName = $this->_GetParamSimpleValue('ValName');
			$cookieName = "oeCookies[$valName]"; // to be able to write several cookies in one script
			$valueToWrite = null;
			if (isset($params['Source']))
				$valueToWrite = $this->_GetParamDBValue('Source');
	
			if ($valueToWrite === null) { // delete cookie if no value specified
				setcookie ($cookieName, "", time() - 400000, '/');
				return;
			}
			
			//echo "Cookie: writing $cookieName = $valueToWrite<br>";
			$timeMins = $this->_GetParamDBValue('ExpireMinutes'); $timeMins = ($timeMins) ? intval($timeMins) : 0;
			$timeDays = $this->_GetParamSimpleValue('ExpireDays'); $timeDays = ($timeDays) ? intval($timeDays) : 0;
			$expireSec = $timeMins * 60 + $timeDays * 60 * 60 * 24;
			if ($expireSec !== 0) $expireSec = time() + $expireSec; // if 0, keep 0 to make cookie stay till browser session ends
			
			// set httponly flag if php version allows:
			if (phpversion() >= '5.2') {
				setcookie($cookieName, $valueToWrite, $expireSec, '/', null, false, true); // httponly = true
				
			} else {
				setcookie($cookieName, $valueToWrite, $expireSec, '/');
			}
			
		
		} catch (Exception $ex) {}
		return null;
	}
	
	function __RunPHPCustomCode() {
		//echo 'Custom code';
		$params = $this->Params; if (!isset($params['Function'])) return null;
		
		$fnName = $params['Function'];
		if (!$fnName || !function_exists($fnName)) return null;
		
		$p = array(); // function parameters
		foreach($params as $key=>$value) { // key = "$p[i]", $value = index of DBValue containing parameter value
			if ($key[0] !== '$') continue;
			$pInd = intval(substr($key, 3, strlen($key)-1-3)); // param index, ex 0
			$p[$pInd] = $this->_GetParamDBValue($key);
		}
		
		$out = array(); // function results, besides "main" result = return
		
		$mainResult = $fnName($p, $out); // call user-written function generated in VB
	
		// store results - keep in sync with VB:
		$this->_AddOutput('_Result', $mainResult); // main return result
		if (!empty($out)) { // additional output
			foreach ($out as $key=>$value) {
				$this->_AddOutput((string)$key, $value);
			}
		}
		
	}
	
	function __SetFlag() {
		$this->_AddOutput('Yes', 1);
		//echo 'Flag set: '.$this->Name.'<br>';
	}
	
	function __RandomVal() {
		
		$params = $this->Params; 
		//var_dump($params['Length']);
		if (!isset($params['Length'])) return null;
		//echo 'CALLING RandomVal ';
		
		$r = OEDynUtils::RandomVal(
			$this->_GetParamSimpleValue('UChars'),
			$this->_GetParamSimpleValue('LChars'),
			$this->_GetParamSimpleValue('Digits'),
			$this->_GetParamDBValue('Length'),
			$this->_GetParamSimpleValue('NoAmbiq'));
			
		$this->_AddOutput('_Result', $r);
	}
	
	function __OutputStop() {
		$params = $this->Params; 
		$message = (isset($params['ErrorMsg'])) ? $this->_GetParamDBValue('ErrorMsg') : "Unknown error in website dynamic functionality";
		OEDynUtils::OutputStop($message, !$this->Manager->modeAJAX); // stop PHP
	}
	
} // end class OEDynAction


 // .......................................................................................... \\
//	== HTML modifiers (outputs) =============================================================  \\
class OEHTMLModifierManager {
/*	Manages HTML modifiers - objects that replace a piece of HTML code with dynamic content.
	Typical example of HTML modifier usage is an inline php code "echo $oei['WEd9669a6ed2..OEDynTag1.style']", 
	which is generated by Render of element WEd9669a6ed2 to make CSS style change depending on dynamic values.
	Besides inline PHP, JQuery can use the full name of modifier to apply its value after AJAX request.
	
	HTML modifier info is contained by parent element, where it has a "short" name (ex. ".OEDynTag1.style"),
	but is usually referenced by it's "full" name containing element's id (ex. "WEd9669a6ed2..OEDynTag1.style")
	* * * */

	var $Manager; // parent OEDynManager object
	var $FItems; // manger of formatted items (OEDynFormattedItemManager)
	
	var $AssociatedFItems; // cache dictionary of html modifier's full id => formatted item, to accelerate search
	
	function __construct($mainManager) {
		$this->Manager =& $mainManager;
		$this->FItems =& $mainManager->mngFormattedItems;

		$this->_AssociateHTMLModifiers();
	}

	/*function &__ElementByName($name) {
		if (!$elName) return null;
		foreach ($this->JSData['DynElements'] as $elName=>&$el) { if ($elName === $name) return $el; }
		unset($el); return null; // disconnect reference to last array element (see PHP manual)		
	}*/
	
//	______________________________________	
//	Association of Formatted Items with HTML modifiers, who are the principal (but not the only) users of Formatted Items:

	function _AssociateHTMLModifiers() {
		// for each HTMLModifier of each element on this page, remember name of its formatted item (usually names of modifier and item are equal)
		$this->AssociatedFItems = array();
		foreach ($this->Manager->JSData['DynElements'] as $elName=>$el) { // each element
			$modifiers = $el['HtmlModifiers']; if (empty($modifiers)) continue;
			foreach($modifiers as $mName => $modifier) { // each element's modifier
				$fullModifierName = $elName.".".$mName; // name used in inline php code inside html code of the page
				if (!isset($modifier['FormattedItem'])) continue;
				$this->AssociatedFItems[$fullModifierName] = $modifier['FormattedItem']; // link modifier "inline name" with formatted item name
				// ex. {"WEd9669a6ed2..OEDynTag1.style":"WEd9669a6ed2..OEDynTag1.style"} 
				// (in most cases formatted item name = modifier name, but in certain cases they may differ)
		}	}	
		//echo "Associated FItems:";var_dump($this->AssociatedFItems);
	}
	
	function FItemName($modifierFullName) { // get formatted item NAME for html modifier with given full name
		if (!$modifierFullName || empty($this->AssociatedFItems[$modifierFullName])) return null;
		return $this->AssociatedFItems[$modifierFullName];
	}

	function FItemValue($modifierFullName, $iRow = 0) { // get formatted item VALUE for html modifier with given full name
		$fiName = $this->FItemName($modifierFullName);
		//echo "FItemValue for $modifierFullName";
		return $this->FItems->GetFIValue($fiName, $iRow);
	}

	
	function GetValue($mFullName, $iRow = 0) { // Alias of FItemValue - Get current value of HTML Modifier
		return $this->FItemValue($mFullName, $iRow);
	}
	
	function _AllUsedDBValues($mFullName, &$accumArDBVals) { // adds to $accumArDBVals (without repeating 2 times) indexes of all DBValues used to "calculate" HTML modifier
		if (empty($this->AssociatedFItems[$mFullName])) return;
		$fItemName =$this->AssociatedFItems[$mFullName]; // name of formatted item associated with modifier
		if ($fItemName) $this->FItems->AllUsedDBValues($fItemName, $accumArDBVals);
	}
	
	
	function ValuesOfAllHTMLModifiers() { // for all HTMLModifiers of all elements on this page,
		// find their currently known values (use their default value if at least one of used Properties' values is missing)
		$allModifiers = array();
		//var_dump($this->FItems);
		foreach ($this->AssociatedFItems as $mFullName=>$fiName) {
			//echo "Iterating formatted items $mFullName $fiName<br/>";
			$modifierValue = $this->FItems->GetFIValue($fiName, 0); //!!iterators!!
			$allModifiers[$mFullName] = $modifierValue;
		} //echo "<br/> =================================== <br/> RESULT HTML MODIFIERS:";var_dump($allModifiers);
		return $allModifiers;
	}	
	
}


 // .......................................................................................... \\
//	== Formatted items =======================================================================  \\
class OEDynFormattedItemManager {
	// Manager of formatted items/outputs (used mostly in elements' HTML modifiers)
	// Formatted item contains format string with placeholders and list of indexes of used DBValues (placeholder links to this list's item, not directly to DBValues)
	// Example of Placeholder: {P1} means second item in list of used DBValue indexes (which may be = 0 to use 1st DBValues item)
	// Format string may include IF blocks: {IF Condition4}green{ELSE}{IF Condition5}black{ELSE}red{ENDIF}{ENDIF}, 
	// where "Condition4" and "Condition5" are names of conditions managed by OEDynConditionManager
	// "{" characters are coded as "{\"
	
	var $Manager; // parent OEDynManager object
	var $Container; // data container where each item is a deserialised FormattedItem object. Does NOT contain items inherited from base template/layer/calque
	// var $InheritedContainers; // there may be are containers for templates/calques; see GetFIValue
	
	var $currLang; // page language/culture
	
	var $currFItemFullID; // full ID of currently processed formatted item, ex. "B:FormattedItems.FItem1" - item coming from base layer
	
	function __construct($mainManager) {
		$this->Manager =& $mainManager;

		$this->Container =& $mainManager->GetContainerRefByName('FormattedItems'); // all formatted items except those inheritted from templates/layers/calques
		//if (empty($this->Container)) $this->Container =& this->_AddSpecialDataContainer('FormattedItems'); // old var file format, remove later
		
		$this->currLang = $mainManager->currLang;
	}

	function GetFItemByName($fiName, &$fullID) {
		if (!$fiName) return null;

		$fullID = $fiName;
		if (strpos($fiName, 'B:') === false && strpos($fiName, 'FormattedItems.') !== 0) { // items belongs to page itself, not inherited from base template/calque
			if (!$this->Container) return null;
			//echo "Getting FItem: $fiName<br/>";
			// DON'T ex WExxxx..dynTag0.html1 $pos = strpos($fiName, '.'); if ($pos !== false) $fiName = substr($fiName, $pos+1); // 15.07.13 remove container name
			$ftItem = $this->Container->GetValue($fiName); // get FormattedItem object from container "FormattedItems" ' , $nRow
			$fullID = $this->Container->Name.'.'.$fiName;
		} else { // item comes from parent template/layer/calque, ex. $fiName = "B:FormattedItems.FItem1"
			//echo "Getting B:FItem: $fiName<br/>";
			$ftItem = $this->Manager->GetDataByFullID($fiName);
		}
		//echo "FormattedItem ";//var_dump($ftItem);echo "<br/>";				
		return $ftItem;
	}
	
	// Get current value by applying data and condition evaluation to placeholders
	// in $modeGetNumRows=true (used for iterations), returns max num of rows if some placeholders in format contain suffixes resultin in array - ALLROWS etc., or 0 if no arrays
	function GetFIValue($fiName, $nRow = 0, $modeGetNumRows = false) {
		if (!$fiName) return null;

		$fullID = $fiName;
		$ftItem = $this->GetFItemByName($fiName, $fullID);
		//echo "FormattedItem ";//var_dump($ftItem);echo "<br/>";				
		if (!$ftItem /*|| !isset($ftItem['Values'])*/) {
			//var_dump($fiName);
			;echo " !! ERRR GetFIValue($fiName):";//var_dump($ftItem);var_dump($this->Container);
			return null;
		}
		$deflt = (isset($ftItem['Default'])) ? $ftItem['Default'] : null; // ex. "No title"
		$format = (isset($ftItem['Format'])) ? $ftItem['Format'] : null; // ex. "<a href="{P1}">Image: {P0}</a>"
		//echo "FItem $fiName: $format $deflt {$ftItem['Values']}"."<br/>";		
		$values = (isset($ftItem['Values'])) ? $ftItem['Values'] : null;
		
		$xFullID = $this->currFItemFullID; // in case there is recusive calculation, when formatted item's placeholders reference other formatted items
		$this->currFItemFullID = $fullID;
		$r = $this->_CalculateFormatted($values, $format, $deflt, $nRow, $modeGetNumRows);
		$this->currFItemFullID = $xFullID;
		
		/*if ($GLOBALS['oephpDebugMode']) {
			if (OEDataContainer::$iCurrentRow < 1) { // don't show for iterations other than 1
				//echo 'FItem <i>'.$fiName.'</i>=';var_dump($r);echo '::<br>';
			}
		}*/
		return $r;
		
	}
	
	// returns array of indexes of all DBValues used to calculate this modifier: used directly, in conditions, or in sub-items (when placeholder uses another formatted item)
	function AllUsedDBValues($fiName, &$accumArDBVals) {
		if (!$accumArDBVals) $accumArDBVals = array();
		$fullID = $fiName;
		$ftItem = $this->GetFItemByName($fiName, $fullID);
		$format = (isset($ftItem['Format'])) ? $ftItem['Format'] : null; // ex. "<a href="{P1}">Image: {P0}</a>"
		$values = (isset($ftItem['Values'])) ? $ftItem['Values'] : null;
		$this->_GetPlaceholderValues($values, $accumArDBVals);
		$this->_GetConditionsValues($format, $accumArDBVals);
		
	}
	
//	______________________________________	
// == Internal methods ================================
	
	function _GetConditionsValues($format, &$accumArDBVals) {
		// find all IF blocks in format and return conditions used there
		if (!$format) return null;
		$conditionNames = array(); // array of all used conditions
		$ar_split = explode('{IF ', $format); // example of $format: "....{IF ConditionName}....", explode gives ["....","ConditionName}...."]
		$count = count($ar_split);
		for ($i=1; $i < $count; $i++) {
			$cstr = $ar_split[$i]; $cstr = substr($cstr, 0, strpos($cstr, '}')); // condition name
			if (!in_array($cstr, $conditionNames, true))
				$conditionNames[] = $cstr;
		}
		foreach($conditionNames as $cName) {
			$this->Manager->mngConditions->AllUsedDBValues($cName, $accumArDBVals);
		}
		// return $conditions;
	}
	
	function _GetPlaceholderValues($usedValues, &$accumArDBVals) { //$format, 
		if (empty($usedValues)) return null;
		foreach($usedValues as $valInd) {
			$this->Manager->DBValueManager->AllUsedDBValues($valInd, $accumArDBVals);
		}
	}
	
	function __FindEndBlock($format, $posStart, $tagToFind = '') { 
		return OEDynUtils::FindEndBlock($format, $posStart, '{IF ', '{ENDIF}', $tagToFind);
		
	}
	
	function _IFTags($format, &$pValResults, $usedValuesIndexes) {
		// Recursive. Handle IF blocks: ex. {IF Condition4}green{ELSE}{IF Condition5}black{ELSE}red{ENDIF}{ENDIF}, 
		// where "Condition4" and "Condition5" are names of conditions managed by OEDynConditionManager.

		// $pValResults, $usedValuesIndexes are used to be able to calcualte special case: {IF Pi} where Pi - i-th property/DBValue of formatted item - is boolean-tested
		

		$r = '';
		$posIF = false;
		$nextPos = 0;
		
		// find first-found IF block bounds:
		$posIF = strpos($format, '{IF ', $nextPos); if ($posIF === false) return $format; // start of IF tag, exit if no IF tags remain (this will end recursion)
		$posENDIF = $this->__FindEndBlock($format, $posIF+1);
		$posELSE  = $this->__FindEndBlock($format, $posIF+1, '{ELSE}'); // = $posENDIF when no ELSE tag in the block
		//echo 'end block:'.substr($format, $posENDIF).'<br/>';
		//echo 'else:'.substr($format, $posELSE).'<br/>';
		if ($posELSE < 0 || $posENDIF < 0) return $format; // error in format

		$r  = substr($format, 0, $posIF); // all code before {IF ..}
			
		// evaluate condition /////////////////////////////////////////////////////////
		$condition = false;

		$posET = strpos($format, '}', $posIF+1); if ($posET === false) return $format; // ending } of IF, exit if error in format
		$conditionID = substr($format, $posIF+4, $posET-($posIF+4)); // condition name
		// if condition ID starts by "!", invert the value:
		$invert = false;
		if (isset($conditionID[0]) && $conditionID[0] === '!') {
			$conditionID = substr($conditionID, 1); $invert = true;
		}
		
		if (isset($conditionID[1]) && $conditionID[0] === 'P' && $conditionID[1] <= '9' && strlen($conditionID) <= 3) { // special case {IF Pi} - see above
			// if not yet done: evaluate used DBValues - get data currently referenced by each of them:
			if ($pValResults === null) $this->_GetValResults($pValResults, $usedValuesIndexes);
			$valInd = intval(substr($conditionID, 1)); // ex 10 for P10
			$condition = isset($pValResults[$valInd]) && !!$pValResults[$valInd]; // transform to boolean, or false if missing
		} else { // evaluate condition named $conditionID
			$condition = $this->Manager->EvaluateCondition($conditionID); // evaluate IF condition to true or false
		} 
		
		if ($invert) $condition = !$condition;
		///////////////////////////////////////////////////////////////////////////////
		
		//var_dump($condition);
		//echo "If condition $conditionID=$condition ";
		if ($condition) { // if true, skip code between {ELSE} and {ENDIF}
			// transform C1{IF true}C2[{ElSE}C3]{ENDIF}C4 into C1C2C4
			$r .= substr($format, $posET+1, $posELSE - ($posET+1)); // all code between {IF ..} and {ELSE}, of {IF ..} and {ENDIF} if no {ELSE}
		} else { // if false, skip code between IF and ELSE, or skip all code between IF and ENDIF if no ELSE
			// transform C1{IF false}C2[{ElSE}C3]{ENDIF}C4 into C1[C3]C4
			if ($posELSE < $posENDIF) { // ELSE exists, use its code
				$posET2 = strpos($format, '}', $posELSE); if ($posET2 === false) return $format; // ending } of ELSE, exit if error in format
				$r .= substr($format, $posET2+1, $posENDIF - ($posET2+1)); // all code between {ELSE} and {ENDIF}, of {ENDIF} if no {ELSE}
			}
		}
		
		// all code after {ENDIF} tag:
		$posET2 = strpos($format, '}', $posENDIF); if ($posET2 === false) return $format; // ending } of ENDIF, exit if error in format
		$r .= substr($format, $posET2+1);
		//echo 'IF applied:'.$r."<br/>";
				
		// now $r = $format with one IF tag "applied", recursive call in case there are other tags
		return $this->_IFTags($r, $pValResults, $usedValuesIndexes);
	}
	
	
	function _CODETags($format, $values) {
		// resolve CODE tags. Format is "{CODE FunctionName CODE}", replace by result of FunctionName($v) located in (var).php file
		$tCode1 = '{CODE '; $tCode2 = ' CODE}'; // open and close strings
		$pos1 = strpos($format, $tCode1, 0); if ($pos1 === false) return $format; // start of IF tag, exit if no IF tags remain (this will end recursion)
		$pos2 = strpos($format, $tCode2, $pos1); if ($pos2 === false) return $format;
		$stBeforeTag = substr($format, 0, $pos1); $stAfterTag = ($pos2 + strlen($tCode2) >= strlen($format)) ? '' : substr($format, $pos2 + strlen($tCode2));
		
		$pos1 += strlen($tCode1); if ($pos2 <= $pos1) return $format;
		$fnName = substr($format, $pos1, $pos2-$pos1); if (empty($fnName)) return $format; // get FunctionName
		$code = (!$fnName || !function_exists($fnName)) ? '' : $fnName($values);
		//echo 'CODE='.$code.'<br>';
		$r = $stBeforeTag.$code.$stAfterTag; // replace function name by calculated value
		return $this->_CODETags($r, $values); // recursive, until all CODE tags are resolved
	}
	
	function _Placeholders($format, $pValResults, $usedValuesIndexes, $default, $modeGetNumRows = false) {
		
		if ($format === '{P0}') { // simple format - only first placeholder:
			if (!isset($pValResults[0])) return null; // data missing
			if ($modeGetNumRows) { // only count rows
				return (is_array($pValResults[0])) ? count($pValResults[0]) : 0;
			} else
				return $pValResults[0];
		}
		
		$format = str_replace('{DEFAULT}', $default, $format); // replace all {DEFAULT} by default falue
		
		$split = explode('{P', $format); //!!improve ex. PO.ALLROWS.MERGE({?}) - don't consider { and } as placeholder limits
		$count = count($split); if ($count < 2) return ($modeGetNumRows) ? 0 : $format; // no {Pi} value placeholders
		$r = ($modeGetNumRows) ? 0 : $split[0]; // before first placeholder
		
		$rRowCount = 0; // in $modeGetNumRows=true mode, calculates maximum number of rows if some placeholder returns array
		
		for ($i=1; $i<$count; $i++) { // each {Pi[ i2 ..]} placeholder
			$st = $split[$i]; // ex "1}" or "O1.SHA}" when "{P1}" or "{PO1.SHA}"
			if (!isset($st[0])) continue; // empty string (fastest check)
			
			$optional = ($st[0] === 'O') ? 1 : 0; // "{POi}" means optional tag - missing data replaced by empty string instead of stop processing and returning default
			
			if (!isset($st[1 + $optional])) { return null; } // error - string too short and no closing }
			
			if ($st[1 + $optional] === '}') { // most common situation: no suffix and only 1 digit in index, ex {P1}, can do it fast:
				$ind = $st[$optional]; // ex '1'
				if (!isset($pValResults[$ind])) { // data missing
					if ($optional) $r .= substr($st, 2 + $optional); // non-critical placeholder, replace by empty string
						else return null; // can't evaluate format, return default value
				} else // data ok
					$r .= $pValResults[$ind].substr($st, 2 + $optional); // replace {Pi} by corresponding value
							
			} else { // 2+ digit index, several consecutive indexes, suffix instructions
				if ($optional) $st = substr($st, 1); // skip "O"
				$pos = strpos($st, '}'); if ($pos === false) return null; // error in format
				$stPlaceholder = substr($st, 0, $pos); // id between "{P[O]" and "}"
				$stAfter = substr($st, $pos+1);
				//echo " :: Split into $stPlaceholder::$stAfter<br/>";
				// $splitP = explode('.', $stPlaceholder); // ex "[1, SHA]"
				$val = ''; // calculated value of placeholder
				$valFound = false;
				
				// special case: {Pi1 i2 i3 i4} means use value of placeholder i1 if value exists, else (only otherwise!) use value of i2 if it exists, and so on; only if none of 4 values exists, consider that value can't be alculated
				$spaceSplit = explode(' ', $stPlaceholder);
				foreach ($spaceSplit as $stPlaceholder) { // each part of placeholder, ex 1.SALT.SHA
					//echo '==  Process placeholder '.$stPlaceholder.'<br>';
					$pos = strpos($stPlaceholder, '.'); // != false if suffix instructions are present
					$ind = (!$pos) ? $stPlaceholder : substr($stPlaceholder, 0, $pos); // ex 1 for {P1}

					if (isset($pValResults[$ind])) { // found data
						$valFound = true;
						if ($pos) { // suffix instructions
							$val = $this->Manager->DBValueManager->GetDBValueWithSuffix($usedValuesIndexes[$ind].substr($stPlaceholder, $pos)); // ex. "4.SHA if placeholder references DBBalues[4]
						} else // use data directly
							$val = $pValResults[$ind];
						if ($val === '' || $val === null) { // special case - empty string or null: consider as existing value, but if there are more parts after, continue to them
						} else
							break; // stop at first existing value
					}
				}
				if (!$valFound && !$optional) return null; // missing data for non-optional placeholder - can't evaluate format

				if (is_array($val)) {
					if ($modeGetNumRows) {
						$arrCount = count($val);
						if ($rRowCount < $arrCount) $rRowCount = $arrCount;
						//echo "<br>Array in FItem $format: ";var_dump($val);echo '<br>';
						//echo "FItem: array in P, size $arrCount<br/>";
					} else { // !$modeGetNumRows
						$val = $this->_ChooseResultArrayRow($val);
						//var_dump($val);
						//echo "FItem: chosen array value $val<br/>";
					}
				} // else if (isset($val) && !$rRowCount) { // $rRowCount = 1; // if value exists, consider as 1 row }
				// when no arrays consider as 0 rows, to know that there's nothing to iterate
				
					
				if (!$modeGetNumRows) { // apply placeholder value
					if ($r === '' && $stAfter === '' && $i === $count-1 && is_array($val)) {
						$r = $val;  //!!improve // not used atm. // ex. format = {P0.SPLIT(;)} or {P0.ALLROWS.UCASE} - return array
					} else 
						$r .= $val.$stAfter; // apply actual value to result
				}
			}
			
		}
		
		return ($modeGetNumRows) ? $rRowCount : $r; // $format with all placeholders replaced by values, or row count in $modeGetNumRows=true mode
	}
	
	
	function _ChooseResultArrayRow($ar) {
		// used when placeholder returns an array - chooses value according to current iteration status
		$row = $this->Manager->GetIterRowByFullID($this->currFItemFullID); // OEDataContainer::$iCurrentRow;
		$arCount = count($ar);
		if ($row >= $arCount || $row < 0) $row = 0;
		//var_dump($row);
		//echo 'FItem: array in placeholder, using row '.$row.'<br>';
		return isset($ar[$row]) ? $ar[$row] : null;
	}
	
	
	function _GetValResults(&$pValResults, $usedValuesIndexes) {
		// evaluate used DBValues - get data currently referenced by each of them:
		$pValResults = array(); // actual data referenced by all used DBValues
		if (!empty($usedValuesIndexes)) {
			$count = count($usedValuesIndexes);
			for ($i=0; $i<$count; $i++) { // Get current data (current row if there are rows, current row may be set by iterator in progress) for every DBValue used in this item:
				$propertyValInd = $usedValuesIndexes[$i]; // index in DBValues array
				//echo "Requesting value[$propertyValInd] for property $i<br>"; //echo " src'{$this->Manager->DBValueManager->DBValues[$propertyValInd]}'"."<br/>";
				$pValResults[$i] = $this->Manager->DBValueManager->GetDBValue($propertyValInd); // current value, ex. "/Files/Image/image.jpg"
				//echo "Result = ";var_dump($pValResults[$i]);echo '<br>';
				//if ($pValResults[$i] === null) $pValResults[$i] = ''; //!!check added later
			}
		}	
	}
	
	function _CalculateFormatted($usedValuesIndexes, $format, $default, $iRow = 0, $modeGetNumRows = false) { // $iRow is ignored atm, row index determined by iterator in progress
	/*  ex. $format = "<a href="{P1}">Image: <b>{P0}</b></a>", $usedValuesIndexes = [2, 4] are indexes in DBValues:
	    uses data that specified DBValues reference atm (that may reference results of SQL queries, session values, GET params etc.)
	    insert their values into placeholders {Px}
	    return $default if at least one of used values is missing
	    $iRow tells which row to use (not used for iterators - iteration row is handled/set by data container when $iRow = 0)
		* * * * * * * * * * * * * * * */
		
		$tm_start = microtime(true);
		
		if (!$default) $default = '';
		if (!$format && !$usedValuesIndexes) return $default;
		if (!$format) $format = '{P0}'; // by default, display first property value
		//if (empty($usedValuesIndexes)) { return $default; } // do not use, as item may contain format with references to conditions and no value placeholders

		$pValResults = null; // actual data referenced by used DBValues (formatted item's properties)

		// evaluate and apply IF tags:
		$format = $this->_IFTags($format, $pValResults, $usedValuesIndexes);
		
		// if not yet done: evaluate used DBValues - get data currently referenced by each of them:
		if ($pValResults === null) $this->_GetValResults($pValResults, $usedValuesIndexes);

		//var_dump($usedValuesIndexes);
		//var_dump($pValResults);

		// evaluate and apply CODE tags:
		$format = $this->_CODETags($format, $pValResults);
		
		$format = $this->_Placeholders($format, $pValResults, $usedValuesIndexes, $default, $modeGetNumRows); 
		
		if ($modeGetNumRows) return $format; // return number of rows
		
		if (is_array($format)) return $format; // if array is returned, no more operations
		
		if ($format === null) return $default;
		
		// if unset placeholders remain, some values are missing; return default value
		//echo '<br>end of _CalculateFormatted:';var_dump($format);echo '<br>';
		if (strpos($format, '{P') !== false) return $default;
		
		$r = str_replace('{\\', '{', $format); // "{\" represents '{' character
		
		return $format;
		
	}
	
} // end OEDynFormattedItemManager


 // .......................................................................................... \\
//	== Boolean expressions (formulas) ========================================================  \\
class OEDynConditionManager {
	// Manager of boolean expressions used in IF actions and in conditions
	
	var $Manager; // parent OEDynManager object
	var $arConditions; // array of deserialised expression objects
	
	function __construct($mainManager) {
		$this->Manager =& $mainManager;
		$this->arConditions =& $mainManager->JSData['DynConditions'];
	}
	
	function GetConditionIndByName($cName) {
		if (!$cName) return -1;
		//echo "Requesting condition $cName".'<br>';
		return OEDynUtils::_FindIndexByName($this->arConditions, $cName);
	}
	
	function EvaluateCondition($cName, $nRow = 0) {
		if (!$cName) return false;
		//echo "Evaluate condition $cName"."<br/>";
		$ind = OEDynUtils::_FindIndexByName($this->arConditions, $cName);
		$r = $this->_EvaluateExpression($ind, $nRow);
		//echo ':'.(($r===true)?'true':(($r===false)?'false':$r)).':<br/>';
		//var_dump($r);
		if ($GLOBALS['oephpDebugMode']) {
			echo "<b>Condition <span style='color:#0080B0'><u>$cName</u></span> evaluated to <span style=\"font-size:16px;background-color:white;color:".(($r===true)?'#40D080;">true':(($r===false)?'red">false':$r))."</span></b><br/>";
		}
		return $r;
	}
	
	function EvaluateConditionByInd($i, $nRow = 0) {
		return $this->_EvaluateExpression($i, $nRow);
	}
	
	function AllUsedDBValues($cName, &$accumArDBVals) {
		//echo "CONDITION USED VALUES ".$cName."<br/>";
		$ind = $this->GetConditionIndByName($cName); if ($ind < 0) return;
		if (!$accumArDBVals) $accumArDBVals = array();
		$this->_AllUsedValues($ind, $accumArDBVals);
	}

// == Internal methods ================================

	// Expression operator constants (need sync with DBVilter.vb):
        const ftEqual = 0;
        const ftNotEqual = 1;
        const ftMore = 2;
        const ftMoreOrEq = 3;
        const ftLess = 4;
        const ftLessOrEq = 5;
		
		const ftEqualSHA = 20;
		
		const ftLIKE = 50;

        //? const ftEqualSHA = 20 ' hash right part of expression before comparing it with left
		
		const ftIsNull = 100;
		const ftIsTrue = 101;
	//////////////////////////////////
	
	function _GetCondition($i) { // get condition object from array
		//echo "Getting condition $i in";var_dump($this->arConditions);
		if (empty($this->arConditions) || 
			!is_array($this->arConditions) || 
			$i < 0 || $i >= count($this->arConditions)) return null;
		return $this->arConditions[$i];
	}
	
	function GetDBValue($valInd, $nRow) {
		if ($valInd === null) return null;
		return $this->Manager->DBValueManager->GetDBValue($valInd, $nRow);
	}
	
	function GetDBValueAddress($valInd) { // used for debug visualization only
		return $this->Manager->DBValueManager->GetNameByInd($valInd);
	}
	
	function _EvaluateExpression($i, $nRow, $externalNOT = false) {
		$exprRslt = false;

		$expr = $this->_GetCondition($i);
		//echo "Expression $i:";var_dump($expr);
		if (empty($expr)) return false; // not found
		
		$exprNOT = ($expr['NOT'] && strlen($expr['NOT']) > 0); // true when expression is marked as NOT (result is inversed)
		if ($externalNOT) $exprNOT = !$exprNOT;
		
		if (!empty($expr['Children'])) { 
			// complex expression (contains hierarchy of expressions)
			$arChildNames = $expr['Children'];
			$arChildNot = (!empty($expr['ChildNOT'])) ? $expr['ChildNOT'] : null;
			$boolAND = ($expr['ChildOp'] != 'OR'); $boolOR = !$boolAND;
			$count = count($expr['Children']);
			for ($iChild=0; $iChild < $count; $iChild++) {
				$childNOT = ($arChildNot && $iChild < count($arChildNot)) ? $arChildNot[$iChild] : false;
				$iChildInd = $this->GetConditionIndByName($arChildNames[$iChild]);
				$childResult = $this->_EvaluateExpression($iChildInd, $nRow, $childNOT);
				if ($iChild === 0) 
					$exprRslt = $childResult; // first in list
				else
					$exprRslt = ($boolAND) ? ($exprRslt && $childResult) : ($exprRslt || $childResult);
				if (($boolAND && !$exprRslt) || ($boolOR && $exprRslt)) break; // no need to calculate further
			}
			//echo "Expression ".($exprNOT?' NOT ':'')."\"{$expr['Name']}\"  evaluated to ".(((!$exprNOT) ? $exprRslt : !$exprRslt) ? "true" : "false")."<br/>";
			
		} else {
			//simple expression, ex "a >= b", no hierarchy
			$params = $expr['Params'];
			if (!empty($params)) { // ex. {"SmpLeft":2,"SmpOp":0,"SmpRight":4}, 2 and 4 are DBValues indexes
				$operator = intval($params['SmpOp']);
				$left = $this->GetDBValue(intval($params['SmpLeft']), $nRow);
				//echo 'Left value:';var_dump($left);
				//echo "Left row $nRow ::$left::";
				if ($GLOBALS['oephpDebugMode']) {
					//echo "Right ::$right::";
					echo ' ** <b style="color:#0080FF;">Left</b>:<i>'.$this->GetDBValueAddress(intval($params['SmpLeft'])).'</i>=';var_dump($left);
				}
				$right = null;
				if ($operator < self::ftIsNull && isset($params['SmpRight'])) {// not one-operand expression
					$right = $this->GetDBValue(intval($params['SmpRight']), $nRow);
					if ($GLOBALS['oephpDebugMode']) {
						echo ' <b style="color:#FF8000;">Right:</b><i>'.$this->GetDBValueAddress(intval($params['SmpRight'])).'</i>=';var_dump($right);
					}
				}
				
				$missingValues = false;
				if ($operator < self::ftIsNull) 
					$missingValues = ($left === null || $right === null);
				else if ($operator != self::ftIsNull) 
					$missingValues = ($left === null);
				
				//!!check following bool expressions:
				if (!$missingValues) { // only if both operands not null!
					$step = 0;
					while(true) { // some operators like >= need several steps
						$continue = false;
						switch ($operator) {
							case self::ftIsNull:
								$exprRslt = ($left === null);
								break;
							case self::ftIsTrue:
								$exprRslt = (!!$left); // also used to check empty strings ""
								break;
								
							case self::ftNotEqual: // also see else{} below
								$exprRslt = !self::__equal($left, $right); 
								break;

							case self::ftMoreOrEq:
								$exprRslt = self::__equal($left, $right);
								if ($exprRslt) break; // true if equal, otherwise continue to ftMore
							case self::ftMore:
								$exprRslt = ($left > $right); break;

							case self::ftLessOrEq:
								$exprRslt = self::__equal($left, $right);
								if ($exprRslt) break; // true if equal, otherwise continue to ftLess
							case self::ftLess:
								$exprRslt = ($left < $right); break;

							case self::ftEqual:
							default: // ftEqualSHA, LIKE etc. // !check
								// !keep same code as in __equal()! function call not used to increase performance a little, as operation is frequently used
								// see comments in __equal()
								$exprRslt = ($left && $right) ? ($left == $right) : (($left === $right) || ((string)$left === (string)$right));
						}
						if (!$continue) break;
					}
					
				} else { // at least one operand = null
					$equal = ($left === null && $right === null);
					// if ($equal && $operator === self::ftEqual) $exprRslt = true; //!!check may be dangerous for security reasons
					if (!$equal && $operator === self::ftNotEqual) $exprRslt = true;
				}
				//echo "Expression ".($exprNOT?' NOT ':'')."\"$left\" {$this->__OperatorToStr($operator)} \"$right\" evaluated to ".(((!$exprNOT) ? $exprRslt : !$exprRslt) ? "true" : "false")."<br/>";
			}	
		}
		
		//echo ' = Evaluated to '.(((!$exprNOT) ? $exprRslt : !$exprRslt) ? 'TRUE' : 'FALSE').'<br>';
		return (!$exprNOT) ? $exprRslt : !$exprRslt;
	}
	
			// * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
			private static function __equal($left, $right) {
				// ATTENTION: in PHP, 0 = any string not containing numbers, ex. 0='1' = false but 0 = 'true' = true. String '0' does not behave this way
				// for comparison, accept int 0 = string "0", but DO NOT accept int 0 = empty string ""
				return ($left && $right) ? ($left == $right) : (($left === $right) || ((string)$left === (string)$right));
			}
			// * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
	
	function _AllUsedValues($ind, &$accumArDBVals) { // recursive - add indexes of DBValues used in condition
		if ($ind < 0) return;

		$expr = $this->_GetCondition($ind); if (empty($expr)) return false;
		
		if (!empty($expr['Children'])) { // complex expression
			$count = count($expr['Children']);
			for ($iChild=0; $iChild < $count; $iChild++) {
				$iChildInd = $this->GetConditionIndByName($expr['Children'][$iChild]);
				$this->_AllUsedValues($iChildInd, $accumArDBVals);
			}		
		} else { // simple expression (no children)
			if (empty($expr['Params'])) return;
			$params = $expr['Params'];
			$vLeft  = (isset($params['SmpLeft'])) ? $params['SmpLeft']  : -1;
			$vRight = (isset($params['SmpRight'])) ? $params['SmpRight'] : -1;
			if ($vLeft >= 0  && !in_array($vLeft, $accumArDBVals, true))  $this->Manager->DBValueManager->AllUsedDBValues($vLeft, $accumArDBVals);
			if ($vRight >= 0 && !in_array($vRight, $accumArDBVals, true)) $this->Manager->DBValueManager->AllUsedDBValues($vRight, $accumArDBVals);
			// note that DBValues ($vLeft and $vRight) may be Formatted Items containing other DBValues
		}
		
		$accumArDBVals;
	}
	
	function __OperatorToStr($operator) {
		switch($operator) {
			case self::ftIsNull: return "IsNull";
			case self::ftIsNull: return "IsTrue";
			case self::ftNotEqual: return "!=";
			case self::ftMore: return ">";
			case self::ftMoreOrEq: return ">=";
			case self::ftLess: return "<";
			case self::ftLessOrEq: return "<=";
			// case self::ftLIKE: return "LIKE";
			case self::ftEqual:
			case self::ftEqualSHA:
			default:  return "=";
		}
	}
	
}


 // .......................................................................................... \\
//	== Generate html code for iterators including DBGrid =====================================  \\
class OEDynIteratorManager {
	// Manager of boolean expressions used in IF actions and in conditions
	
	var $Manager; // parent OEDynManager object
	
	var $ITSTART;
	var $ITEND;
	
	var $CurrentIterBases; // hierarchy of iteration bases (values/containers being iterated), considering there may be subiterators
	
	function __construct($mainManager) { // constructor
		$this->Manager =& $mainManager;
		$this->ITSTART = '<!--__OEITSTART'; $this->ITEND = '<!--__OEITEND-->'; // open and close tags of iterator block
		$this->INLINEMODIFIER = '__OEI[';
		// parts that shoudl only be present in even iterations:
		$this->EVENSTRT = '<!--__OEITEVENSTART=-->'; $this->EVENEND = '<!--__OEITEVENEND=-->';
		
		$this->CurrentIterBases = array();
	}

	function GenerateAllIterationZones() {
		// generate all iteration zones in the page code
		$tm_start = microtime(true);
		$r = array();
		foreach ($this->Manager->JSData['DynElements'] as $elName=>$el) { // each element
			if (empty($el['IterationZones'])) continue;
			$iZones = $el['IterationZones'];
			foreach($iZones as $ind=>$iZone) { // each element's iteration zone
				if (empty($iZone['Name']) || empty($iZone['Code'])) continue;
				$name = $iZone['Name']; if (!$name) continue;
				$r[$name] = "";
				$this->_RestoreLocalizedIterCode($iZone, $this->Manager->currLang);
				$code = $iZone['Code']; if (!$code) continue;
				//$tm_start2 = microtime(true);
				//echo "==TIME LOCL::".(($tm_start2 - $tm_start)*1000)." before iterator {$iZone['Name']}<br/>";
				$code = $this->_ProcessCodeContainingIterators($code);
				//echo "==TIME USED::".((microtime(true) - $tm_start2)*1000)."  used by iterator {$iZone['Name']}<br/>";
				$r[$name] = $code;
			}
		}
		//var_dump($r);
		return $r;
	}	
	

//	____________________________________________
//	Processing /////////////////////////////////////

	function _RestoreLocalizedIterCode(&$iZone, $culture) { // restores iterator code for given localization, from common part + array of differences
		if (!isset($iZone['LocDiff'][$culture])) return;
		$diff = $iZone['LocDiff'][$culture]; // all insertions (substrings representing localized difference) to be made into the code for this culture, in the form position=>substring
		$code =& $iZone['Code'];
		// insert substrings-differences:
		$newcode = '';
		$lastpos = 0;
		foreach($diff as $pos=>$subcode) {
			if ($pos) $newcode .= substr($code, $lastpos, ($pos-$lastpos));
			$newcode .= $subcode;
			$lastpos = $pos;
		}
		if ($lastpos < strlen($code)) $newcode .= substr($code, $lastpos);
		//echo "<br/>Iterators:<br/>$code<br/>$newcode<br/><br/>";
		$code = $newcode;
	}

	function _ProcessCodeContainingIterators($st) { // recursive
		/*	ex. $st="somehtmlcode<!--__OEITSTART-->iteratorcode<!--__OEITEND--><!--__OEITSTART-->iteratorcode<!--__OEITEND-->somehtmlcode"
			where iteratorcode may = somehtmlcode<!--__OEITSTART-->subiteratorcode<!--__OEITEND-->somehtmlcode<!--__OEITSTART-->subiteratorcode<!--__OEITEND-->somehtmlcode
			
			apply values (HTML modifiers) in somehtmlcode (non-iterator parts) according to current values and row counters - _ApplyValuesInHTMLCode()
			then process each top-level iterator block - _ProcessIterator(), who recursively calls _ProcessCodeContainingIterators(iterator's inner code) for each row
			returns output html code string with all values and iterators applied
			
			See comments for _ProcessIterator()
			
		* * * */
		//echo "Entering _ProcessCodeContainingIterators()<br/>";
		if (!isset($st[0])) return $st; // fast check for non-empty string
		$r = "";
		
		$posLastIterEnd = 0;
		while (true) {
			$tm_start2 = microtime(true);
			$posIterator = strpos($st, $this->ITSTART, $posLastIterEnd); // find next <!--__OEITSTART

			// apply inline HTML modifiers to not-yet-processed html BEFORE iterator block, or remaining code till the end if no more iterators:
			$codeOutsideIterator = ($posIterator !== false) ? substr($st, $posLastIterEnd, $posIterator-$posLastIterEnd) : substr($st, $posLastIterEnd);
			if ($codeOutsideIterator) {
				//echo '$codeOutsideIterator:';var_dump($codeOutsideIterator);
				$tm_start = microtime(true);
				$r .= $this->_ApplyValuesInHTMLCode($codeOutsideIterator); // outside-iterator code
				//echo "====TIME USED _ApplyValuesInHTMLCode::".((microtime(true) - $tm_start)*1000)." <br/>";
				//echo "\$posIterator = $posIterator ";
			}
			
			if ($posIterator === false) break; // all processed
				 
			// find substring corresponding to iterator (note that iterator may include subiterators):
			$tm_start = microtime(true);
			$posLastIterEnd = OEDynUtils::FindEndBlock($st, $posIterator+1, $this->ITSTART, $this->ITEND); if ($posLastIterEnd < 0) return $st; // error, non-terminated block
			$posLastIterEnd += strlen($this->ITEND); // position just after the iterator
			//echo "\$posLastIterEnd = $posLastIterEnd ";
			// recursive-generate iterator code and apply it to output
			//echo '$codeIterator:';var_dump(substr($st, $posIterator, $posLastIterEnd-$posIterator));
			$tm_start = microtime(true);
			
			$xCurrentRow = OEDataContainer::$iCurrentRow;
			$r .= $this->_ProcessIterator(substr($st, $posIterator, $posLastIterEnd-$posIterator)); // inside-iterator code
			OEDataContainer::$iCurrentRow = $xCurrentRow; // restore global row counter
			
			//echo "====TIME USED _ProcessIterator::".((microtime(true) - $tm_start)*1000)." <br/>";
			//echo "====TIME USED inside CI while::".((microtime(true) - $tm_start2)*1000)." <br/>";
			
		}	
		//echo "_ProcessCodeContainingIterators:";var_dump($r);
		return $r;		
	}
	
	
	function __GetAllHTMLModifiers($html) { // return array of full names of all HTML modifiers used in the code
		// in iterator's html code, each modifier is coded like __OEI[FullName] (unlike usual code where it's ?php ;echo oei[fullName] ?
		if (!isset($html[0])) return null; // fast check for non-empty string
		$r = array();
		$st_split = explode($this->INLINEMODIFIER, $html);
		$count = count($st_split);
		for ($i=1; $i<$count; $i++) {
			$subst = $st_split[$i]; $posEndId = strpos($subst, ']'); if ($posEndId === false) return $r;
			$fullName = substr($subst, 0, $posEndId);
			if (!in_array($fullName, $r)) $r[] = $fullName; // add full name if not yet in array
		}
		return $r;
	}
	
	function _ApplyEvenBlocks(&$html) {
		// replace each <!--__OEITEVENSTART=-->code<!--__OEITEVENEND=--> by empty string if not even iteration, code if even
		$evenRow = (OEDataContainer::$iCurrentRow & 1); // true for rows #1,3,5,7 etc. - note that 1 is even # because first # is 0
		
		//echo "<br>String before even replacement:<br>$html<br>";
		if (!$evenRow) { // odd row - remove all even bolcks from string
			$html = OEDynUtils::RemoveBlocksFromString($html, $this->EVENSTRT, $this->EVENEND);
			return;
		} else { // even row - simply remove even boundary tags and keep the rest of the code
			$html = str_replace($this->EVENSTRT, '', $html);
			$html = str_replace($this->EVENEND, '', $html);
		}
		//echo "<br>String after even replacement:<br>$html<br>";
	}
	
	function _ApplyValuesInHTMLCode($html) { // apply all inline html modifiers (no subiterators)
		//$tm_start = microtime(true);
		//echo "====TIME USED _ApplyValuesInHTMLCode::".((microtime(true) - $tm_start)*1000)." <br/>";
		$tm_start = microtime(true);
		$tm_sum2 = 0;
		if (empty($html)) return $html;
		
		// replace each <!--__OEITEVENSTART=-->code<!--__OEITEVENEND=--> by code if even iteration/row, empty string otherwise
		if (strpos($html, $this->EVENSTRT) !== false) $this->_ApplyEvenBlocks($html);
		
		// replace each __OEI[fullModifierName] with HTML modifier's value (calculated according to currently iterated rows)
		$st_split = explode($this->INLINEMODIFIER, $html);
		$count = count($st_split);
		for ($i=1; $i<$count; $i++) {
			//echo '::Found Inline Modifier::';
			$subst = $st_split[$i];
			$posEndId = strpos($subst, ']'); if ($posEndId === false) return $html;
			$modifierId = substr($subst, 0, $posEndId);
			//echo $modifierId.'=';

			$tm_start2 = microtime(true);
			$modifierValue = $this->Manager->EvaluateHTMLModifier($modifierId);
			$tm_sum2 += microtime(true)-$tm_start2;
			//echo $modifierValue;
			
			if ($modifierValue === null) $modifierValue = ''; //!!check
			$st_split[$i] = $modifierValue . substr($subst, $posEndId+1); // replace modifier by its value
			
			//echo "<br/>";			
		}
		//echo "====TIME USED _ApplyValuesInHTMLCode::".((microtime(true) - $tm_start)*1000)." after for ".count($st_split)."<br/>";
		//echo "====Total Evaluate ".($tm_sum2*1000)."<br/>";
		
		$r = implode($st_split);
		//echo "_ApplyValuesInHTMLCode:";var_dump($r);
		//echo "====TIME USED _ApplyValuesInHTMLCode::".((microtime(true) - $tm_start)*1000)." <br/>";
		return $r;
	}
	
	function _ProcessIterator($st) {
		/*	Process code between START and END iterator tags, repeating it according to data used inside.
		
			ex. $st = "<!--__OEITSTART-->iterator's inner code with HTML modifiers and possibly subiterators<!--__OEITEND-->"
		
			The idea is to iterate through results/data of action which is "iteration source/basis" for this iterator,
			and every time call _ProcessCodeContainingIterators to 
			 - apply HTML modifiers calculated according to current iteration
			 - generate subiterators
		* * * */
		echo "Entering _ProcessIterator()<br/>";
		// remove iterator's open and close tags:
		//!!improve!! subiterators
		$pos1 = strpos($st, '>'); $pos2 = strrpos($st, '<'); if ($pos1 === false || $pos2 === false) return $st; // error
		$openTag = substr($st, 0, $pos1+1); // store open tag as it may contain parameters
		$st = substr($st, $pos1+1, $pos2 - $pos1 - 1); // = iterator's inner code between open and close tags
		//echo "Iterator inner code:";var_dump($st);
		
		// iterator's PARAMETERS from open/start tag (format example: <!--__OEITSTART BASIS=ActionGet1.Table1.Column1;NOITERATETAG=1;-->)
		$arParams = array();
		$paramStr = substr($openTag, strlen($this->ITSTART.' ')); // ex. "BASIS=ActionGet1.Table1.Column1;NOITERATETAG=1;-->"
		if (!strpos($paramStr, ';')) // no parameters
			$paramStr = '';
		else {
			$paramStr = substr($paramStr, 0, strrpos($st, ';')+1); // remove "-->" or whatever comes after last ";"
			// ex. "BASIS=ActionGet1.Table1.Column1;NOITERATETAG=1;" //!!check NOITERATETAG
			// means iterate rows of ActionGet1.Table1.Column1 (not the whole query result),
			// and do not include first html tag into iteration (iterate only its inner html, not the tag itself)
			$paramStr = explode(';', paramStr);
			foreach($paramStr as $param) {
				if (!$param) continue;
				$ar = explode('=', $param);	if (count($ar) !== 2) continue;
				$arParams[$ar[0]] = $ar[1]; // $arParams[parameter name] = parameter value
			}
		}
		
		$allDBValues = array(); // all DBValues used inside iterator
		
		// if several output columns - <td> tags - split into several "output parts", synchronous but supporting "row spans" 
		// (part may be rendered only once for several rows, if there's an hierarchy in linked data)
		// even if there's one part (simple iterator without "columns"), it may span several iterations if it uses part of data from container with hierarchy
		$arCodeParts = $this->__SplitCodeIntoColumns($st, $allDBValues); // at the same time, get all DBValues used in the code
		// structure of $arCodeParts[i] {'code':html code, 'codeNoSubIt':same but without subiterators, 'usedDBValues':[array of DBValues]}
		
		//echo "Split code into columns:";var_dump($arCodeParts);
		
		// iterator basis - column or whole container/table (ex. db read action's result) whose rows to iterate:
		$iName = $this->_AutoDetectIterBase($allDBValues, $arParams);
		echo '    IterBase='.$iName.'<br/>';
		
		//if (!$iName) return $st; // failed to find iteration basis, no changes
		if (!$iName) $iName = '..1row..';
		$onerow = ($iName === '..1row..');
		$nodata = ($iName === '..empty..');

		if ($onerow) // only one row - no iteration for this level
			return $this->_ProcessCodeContainingIterators($st); // just render the code and process possible subiterators
		if ($nodata)
			return $this->_HideWhenNoRows($st); // no rows/data, ignore all code
		
		
		$iNames = explode(';', $iName); // in case several columns need to iterate synchronously, ex. 2 FormattedItems like Month name - Month index
		$itBaseCount = count($iNames); // how many simultaneous iterators - usually 1
		$iContainers = array(); // all containers to iterate synchronously - usually only 1, but may be ex. FormattedItems and B:FormattedItems
		$nRows = 0; // number of rows in total/max
		
		for ($i=0; $i<$itBaseCount; $i++) { // all values/containers to iterate
			$iName = $iNames[$i];
			
			// iterate data container or, sometimes, only one of its column:
			$pos = strpos($iName, '.');
			$iContainerName = ($pos) ? substr($iName, 0, $pos) : $iName;
			$iColumnName 	= ($pos) ? substr($iName, $pos+1)  : '';
			
			//echo " Basis: $iContainerName $iColumnName<br/>"; //echo $this->Manager->GetContainerByName($iContainerName);
			$iContainer = $this->Manager->GetContainerByName($iContainerName); //var_dump($iContainerName, $iContainer);
			if (!$iContainer) { 
				;echo '<br/>!!ERROR IT01: not found container '.$iContainerName.'<br>';
				return $st;
			}

			// how many iterations in total?
			$nRowsi = $iContainer->GetNRows($iColumnName);
			if ($nRowsi > 1) { // only consider iterators with several rows
				if (!in_array($iContainer, $iContainers)) {
					$iContainers[] = $iContainer;
					$iContainer->InitIteration();
				}
				if ($iColumnName) 
					$iContainer->AddIterationColumn($iColumnName); 
				else
					$iContainer->StartIteration('', false); // if $iColumnName='', iterate whole container, example result of DB Get action
				if ($nRows < $nRowsi) $nRows = $nRowsi;
				
			} else { // no rows
				$iNames[$i] = ''; // remove value from iteration list
			}

		}

		if (!$nRows) {
			;echo '<br/>!!ERROR IT02: no rows to iterate while several rows detected initially: '.$st.'<br>';
			return $st; // no multi-row values
		}
		
		$parentIterBases = $this->CurrentIterBases;
		for ($i=0; $i<$itBaseCount; $i++) { // add iteration basis values to parent list, to avoid using them in subiterators
			if ($iNames[$i]) $this->CurrentIterBases[] = $iNames[$i];
		}
		//echo '<br/>Current iteration basis:';var_dump($this->CurrentIterBases);echo '<br/>';
		

		//$iContainer = $iContainers[0];
		//$itBaseCount = count($iContainers);
		
		//$nRows = $iContainer->GetNRows($iColumnName); // if $iColumnName='', max num rows among all container's columns
		echo "Total rows = $nRows<br/>";
		if ($nRows > 5000) $nRows = 5000; //!!improve // protect from too many rows
		
		//var_dump($arCodeParts);
		$r = ''; // iterator's output html code for all rows
		for ($i=0; $i<$nRows; $i++) {
			//echo "::::Processing iteration row $i<br/>";
			foreach ($arCodeParts as $part) {
			//	Process each "cell" (row&column), to output cell of html table, or just the whole row in case of simple iterator:
				$code = $part['code']; 			// "source" code of current column, values not yet applied
				$vals = $part['usedDBValues']; 	// all DBValues needed to calculate column's output code
				$rowspan = $iContainer->RowSpanForSetOfDBValues($this->Manager, $vals, -1, true); // for outputting grouped hierarchy results, get num rows in resulting html table for current cell
				//echo "Rowspan = $rowspan<br/>";
				if ($rowspan < 1) continue; // means that, for this column, on one of previous rows there has been a rowspan that still lasts
				if ($rowspan > 1) $code = $this->__ApplyRowSpanToTD($code, $rowspan);
				$r .= $this->_ProcessCodeContainingIterators($code); // recursion - "apply" (calculate) HTML modifiers and all subiterators for each row/cell's code
			}
			
			$advance = false;
			for ($i2=0; $i2<count($iContainers); $i2++) {
				$iContainer = $iContainers[$i2];
				if ($iContainer->NextRow()) { $advance = true; // next row for all values forming iteration basis
				} // else if ($i2 === 0) break; // try to go to next row, stop when no more rows
			}
			if (!$advance) {
				//echo "::::No more rows::::<br/>";
				break; // no more rows in any of the columns
			}
			//echo "::::End iteration row $i<br/>";
		}

		$this->CurrentIterBases = $parentIterBases; // remove iteration basis of current level at the end of processing it

		return $r;
	}
	
	//__________________
	// Helper functions ///////////////////////
		
		function _AutoDetectIterBase($arDBValues, $arParams) {
			// note: $arDBValues do NOT include subiterators' values!
			// returns ;-separated list of iteratable values or whole containers (ex result of DB Get action)
			// if nothing can be iterated, or if all that can be iterated contains no more than 1 row, returns "..1row.."
			// if iteratable values all contain no data, returns "..empty.." to completely disable iterator's inner code
			// if this is a sub-iterator, ignores all values used as basis in parent interators
			
			$iName = ''; $iCount = 0; // name of whole container or container's column to iterate through
			$iNameDB = ''; $iCountDB = 0; // iteratable DB results
			$iNameFI = ''; $iCountFI = 0; // iteratable FormattedItems

			if ($arParams && !empty($arParams['BASIS']))
				return $arParams['BASIS']; // use parameter value
			
			//echo "All DBValues in part:";var_dump($arDBValues);
			if (count($arDBValues) === 1) { // only one DBValue in the iterator code
				$valInd = $arDBValues[0];
				$iName = $this->Manager->DBValueManager->GetNameByInd($valInd); // use data referenced by this value
				//echo "Only one value in code: $iName<br/>";
				if ($this->Manager->DBValueManager->GetContainerTypeByInd($valInd) === 'DBGet') { // value points to db SELECT result
					$iName = $this->Manager->DBValueManager->GetContainerNameByInd($valInd); // in case of DB result, use whole container rather than single column
				}
				if (in_array($iName, $this->CurrentIterBases)) $iName = '';
				
				return $iName;
				
			}
			
			// Search for db results:
			foreach ($arDBValues as $valInd) {
				if ($this->Manager->DBValueManager->GetContainerTypeByInd($valInd) === 'DBGet') { // value points to db SELECT result
					$containerName = $this->Manager->DBValueManager->GetContainerNameByInd($valInd); // found db query result container with data to iterate through
					//echo "Testing DB container: $containerName<br/>";
					//echo "Parent bases:";var_dump($this->CurrentIterBases);echo '<br/>';
					if (in_array($containerName, $this->CurrentIterBases)) continue; // already a basis in parent iterator
					
					$testValsExist = $this->Manager->DBValueManager->GetDBValue($valInd, -1);
					$count = ($testValsExist) ? count($testValsExist) : 0;
					
					//echo "Test DB value $containerName : rows $count<br/>";
					
					if ($count < 1) { // empty result
						if (!$iNameDB) { // store only if nothing else found yet
							$iNameDB = $containerName;
							$iCountDB = 0;
						}
						continue;
					}
					
					$iNameDB = $containerName;
					$iCountDB = $count;
					//echo "Possible DB iter basis: $iNameDB : $iCountDB rows<br/>";
					
					if ($count === 1) { // only one row - store but proceed searching
					} else { // more than one row - use this data as iterator basis
						break;
					}
				}
			}
			if ($iCountDB >= 2) { // found db result with more than 1 row - use it without further search
				//echo "DB iter basis chosen: $iNameDB : $iCountDB rows<br/>";
				return $iNameDB;
			}
			
			$iName = $iNameDB; $iCount = $iCountDB;
			
			// search for FItems with arrays in placeholders:
			echo 'Check FItems for iterbase<br/>';
			foreach($arDBValues as $valInd) {
				if ($this->Manager->DBValueManager->GetContainerTypeByInd($valInd) === 'FI') { // FormattedItem
					
					$fiName = $this->Manager->DBValueManager->GetNameByInd($valInd); // use data referenced by any used FItem
					$fiRows = $this->Manager->EvaluateFormattedItem($fiName, 0, true); // number of rows in placeholders' result-arrays

					//echo "**Test FI value $fiName : rows $fiRows<br/>";

					if ($fiRows > 0) { // can be iterated, add to list separated by ;
						if (strpos($iNameFI, $fiName) === false) { // not yet added
							$iNameFI = ($iNameFI) ? "$iNameFI;$fiName" : $fiName;
							if ($iCountFI < $fiRows) $iCountFI = $fiRows;
							//echo "Possible FI iter basis: $iNameFI : $fiRows rows<br/>";
						}
					}
				}
			}
			
			if ($iCountFI >= 2) { // use formatted items as iteration basis as there are several rows in at least one of them
				//echo "FI iter basis chosen: $iNameFI : $iCountFI rows<br/>";
				return $iNameFI;
			}
			
			// no certainty, choose best possible type of value: if 1 row in DB, use it, otherwise use FItems
			if ($iCount < 1) { $iName = $iNameFI; $iCount = $iCountFI; } // use FItems
			
			if (!$iName) return ''; // no iteratable values found
			
			
			if ($iCount < 1) return '..empty..'; // if even best iteratable values contain no data rows
			if ($iCount < 2) return '..1row..'; // if only 1 row max - no need to iterate in this case
			
			return $iName; // all iteratable values/containers
			// ex. 'ActionDBGetAllUsers' or 'ActionDBGetAllUsers.Table1.Column1' or "FormattedItems.MonthNames;FormattedItems.MonthValues;FormattedItems.IsSelectedMonth"
		}

		
		function __AddAndAnalyseCodePart($st, $start, $end, &$partInd, &$arCodeParts, &$allDBValues) {
			$partInd++;
			$codePart = ($end >= 0) ? (($start <= $end) ? substr($st, $start, $end-$start+1) : '') : $st;
			$codeNoSubIt = OEDynUtils::RemoveBlocksFromString($codePart, $this->ITSTART, $this->ITEND);
			//echo "Adding code part:";echo htmlentities($codePart).'<br>'.'<br>';
			
			while (count($arCodeParts) <= $partInd) $arCodeParts[] = array();
			$arCodeParts[$partInd]['code'] = $codePart;
			$arCodeParts[$partInd]['codeNoSubIt'] = $codeNoSubIt;
			$arCodeParts[$partInd]['usedDBValues'] = array();
			//echo "Parts for \$partInd = $partInd :<br/>";
			//echo 'code: '.htmlentities($arCodeParts[$partInd]['code']).'<br>'.'<br>';
			//echo 'nosb: '.str_replace('__OEI[', '<b><u>__OEI[</u></b>', htmlentities($arCodeParts[$partInd]['codeNoSubIt'])).'<br>'.'<br>';
			
			// find all DBValues used in this code part:
			$allModifiers = $this->__GetAllHTMLModifiers($codeNoSubIt); if (empty($allModifiers)) return; // all HTML modifiers
			$arDBValues = array(); // all DBValues used in all modifiers
			foreach($allModifiers as $mFullName) $this->Manager->mngModifiers->_AllUsedDBValues($mFullName, $arDBValues); // DBValues used in each modifier
			if (empty($arDBValues)) return; // no dynamic content
			$arCodeParts[$partInd]['usedDBValues'] = $arDBValues; // DBValues used by this code part
			//var_dump($arCodeParts[$partInd]['usedDBValues']);echo '<br>';
			// also, add DBValues to the "complete" list representing all iterator code (not just this part):
			foreach ($arDBValues as $valInd) 
				if (!isset($allDBValues[$valInd])) $allDBValues[] = $valInd;
		}
			
		function &__AddAllCodeAsOnePart($st, &$arCodeParts, &$allDBValues) {
			$arCodeParts = array();
			$partInd = -1; $this->__AddAndAnalyseCodePart($st, 0, -1, $partInd, $arCodeParts, $allDBValues);
			return $arCodeParts;
		}
		
		function __SplitCodeIntoColumns($st, &$allDBValues) {
			/*	Split inner iterator code into "output columns" - synchronous parts, normally corresponding to <td> tags. 
				If there is no split, code is considered to be 1 column
				"Output column" is, for example, a column of DBGrid (html table showing query results)
				Main reason to split is that a column may have rows with ROWSPANS, if data is grouped 
				(ex. several values in column B corresponding to 1 value in column A)
			* * * */
			//echo "Entering __SplitCodeIntoColumns()<br/>";var_dump($st);
			
			$arCodeParts = array(); $arCodeParts[]['code'] = ''; $arCodeParts[0]['codeNoSubIt'] = ''; $arCodeParts[0]['usedDBValues'] = array(); 
			if ($st === null || $st === '' || strpos($st, '</td>') === false) { // no parts
				return $this->__AddAllCodeAsOnePart($st, $arCodeParts, $allDBValues); // a single part, no html columns
			}
			
			// go through all <td> that are not in subiterators:
			$currLen = 0; // part of string currently processed or already processed
			$lastPartEnd = -1; // pos of last character of code already added to $arCodeParts
			$posAfterLastIterator = 0; // pos of first character after last-found subiterator
			$inTD = false; // currently inside <td>
			$posTD = -1; // position of last-found <td> tag in $st
			$partInd = -1; // part counter, modified in __AddAndAnalyseCodePart()
			
			while (true) {
				// process till next subiterator or till end of code string:
				$posIterator = strpos($st, $this->ITSTART, $posAfterLastIterator); // find next <!--__OEITSTART
				$stBeforeIterator = ($posIterator !== false) ? substr($st, $posAfterLastIterator, $posIterator-$posAfterLastIterator) : substr($st, $posAfterLastIterator);
				$currLen = $posAfterLastIterator + strlen($stBeforeIterator);
				//echo "\$stBeforeIterator:<br/>";var_dump($stBeforeIterator);
				
				// the following loop applies to substring before/between/after subiterator block(s):
				// in the currently processed substring, process <td></td> as well as html outside them
				// (note that beginning of <td> may be before last subiterator, i.e. in one of previous substrings, not in $stBeforeIterator)
				while (true) {
					
					if ($inTD) { // currently inside <td>
						// find </td> nd see if it's inside $stBeforeIterator (before next subiterator):
						$pos = OEDynUtils::FindEndBlock($st, $posTD+1, '<td', '</td>'); // find closing </td>, consider possible sub-tables inside <td>
						if ($pos === false || $pos < $posTD) {echo '<br/><br/>!!!!!!!!!!!!IT ERR 1<br/>$st<br/>';
							return $this->__AddAllCodeAsOnePart($st, $arCodeParts, $allDBValues); } // error - <td> tag is not closed					
						$pos += strlen('</td>')-1; // pos of last character of code part
						if ($pos >= $currLen) break; // </td> is after subiterator, not in current substring; process in next WHILE iteration

						// add and analyse new code part corresponding to <td>..</td>:
						$this->__AddAndAnalyseCodePart($st, $posTD, $pos, $partInd, $arCodeParts, $allDBValues);	 // ex ....[<td....</td>]....
						$lastPartEnd = $pos;
						$inTD = false; // quitting <td>
						//echo "outTD<br/>";
					}
					
					$endReached = ($lastPartEnd+1 === strlen($st)); // true if no more code left
					if ($endReached) return $arCodeParts; // all processed
						
					if (!$inTD) { // currently outside <td>
						$pos = strpos($st, '<td', $lastPartEnd+1);
						if ($pos === false) { // no more <td>, but some code remains at the end - add as last part and exit:
							$this->__AddAndAnalyseCodePart($st, $lastPartEnd+1, strlen($st), $partInd, $arCodeParts, $allDBValues); // ex ....</td>[</tr>]
							return $arCodeParts;
						}
						if ($pos >= $currLen) break; // <td> is after subiterator, not in current substring; process in next WHILE iteration
						
						// new <td> found:
						//echo "inTD<br/>";
						$posTD = $pos;
						$inTD = true; // now we are inside <td>
						// if there's any code before this <td> (ex. <tr> at beginning of iterator) not yet added as a part, add new part:
						if ($posTD - ($lastPartEnd+1) > 0) {
							$this->__AddAndAnalyseCodePart($st, $lastPartEnd+1, $posTD-1, $partInd, $arCodeParts, $allDBValues); // ex [<tr>]<td>....
							$lastPartEnd = $posTD-1;
						}
					}
					if (!$inTD) break; // no more parts
				}
					
				if ($posIterator === false) {echo '<br/><br/>!!!!!!!!!!!!IT ERR 0<br/>$st<br/>'; // error, normally should never happen
					return $this->__AddAllCodeAsOnePart($st, $arCodeParts, $allDBValues);	}
				
				// Bypass subiterator:
				// find substring corresponding to iterator:
				$posAfterLastIterator = OEDynUtils::FindEndBlock($st, $posIterator+1, $this->ITSTART, $this->ITEND); 
				if ($posAfterLastIterator < 0) {echo '<br/><br/>!!!!!!!!!!!!IT ERR 2<br/>$st<br/>'; // error, missing end of subiterator
					return $this->__AddAllCodeAsOnePart($st, $arCodeParts, $allDBValues); }
				$posAfterLastIterator += strlen($this->ITEND); // position just after the iterator
			}	
			
			return $arCodeParts;			
		}
		
		function __ApplyRowSpanToTD($code, $rowspan) { // insert rowspan into first <td> in $code
			if (!$code) return $code;
			$pos  = strpos($code, '<td'); 		if ($pos === false) return $code;
			$pos2 = strpos($code, '>', $pos); 	if ($pos2 === false) return $code;
			$codeRs = ' rowspan="'.$rowspan.'"';
			$code = substr($code, 0, $pos2).$codeRs.substr($code, $pos2); // ex. <td rowspan="4">....
			return $code;
		}
	
		function _HideWhenNoRows($code) {
			return ''; //!!improve - hide first tag or enclose into hidden span
			//if (!$code) return '';
			//$pos  = strpos($code, '<td'); if ($pos === false) return '';
		}
		
		
}
