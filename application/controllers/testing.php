<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Testing extends CI_Controller {

	public function index()
	{
		
		$this->main();

	}
	
		public function main()
	{
		$this->getCheckDone("3G", "MG", "1");
	}
		
public function getCheckDone($networktype, $cluster, $baselineRuleID)
{
	#echo __DIR__;
	//include_once("baselineStructure.php"); 
	include_once(__DIR__."\..\models\baselineStructure.php"); 
	$g_MOs = array();
	$g_rules = array();

	$page = 1;
	$total_pages = 1;
	$count = 5;
	// STEP 1
	// PART 1 - Loading Rules from DB

	$SQL = "SELECT a.id, a.MO as \"MO\", a.parameter, a.subparameter,a.MML as \"MML\",
			a.value1,a.value2,a.value3, value4,  value5,  value6,  value7 
			FROM baseline_rules_details as a where a.id=$baselineRuleID;";

	$query = $this->db->query($SQL);
	//if ($query->num_rows() > 0)
	//if( !$query )
	//{
	//   $errNo   = $this->db->error();// $this->db->_error_number();
	

	$i=0;
	foreach($query->result() as $row){
		if ($row->parameter!='' && $row->value1!='') {
			
			$v_rule = new Rule();
			$v_rule->setRuleDetails2($row);
			$g_rules[] = $v_rule;
		}
		$i++;
	} 

	$count = $i;	
	// PART 2: Generate where clause based on cluster selected


	$g_elements = array();
//	$SQL = "SELECT element_name FROM clusters where cluster='$cluster' and network_type='3G' and element_type='RNC';";
	$SQL = "SELECT distinct RNC as element_name FROM umts_control.cells where regional='$cluster';";
	
	$query = $this->db->query($SQL);

	foreach($query->result() as $row){
		$g_elements[] = $row->element_name;
	}
	$whereRNC = "IN ('".join("','",$g_elements)."')"; //$whereRNC = "IN ('RNCDF01', 'RNCDF02', 'RNCDF03')";

		
	// STEP 2: For each rule check discrepancies

	$responce = new stdClass();

	$responce->page = $page;
	$responce->total = $total_pages;
	$responce->records = $count;


	$logFileName="logFile.xls";

	$responce->userdata = $logFileName;



	$i=0;
	//		echo getcwd();

	$logFile = fopen("output/$logFileName", "w") or die("Unable to open file!"); // TODO: create dynamic naming
	 
	$txt = "RNC \t ElementID \t MO \t Parameter \t Baseline \t Value found \t BASELINE_OK\r\n";
	fwrite($logFile, $txt);

		foreach ($g_rules as &$v_rule) {
		
		//in MO we have table name so it can be used to create SQL query
		//using $cluster we can get list of RNC (or other elements ) ... TODO: implement later
		
		$paramToCheck = strtolower($v_rule->parameterToCheck);
		$subParamToCheck = strtolower($v_rule->subParameterToCheck);
		$tableToCheck = strtolower($v_rule->command);
		$isSubParameter = false;
		
		if (!(is_null($subParamToCheck) || empty($subParamToCheck))) $isSubParameter = true;
	 
		//mysql_select_db('udb_configuration') or die("Error conecting to db.");

		//TODO: MySQL - check if column exists
		//$SQL = "SHOW COLUMNS FROM udb_configuration.$tableToCheck;";
		

		$SQL = "select column_name, data_type, character_maximum_length	from INFORMATION_SCHEMA.COLUMNS where table_name = '$tableToCheck';";
	
		$query = $this->db->query($SQL);
		$v_tableColumns = "";
		$v_hasRNCColumn=false;
		$v_hasCIDColumn=false;
		$t_clm="";
		$MMLCMD="";

		foreach($query->result() as $row){
			$t_clm=strtoupper($row->column_name);
			if ($t_clm=='RNCNAME') $v_hasRNCColumn=true;
			if ($t_clm=='CELLID') $v_hasCIDColumn=true;
			$v_tableColumns = $v_tableColumns.$row->column_name.",";
		}
		
		$v_elementID="";
		$v_controller="";
		$v_elementToUseAsID='a.rncname'; // later should be modified to work with 2g, 4g, etc
		
		
		if ($v_hasCIDColumn) $v_elementToUseAsID='a.cellId';
		
		$SQL = "select a.rncname, $v_elementToUseAsID as \"v_elementID\", a.$paramToCheck as \"realValue\"
		FROM umts_configuration.$tableToCheck as a 
		where rncname $whereRNC;";
		
		$query = $this->db->query("select count(*) as count FROM umts_configuration.$tableToCheck as a where rncname $whereRNC;");
		
		$row = $query->row(); 
		$rowsCount = $row->count;
		
		if ($rowsCount==0) {
			//throw some warning;
			$v_rule->elementsChecked=1; // this is to avoid exception
		}
		
			
		$query = $this->db->query($SQL);

		foreach($query->result() as $row){
			
			$t_parameterCorrect = false;
			$v_rule->elementsChecked++;
			
			$txt ="";
			
			$v_elementID=$row->v_elementID;
			$v_controller=$row->rncname; // modify later for 2g/4g
			
			
			if ($isSubParameter) {
				//check value should be compared to value of subparameter parsed from realvalue
				$v_subParameters=explode('&',$row->realValue);
				$isSubFound=false;
				foreach($v_subParameters as &$t_subPair) {
					$t_pair = explode('-',$t_subPair);
					if ($t_pair[0]==$v_rule->subParameterToCheck)
					{
						$isSubFound=true;
						
						if ($v_rule->isMultipleCheckValue)
						{
							$valueBaseline = implode(",",$v_rule->checkValue);
							if (in_array($t_pair[1], $v_rule->checkValue)) $t_parameterCorrect = true;
							$v_result= $t_parameterCorrect ? 'OK' : 'NOK';
							$txt = "$v_controller\t$v_elementID\t$v_rule->command\t$v_rule->parameterToCheck - $v_rule->subParameterToCheck\t$valueBaseline\t$t_pair[1]\t$v_result\t";
						}
						else
						{
							if ($t_pair[1]==$v_rule->checkValue) $t_parameterCorrect = true;
							$v_result= $t_parameterCorrect ? 'OK' : 'NOK';
							$txt = "$v_controller\t$v_elementID\t$v_rule->command\t$v_rule->parameterToCheck - $v_rule->subParameterToCheck\t$v_rule->checkValue\t$t_pair[1]\t$v_result\t";
						}
					
						
					}
				}
				//if (!$isSubFound) throw some warning;
				
			} else
			{		
				$valueFound=$row->realValue;
				
				if ($v_rule->isMultipleCheckValue)
				{
					$valueBaseline = implode(",",$v_rule->checkValue);
					if (in_array($row->realValue, $v_rule->checkValue)) $t_parameterCorrect = true;
					$v_result= $t_parameterCorrect ? 'OK' : 'NOK';
					$txt = "$v_controller\t$v_elementID\t$v_rule->command\t$v_rule->parameterToCheck\t$valueBaseline\t$valueFound\t$v_result\t"; // TODO: verify in can be joined with lower line
				}
				else
				{
					if ($row->realValue==$v_rule->checkValue) $t_parameterCorrect = true;
					$v_result= $t_parameterCorrect ? 'OK' : 'NOK';
					$txt = "$v_controller\t$v_elementID\t$v_rule->command\t$v_rule->parameterToCheck\t$v_rule->checkValue\t$valueFound\t$v_result\t";
				}
			}
			// TODO: add check for subparameters and multiple values
			
			//$txt = "MO\tParameter\tBaseline\tValue found\r\n";



			if (!$t_parameterCorrect) {
				$v_rule->discrepanciesFound++;
				
				if ($v_hasCIDColumn ) $MMLCMD=str_replace("%CELLID%", $v_elementID, $v_rule->MML);
				if (!$v_hasCIDColumn ) $MMLCMD=$v_rule->MML;
				
				// TODO: generate table with output
				
			}
			
			$txt=$txt."$MMLCMD\r\n";		
			fwrite($logFile, $txt);		
			

		}        

	$i++;	


	// print result for current param 

	//$responce->rows[$i-1]['mo']=$v_rule->command.$v_rule->parameterToCheck;
	//$responce->rows[$i-1]['num_param']=1;
	//$responce->rows[$i-1]['discrepancy']=$v_rule->discrepanciesFound;
	//$responce->rows[$i-1]['percentage']=number_format(100 * $v_rule->discrepanciesFound / $v_rule->elementsChecked,2);

	} 


	//var_dump($g_rules);

	// populate MO structure
	foreach ($g_rules as &$v_rule) {

		$v_foundFlag=false;
		foreach ($g_MOs as &$t_MO) {
			if ($t_MO->MOName==$v_rule->command) $v_foundFlag=true;
		}
		 
		if (!$v_foundFlag) {
			unset($v_MO);
			$v_MO = new MO();
			$v_MO->MOName=$v_rule->command;
			$g_MOs[]=$v_MO;
		}
	}
	 
	// Update it with results of verification
	foreach ($g_rules as &$v_rule) {

		foreach ($g_MOs as &$v_MO) {
			if ($v_MO->MOName==$v_rule->command) {
				$v_MO->NumParameters++;
				$v_MO->NumElements=$v_rule->elementsChecked + $v_rule->elementsSkipped;
				$v_MO->NumDescrepancies = $v_MO->NumDescrepancies + $v_rule->discrepanciesFound;
			}
		}
	}



	//generate output
	$i=0;
	foreach ($g_MOs as &$v_MO) {
		
		$responce->rows[$i]['mo']=$v_MO->MOName;
		$responce->rows[$i]['num_param']=$v_MO->NumElements;
		$responce->rows[$i]['discrepancy']=$v_MO->NumDescrepancies;
		$responce->rows[$i]['percentage']=$v_MO->getPercentage();
		$i++;
	}


	//$SQL = "SELECT a.id, a.invdate, b.name, a.amount,a.tax,a.total,a.note FROM invheader a, clients b WHERE a.client_id=b.client_id ORDER BY $sidx $sord LIMIT $start , $limit";
	//$result = pg_query( $SQL ) or die("Couldn t execute query.".pg_last_error());

	/** 
	TODO: 
		  output warnings
		  generate & output MMLs

	$i=0;
	while($row = pg_fetch_array($result,MYSQL_ASSOC)) {
		$responce->rows[$i]['id']=$row[id];
		$responce->rows[$i]['cell']=array($row[id],$row[invdate],$row[name],$row[amount],$row[tax],$row[total],$row[note]);
		$i++;
	}        

	**/

	fclose($logFile);

	echo json_encode($responce);	
}	
}
