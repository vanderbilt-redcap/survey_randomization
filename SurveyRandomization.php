<?php
namespace Randomization\SurveyRandomization;

class SurveyRandomization extends \ExternalModules\AbstractExternalModule {
	## Need to cache these to limit time from identifying available randomization row
	## And actually saving the randomization value
	public $randomProject;
	public $randomizedField;

	public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}
	
	public function redcap_save_record($project_id, $record, $instrument, $event_id, $group_id, $survey_hash, $response_id, $repeat_instance ) {
		$demoFields = $this->getProjectSetting("this_demo_field");
		$randomDemoFields = $this->getProjectSetting("that_demo_field");

		$mappedFields = $this->getProjectSetting("this_mapped_field");
		$randomMappedFields = $this->getProjectSetting("that_mapped_field");

		$randomizedField = $this->getProjectSetting("this_record_field");
		$this->randomizedField = $this->getProjectSetting("that_record_field");

		$this->randomProject = $this->getProjectSetting("randomization_project");

		$randomizationMappingInput = $this->getProjectSetting("randomization_value");
		$randomizationMappingOutput = $this->getProjectSetting("mapped_value");
		$randomizationCalculations = $this->getProjectSetting("calculation_value");
		$randomizationCalculationFields = $this->getProjectSetting("calculation_mapping_output");

		if(count($demoFields) == 0 || count($mappedFields) == 0 || count($randomizedField) == 0 || !$this->randomProject) {
			## Module has not been fully configured yet
			return;
		}
		
		$randomizationMapping = [];

		foreach($randomizedField as $fieldId => $fieldName) {
			foreach($randomizationMappingInput[$fieldId] as $mappingId => $mappingInput) {
				$randomizationMapping[$fieldName][$mappingInput] = $randomizationMappingOutput[$fieldId][$mappingId];
			}
		}

		## Check to make sure project is configured before continuing
		if($this->randomProject == "" || $randomizedField == "" || $this->randomizedField == ""
				|| count($randomMappedFields) == 0) {
			return;
		}

		$recordData = $this->getData($project_id,$record);

		$readyToRandomize = true;
		$demoValues = [];

		## Check if record is ready to randomize
		foreach($recordData as $recordId => $recordDetails) {
			foreach($recordDetails as $eventId => $eventDetails) {

				error_log("Randomization: Is $randomizedField already ".$eventDetails[$randomizedField]);
				if($eventDetails[$randomizedField] != "") {
					error_log("Randomization: Already Randomized Breaking");
					$readyToRandomize = false;
					break 2;
				}

				## Check for randomization fields, then save those values
				foreach($demoFields as $fieldName) {
					if(array_key_exists($fieldName,$eventDetails)) {
						if($eventDetails[$fieldName] === "") {
							error_log("Randomization: Not ready to be Randomized Breaking");
							$readyToRandomize = false;
							break 3;
						}
						else {
							$demoValues[$fieldName] = $eventDetails[$fieldName];
						}
					}
				}
			}
		}

		## Start randomization
		if($readyToRandomize) {
			## Get all the randomization mapping data from the randomization project
			## In order to find the next record to be used to randomize
			$sql = "SELECT record,field_name,event_id,value
					FROM redcap_data
					WHERE project_id = ".db_escape($this->randomProject)."
						AND field_name IN ('".db_escape($this->randomizedField)."'";

			foreach($randomDemoFields as $fieldName) {
				$sql .= ",'".db_escape($fieldName)."'";
			}

			$sql .= ")";

			$q = $this->query($sql);

			$randomizationData = [];
			$randomizationEvent = false;
			while($row = db_fetch_assoc($q)) {
				$randomizationEvent = $row['event_id'];
				$randomizationData[$row['record']][$row['field_name']] = $row['value'];
			}
			$chosenRandomizationRecord = false;

			foreach($randomizationData as $randomizationRecord => $recordDetails) {
				if($recordDetails[$this->randomizedField] != "" && $recordDetails[$this->randomizedField] != $record) {
					continue;
				}

				foreach($demoFields as $demoIndex => $fieldName) {
					if($recordDetails[$randomDemoFields[$demoIndex]] != $demoValues[$fieldName]) {
						continue 2;
					}
				}

				## This is a record with the same demographics values and which hasn't
				## yet been used for randomization
				if($this->randomizeUsingRecord($randomizationRecord,$randomizationEvent,$record,$project_id,$event_id)) {
					$chosenRandomizationRecord = $randomizationRecord;
					break;
				}
			}


			## Now perform the randomized mapping and copy that data into the record
			$randomizationData = $this->getData($this->randomProject,$chosenRandomizationRecord);
			$randomizedOutput = [];
			$mappedData = [];

			foreach($randomMappedFields as $randomIndex => $fieldName) {
				$outputField = $mappedFields[$randomIndex];

				$randomizedOutput[$outputField] = $randomizationData[$chosenRandomizationRecord][$randomizationEvent][$fieldName];
			}

			$results = $this->saveData($project_id,$record,$event_id,$randomizedOutput);

			if(count($results['errors']) > 0) {
				error_log("Randomization: Save Randomized Data: ".var_export($results,true));
			}

			foreach($randomizationCalculations as $mappingIndex => $mappingDetails) {
				foreach($mappingDetails as $calcIndex => $calculation) {
					$outputField = $randomizationCalculationFields[$mappingIndex][$calcIndex];

					$calculation = preg_replace_callback("/\\[([a-z\\_0-9]+)\\]/",function($matches) use ($randomizedOutput) {
						return ($randomizedOutput[$matches[1]] == "" ? "''" : $randomizedOutput[$matches[1]]);
					},$calculation);

					$calculation = str_replace(" or ", ") || (", $calculation);
					$calculation = str_replace(" and ", ") && (", $calculation);
					$calculation = $calculation == "" ? "" : "(" . $calculation . ")";

					$parser = new \LogicParser();
					list($logicCode) = $parser->parse($calculation);

					$calculation = call_user_func_array($logicCode, array());

					foreach($randomizationMappingInput[$mappingIndex] as $calcMappingIndex => $mappedInput) {
						if($mappedInput == $calculation) {
							$mappedData[$outputField] = $randomizationMappingOutput[$mappingIndex][$calcMappingIndex];
						}
					}
				}
			}

			$results = $this->saveData($project_id,$record,$event_id,$mappedData);

			if(count($results['errors']) > 0) {
				error_log("Randomization: Save Mapped Data: ".var_export($results,true));
			}
		}
	}

	function randomizeUsingRecord($randomizationRecord,$randomizationEvent,$thisRecord,$thisProject,$thisEvent) {
		## Double-check that record is still available
		$randomizationCheckSql = "SELECT *
				FROM redcap_data
				WHERE project_id = ".db_escape($this->randomProject)."
					AND record = '".db_escape($randomizationRecord)."'
					AND field_name = '".db_escape($this->randomizedField)."'";

		$q = $this->query($randomizationCheckSql);

		if(db_num_rows($q) > 0) {
			return false;
		}

		## Attempt to save the randomization value
		$results = $this->saveData($this->randomProject,$randomizationRecord,$randomizationEvent,[$this->randomizedField => $thisRecord]);

		if(count($results['errors']) > 0) {
			error_log("Randomization: Save Randomization Record: ".var_export($results,true));
		}

		## Check to make sure that's still the only record attached to this randomization
		$q = $this->query($randomizationCheckSql);

		if(db_num_rows($q) > 1) {
			## Remove the now duplicate record row
			$sql = "DELETE FROM redcap_data
					WHERE project_id = ".db_escape($this->randomProject)."
						AND record = '".db_escape($randomizationRecord)."'
						AND field_name = '".db_escape($this->randomizedField)."'
						AND value = '".$thisRecord."'";

			$this->query($sql);

			## Wait a random time
			usleep(rand(1000,30000));

			## Re-attempt to randomize using the same record
			return $this->randomizeUsingRecord($randomizationRecord,$randomizationEvent,$thisRecord,$thisProject,$thisEvent);
		}
		else {
			## Still need to save this record to the participant record
			$recordField = $this->getProjectSetting("this_record_field");

			$results = $this->saveData($thisProject,$thisRecord,$thisEvent,[$recordField => $randomizationRecord]);

			if(count($results['errors']) > 0) {
				error_log("Randomization: Hold Record: ".var_export($results,true));
			}

			return true;
		}
	}
}