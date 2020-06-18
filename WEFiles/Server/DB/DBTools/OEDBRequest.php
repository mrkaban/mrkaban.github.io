<?php
/*_______________________________________________
|                                                |
|    ©2012 Element Technologie - openElement     |
|________________________________________________|

	Using different SQL engines to handle DB and process packs of SQL requests
	See comments in the code
*/

$dir_dbrequestphp = dirname(__FILE__); // folder of this script
//$dir_dbrequestphp_to_tools = dirname(dirname($dir_dbrequestphp)); // corresponds to ../../
$dir_dbrequestphp_to_db = dirname($dir_dbrequestphp); // corresponds to ../
require_once $dir_dbrequestphp."/OEDynUtils.php";
require_once $dir_dbrequestphp."/OEDataContainers.php";

require_once $dir_dbrequestphp_to_db."/sqllogin.php"; // OE-specified login parameters

//_________________________________
// Base class for sql handlers ////////////////////////////////////
class OESQLBase {

	// Connection:
	public $connectionParams;				// connection parameters - server, user etc.
	public $sqlConn; 			// database connection, can be of different type according to chosen engine
	//protected $sqlConnParams;	// connection parameters, like db name, username/password - depends on chose engine
	
	// Prepared statements:
	protected $psQuery; // prepared statement query code
	protected $psVals; // values of placeholders for prepared statement
	protected $psTypes; // corresponding value types
	protected $preparedStatement; // object-result of sql engine's Prepare was called
	
	// Errors:
	public $connectError; 		// connection error
	public $lastQueryError; 	// last SQL query error
	
	private static $sqlInstance; // instance of one of descendant classes depending on chosen engine
	
	// Public Static ////////////////////////////////////////////////
	
	// Return - create if not yet done - an instance of SQL handler according to chosen mode/engine and where the page is - local webserver previsualisation, local AMP, online
	public static function SQLInstance() {
		if (self::$sqlInstance) return self::$sqlInstance; // already instantiated

		$params = null; // server, user, password etc.

		if (OESQLBase::isLocalSQLite()) {
			$params = $GLOBALS['oeLoginSQLite_LocalWebServer'];
		} else 
		if (OESQLBase::isLocalDB()) {
			$params = $GLOBALS['oeDBConnectionLocalAMP'];
		} else {
			$params = $GLOBALS['oeDBConnectionOnline'];
		}
		
		$type = ($params && !isset($params['not_set']) && isset($params['engine'])) ? $params['engine'] : 'MySQL';
		
		echo '  = = Connecting to '.$type.'<br>';
		
		switch ($type) {
			case 'SQLite': 	
				self::$sqlInstance = new OESQLite($params); break;
			default:
				self::$sqlInstance = new OEMySQL($params); break;
		}
		//var_dump(self::$sqlInstance);
		return self::$sqlInstance;
	} // ______________________________________________________________
	
	
	// Public functions (do not override directly) /////////////////////
	
	// Connect to database
	public function connect($params = null) {
		if (!$params) $params = $this->_getConnectParams();
		//var_dump($params);
		if (!$params) return false;
		$tm=microtime(true);
		$r = $this->_connect($params);
		echo "<span style='color:brown'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for <b>DB connection</b><br/>";
		return $r;
	}
	
	// Run a simple SQL query
	public function sql($query) {
		if (!$this->_checkInit($query)) return false;
		return $this->_sql($query);		
	}
	
	public function runSQLPack($sqlPack) {
		if (!$this->_checkInit($sqlPack)) return false;
		//var_dump($sqlPack);
		return $this->_runSQLPack($sqlPack);
	}
	
	// Secure value used in queries from SQL injections
	public function secureSQLValue($val, $forceNumeric = false) {
		if ($val === null || ($val === '' && $forceNumeric)) return null; //!!improve
		$len = strlen($val); if ($len < 1) return '';
		$val2 = $val;
		$val2 = $this->_sqlEscapeString($val2);
		if ($forceNumeric) { // if numeric mode demanded (to avoid injections like "1 or 1=1" => "..WHERE users.password=1 or 1=1.." and other):
			if (strpos($val2, '.') !== false) $val2 = (float)$val2; else $val2 = (int)$val2;
		}
		return $val2;
	}
	
	public function StringQuoteChar() { // SQLite needs 'string', "string" is very dangerous as it means identifier
		return '"';
	}
	
	
	// Prepared statements // // // // // // // // // //
	
	public function setPS($query, $arVals, $arTypes, $callPrepare = true) { // set prepared query and placeholder values with types
		$this->initPS($query);
		$this->addPSValues($arVals, $arTypes);
		//$tm=microtime(true);
		if ($callPrepare) $this->_preparePS();
		//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." setPS2<br/>";
	}


	public function initPS($query = null) { // initialise new statement
		$this->psVals = array();
		$this->psTypes = array();
		$this->psQuery = $query;
		$this->preparedStatement = null;
	}
	
	public function setPSQuery($query) { // query code
		$this->psQuery = $query;
	}
	
	public function addPSValue($val, $type) { // add placeholder value
		$this->psVals[] = $val;
		$this->psTypes[] = $type; // "s"|"i"|"d"
	}

	public function addPSValues($arVals, $arTypes) {
		// add placeholder values; $arTypes is string with types ex 'sddi' string-double-double-integer
		if (!$arVals || !$arTypes) return;
		foreach ($arVals as $i=>$val) {
			$type = 's';
			if (isset($arTypes[$i])) $type = $arTypes[$i]; 
				else { echo "<br/>!!ERROR SQL01 - prepared statement - type missing for placeholder $i value $val<br/>"; }
			$this->psVals[] = $val;
			$this->psTypes[] = $type; // "s"|"i"|"d"
		}
	}
	
	// ____________________
	// Non-overridable ///////////////////////////////
	
	public static function isLocalDB() { //echo "!!__FILE__!!".__FILE__;
		return (strpos(__FILE__, ':') === 1);
	}

	public static function isLocalSQLite() { 
		return OEDynUtils::isLocalWebServer();
	}
	
	protected function _checkInit($val = true) {
		if (!$val) return false;
		if (!$this->sqlConn && !$this->connectError) $this->connect();
		//var_dump($this);
		if (!$this->sqlConn) return false;
		//echo 'Connected Ok ';var_dump($this->sqlConn);
		return true;
	}

	// ___________________________
	// Optionally overridable ///////////////////////////////////
	
	protected function _toPS($code) { // for query code containing placeholders "?", format placeholders according to engine
		return $code; // by default, keep ? as placeholders
	}
	
	protected function _applyVals_NonPS($code, $valsSecured) {
		// for non-prepared statement mode
		// replace each ? with corresponding value

		if (strpos($code, '?') === false || !$valsSecured) return $code;
		
		// ex "INSERT INTO `user` (name, age) VALUES (?, ?)"
		$cntrVals = 0;
		$rCodeSplit = explode('?', $code);
		$splitCount = count($rCodeSplit); if (!$splitCount) return $code;
		
		$rCodeWithVals = $rCodeSplit[0]; // SQL with "?" replaced by values
		for ($i=1; $i<$splitCount; $i++) { // add value to beginning of each split item except first - this will replace ? with value:
			$val = (isset($valsSecured[$cntrVals])) ? $valsSecured[$cntrVals] : 'NULL';
			$rCodeWithVals .= $val.$rCodeSplit[$i];
			$cntrVals++; // pass to next placeholder and value
		}
		// ex "INSERT INTO `user` (name, age) VALUES ('UserName', 16)"
			
		return $rCodeWithVals;
	}
	
	
	// run a pack of SQL requests generated by OE
	protected function _runSQLPack($sql) {
		/*	$sql is  an object containing requests, values for placeholders, and special instructions
			Format: {"Requests":[{"Type":1,"Code":"SELECT *","Values":["1","\"2\""],"ValueTypes":"is"},....]}
			or simply an array: [{"Type":1,"Code":"SELECT *","Values":["1","\"2\""],"ValueTypes":"is"},....]
			
			When possible, prepared statement is used; in case of error or simple request qwithout placeholder, usual query mode is used
		*/

		$arResultContainers = array();

		// if still in json serialisation, deserialise:
		if (!is_object($sql) && !is_array($sql)) {
			//$tm = microtime(true);
			$RContainer = OEDynUtils::decodeJSON($sql);
			//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for RContainer decodeJSON<br/>";
		} else
			$RContainer = $sql;
		//var_dump($RContainer);//var_dump($RContainer->Requests[0]->Values);

		if ($RContainer && is_object($RContainer) && $RContainer->Requests) $RContainer = $RContainer->Requests;
		if (!$RContainer || count($RContainer)<1) return null;

		$rLastResult = null; // last mysqli request result
		//$cntrSQL = 0; // counter of sql requests
		$InsertedIDs = array(); // store last insert id (last written autoinc value by INSERT queries) for an sql request[i] (i=$cntrSQL)
		//$aiPref = "__INAI"; // not used atm // keep same as DBTools.vb value!

		foreach ($RContainer as $rq) {
			$rType = $rq->Type;
			
			$rCode 			= $rq->Code;
			$rVTypes 		= $rq->ValueTypes;
			$rValues 		=& $rq->Values;
			$rValsSecured 	=& $rq->ValuesSecured;

			$rCodeWithVals = null;
			
			$sqlResult = false;
			
			// fix SQL query format:
			$arExtraQueries = array(); 	// sometimes query may need several extra queries (depending on sql engine, ex CREATE TABLE with indexes for SQLite)
										// they are automatically generated here, not extracted from the passed query
			//$tm = microtime(true);
			$rCode = $this->_fixQueryFormat($rCode, $arExtraQueries);
			//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for fixQueryFormat<br/>";

			// probably not used atm // replace foreign key "targets" with respective autoinc values
			/*if ($rValues && count($rValues)) { $rValCount = count($rValues); for ($i=0; $i<$rValCount; $i++) {
					if (strpos($rValues[$i], $aiPref) === 0) { $indTargetTable = substr($rValues[$i], strlen($aiPref)); $rValues[$i] = $InsertedIDs[$indTargetTable]; }
			}	}*/
			
			// In this version, use prepared statements if placeholders, and direct otherwise
			//$tm=microtime(true);
			if (isset($rValues) && !empty($rValues)) { // placeholders exist
				// Try to use prepared statement:
				$rCodePS = $this->_toPS($rCode);
				//$tm=microtime(true);
				$this->setPS($rCodePS, $rValues, $rVTypes);
				//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for sql setPS<br/>";
				$sqlResult = $this->_execPS();
			} else {
				// direct non-prepared query:
				$rCodeWithVals = $this->_applyVals_NonPS($rCode, $rValsSecured); // SQL with "?" replaced by values
				//echo $rCodeWithVals."<br/>";
				if (!$rCodeWithVals || strlen($rCodeWithVals) < 7) continue;
				$sqlResult = $this->sql($rCodeWithVals);
			}
			//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for sql<br/>";
			
			//var_dump($sqlResult);
			if ($sqlResult === false || $sqlResult === null) {
				continue; // query failed, or (sometimes for create queries) item already exists
			}

			
			// only for certain query types - store results in data container:
			$queryCodeStart = strtolower(substr($rCode, 0, 6));
			
			
			//$tm=microtime(true);
			if ($queryCodeStart === "select") { // store results of Select
			
				$rContainer = $this->_createSQLResultContainer();
				$rContainer->addAllRows($sqlResult);
				array_push($arResultContainers, $rContainer);
				//echo $rContainer->toJSON(); // ."<br>" // serialise result of every request
				//echo ' SELECT result:';var_dump($rContainer);
			
			} else 
			if ($queryCodeStart === "insert") { // store autoinc value for insert requests
				/*$InsertedIDs[$cntrSQL] = $this->_sqlInsertID();*///echo 'Inserted row '.$this->_sqlInsertID().'<br>';
				
				// in result container, add the source "last inserted primary key value":
				$rContainer = $this->_createSQLResultContainer();
				$rContainer->Values['_NewRowPrimKey'] = $this->_sqlInsertID(); // keep name '_NewRowPrimKey' in sync with vb!
				//echo 'INSERT new row result:'.$rContainer->Values['_NewRowPrimKey'];var_dump($rContainer->Values);
				array_push($arResultContainers, $rContainer);
			}
			//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for result handling<br/>";
			
			if (!empty($arExtraQueries)) {
				//$tm=microtime(true);
				foreach($arExtraQueries as $extraSQL) {
					$this->sql($extraSQL); // run extra queries in simple mode, ex create indexes after creating table in sqlite mode
				}
				//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for extra queries<br/>";
			}
			//$cntrSQL++;
				
		} // for each request in the pack
		
		if (count($arResultContainers) < 1) 
			return null; 
		else 
			return $arResultContainers;	
		
	}

	
	// ___________________________
	// Overridable functions ///////////////////////////////////
	
	// Constructor
	function __construct($params) {	
		$this->connectionParams = $params;
	}
	
	protected function _getConnectParams() {
		return $this->connectionParams;
	}
	
	// Connect to database
	protected function _connect($params) {
		return false;
	}
	
	// Run either a simple SQL query
	protected function _sql($query) {
		return null;
	}
	
	// auto primary key value for last insert operation
	protected function _sqlInsertID() {
		return 0;
	}
	
	protected function _createSQLResultContainer() {
		return new OESQLResult();
	}

	// normally called BEFORE applying values to placeholders, to correct formats, ex for SQLite ` => " and " => '
	protected function _fixQueryFormat($query, &$arExtraQueries) {
		return $query;
	}
	
	protected function _sqlEscapeString($val) {
		return $val;
	}
	
	// MUST OVERRIDE with call to base function
	protected function _preparePS() { // run sql engine Prepare
		if (!$this->psQuery || !$this->psVals || !$this->psTypes) return false;
		return true;
	}
	
	// MUST OVERRIDE with call to base function
	protected function _execPS() { // execute prepared statement
		if (!$this->preparedStatement && !$this->_preparePS()) return false;
		
		return true;
	}
	
} 



//__________________________
// MySQL engine wrapper ////////////////////////////////////
class OEMySQL extends OESQLBase {


	// Overridable functions ///////////////////////////////////
	
	/*protected function _getConnectParams() {
		if (OESQLBase::isLocalDB()) {
			global $oeLoginMySQL_Local; if (!isset($oeLoginMySQL_Local)) return null;
			return $oeLoginMySQL_Local;
		} else {
			global $oeLoginMySQL; if (!isset($oeLoginMySQL)) return null;
			return $oeLoginMySQL;
		}
		return null;
	}*/
	
	private function _mysqlerrorexit($error) {
		// exit script without showing any output except error report
		$message = 'MySQL Database - Connection Error: <b>'.$error.'</b><br/>Check connection parameters (user, password etc.)<br/><br/>'
				  .'Base de donnees MySQL - Erreur de Connection <b>: '.$error.'</b><br/>Verifier les parametres de connexion (utilisateur, mot de passe etc.)<br/><br/>';
		OEDynUtils::OutputStop($message); // stop PHP
		exit;
	}
	
	protected function _onQueryError() {
		$this->lastQueryError = mysqli_error($this->sqlConn);
		echo '<br>!MySQLi query error: '.$this->lastQueryError.'<br>';
	}
	
	// Connect to database
	protected function _connect($params) {
		if (!isset($params['server']) || !isset($params['user'])) return false;
		
		$mysqli_con = mysqli_connect($params['server'], $params['user'], $params['pw'], $params['dbname']);
		if (!$mysqli_con) {
			$this->connectError = ($mysqli_con !== false) ? mysqli_error($mysqli_con) : 'Unknown MySQLi error'; 
			
			$this->_mysqlerrorexit($this->connectError); // stop script
			
			return false;  
		}
		
		$r_db = mysqli_select_db($mysqli_con, $params['dbname']); //!!check if necessary
		if (!$r_db) {
			$this->connectError = 'Error: no database "'.$params['dbname'].'" found';

			$this->_mysqlerrorexit($this->connectError); // stop script

			return false;
		}
		
		mysqli_set_charset($mysqli_con, 'utf8');
		
		$this->sqlConn = $mysqli_con;

	}
	
	// Run a simple SQL query
	protected function _sql($query) {
		if (!$query || !$this->sqlConn) return false;
		$mysqli_con = $this->sqlConn;
		$r = null; 
		
		$tm_start = microtime(true);
		$msqliR = $mysqli_con->query($query, MYSQLI_STORE_RESULT);
		if ($GLOBALS['oephpDebugMode']) {
			echo '<span style="font-size:7px;">'.$query.'</span><br>';
			echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $tm_start)*10000)*0.1)." for sql query<br/>";
		}
		if ($msqliR === false || $msqliR === null) {
			$this->_onQueryError();
			return false;
		}
		
		
		$r = $msqliR; //mysqli_store_result($mysqli_con);
		//var_dump($r);
		
		if (false) { // debug
			$query = str_ireplace("Select ", "<br>SELECT ", $query);
			$query = str_ireplace(" From ", 	"<br>FROM ", $query);
			$query = str_ireplace(" Where ", 	"<br>WHERE ", $query);
			;var_dump($r);echo ": ";var_dump($query);echo "<br>_ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _ _<br>";
		}
		return $r;
	}
	
	// auto primary key value for last insert operation
	protected function _sqlInsertID() {
		if (!$this->sqlConn) return 0;
		return $this->sqlConn->insert_id;
	}
	
	
	protected function _sqlEscapeString($val) {
		if (!$this->_checkInit()) return $val;
		return mysqli_real_escape_string($this->sqlConn, $val);
	}
	
	// Prepared statements ////////////////////////////////////
	
	protected function _psBindValues() {
		if (!$this->preparedStatement || !$this->psVals || !$this->psTypes) return false;
		
		// MySQLi is rather awkward for dynamic binding, need to use workarounds:
		$pa = array_merge(array(implode($this->psTypes)), $this->psVals); // creates array where 1st element is types ex "sddi", and other elements are values
		
		if ($GLOBALS['oephpDebugMode']) {
			;echo "<span style='font-size:7px;'>PS: prepared query ={$this->psQuery}</span><br/>";
			;echo "<span style='font-size:7px;'>PS: MySQLi binding values:";var_dump($pa);echo '</span><br>';
		}
			
		// create array of references:
		$paRef = array(); foreach($pa as $key=>$value) { $paRef[$key] =& $pa[$key]; }
		
		if (!call_user_func_array( array(&$this->preparedStatement, 'bind_param'), $paRef)) return false;  
		// equivalent to $this->preparedStatement->bind_param(!$this->psTypes, $this->psVals[0], $this->psVals[1], ..);
		
		return true;
	}
	
	protected function _preparePS() { // run sql engine Prepare
		if (!$this->sqlConn) return false;
		if (!parent::_preparePS()) return false;
		
		$tm=microtime(true);
		$this->preparedStatement = $this->sqlConn->prepare($this->psQuery);
		echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." for sqlConn.prepare<br/>";
		if (!$this->_psBindValues()) return false;
		//if (!$this->modePDO) { // direct SQLite
		//} else { // PDO mode
		//}
		//echo "<span style='color:blue'>==TIME</span>::".(round((microtime(true) - $tm)*10000)*0.1)." preparePS2<br/>";
		
		return true;
	}
	
	protected function _execPS() { // execute prepared statement
		if (!$this->sqlConn || !$this->preparedStatement) return false;
		if (!parent::_execPS()) return false; // _preparePS is called here if necessary

		$tm_start = microtime(true);
		$r = $this->preparedStatement->execute();
		echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $tm_start)*10000)*0.1)." for sql prepared execute<br/>";
		
		if (!$r) { // query didn't succeed
			$this->_onQueryError();
			return false;
		}
		
		$this->preparedStatement->store_result();
		return $this->preparedStatement; // result type is mysqli_stmt
	}
	
	
} // end class OEMySQL



//__________________________
// SQLite engine wrapper ////////////////////////////////////
class OESQLite extends OESQLBase {

	private $sqliteOk;
	private $sqliteVers;
	
	private $modePDO; // true means using PDO wrapper
	
	// Prepared statements:
	protected $typeCorrespondence; // dictionary of engine-specific type correspondence, ex. $typeCorrespondence['i'] = SQLITE3_INTEGER
	
	// Overridable functions ///////////////////////////////////
	
	// Constructor
	function __construct($params) {
		parent::__construct($params);
		$this->sqliteOk = false;
		$this->sqliteVers = 0;
		if (class_exists('SQLite3')) { // directly SQLite
			echo ' ..SQLite3 class exists..<br/>';
			$this->sqliteVers = 3;
			$this->sqliteOk = true;
		} else if (extension_loaded('pdo_sqlite')) { // PDO SQLite
			echo ' ..Using PDO SQLite..<br/>';
			$this->modePDO = true;
			$this->sqliteVers = 3;
			$this->sqliteOk = true;
		} else {
			$this->sqliteOk = function_exists('sqlite_escape_string'); //!!improve
			$this->sqliteVers = 2;
			echo ' ..No SQLite3.. ';
			//phpinfo();
		}
		//echo "<br/>SQLite activated, version {$this->sqliteVers} <br/>";
	}
	
	private function _sqliteerrorexit($error) {
		// exit script without showing any output except error report
		$message = 'SQLite Database - Connection Error: <b>'.$error.'</b><br/>Make sure this PHP server supports SQLite, or try MySQL instead<br/><br/>'
				  .'Base de donnees SQLite - Erreur de Connection <b>: '.$error.'</b><br/>Verifier que ce serveur PHP supporte SQLite, ou essayer MySQL<br/><br/>';
		OEDynUtils::OutputStop($message); // stop PHP
	}	

	private function _onQueryError() {
		if (!$this->modePDO) {
			//$errcode  = isset($this->sqlConn->lastErrorCode) ? $this->sqlConn->lastErrorCode.' ' : '';
			//$errmessg = isset($this->sqlConn->lastErrorMsg)  ? $this->sqlConn->lastErrorMsg : 'Unknown SQL error';
			$this->lastQueryError = (isset($this->sqlConn->lastErrorMsg)) ? $this->sqlConn->lastErrorMsg : 'SQL error';
		}  else {
			$errmessg = $this->sqlConn->errorInfo();
			$this->lastQueryError = (isset($errmessg)) ? $errmessg : 'SQL error';
		}
		echo '<br><span style="color:red;">!LastQueryError: '.$this->lastQueryError.'</span><br>';
	}
	
	protected function _handleDBFolder($relPath, $dbname, $dbObsoleteLocation = 'WEFiles/Server/DB/') {
		// only if folder for db file does not yet exist: 
		//   - create it 
		//   - check whether ancient/obsolete path (for first DB versions of openElement) exists, if yes, if ancient db file exists, copy it to the new location
		//     (ex copy WEFiles/Server/DB/SQLite_DB/oedb.db to Data/SQLite_DB/oedb.db)
		try {
		
			if (!$relPath) $relPath = '';
			$dbPath = dirname($dbname);
			if (isset($dbPath) && $dbPath !== '/' && $dbPath !== '\\' && strlen($dbPath)>0) {
				// create path if not exists
				$inData = (strpos($dbPath, 'Data/') === 0); // if "Data/SQLite_DB"
				$subName = ($inData) ? substr($dbname, 5) : ''; // ex. SQLite_DB/oedb.db - all that goes after Data/
				
				$dbPath = $relPath.$dbPath; // ex ../Data/SQLite_DB
				if (!file_exists($dbPath)) {
					// only if the db folder does not yet exist
					
					mkdir($dbPath, 0755, true);
				
					if ($inData && $dbObsoleteLocation) {
						// check if obsolete path with old db exists, if yes, copy it to new location
						$dbObsoleteLocation = str_replace('\\', '/', $dbObsoleteLocation);
						if ($dbObsoleteLocation[strlen($dbObsoleteLocation)-1] !== '/') $dbObsoleteLocation .= '/';
						$dbObsoleteLocation .=$subName; 					// ex    WEFiles/Server/DB/SQLite_DB/oedb.db
						$obsoleteDBName = $relPath.$dbObsoleteLocation; 	// ex ../WEFiles/Server/DB/SQLite_DB/oedb.db
						if (file_exists($obsoleteDBName)) {
							try {
								rename($obsoleteDBName, $relPath.$dbname); // move old db file to correct location
							} catch (Exception $ex) { ; }
						}
					}
				}
			} else
				return false;
			
		} catch (Exception $ex) {
		  return false;
		}
		return true;
	}
	
	// Connect to database
	protected function _connect($params) {
		//echo 'Connecting start:';var_dump($this);
		if (!$this->sqliteOk || !isset($params['dbname'])) {
			$this->_sqliteerrorexit('SQLite engine not initialized');
		
			return false;
		}
		
		// make sure directory for the database file exists:
		global $dynManager;
		$relPath = (isset($dynManager->JSData['PageRelPath']))?$dynManager->JSData['PageRelPath']:'';
		
		if (!$this->_handleDBFolder($relPath, $params['dbname'])) return false;
		
		if ($this->sqliteVers >= 3) {

			if (!defined('SQLITE3_INTEGER')) { // sometimes these constants are not defined
				define('SQLITE3_INTEGER', 	1); define('SQLITE3_FLOAT', 	2);
				define('SQLITE3_TEXT', 		3); define('SQLITE3_BLOB', 		4);
			}
				
			if (!$this->modePDO) { // direct SQLite
				try {
				$this->sqlConn = new SQLite3($relPath.$params['dbname']);
				} catch (Exception $ex) {
					$message = 
					  'SQLite database creation error : cannot create database. It may happen if the full path to your project contains accented characters or other non-basic symbols. Please copy your project folder to another place, ex. C:\OEProjects\MySite(1), and try again<br/><br/>'
					  .'SQLite - erreur de creation de base de donnees: impossible de creer la base. Ca peut arriver si le chemin complet vers votre projet contient des caracteres accentes (speciaux) ou d\'autres symboles non-basiques. Veuillez copier le dossier de votre projet a un autre emplacement, par exemple C:\OEProjects\MonSite(1), et re-essayez<br/><br/>';
					OEDynUtils::OutputStop($message); // stop PHP
				}
				$this->typeCorrespondence['s'] = SQLITE3_TEXT;				
				$this->typeCorrespondence['i'] = SQLITE3_INTEGER;
				$this->typeCorrespondence['d'] = SQLITE3_FLOAT;
			
			} else { // PDO mode
				$this->sqlConn = new PDO('sqlite:'.$relPath.$params['dbname']);
				$this->sqlConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // check
				$this->typeCorrespondence['s'] = (defined('PDO::PARAM_STR')) ? PDO::PARAM_STR : PDO_PARAM_STR;
				$this->typeCorrespondence['i'] = (defined('PDO::PARAM_INT')) ? PDO::PARAM_INT : PDO_PARAM_INT;
				$this->typeCorrespondence['d'] = $this->typeCorrespondence['s'];
				//echo 'Connecting PDO SQLite:';var_dump($this->sqlConn);echo ' db:'.$relPath.$params['dbname'].'<br>';
			}
			
			if (!$this->sqlConn) $this->_sqliteerrorexit('SQLite engine not initialized');
			return !!$this->sqlConn;
			
		} else {
			$this->_sqliteerrorexit('SQLite engine not initialized');
			return false;
		}
	}

	// Run a simple SQL query
	protected function _sql($query) {
		if (!$query || !$this->sqlConn) return;

		
		$tm_start = microtime(true);
		
		// for some implementations (like Free.fr), IF NOT EXISTS syntax may not be supported, but the existence of table or index may be checked by the fact that an exception is thrown
		try {
			$sqlR = $this->sqlConn->query($query);
		} catch (Exception $ex) {
			// check if IS NOT EXISTS present, if so, remove it and retry once (by recursion):
			if (stripos($query, ' IF NOT EXISTS')) {
				$query = str_replace(' IF NOT EXISTS', '', $query);
				$r = $this->_sql($query); // retry without IF NOT EXISTS
				return $r; // false in this case may mean that an item already exists
			} else {
				// _onQueryError(); //!!check in this case it's not necessarily an error, maybe a table or an index already exists
				if ($GLOBALS['oephpDebugMode']) { ;echo '<br>SQLite Exception: ';var_dump($ex);echo '<br>'; }
				return false;
			}
		}
		
		if ($GLOBALS['oephpDebugMode']) {
			echo '<span style="font-size:7px;">'.$query.'</span><br>';
			echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $tm_start)*10000)*0.1)." for sqlite query<br/>";
		}
		//var_dump($sqlR);var_dump($this->sqlConn);
		
		if ($sqlR === false) {
			_onQueryError();
			return false;
		}
		return $sqlR;
	}

	// auto primary key value for last insert operation
	protected function _sqlInsertID() {
		if (!$this->sqlConn) return 0;
		return (!$this->modePDO) ? $this->sqlConn->lastInsertRowID() : $this->sqlConn->lastInsertId();
	}
	
	protected function _createSQLResultContainer() {
		$r = new OESQLite3Result();
		if ($this->modePDO) $r->setModePDO();
		return $r; //!! implement old sqlite??
	}

	// normally called BEFORE applying values to placeholders, to correct formats, ex for SQLite ` => " and " => '
	protected function _fixQueryFormat($query, &$rNeededExtraQueries) {
		/*
		No stored procedures
		" => ' 
		` => " or nothing?
		SQLite doesn't support FOREIGN KEY constraints
		SQLite: CREATE TABLE Blah (foreignId Integer REFERENCES OtherTable (id)); 
		
		PKeys:
		MySQL: int(11) NOT NULL AUTO_INCREMENT), ..., PRIMARY_KEY()
		SQLite: id INTEGER PRIMARY KEY ASC, ...
		
		CREATE TABLE IF NOT EXISTS `oe_logv0_user` 
			(oeid int(11) NOT NULL AUTO_INCREMENT, name TEXT NOT NULL, pw TEXT NOT NULL, email TEXT NOT NULL, 
			address TEXT NOT NULL, info TEXT NOT NULL, permissions int(11) NOT NULL, 
			PRIMARY KEY(oeid) [, FOREIGN KEY(key) REFERENCES table(column)]) ENGINE=InnoDB DEFAULT CHARSET=utf8		
		
		*/
		if (!$query) return $query;
		
		// 
		//$query = str_replace('"', "'", $query);
		//$query = str_replace('`', '"', $query); // important

		// types:
		$query = preg_replace('/int\(\d+\)/i', 'INTEGER', $query);
		$query = str_ireplace('double', 'REAL', $query);		
		
		// CREATE queries:
		if (isset($query[5]) && strtoupper(substr($query, 0, 6)) == 'CREATE') {
			
			// find table name, considering it's between first two `
			$posFirstQuote = strpos($query, '`');
			$posSecondQuote = strpos($query, '`', $posFirstQuote+1);
			$tableName = (!$posFirstQuote || !$posSecondQuote) ? null : substr($query, $posFirstQuote+1, $posSecondQuote-$posFirstQuote-1);
			//echo $tableName.'<br>';
		
			$query = str_ireplace(' NOT NULL AUTO_INCREMENT', ' PRIMARY KEY ASC', $query); // change primary key column format
			
			$query = preg_replace('/, PRIMARY KEY\(.+?\)/', '', $query); // remove ", PRIMARY KEY(column)" (lazy +)
			$query = preg_replace('/, FOREIGN KEY\(.+?\) REFERENCES .+?\(.+?\)/', '', $query); // remove ", FOREIGN KEY(key) REFERENCES table(column)" (lazy +)
			
			$query = str_ireplace(' ENGINE=InnoDB', '', $query);
			$query = str_ireplace(' DEFAULT CHARSET=utf8', '', $query);
			
			// move indexes to separate queries - sqlite doesn't support indexes in CREATE TABLE:
			if ($tableName) {
				$posRemoveStart = -1; $posRemoveEnd = -2;

				$query = str_replace('`(255))', '`)', $query); //!!check improve // remove index length: INDEX `ind_name` (`ind_text_column`(255)) => INDEX `ind_name` (`ind_text_column`)
				$posINDEX = -1;
				while (($posINDEX = strpos(strtoupper($query), ', INDEX `', $posINDEX+1)) !== false) { // find all index definitions, and move them into a seprate query:
					//echo $posINDEX;
					$posBRACKET  = strpos($query, '(', $posINDEX);
					$posINDEXend = strpos($query, ')', $posINDEX); 
					if (!$posBRACKET || !$posINDEXend) break;
					$codeINDEX1 = substr($query, $posINDEX+2, $posBRACKET-1-($posINDEX+2)+1); // ex INDEX `ind_table1_column1` 
					$codeINDEX2 = substr($query, $posBRACKET, $posINDEXend-$posBRACKET+1); // ex (`column1` ASC)
					$codeINDEX  = 'CREATE '.$codeINDEX1.' ON `'.$tableName.'` '.$codeINDEX2; // ex CREATE INDEX `ind_table1_column1` ON `table1` (`column1` ASC)
					//echo '<br>Added query: '.$codeINDEX.'<br/>';
					if (!stripos($codeINDEX, 'IF NOT EXISTS')) $codeINDEX = str_ireplace('INDEX ', 'INDEX IF NOT EXISTS ', $codeINDEX); // add only nonexisting indexes
					//$codeINDEX = str_replace('` (', '`(', $codeINDEX);
					//$codeINDEX = str_replace('`', '', $codeINDEX);
					$rNeededExtraQueries[] = $codeINDEX;
					if ($posRemoveStart < 0) 			$posRemoveStart = $posINDEX;
					if ($posRemoveEnd < $posINDEXend) 	$posRemoveEnd   = $posINDEXend;
					//echo '<br>Added query: '.$codeINDEX.'<br/>';
				}

				// remove INDEX code from original query:
				if ($posRemoveEnd > $posRemoveStart) $query = substr($query, 0, $posRemoveStart).substr($query, $posRemoveEnd+1);
			}
			 
		}
		
		//$query = 'SELECT OE_Table.test AS OE_Table.test, OE_Table2.score AS OE_Table2.score, OE_Table.OEID AS OE_Table.OEID, OE_Table2.OEID AS OE_Table2.OEID FROM OE_Table, OE_Table2 WHERE (OE_Table2.auto=OE_Table.OEID) AND (OE_Table2.score > 0) LIMIT 0,500';
		return $query;
	}
	
	protected function _sqlEscapeString($val) {
		if (!$this->sqliteOk || $val === null) return $val;
		if ($this->sqliteVers < 3) return sqlite_escape_string($val);
		// SQLite3:
		if (!$this->_checkInit()) return $val;
		if (!$this->modePDO) { // direct SQLite
			 return $this->sqlConn->escapeString($val);
		} else { // PDO
			$r = $this->sqlConn->quote($val);
			$rLen = strlen($r);
			if ($rLen >= 2 && $r[0] === "'" && $r[$rLen-1] === "'")
				$r = substr($r, 1, $rLen-2); // remove wrapping quotes
			return $r;
		}		
	}
	
	public function StringQuoteChar() { // SQLite needs 'string', "string" is very dangerous as it means identifier
		return "'";
	}

	
	// Prepared statements ////////////////////////////////////

	protected function _toPS($code) { // for query code containing placeholders "?", format placeholders as ":pi" for SQLite/PDO
		// transform query code into prepared statement query, replacing ? with :pi, ex. ":p0", ":p1" etc.
		if (strpos($code, '?') === false) return $code;
		
		// ex "INSERT INTO `user` (name, age) VALUES (?, ?)"
		$split = explode('?',$code);
		$count = count($split); if (!$count) return $code;
		$codePS = $split[0];
		for ($i=1; $i<$count; $i++) { $codePS .= ':p'.($i-1).$split[$i]; }
		// ex "INSERT INTO `user` (name, age) VALUES (:p0, :p1)"
		//echo 'Formatted placeholders: '.$codePS.'<br>';
		return $codePS;
	}
	
	
	protected function _psBindValues() {
		if (!$this->preparedStatement || !$this->psVals) return false;
		//echo 'SQLite bindValues<br/>';
		if ($GLOBALS['oephpDebugMode']) {
			;echo "<span style='font-size:7px;'>PS: prepared query ={$this->psQuery}</span><br/>";
		}
		foreach ($this->psVals as $i=>$val) {
			$placeholder = ':p'.$i;
			$type = (isset($this->psTypes[$i])) ? $this->psTypes[$i] : 's';
			//echo " Typechar $type ";
			$type = (isset($this->typeCorrespondence[$type])) ? $this->typeCorrespondence[$type] : $this->typeCorrespondence['s'];

			$br = $this->preparedStatement->bindValue($placeholder, $val, $type);
			if (!$br) return false;
			if ($GLOBALS['oephpDebugMode']) echo "<span style='font-size:7px;'>PS: binding value ph=$placeholder, value=$val, type=$type</span><br/>";
			//echo "Binding ok<br/>";
		}
		return true;
	}
	
	protected function _preparePS() { // run sql engine Prepare
		if (!$this->sqlConn || $this->sqliteVers < 3) return false;
		if (!parent::_preparePS()) return false;
		//echo 'SQLite _preparePS()<br/>';

		try {
			echo '<br>Preparing query: '.$this->psQuery.'<br>';
			$this->preparedStatement = $this->sqlConn->prepare($this->psQuery);
		} catch (Exception $ex) { return false; } // may happen in PDO mode if no table in database
		//echo 'SQLite prepared statement: ';var_dump($this->preparedStatement);echo '<br>';
		if (!$this->_psBindValues()) return false;
		//if (!$this->modePDO) { // direct SQLite
		//} else // PDO mode
		
		return true;
	}
	
	protected function _execPS() { // execute prepared statement
		if ($this->sqliteVers < 3 || !$this->preparedStatement) return false;
		if (!parent::_execPS()) return false; // _preparePS is called here if necessary
		//echo 'SQLite _execPS()<br/>';

		$tm_start = microtime(true);
		try {
			$r = $this->preparedStatement->execute();
		} catch (Exception $ex) { return false; }
		echo "<span style='color:silver'>==TIME</span>::".(round((microtime(true) - $tm_start)*10000)*0.1)." for sqlite prepared execute<br/>";
		//echo 'SQLite prepared exec result:';var_dump($r);echo '<br/>';
		
		if (!$r) { // query didn't succeed
			$this->_onQueryError();
			return false;
		}
		
		if (!$this->modePDO) { // direct SQLite
			return $r; // result type is SQLite3Result
		} else { // PDO
			return $this->preparedStatement; // result type is PDOStatement
		}
	}
	
	
}
