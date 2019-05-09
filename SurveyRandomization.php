<?php
namespace Randomization\SurveyRandomization;

class SurveyRandomization extends \ExternalModules\AbstractExternalModule {
	## Need to cache these to limit time from identifying available randomization row
	## And actually saving the randomization value
	private $randomProject;
	private $randomizedField;

	public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}
	
	public function redcap_save_record( int $project_id, string $record = NULL, string $instrument, int $event_id, int $group_id, string $survey_hash = NULL, int $response_id, int $repeat_instance = 1 ) {
		$demoFields = $this->getProjectSetting("this_demo_field");
		$randomDemoFields = $this->getProjectSetting("that_demo_field");

		$mappedFields = $this->getProjectSetting("this_mapped_field");
		$randomMappedFields = $this->getProjectSetting("that_mapped_field");

		$randomizedField = $this->getProjectSetting("this_record_field");
		$this->$randomizedField = $this->getProjectSetting("that_record_field");

		$this->$randomProject = $this->getProjectSetting("randomization_project");

		## Check to make sure project is configured before continuing
		if($this->$randomProject == "" || $randomizedField == "" || $this->$randomizedField == ""
				|| count($randomMappedFields) == 0) {
			return;
		}

		$recordData = $this->getData($project_id,$record);

		$readyToRandomize = true;
		$demoValues = [];
		$mappedValues = [];

		## Check if record is ready to randomize
		foreach($recordData as $recordId => $recordDetails) {
			foreach($recordDetails as $eventId => $eventDetails) {
				if($eventDetails[$randomizedField] != "") {
					$readyToRandomize = false;
					break 2;
				}

				## Check for randomization fields, then save those values
				foreach($demoFields as $fieldName) {
					if(array_key_exists($fieldName,$eventDetails)) {
						if($eventDetails[$fieldName] === "") {
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
					WHERE project_id = ".db_escape($this->$randomProject)."
						AND field_name IN ('".db_escape($this->$randomizedField)."'";

			foreach($randomDemoFields as $fieldName) {
				$sql .= ",'".db_escape($fieldName)."'";
			}

			$sql .= ")";

			echo $sql;

			$q = $this->query($sql);

			$randomizationData = [];
			$randomizationEvent = false;
			while($row = db_fetch_row($q)) {
				$randomizationEvent = $row['event_id'];
				$randomizationData[$row['record']][$row['field_name']] = $row['value'];
			}
			$chosenRandomizationRecord = false;

			foreach($randomizationData as $randomizationRecord => $recordDetails) {
				if($recordDetails[$this->$randomizedField] != "") {
					continue;
				}

				foreach($demoFields as $fieldName) {
					if($recordDetails[$fieldName] != $demoValues[$fieldName]) {
						continue 2;
					}
				}

				## This is a record with the same demographics values and which hasn't
				## yet been used for randomization
				if($this->randomizeUsingRecord($randomizationRecord,$randomizationEvent,$record,$project_id)) {
					$chosenRandomizationRecord = $randomizationRecord;
					break;
				}
			}


			## Now perform the randomized mapping and copy that data into the record

		}
	}

	function randomizeUsingRecord($randomizationRecord,$randomizationEvent,$thisRecord,$thisProject) {
		## Double-check that record is still available
		$randomizationCheckSql = "SELECT *
				FROM redcap_data
				WHERE project_id = ".db_escape($this->randomProject)."
					AND record = '".db_escape($randomizationRecord)."'
					AND field_name = '".db_escape($this->$randomizedField)."'";

		$q = $this->query($randomizationCheckSql);

		if(db_num_rows($q) > 0) {
			return false;
		}

		## Attempt to save the randomization value
		$this->saveData($this->randomProject,$randomizationRecord,$randomizationEvent,[$this->randomizedField => $thisRecord]);

		## Check to make sure that's still the only record attached to this randomization
		$q = $this->query($randomizationCheckSql);

		if(db_num_rows($q) > 1) {
			## Remove the now duplicate record row
			$sql = "DELETE FROM redcap_data
					WHERE project_id = ".db_escape($this->randomProject)."
						AND record = '".db_escape($randomizationRecord)."'
						AND field_name = '".db_escape($this->$randomizedField)."'
						AND value = '".$thisRecord."'";

			$this->query($sql);

			## Wait a random time
			usleep(rand(1000,30000));

			## Re-attempt to randomize using the same record
			return $this->randomizeUsingRecord($randomizationRecord,$randomizationEvent,$thisRecord,$thisProject);
		}
		else {
			## Still need to save this record to the participant record

			return true;
		}
	}
}