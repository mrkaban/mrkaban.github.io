<?php
/*_______________________________________________
|                                                |
|    ©2012 Element Technologie - openElement     |
|________________________________________________|

	ATTENTION! SAVE IN UTF8 WITHOUT BOM, DO NOT EDIT/SAVE IN VISUAL STUDIO
	
	Dynamic/DB tools
	
	 - Data/result containers: in each container, data is represented as set of named "columns", each column can either contain rows or hold one value.
	   Columns can form a hierarchy if they represent result of grouped db query etc., in which case there's a correspondence between rows of certain columns
	   
	 - Manager of DBValues (dynamic data pointers/references: each DBValue holds address of data in data container; different addresses can be assigned by actions)
*/

$dir_dbDataContainersphp = dirname(__FILE__);
require_once $dir_dbDataContainersphp."/OEDynUtils.php";

function oedSlice($str, $start, $end) {
    $end = $end - $start;
    return substr($str, $start, $end);
}


 // .................................................................................... \\
//	== Data containers =================================================================  \\

// Basic class to hold data (results)
class OEDataContainer {
	
	var $Name;			// Unique identifier of result
	
	var $Values;		// Holds values as an associative array of values or "columns" (column contains array of row values), 
						// ex Values['RedirectURL'] = ["http://google.com", "http://google.fr"] (2 rows)
						// in Values[key], key is an unique data ID, which allows referencind this data using "address" = container+key (used primarily by DBValues)
						
	var $ParentColumns;	// for each column, indicate parent column (usually primary key of related table) if any
	var $ParentRows;	// for each "child" column, for each row, indicate corresponding parent row index
						// see IncCurrentRow() for more comments
					
	var $PKeys; 		// when name of table's primary key column is different from "oeid", it is stored in this array as $PKeys['TabName'] = 'TabName.PKeyColumnName'
	
	// Row handling, used mostly in iterators:
	var $iRow;			// current row that applies to the whole result (ex. row of SQL result)
	var $iRows;			// "individual" current row indexes for given columns
	
	public static $iCurrentRow;
	
	
	public static $magicQuotesOn; // if need to remove \ in GET POST and COOKIE
	
	// !Attention - when adding fields, update AddFromContainer()
	
	function __construct($Name = '') // constructor
		{ $this->Name = $Name; $this->Reset(); $this->PKeys = array(); }

	function GetName() 		
		{ return $this->Name; }
		
	function SetName($Name)	
		{ $this->Name = $Name; }
		
	function Reset() 
		{ $this->Values = array(); /*$this->PKeys = array();*/ }

	function SetFromAssociativeArray($ar) {
		$this->Values = $ar;
	}
	
	function SetPrimaryKey($pkeyFullName) { // $pkeyFullName = 'TabName.PKeyColumnName'
		$pos = strpos($pkeyFullName, '.'); if ($pos === false) return;
		$tabName = substr($pkeyFullName, 0, $pos); //$colName = substr($pkeyFullName, $pos+1);
		$this->PKeys[$tabName] = $pkeyFullName; // $PKeys['TabName'] = 'TabName.PKeyColumnName'
	}
	
	function _ArrayMerge(&$orig, &$toadd) {
		if (empty($toadd)) return;
		if (empty($orig)) $orig = $toadd; else $orig = array_merge($orig, $toadd);
	}
	
	function AddFromContainer($container) { // add data columns from another container, overwrites columns with identical names
		$this->_ArrayMerge($this->Values, $container->Values);
		$this->_ArrayMerge($this->ParentColumns, $container->ParentColumns);
		$this->_ArrayMerge($this->ParentRows, $container->ParentRows);
		$this->_ArrayMerge($this->PKeys, $container->PKeys);
		//$this->iRows
	}

//	____________________________________
//	Get data ///////////////////////////////
	
	function GetValue($valID, $iRow = -1, $pKeyValue = false) { // get value by its ID; when value is an array of row values, can return all array if $iRow is not specified
		// $pKeyValue=true means use corresponding primary key value(s) instead of value itself
		
		if ($valID === null || $valID === '') {
			//var_dump($valID);
			return null;
		}

		// special case, used by iterators - if $iRow=0 and $this->iRows[$valID] exists, get data for "current row":
		if (!empty($this->iRows) && $iRow === 0 && isset($this->iRows[$valID])) //  && $this->iRows[$valID] !== null
			$iRow = $this->iRows[$valID];
		
		// if ($pKeyValue) return null; // primary keys only supported in db results
		if ($pKeyValue) { //!!improve for multi-column primary keys
			// replace specified column name by primary key column name:
			$pos =  strpos($valID, '.'); 
			if ($pos !== false) {
				$tabName = substr($valID, 0, $pos);
				$valID = (isset($this->PKeys[$tabName])) ? $this->PKeys[$tabName] : $tabName.'.oeid'; // default primary key name "TabName.oeid", or custom one set by action
			} else
				return null; // $valID is not "table.column", no support for primary key
		}
		
		$r = $this->getData($valID, $iRow);
		
		if ($iRow >= 0 && is_array($r)) // contains rows (at least one)
			// return (/*$iRow === 0 ||*/ array_key_exists($iRow, $r)) ? $r[$iRow] : (isset($r[0]) ? $r[0] : null);
			return (isset($r[$iRow]) ? $r[$iRow] : null); //!!check
		else 
			return $r; // not array => no rows; return the value as is	
	}
	
	// Overridable function called by GetValue. Use of $iRow is reserved for descendants
	protected function getData($valID, $iRow) {
		return (!isset($this->Values[$valID])) ? null : $this->Values[$valID];
	}
	
//	____________________________________
//	Row control ////////////////////////////

	function GetNRows($valID = '') { // get num of rows for specific column or for all the data "table" if valID is not specified
		if (empty($this->Values)) return 0;
		if ($valID === '') { // return total num rows for all the columns = num rows for "longest" column
			$maxNRows = 0;
			foreach($this->Values as $column) { // column may contain either rows or singular value (=1 row)
				if ($column === null) continue;
				$isarray =  is_array($column); if ($isarray && empty($column)) continue;
				$nRows = ($isarray) ? count($column) : 1; 
				if ($maxNRows < $nRows) $maxNRows = $nRows;
			}
			return $maxNRows;
		} else { // return num rows for specified column
			if ($valID === null || !isset($this->Values[$valID])) return 0;
			return (count($this->Values[$valID]));
		}
	}

	function InitIteration() { // initialize iteration-related values
		if (empty($this->Values)) return;
		$this->iRow = 0; OEDataContainer::$iCurrentRow = $this->iRow;
		$this->iRows = array(); 
		if ($this->ParentRows === null) $this->ParentRows = array();
		if ($this->ParentColumns === null) $this->ParentColumns = array();
	}
	
	function StartIteration($columnToIterate = '', $init = true) {
		if (empty($this->Values)) return;
		if ($init) $this->InitIteration();
		if (!$columnToIterate) { // iterate rows of all data columns together
			//var_dump($this->Values);
			foreach($this->Values as $k=>$v) $this->iRows[$k] = 0;
		} else
			$this->iRows[$columnToIterate] = 0; // iterate rows of specific column/value
	}
	
	function AddIterationColumn($columnToIterate) {
		if (!$columnToIterate) return;
		$this->iRows[$columnToIterate] = 0;
	}
	
	function NextRow() { 
		// step to next data row 
		// considers group-parent relations between columns - parent columns' rows may stay unchanged
		// returns false if stepped beyond last row in one of the columns
		
		/* 	Description of structure info:
			ParentColumns[column] - column's parent column, absent if no parent
			ParentRows[column][row] - parent row corresponding to column's row, used to handle grouping;
			if ParentColumns[column] exists and ParentRows[column] doesn't, it means 1to1 relation, like to primary key of same db table
			This can handle "tree" hierarchy where primary key column may have parent - other table's primary key column, while other columns use primary key column as 1to1 parent
		* * * */
		if (empty($this->iRows)) $this->StartIteration();
		
		$totalRows = $this->GetNRows();
		//echo "<br/>Next Row: Total Rows: $totalRows<br/>";
		//echo 'Values:';var_dump($this->Values);
		//echo 'ParentColumns:';var_dump($this->ParentColumns);
		//echo 'ParentRows:';var_dump($this->ParentRows);
		// ________________________________________
		// if iterate only 1 column - no relations:
		if (count($this->iRows) === 1) {
			foreach($this->iRows as $column=>$row) {
				if (!array_key_exists($column, $this->Values)){
					//echo 'Iterator ended 01 at '.$column.'<br/>';
					return false; // column not found in container
				}
				$columnValues = $this->Values[$column];				// all data rows for this column
				if ($row + 1 >= count($columnValues)) {
					//echo 'Iterator ended 02 at '.$column.'<br/>';
					return false; // already at last row, stop iteration
				}
				$this->iRow++; $this->iRows[$column]++; 			// select next row
				OEDataContainer::$iCurrentRow = $this->iRow;
			}
			return true; // next row selected
		} // ______________________________________
		
		
		$columnsInc = array(); // row inc step for every column
		// can = 1, or = 0 when next "child" column's row still corresponds to same parent row => parent row should not increase yet
		// ex. if there are still articles for current customer, article inc = 1 and customer inc = 0
				
		// for each column, test that data exist and mark "parent" columns by $columnInc[]=0:
		$parents_remain = false;
		foreach ($this->iRows as $column=>$row) {
			$values = $this->Values[$column]; // data column
			if (empty($values)) {
				//echo 'Iterator ended 1 at '.$column.'<br/>';
				return false; // no rows for this column
			}
			
			// do not process child<=1to1=>parent cases, it will be done later:
			if ( array_key_exists($column, $this->ParentColumns) && !array_key_exists($column, $this->ParentRows)) continue;
				
			// if this column is not yet marked (as someone's parent), mark (for now) as need-to-inc-row
			if (!array_key_exists($column, $columnsInc)) $columnsInc[$column] = 1; // at the end of this loop, only non-parent columns = 1, and parent & sub-parent = 0
				
			// if column has parent, mark parent:
			if (!empty($this->ParentColumns) && 
					array_key_exists($column, $this->ParentColumns)) { // && array_key_exists($column, $this->ParentRows)
				$parent = $this->ParentColumns[$column]; if (!$parent) continue;
				$columnsInc[$parent] = 0; // mark parent column
				$parents_remain = true; // complex hierarchy present
			}
		}
		
		// for 1to1 relations skipped above, reference parent's value (becomes the same variable):
		foreach ($this->iRows as $column=>$row) {
			if (array_key_exists($column, $this->ParentColumns) && !array_key_exists($column, $this->ParentRows)) { // 1to1 relation
				$parent = $this->ParentColumns[$column]; if (!$parent) continue;
				$columnsInc[$column] =& $columnsInc[$parent]; // child and parent will change together
		}	}
		
		// now, for each column, find the row increment = $columnsInc[$column] (0/unchanged or 1/increase; -1 is same as 1):
		while ($parents_remain) {
			// calculation in several waves, each time for a higher child level until all parents are processed
			$parents_remain = false;
			foreach ($this->iRows as $column=>$row) {
				if ($columnsInc[$column] < 1) continue; // already done with, or marked as no-inc (may change during subsequent iterations, but for now we ignore it)
				
				// we know that we need to inc $column's row; test if parent column needs to inc as well:
				// (note: all columns that need inc & already processed are marked as -1)
				if (array_key_exists($column, $this->ParentColumns)) { // parent column exists
					$parent = $this->ParentColumns[$column]; if (!parent) continue; // parent column
					if (!array_key_exists($column, $this->ParentRows) || // 1to1 relation (ex. primary key column of the same table)
						 $row+1 >= count($this->ParentRows[$column])) { // or error in structure
						// do not change current row
					} else { // parent may be grouped, test if its row should be increased when child row increases
						$currParentRow = $this->ParentRows[$column][$row]; // index of parent row corresponding to curr child row
						$nextParentRow = $this->ParentRows[$column][$row+1]; // same for next child row
						if ($nextParentRow > $currParentRow) {
							$parent_has_parent = (array_key_exists($parent, $this->ParentColumns));
							$parents_remain = ($parents_remain || $parent_has_parent); // need one more wave to check parents (if they exist) of incremented parents
							$columnsInc[$parent] = ($parent_has_parent) ? 1 : -1; // inc parent, and next time if it itself has no parent, skip it (-1)
						}
					}
					$columnsInc[$column] = -1; // mark as processed, to ignore in next wave
				}
		}	}
		// Now $columnsInc[] contains row inc steps for every column.

		// if, for any column, increment goes beyond its last row, return false:
		foreach ($this->iRows as $column=>$row) { // for each column
			if ($columnsInc[$column] === -1) $columnsInc[$column] =  1;	// -1 => 1
			$columnValues = $this->Values[$column];	// all data rows for this column
			$nRows = count($columnValues);
			if ($row + $columnsInc[$column] >= $nRows) { // inc goes beyond last row
				// special case: there are several rows in container, but this column contain only 1 row, for example COUNT value 
				// (may also be a usual "parent" column, but it's safe to treat it as special if there are child columns with more than 1 row)
				if ($totalRows > 1 && $nRows === 1) {
					$columnsInc[$column] = 0; // do not increment later
				} else {
					//echo 'Iterator ended 2 at '.$column.'<br/>';
					return false; // stop iterating rows
				}
			}
		}
		
		// cnahge current row indexes according to calculated increments
		$this->iRow++; OEDataContainer::$iCurrentRow = $this->iRow;
		foreach ($this->iRows as $column=>$row) {
			$this->iRows[$column] += $columnsInc[$column];
		}
		//echo "Current rows for".$this->Name.":";var_dump($this->iRows);
		return true; // next row selected
	}
	
	function RowSpan($column, $iRow = -1, $return0InsideSpan = false) { // recursive
		/*	during iteration, for current row of $column, return the amount of corresponding child rows
			can used to generate dnamic table with <td rowspan>, and to calculate total number of iterations needed to show N parent rows
			
			$return0InsideSpan=true activates additional test: if current iteration is not the first one corresponding to current $column's row, return 0
			(example: if on row 1 we had <td rowspan=4>, this <td> should be skipped for rows 2,3 and 4, and RowSpan() will return 0)
			
			Imagine hierarchy Customer 1-many Date 1-many Purchase, for any given row of Customer there may be several Dates, each Date having several Purchases
			In this case, ParentRowSpan("Customer", i) = number of all Purchases for all Dates corresponding to row i in Customer
			
			Example of 3-level hierarchy:

				* 5 rows in Customer column;

				* 10 rows in Date column, ParentColumns(Date) = Customer, ParentRows(Date) = [0,0,1,1,1,1,2,3,4,4] = corresponding rows in Customer column,
			means 2 Dates for Customer row 0, then 4 dates for 1, 1 for 2 and 3, and 2 dates for Customer row 4

				* 14 rows in Purchase column, ParentColumns(Purchase) = Date, ParentRows(Purchase) = [0,1,2,3,4,4,5,6,7,8,8,8,8,9]
			means Purchases 5 and 6 both correspond to Date=4 (which in turn corresponds to Customer=1)
			and four Purchases - 10, 11, 12 and 13 - correspond to Date=8 (which in turn corresponds to Customer=3):
		* * * */
		
		if (!$column || empty($this->iRows) || !isset($this->iRows[$column])) return 1;
		if (empty($this->ParentColumns) || count($this->iRows) === 1) return 1; // no hierarchy
		if ($iRow < 0) $iRow = $this->iRows[$column];
		
		// If $column has 1to1 parent column (primary key or another), calculate row span for that parent column - all 1to1 children should use same row span:
		while ( isset($this->ParentColumns[$column]) && 
			   !isset($this->ParentRows[$column])) { // while 1to1 parent exists
			$column = $this->ParentColumns($column); // analyse its parent
		} // now $column = its "top" 1to1 parent, ex. if column 1<=>1 primary key that itself 1<=>1 another primary key, take this last primary key
		
		$r = 0;
		foreach ($this->iRows as $column2=>$currRow2) {
			$column2RecursiveSpan = 0;
			if ($column2 !== $column && // $column2 is another iterated column
					array_key_exists($column2, $this->ParentColumns) && 
						$this->ParentColumns[$column2] === $column && // $column is parent of $column2
							array_key_exists($column2, $this->ParentRows)) { // not 1to1 relation
				foreach ($this->ParentRows[$column2] as $row2=>$parentrow2) { // check list of parent rows for $column2
					if ($parentrow2 === $iRow) // if parent row = current $column's row
						
						if ($return0InsideSpan) {
							if ($row2 < $currRow2) // the current iteration is not the first iteration corresponding to $column's $iRow row
								return 0;
						}
						
						$column2RecursiveSpan += $this->RowSpan($column2, $row2, false); // inc rowspan by num of iterations needed for $column2's current row
				}
			}
			if ($r < $column2RecursiveSpan) $r = $column2RecursiveSpan;
		}
		if ($r < 1) $r = 1;
		return $r;	
	}
	
	function RowSpanForSetOfDBValues($manager, $arDBValues, $iRow = -1, $return0InsideSpan = false) {
		// same as RowSpan() but for a set of data columns targetted by DBValues in $arDBValues array
		// can be used for <td> or other html block containing html modifiers (each modifier can use several DBValues)
		if (empty($arDBValues)) return 1;
		$r = 1;
		foreach($arDBValues as $value) {
			if ($this->Name === $manager->DBValueManager->GetContainerNameByInd($value)) { // value targets this container
				$rowspan = $this->RowSpan($manager->DBValueManager->GetNameByInd($value), $iRow, $return0InsideSpan);
				if ($return0InsideSpan && $rowspan === 0) return 0;
				if ($r < $rowspan) $r = $rowspan;
			}
		}
		return $r;
	}
	
//	____________________________________
//	JSON ///////////////////////////////////
	
	function fromJSON($jsonStr) {
		$this->Reset();
		$o = OEDynUtils::decodeJson($jsonStr); if (empty($o)) return;
		if (array_key_exists('Name', o)) $this->Name =& $o['Name'];
		if (array_key_exists('Values', o)) $this->Values =& $o['Values'];
		// if (array_key_exists('PKeys', o)) $this->PKeys =& $o['PKeys'];
	}
	
	function toJSON() {
		$iRows = $this->iRows; $this->iRows = null; // to not pass unnecessary info
		$jsonStr = OEDynUtils::encodeJson($this);
		$this->iRows = $iRows; // restore info
		return $jsonStr;
	}	
	
	function Clean($elem) { //!!improve later
		// basic security
		if (empty($elem)) return $elem;
		if(!is_array($elem))
			$elem = htmlspecialchars($elem, ENT_QUOTES, 'UTF-8');//!!check encoding etc. // keep sync  with HTMLSPEC_QUOTES below
		else
			foreach ($elem as $key => $value)
				$elem[$key] = $this->Clean($value);
		return $elem; 
	}	
	
}


//_________________
// Extends OEDataContainer.
// Container of formatted items
class OEConstSrcContainer extends OEDataContainer {

	function _TranslateLS(&$val, $lang) {
		if (isset($val['Items']) && !is_string($val)) { // Localizable string detected //  && !is_string($val) is necessary as some php implementation consider that $val['Items'] is same as $val[0] = first char in the string, in the case of string. rather ugly.
		
			$lsItems =& $val['Items']; // lang-specific values in LocalizableString
			$langUse = (isset($lsItems[$lang])) ? $lang : 'DEFAULT';
			// replace localizable string by value coresponding to current or default language:
			$val = (isset($lsItems[$langUse])) ? $lsItems[$langUse] : null;
			//echo 'Translated Constant:';var_dump($val);
		}
	}
	
	function TranslateLocalizableValues($lang) {
		if (!$lang) return;
		$constants =& $this->Values;
		foreach($constants as $id=>$ar) {
			if (!isset($ar)) continue;
			$aVal =& $constants[$id];
			if ((array)$aVal !== $aVal) { // not array
				$this->_TranslateLS($aVal, $lang);
			} else { // array
				foreach ($aVal as $i=>$v) {
					$this->_TranslateLS($aVal[$i], $lang);
				}
			}
		}
		//echo 'Translated FItems:';var_dump($this->Values);
	}
}


//_________________
// Extends OEDataContainer.
// Virtual container that handles POST - html inputs' values, normally received through $_POST
// Value may be user-specified values named POST.name ex POST.SelectCategory, or auto-included from this page's inputs, named WExx.type.name ex. WE12345678.select.SelectCategory
class OEHTMLValsContainer extends OEDataContainer {

	function ObtainPOSTValues() { // to be called after SetFromAssociativeArray etc.
		//echo 'HTMLInputs:';var_dump($this->Values);
		if (empty($this->Values)) return;
		
		$isPost = (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) == 'POST');
		//echo 'Is Post: '.($isPost?'true':'false').'<br>';
		$params = ($isPost) ? $_POST : $_GET;
		
		foreach ($this->Values as $id=>$val) {
			$inputName = null;
			
			// Scan all data named POST.xx, and all names of HTML inputs on this page coming from json FormInputs array:
			if (substr($id, 0, 5) === 'POST.') {
				$inputName = substr($id, 5);
				//echo 'Searching POST.'.$inputName.'<br/>';
			} else {
				$split = explode('.', $id); 
				$inputName = $split[count($split)-1]; // take input_name in element_id.input_type.input_name
				//echo 'Searching this page input '.$inputName.'<br/>';
			}
			
			if (isset($params[$inputName])) { // value to receive from POST-submitted data
				if (OEDataContainer::$magicQuotesOn) $params[$inputName] = stripslashes($params[$inputName]);
				$postVal = $this->Clean($params[$inputName]); //!!check security
				$this->Values[$id] = $postVal;
				//echo "Found POST input $id = $postVal<br/>";
			}
		}
	}	

}

//_________________
// Extends OEDataContainer.
// Container of formatted items
class OEFormattedItemsContainer extends OEDataContainer {

	var $Manager;		// Main manager
	
	var $lastGetNRows;	// last result of GetNRows() for each column
	
	function __construct($Name, $mainManager) { // constructor
		parent::__construct($Name);
		if (empty($mainManager)) return;		
		$this->Manager = $mainManager;
	}
	
	function TranslateLocalizableValues($lang) {
		if (!$lang) return;
		$fItems =& $this->Values;
		foreach($fItems as $id=>$item) {
			$fItem =& $fItems[$id];
			// apply language to Format:
			if (isset($fItem['Format']) && isset($fItem['Format']['Items'])) { // Localizable string detected
				$lsItems =& $fItem['Format']['Items']; // lang-specific values in LocalizableString
				$langUse = (isset($lsItems[$lang])) ? $lang : 'DEFAULT';
				// replace localizable string by value coresponding to current or default language:
				$fItem['Format'] = (isset($lsItems[$langUse])) ? $lsItems[$langUse] : null;
			}
			// Apply language to Default string:
			if (isset($fItem['Default']) && isset($fItem['Default']['Items'])) { // Localizable string detected
				$lsItems =& $fItem['Default']['Items']; // lang-specific values in LocalizableString
				$langUse = (isset($lsItems[$lang])) ? $lang : 'DEFAULT';
				// replace localizable string by value coresponding to current or default language:
				$fItem['Default'] = (isset($lsItems[$langUse])) ? $lsItems[$langUse] : null;
			}

		}
		//echo 'Translated FItems:';var_dump($this->Values);
	}

	function GetNRows($valID = '') { // get num of rows for specific column or for all the data "table" if valID is not specified
		if (empty($this->Values)) return 0; // nothing to iterate
		
		if ($valID === '') { // return max num rows for all formatted items - slow! avoid calling
			$maxNRows = 0;
			foreach($this->Values as $column) {
				$rows = $this->GetNRows($column); if ($rows && $maxNRows < $rows) $maxNRows = $rows;
			}
			//$this->lastGetNRows = $maxNRows;
			return $maxNRows;
		}		
		
		//$r = parent::GetNRows($valID);
		if (!isset($this->Values[$valID])) return 0;
		
		// there may be one formatted item, ex. FormattedItems.Months, but its placeholders may contain suffixes that makes value an array, ex ALLROWS or SPLIT
		if (!isset($this->lastGetNRows)) $this->lastGetNRows = array();
		if (!isset($this->lastGetNRows[$valID])) $this->lastGetNRows[$valID] = 0;
		$rowsInFormat = $this->Manager->EvaluateFormattedItem($this->Name.'.'.$valID, 0, true);
		if ($rowsInFormat >= 1) { // some placeholders returned arrays
			$this->lastGetNRows[$valID] = $rowsInFormat;
			return $rowsInFormat;
		} else
			return 0; // no iteration as no arrays in placeholders
	}	
	
	// iterations - make possible to iterate several FItem values simultaneously
	function NextRow() { 
		if (empty($this->iRows)) return false; // no columns to iterate
		
		$advance = false;
		foreach($this->iRows as $column=>$row) {
			if (!array_key_exists($column, $this->Values)){
				//echo 'Iterator ended 01 at '.$column.'<br/>';
				return false; // column not found in container
			}
			
			if (!isset($this->lastGetNRows[$column])) continue;
			$numRows = $this->lastGetNRows[$column];
			
			if ($row + 1 >= $numRows) {
				//echo 'Iterator ended 02 at '.$column.'<br/>';
				// return false; // already at last row, stop iteration
				continue;
			}
			$advance = true;
			$this->iRow++; $this->iRows[$column]++; 			// select next row
			OEDataContainer::$iCurrentRow = $this->iRow;
		}
		return $advance; // next row selected in at least one of columns
	}

	function RowSpan($column, $iRow = -1, $return0InsideSpan = false) {
		return 1;
	}

}

//_________________
// Extends OEDataContainer.
// Virtual container that returns current value of DBValue used by given element property, ex GetValue("WE12345678.MainImage_URL")
class OEEventParamsContainer extends OEDataContainer {
	
	var $Manager;		// Main manager
	var $JSDynElements;	// Part of deserialised JSON data from (var).php taken from main manager, corresponding to elements
	
	function __construct($Name, $mainManager) { // constructor
		parent::__construct($Name);
		if (empty($mainManager) || empty($mainManager->JSData)) return;
		
		$this->Manager = $mainManager;
		$jsData = $mainManager->JSData;
		if (array_key_exists('DynElements', $jsData)) {	$this->JSDynElements = $jsData['DynElements'];	}		
	}	
	
	// Overriden function:
	function GetValue($valID, $iRow = -1, $pKeyValue = false) { // Get value of given element property. See comments below.
		if (($valID === null || $valID === '') || empty($this->JSDynElements)) return null;
		
		// $valID should be like "WE12345678.MainImage_URL" 
		// where MainImage_URL is the name of element's dynamic property's field (or just dynamic property when it's not complex = has only 1 default field)
		// whatever comes after is considered a "suffix instruction": ex ".SHA" in "WE12345678.UserPassword.SHA"
		// This function gets index of DBValue used by specified property, and requests its value
		// Structure reminder: $this->JSDynElements[$elemName]["Properties"][$propName] = DBValue index
				
		$pos = strpos($valID, '.'); if ($pos === false) return null;
		$elemName = substr($valID, 0, $pos); // ex WE12345678
		$propName = substr($valID, $pos+1); // ex MainImage_URL
		
		$suffix = null;
		$pos = strpos($propName, '.'); 
		if ($pos !== false) { // suffix included, ex. 
			$suffix = substr($propName, $pos+1);
			$propName = substr($propName, 0, $pos);
		}
		if (empty($elemName) || empty($propName) || !isset($this->JSDynElements[$elemName])) return null;
		
		$elemJSData = $this->JSDynElements[$elemName]; if (empty($elemJSData)) return null;
		if (!isset($elemJSData['Properties'][$propName])) return null;
		$valueInd = $elemJSData['Properties'][$propName];
		if ($valueInd === null) return null;
		if ($suffix) $valueInd .= '.'.$suffix; // ex "4.SHA"

		return $this->Manager->GetDBValue($valueInd, $iRow, $pKeyValue); // get value of DBValues[$valueInd] (get data referenced by given DBValue)
	}
	
}

//_________________
// Extends OEDataContainer.
// URL param Container providing GET and POST page/url paramters, ex GetValue("Param1") or, to choose explicitly GET vs POST, GetValue("GET.Param1")
class OEPageParamsContainer extends OEDataContainer {
	
	var $IsPost; // true when there are Post parameters // not used atm
	var $DefaultArray; // can be GET or POST // not used atm
	
	function __construct($Name) {
		parent::__construct($Name);
		$this->IsPost = false; //!!!!check // (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) == 'POST');
		$this->DefaultArray = ($this->IsPost) ? $_POST : $_GET;
	}	
	
	// Overriden function:
	protected function getData($valID, $iRow) { // get page parameter, may use GET or POST
		if ($valID === null || $valID === '') return null;
		
		//echo "Requested parameter $valID<br/>";
		
		$paramArray = null;
		// $forceGet = $forcePost = false;
		if (strpos($valID, 'GET.') === 0) {
			$paramArray = $_GET; $valID = substr($valID, 4);
		} else if (strpos($valID, 'POST.') === 0) {
			$paramArray = $_POST; $valID = substr($valID, 5);
		} else {
			if ($this->IsPost && isset($_POST[$valID])) $paramArray = $_POST; //!!check
			else $paramArray = $_GET; // by default, GET is only used when parameter not found in POST
		}
		if (!isset($paramArray[$valID])) return null; // no value found for parameter

		if (OEDataContainer::$magicQuotesOn) $paramArray[$valID] = stripslashes($paramArray[$valID]);
		$r = $this->Clean($paramArray[$valID]);
		//echo "Retrieved parameter $valID = $r<br/>";
		
		return $r;
	}
	
}

//_________________
// Extends OEDataContainer.
// Provides session values.
class OESessionContainer extends OEDataContainer {
	
	function __construct($Name) {
		parent::__construct($Name);
	}	
	
	// Overriden function:
	protected function getData($valID, $iRow) { // get session data
		// if ($valID === null || $valID === '') return null;
		
		// if (empty($_SESSION)) return null;
		
		//!!improve accessing array items etc., ex $valID="User[0].Choice[1].Name"?
		
		if (!isset($_SESSION[$valID])) return null;
		
		$r = $_SESSION[$valID];
		//echo "Session value $valID=$r<br/>";
		return $r; // $this->Clean($r);
	}
	
	/*function Clean($elem) { //!!improve later
		if (empty($elem)) return $elem;
		// basic security
		if(!is_array($elem))
			$elem = htmlentities($elem, ENT_QUOTES, 'UTF-8');//!!check encoding etc.
		else
			foreach ($elem as $key => $value)
				$elem[$key] = $this->Clean($value);
		return $elem; 
	}*/
	
}

//_________________
// Extends OEDataContainer.
// Provides cookie values.
class OECookieContainer extends OEDataContainer {
	
	function __construct($Name) {
		parent::__construct($Name);
	}	
	
	// Overriden function:
	protected function getData($valID, $iRow) { // get cookie data
		if (!isset($_COOKIE['oeCookies'][$valID])) return null;
		$r = $_COOKIE['oeCookies'][$valID]; //echo "Cookie value $valID=$r<br/>";
		if (OEDataContainer::$magicQuotesOn) $r = stripslashes($r);
		return $r;
	}
	
}

//_________________
// Extends OEDataContainer.
// Virtual container that handles html inputs' values, normally received through $_POST
// Value may be user-specified values named POST.name ex POST.SelectCategory, or auto-included from this page's inputs, named WExx.type.name ex. WE12345678.select.SelectCategory
class OEAutoContainer extends OEDataContainer {

	var $Manager;		// Main manager
	

	function __construct($Name, $mainManager) { // constructor
		parent::__construct($Name);
		if (empty($mainManager)) return;		
		$this->Manager = $mainManager;
	}
	
	function InitValues($manager) { // to be called after SetFromAssociativeArray etc.
		//echo 'HTMLInputs:';var_dump($this->Values);
		if (empty($this->Values)) return;
		
		$this->Values['CurrIterationRow'][0] = null; // (isset(OEDataContainer::$iCurrentRow)) ? OEDataContainer::$iCurrentRow : 0;
		
		// culture (ex DEFAULT, FR) and language code (ex EN, FR):
		$culture = strtoupper($manager->currLang);
		$this->Values['PageCulture'][0] = $culture;
		$isDefault = ($culture === 'DEFAULT');
		$this->Values['PageLocalization'][0] = ($isDefault) ? '' : $culture;

		$this->Values['CaptchaOk'] = null;
		$this->Values['SecurPostTokenOk'] = null;

		$lang = ($isDefault && isset($manager->JSData['DefCultureTrueCode'])) ? $manager->JSData['DefCultureTrueCode'] : $culture;
		$this->Values['PageLanguage'][0] = $lang;
		
		$this->Values['CurrTimeStamp'][0] = time();
		
		// Site root:
		if (OEDynUtils::isLocalWebServer()) { // for local previs tests
			//var_dump($_SERVER);
			if (!isset($this->Values['SiteRootURL'])) $this->Values['SiteRootURL'] = array();
			$port = (isset($_SERVER['SERVER_PORT'])) ? $_SERVER['SERVER_PORT'] : '8086';
			$this->Values['SiteRootURL'][0] = "http://localhost:$port/"; //!!check and keep updated
		} else
		if (!isset($this->Values['SiteRootURL'][0]) || empty($this->Values['SiteRootURL'][0])) {
			$this->Values['SiteRootURL'][0] = '';
			
			if (isset($_SERVER['PHP_SELF']) && isset($_SERVER['REQUEST_URI']) && isset($_SERVER['HTTP_HOST'])) {
				$scriptname=explode('/',$_SERVER['PHP_SELF']);
				if (!empty($scriptname) && isset($this->Values['PathFromRoot'][0])) {
					$scriptname = $scriptname[count($scriptname)-1];
					$scriptpath=str_replace($scriptname,'',$_SERVER['REQUEST_URI']);
					$rootURL = $_SERVER['HTTP_HOST'].$scriptpath;
					//echo 'Page URL: '.$rootURL.'<br>';
					$rootURL = substr($rootURL, 0, strlen($rootURL) - strlen($this->Values['PathFromRoot'][0]));
					echo '<br> == Site URL auto-detect: '.$rootURL.'<br>';
					$this->Values['SiteRootURL'][0] = $rootURL;
				}
			}
		}
	}	
	
	protected function getData($valID, $iRow) {
		if (empty($valID)) return null;
		
		switch($valID) { // data to recalculate each time
			case 'CurrIterationRow':
				$this->Values['CurrIterationRow'][0] = (isset(OEDataContainer::$iCurrentRow)) ? OEDataContainer::$iCurrentRow : 0;
				return $this->Values['CurrIterationRow'];
		}
		
		if (isset($this->Values[$valID])) return $this->Values[$valID];
		
		// certain data is calculated on demand but only once:
		switch($valID) {
			case 'CaptchaOk':
				$this->Values['CaptchaOk'][0] = OEDynUtils::IsCaptchaOk();
				return $this->Values['CaptchaOk'];
			case 'SecurPostTokenOk':
				$this->Values['SecurPostTokenOk'][0] = OEDynUtils::IsSecurPostTokenOk((isset($this->Values['PageID'][0])) ? $this->Values['PageID'][0] : null);
				return $this->Values['SecurPostTokenOk'];
		}
		
		return null;
	}


}



//_________________
// OESQLResult class extends OEDataContainer to add data from SQL query result.
// This class uses MySQL engine results; descendant classes may modify the behavior
// Designed to represent result of SQL SELECT, but used for static (non-DB) content as well (? not sure about last part)
class OESQLResult extends OEDataContainer {
	//!!todo change deserialisation and name processing in VB
	var $LastQueryColumns; 	// list of column names added by last query result (in addColumns)
	
	private $modeMySQLiPrepared; // true when $sqliR is of type mysqli_stmt and result binding is needed
	/*
	var $Data; 		// As List(Of String) // array of NumColumns*NumRows size: stores row by row, value[iColumn, iRow] = Data[iRow*NumColumns + iColumn]
	var $ParentTRName; 	// As String
	var $ParentRows; 	// As List(Of String)*/

	function __construct($Name = '', $sqliR = null) { // constructor
		if (!defined('SQLITE3_NUM')) define('SQLITE3_NUM', 2);
		
		$this->SetName($Name);
		// if $sqliR is specified ($sqliR should be result of sqli query, like one returned by mysqli_store_result()),
		// all result columns and rows are stored
		if ($sqliR) $this->addAllRows($sqliR); // extract columns and all rows
	}
	
	// $sqliR parameter is result of sqli
	function addColumns($sqliR, $tabName = "") { 
		// Analyse structure of sqli result (columns of result table) and create $Columns accordingly
		$this->LastQueryColumns = array();
		if (!$sqliR) return;
		if (!$this->Values) $this->Values = array();
		if ($sqliR === true) return; // no data returned, ex INSERT query

		if (!method_exists($sqliR, 'fetch_fields')) { // prepared statement mode
			//echo '<br> = = MySQLi detected PS mode<br>';
			$sqliR = mysqli_stmt_result_metadata($sqliR); // sometimes $sqliR->result_metadata is not defined, ex easyPHP12 PHP5.4
			
			$this->modeMySQLiPrepared = true;
		}
		
		$f = $sqliR->fetch_fields();
		$count = count($f);
		//echo "MySQLi result = num columns: $count<br>";
		for ($i=0; $i<$count; $i++) { 
			$name = $f[$i]->name;
			$this->LastQueryColumns[] = $name;
			if (!isset($this->Values[$name])) $this->Values[$name] = array(); // add new column
		}
	}
	
	function addRowData($row) {
		if (empty($this->Values) || empty($row) || empty($this->LastQueryColumns)) return;

		$countRow = count($row); $countColNames = count($this->LastQueryColumns);
		$keysBoth = false;
		if ($countRow !== $countColNames) {
			$keysBoth = ($countRow === $countColNames*2); // both indexes and name-keys
			if (!$keysBoth) return; // mismatch between row results and column names
		}
		
		//echo 'Last query columns:';var_dump($this->LastQueryColumns);echo '<br>';
		//echo 'Row:';var_dump($row);echo '<br>';
		foreach($row as $key=>$value) {
			if ($keysBoth && !is_int($key)) continue;
			$name = $this->LastQueryColumns[$key]; //echo "Name: $name <br/>";
			if ($name) $this->Values[$name][] = $value; // add new row to column
		}
	}
	
	function addAllRows($sqliR) {
		if (!$sqliR || $sqliR === true) return;
		$this->addColumns($sqliR);
		
		if (!$this->modeMySQLiPrepared) {
			while ($row = $sqliR->fetch_row()) {
				$this->addRowData($row);
			}
			mysqli_free_result($sqliR);//!!attention
			
		} else { // mysqli prepared statement mode
			//echo '<br> = = MySQLi PS bind result mode<br>';
			$numColumns = count($this->LastQueryColumns);
			if (!$numColumns) return;
			//$pa = array_merge(array(implode($this->psTypes)), $this->psVals); // creates array where 1st element is types ex "sddi", and other elements are values
			
			$row = array_fill(0, $numColumns, null); // numeric-key array of values corresponding to columns
			
			$rowRef = array(); foreach($row as $key=>$value) { $rowRef[$key] =& $row[$key]; } // aray of references
			if (!call_user_func_array( array($sqliR, 'bind_result'), $rowRef)) return; // ex. in case of 2 columns corresponds to $sqliR->bind_result($row[0], $row[1])
			
			while ($sqliR->fetch()) {
				//echo '<br> = = MySQLi PS bind result row:';var_dump($row);echo '<br>';
				$this->addRowData($row);
			}
			
			$sqliR->free_result();//!!attention - remove if removed $this->preparedStatement->store_result(); in _execPS in OEDBRequest.php			
		}
		

	}
		
}

// Modified container for SQLite engine
class OESQLite3Result extends OESQLResult {
	
	protected $modePDO;
	
	function setModePDO() { $this->modePDO = true; }
	
	// $sqliR parameter is result of sqli
	function addColumns($sqliR, $firstRow = null) { // , $tabName = ""
		// Analyse structure of sqli result (columns of result table) and create $Columns accordingly
		$this->LastQueryColumns = array();
		if (!$sqliR) return;
		if (!$this->Values) $this->Values = array();
		
		if ($firstRow && !empty($firstRow) && $this->modePDO) { // take columns from the result row keys
			//echo 'Getting column names from first row'.'<br>';
			foreach($firstRow as $name=>$value) {
				if (is_int($name)) continue; // ignore numeric keys
				$this->LastQueryColumns[] = $name;
				if (!isset($this->Values[$name])) $this->Values[$name] = array();
			}
			//echo 'Columns:';var_dump($this->LastQueryColumns);echo '<br>';
			return;
		}

		if ($this->modePDO) return; //!!check - added to keep similar behavior between local and non-supporting servers
		
		$count = (!$this->modePDO) ? $sqliR->numColumns() : $sqliR->columnCount();
		for ($i=0; $i<$count; $i++) { 
			
			$name = '';
			if (!$this->modePDO) 
				$name = $sqliR->columnName($i);
			else {
				//echo "Trying to get name of column $i / $count<br/>";
				try{
					$meta = $sqliR->getColumnMeta($i); $name = $meta['name'];
				} catch(Exception $ex) { return; } // some implementations do not work, like on Izihost - get columns later
			}
			$this->LastQueryColumns[] = $name;
			if (!isset($this->Values[$name])) $this->Values[$name] = array(); // add new column
		}
	}
	
	function addAllRows($sqliR) {
		//echo "<br/>Adding All Rows, PDO={$this->modePDO}<br/>";
		if (!$sqliR || $sqliR === true) return;
		//echo 'addAllRows:';echo $sqliR->numColumns().' columns<br/>';
		$this->addColumns($sqliR);
		//var_dump($this->Values);
		//echo '<br/>----<br/>';
		if (!$this->modePDO) {
			while ($row = $sqliR->fetchArray(SQLITE3_NUM)) {
				$this->addRowData($row);
			}
		} else {
			//echo 'Fetch All:';var_dump($sqliR->fetchAll());
			while ($row = $sqliR->fetch(PDO::FETCH_BOTH)) { // FETCH_ASSOC FETCH_NUM
				//echo '<br/>PDO row:';var_dump($row);echo '<br/>';
				if (!count($this->LastQueryColumns)) // failed to obtain columns, get them now from row array keys
					$this->addColumns($sqliR, $row);
				$this->addRowData($row);
			}
		}
		//echo '<br/>----<br/>';
	}
		
}

 // .......................................................................................... \\
//	== DBValues - dynamic pointers/references to data sources ================================  \\
class OEDBValueManager {
	// Manager of DBValues and of their current values (data in sources referenced by a DBValue)
	
	var $Manager; // parent OEDynManager object
	var $DBValues; 	// Array of DBValue - page "values" (consider them as pointers that can get their actual value from result containers):
					// every DBValue is "updated" by an action that sets its "last source id", 
					// so that value is able to find its current value by looking in actions' result
	
//  var $cacheResults; // stores results for each requested identifier (ex. "ActionName1.TableName1.ColumnName1") and each calculated DBValue (ex. [0]], to not re-read it every time

	public function __construct($mainManager) {
		$this->Manager =& $mainManager;
		if (isset($this->Manager->JSData['DynValues']))
			$this->DBValues = $this->Manager->JSData['DynValues'];
		// $this->cacheResults = array();
	}
	
	// return full address = name of source data by DBValue index
	function GetNameByInd($ind) {
		if (isset($this->DBValues[$ind])) return $this->DBValues[$ind]; else return null;
	}
	
	// return data contaner name which the given DBValue targets at the moment
	function GetContainerNameByInd($ind, $removeBase = false) {
		// ex B:FormattedItems.FItem1 => FormattedItems
		$name = $this->GetNameByInd($ind); if (!$name) return null;
		$pos = strpos($name, '.'); if (!$pos) return null;
		$pos2 = 0;
		if ($removeBase) { $pos2 = strpos($name, ':'); if (!$pos2 || $pos2>$pos) $pos2 = 0; }
		return substr($name, $pos2, $pos); // ex. "Session" or "ActionDBGet1"
	}
	
	function GetColumnNameByInd($ind) { 
		// while GetContainerNameByInd() returns name of container, this function returns name of data column within container
		$name = $this->GetNameByInd($ind); if (!$name) return null;
		$pos = strpos($name, '.'); if (!$pos) return null;
		return substr($name, $pos+1); // ex. "Session" or "ActionDBGet1"
	}

	// similar to above but returns the TYPE of container
	function GetContainerTypeByInd($ind) {
		$containerName = $this->GetContainerNameByInd($ind); if (!$containerName) return null;
		
		// Special containers:
		switch ($containerName) {
			case 'FormInputs':
			case 'ConstVals':
				return "Special";
				break;
			case 'FormattedItems':
				return "FI";
				break;
		}
		
		// see if container is one of the actions' result, in which case return action type:
		$actions = $this->Manager->JSData['DynActions']; if (!$actions) return; // all action of this page
		$action = OEDynUtils::_FindByName($actions, $containerName);
		if ($action && !empty($action['Type'])) {
			return $action['Type']; // action's type, ex. 'DBGet' for result of db SELECT queries
		}
		
		return $containerName;
	}
	
	function IsFormattedItem($ind) {
		$containerName = $this->GetContainerNameByInd($ind, true); if (!$containerName) return null;
		return ($containerName === 'FormattedItems');
	}
	
	function AllUsedDBValues($ind, &$accumArDBVals) {
		// usually just add DBValue[$ind] to $accumArDBVals array if it's not yet there,
		// but if DBValue is FormattedItem that can by itself contain several values and conditions with values,
		// add all its values as well
		if (!$ind && $ind !== 0 && $ind !== '0') return;
		if (!in_array($ind, $accumArDBVals, true)) {
			$accumArDBVals[] = $ind; // add if not yet added
			if ($this->IsFormattedItem($ind)) { // if value is FormattedItem
				$fiName = $this->GetColumnNameByInd($ind);
				$this->Manager->mngFormattedItems->AllUsedDBValues($fiName, $accumArDBVals); // add all values used by FormattedItem
			}
		}		
	}
	
	
	function __FindDataBySrcID($valFullID, $iRow, $pKeyValue = false) { 
		// Examples of $valID: "Session.UserName", "Action1.TableUsers.Image_URL"
		// The part before "." is the name of OEDataContainer (OESQLResult for results of DB actions)
		// $pKeyValue=true means return corresponding primary key instead of column itself
		// if $iRow = -1, tries to return all rows of column/primary key
		if (!$valFullID) return null;
		// $cacheID = ($pKeyValue) ? '_getPKey_' : ''; // needed for buffer/cache of results
		// $cacheID .= "R{$iRow}_{$valFullID}";
		
		//!!cache disabled because of iterators // if (array_key_exists($cacheID, $this->cacheResults)) return $this->cacheResults[$cacheID]; // result was already requested before
		// $this->cacheResults[$cacheID] = null;
		
		// find container name and column name:
		$pos = strpos($valFullID, '.'); if (!$pos) return null;
		$containerName = substr($valFullID, 0, $pos); // ex. "Session" or "ActionGetDB1"
		$valID = substr($valFullID, $pos + 1); // ex. "UserName"
		
		// if (is_numeric($valID)) $valID = intval($valID);
		
		// Special containers:
		if (strpos($containerName, 'FormattedItems') !== false) {
				//echo "Requested data is FItem, evaluating: $valID<br>";
				// attention: if formatted item is not from base layer, use short ID without container name, otherwise use full ID
				$r = $this->Manager->EvaluateFormattedItem(($containerName == 'FormattedItems') ? $valID : $valFullID); //! $iRow is ignored for Formatted Item evaluation
				return $r;
		}

		// Find value $valID in result container $containerName:
		foreach ($this->Manager->dataContainers as $container) {
			if ($container->GetName() !== $containerName) continue;
			//echo "Found result with id {$containerName}"."<br/>";

			$r = $container->GetValue($valID, $iRow, $pKeyValue); // get given value (all rows if it's an array) from result container
			//echo "::$r::";
			// $this->cacheResults[$cacheID] = $r;
			return $r;
		}
		return null;
	}
	
	private function __GetPrefSeparator($parts, &$i, $prefix = 'MERGE') {
		// separator specified after MERGE or SPLIT (note that spaces should be specified as '\s'
		$part = $parts[$i];
		$glue = ',';
		$count = count($parts);
		if ($part !== $prefix) { // separator specified, ex "MERGE," "MERGE - " or in brackets "MERGE(->)"
			$glue = substr($part, strlen($prefix));
			if ($glue[0] === '(') { // separator specified in (), ex "MERGE(.)"
				// find first part ending with ")"; as "." may be a separator, should add next explode items until an item ends with )
				while ($glue[strlen($glue)-1] !== ')' && $i < $count-1) { // ex. "MERGE(..)" exploded into ["MERGE(", "", ")"], fix it:
					$i += 1; $glue .= '.'.$parts[$i];
				}
				$glue = substr($glue, 1, strlen($glue)-2);
			}
		}
		return str_replace("\\s", ' ', $glue);
	}
	
	// To use when value index may be followed by suffix, ex. ".SHA" means hash data before returing it
	function GetDBValueWithSuffix($idWithSuffix, $iRow = 0, $pKeyValue = false) {
		if ($idWithSuffix === null || strlen($idWithSuffix) < 1) return null;

		$parts = explode('.', $idWithSuffix); // ex. "4.SHA" means 4th DBValue, apply SHA() to current data
		$vInd = intval($parts[0]); // DBValue index
		$count = count($parts);
		if ($count == 1) return $this->GetDBValue($vInd, $iRow, $pKeyValue); // no suffixes
		
		//echo ' :: Suffix in DBValue ID :: '.$idWithSuffix.'<br/>';//var_dump($parts);
		
		// example of complex instruction: 4.ALLROWS.SORT.MERGE(;) - get all rows, sort ascending, merge/implode rows into string a;b;c;d
		
		// apply special suffixes BEFORE getting value _____________________________
		$allRows = false;
		for ($i=1; $i<$count; $i++) {
			$part = $parts[$i];
			switch ($part) {
				case 'ALLROWS': // obtain array of all row values
				case 'COUNT': 	// or if count all rows
					$allRows = true;
					$iRow = -1; // to get all data rows
					break;
			}
		}
		
		$data = $this->GetDBValue($vInd, $iRow, $pKeyValue); 
		if ($data === null) return $data; //!!check
		$isArray = is_array($data);
		if (!$allRows && $isArray) return $data; //!!check
		
		// apply special suffixes AFTER getting value _____________________________
		for ($i=1; $i<$count; $i++) {
			$part = $parts[$i];
			
			// instruction to merge data from all rows into one string, like implode(), with separator or wrapper
			if ($isArray && substr($part, 0, 5) === 'MERGE') {
				$glue = $this->__GetPrefSeparator($parts, $i);
				// examples: ".MERGE;" => "1;2", ".MERGE(.)" => "1.2", ".MERGE{?}" => "{1}{2}",".MERGE//?" => "//1//2"
				if (strlen($glue) > 2 && ($posPH = strpos($glue, '?')) !== false) { // ex (?) means each item, even first, needs to be wrapped in ( )
					$left = substr($glue, 0, $posPH) or $left = ''; $right = substr($glue, $posPH+1) or $right = '';
					$dataGlued = "";
					foreach($data as $rVal) $dataGlued .= $left.$rVal.$right;
					$data = $dataGlued; // ex "<1><2><3><4>"
				} else {
					$data = implode($glue, $data); // ex "1,2,3,4"
				}
				
				$isArray = false;
				continue;
			}
			
			// split/explode string into array
			if (!$isArray && substr($part, 0, 5) === 'SPLIT') { // ex "1.2".SPLIT(.) => ["1","2"]
				$isArray = true;
				$data .= '';
				if ($data === '') { $data = array(); continue; }
				
				$glue = $this->__GetPrefSeparator($parts, $i, 'SPLIT');
				if (strlen($glue) > 2 && ($posPH = strpos($glue, '?')) !== false) { // ex (?) means each item, even first, needs to be wrapped in ( )
					$left = substr($glue, 0, $posPH) or $left = ''; $right = substr($glue, $posPH+1) or $right = '';
					$rl = $right.$left; // ex "><"
					$dataSplit = array();
					if (strlen($rl)) { 
						// ex "<1><2>"
						if ($left) $data = substr($data, strlen($left));
						if ($right) $data = substr($data, 0, strlen($data) - strlen($right));
						// ex "1><2"
						$dataSplit = explode($rl, $data); 
						// ex ["1","2"]
					} else {
						$dataSplit[] = $data;
					}
					$data = $dataSplit; 
				} else {
					$data = explode($glue, $data);
				}
				continue;
			}
			
			
			if (isset($data)) { // only if not null - at the moment not necessary, see return above
				switch ($part) {
					
					case 'SALT': // add salt string to the end of data:
						$salt = $this->__FindDataBySrcID('AutoVals.HashSalt', 0);
						if (isset($salt)) {
							if (!$isArray) $data = $data.$salt; 
							else foreach ($data as $k=>$v) { $data[$k] = $data[$k].$salt; }
						}
						break;
					case 'SHA': // hash data
					case 'HASH':
						if (!$isArray) $data = sha1($data); 
						else foreach ($data as $k=>$v) { $data[$k] = sha1($data[$k]); }
						break;
					case 'MD5': // hash data
						if (!$isArray) $data = md5($data);
						else foreach ($data as $k=>$v) { $data[$k] = md5($data[$k]); }
						break;
					
					case 'INC': // inc data by 1 assuming it's numeric
						if (!$isArray) $data = $data+1;
						else foreach ($data as $k=>$v) { $data[$k]++; }
						break;
					case 'DEC': // dec data by 1 assuming it's numeric
						if (!$isArray) $data = $data-1;
						else foreach ($data as $k=>$v) { $data[$k]--; }
						break;
					case 'BOOL': // converts to true or false, ex null=>false, "0"=>false, "text"=>true
						if (!$isArray) $data = !!$data;
						else foreach ($data as $k=>$v) { $data[$k] = !!$data[$k]; }
						break;
					
					case 'UCASE': // upper case
						if (!$isArray) $data = strtoupper($data);
						else foreach ($data as $k=>$v) { $data[$k] = strtoupper($data[$k]); }
						break;
					case 'LCASE': // lower case
						if (!$isArray) $data = strtolower($data);
						else foreach ($data as $k=>$v) { $data[$k] = strtolower($data[$k]); }
						break;
					case 'INT': // convert to number
					case 'NUM': // convert to number
						if (!$isArray) $data = intval($data);
						else foreach ($data as $k=>$v) { $data[$k] = intval($data[$k]); }
						break;
						
					
					// instructions for multiple rows, normally to be used after ALLROWS instruction
					case 'SORT': // sort rows ascending
						if (!$isArray) continue; else sort($data);
						break;
					case 'RSORT': // sort rows descending
						if (!$isArray) continue; else rsort($data);
						break;
					case 'COUNT': // in combination with ALLROWS, gets the amount of rows/values, i.e. array length
						if ($isArray) { $data = count($data); $isArray = false; }
						break;
				// 	case MERGE: see above
				
					// security and encoding
					case 'HE':
					case 'HTMLENT':
						if (!$isArray) $data = htmlentities($data); //!!check encoding etc.
						else foreach ($data as $k=>$v) { $data[$k] = htmlentities($data[$k]); }
						break;

					case 'HS':
					case 'HTMLSPEC':
						if (!$isArray) $data = htmlspecialchars($data); //!!check encoding etc.
						else foreach ($data as $k=>$v) { $data[$k] = htmlspecialchars($data[$k]); }
						break;

					case 'HTMLSPEC_QUOTES':					
						if (!$isArray) $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); //!!check encoding etc.
						else foreach ($data as $k=>$v) { $data[$k] = htmlspecialchars($data[$k], ENT_QUOTES, 'UTF-8'); }
						break;
						
					case 'HD':
					case 'HTMLDECODE':
						if (!$isArray) $data = html_entity_decode($data); //!!check encoding etc.
						else foreach ($data as $k=>$v) { $data[$k] = html_entity_decode($data[$k]); }
						break;
						
					case 'HTMLUNSPEC':
						if (!$isArray) $data = htmlspecialchars_decode($data); //!!check encoding etc.
						else foreach ($data as $k=>$v) { $data[$k] = htmlspecialchars_decode($data[$k]); }
						break;
						
					case 'STRIPTAGS':
					case 'NOTAGS':
						if (!$isArray) $data = strip_tags($data); //!!check encoding etc.
						else foreach ($data as $k=>$v) { $data[$k] = strip_tags($data[$k]); }
						break;
						
					case 'UE':
					case 'URLENCODE':
						if (!$isArray) $data = urlencode($data); //!!check encoding etc.
						else foreach ($data as $k=>$v) { $data[$k] = urlencode($data[$k]); }
						break;						
						
				}
			}
		}
		return $data;
		
	}
	
	// Returns current value/result (actual data), ex. GetDBValue('DBGet0.TableCountries.Name', 0) = ["France","USA"]
	function GetDBValue($vInd, $iRow = 0, $pKeyValue = false) {
		// DBValues items are ids pointing to value sources: action and "field" (column) of its result, ex. DBGet0.TableUsers.Image_URL (DBGet0 is action's name)
		// this function uses this ids to get actual current value (result)
		// if $pKeyValue=true, instead of the value itself, get associated primary key value (falue of pkey for the row containing specified value)
		
		if (!is_int($vInd) || !isset($this->DBValues[$vInd])) {
			//echo "<br>!!Value wrong: ind = $vInd :: DBValue Src = {$this->DBValues[$vInd]}<br>";var_dump($this->DBValues);
			return null;
		}
		
		$srcID = $this->DBValues[$vInd]; if (!$srcID) return null; // data address, ex. "DBGet0.TableUsers.Image_URL"
		//echo "Finding data for $srcID"."<br/>";
		$r = $this->__FindDataBySrcID($srcID, $iRow, $pKeyValue);
		
		return $r;
	}
	
	/* Do not delete
	function _GetData($id, $iRow = -1) { // get data from container (directly by full string identificator)
		$r =& $this->__FindDataBySrcID($id, false); //!!OLD
		//var_dump($r);
		if (is_array($r)) { // there are several rows
			if ($iRow >= 0) {
				if (array_key_exists($iRow, $r))
					return $r[$iRow]; // return the value of only one specified row
				else if ($iRow == 0 && !array_key_exists(0, $r) && count($r) > 0)
					return $r; // it is in fact 1-row data containing an associative array
				else 
					return null;
			}
			if (empty($r)) return null;
		}
		return $r;
	}*/
	

}

