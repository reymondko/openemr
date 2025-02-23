<?php

/**
 * Patient Service
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Victor Kofia <victor.kofia@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2017 Victor Kofia <victor.kofia@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Events\Patient\BeforePatientCreatedEvent;
use OpenEMR\Events\Patient\BeforePatientUpdatedEvent;
use OpenEMR\Events\Patient\PatientCreatedEvent;
use OpenEMR\Events\Patient\PatientUpdatedEvent;
use OpenEMR\Services\Search\FhirSearchWhereClauseBuilder;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\TokenSearchField;
use OpenEMR\Services\Search\SearchModifier;
use OpenEMR\Services\Search\StringSearchField;
use OpenEMR\Services\Search\TokenSearchValue;
use OpenEMR\Validators\PatientValidator;
use OpenEMR\Validators\ProcessingResult;

class PatientService extends BaseService
{
    private const TABLE_NAME = 'patient_data';
    private const PATIENT_HISTORY_TABLE = "patient_history";

    /**
     * In the case where a patient doesn't have a picture uploaded,
     * this value will be returned so that the document controller
     * can return an empty response.
     */
    private $patient_picture_fallback_id = -1;

    private $patientValidator;

    /**
     * Key of translated suffix values that can be in a patient's name.
     * @var array|null
     */
    private $patientSuffixKeys = null;

    /**
     * Default constructor.
     */
    public function __construct($base_table = null)
    {
        parent::__construct($base_table ?? self::TABLE_NAME);
        $this->patientValidator = new PatientValidator();
    }

    /**
     * TODO: This should go in the ChartTrackerService and doesn't have to be static.
     *
     * @param  $pid unique patient id
     * @return recordset
     */
    public static function getChartTrackerInformationActivity($pid)
    {
        $sql = "SELECT ct.ct_when,
                   ct.ct_userid,
                   ct.ct_location,
                   u.username,
                   u.fname,
                   u.mname,
                   u.lname
            FROM chart_tracker AS ct
            LEFT OUTER JOIN users AS u ON u.id = ct.ct_userid
            WHERE ct.ct_pid = ?
            ORDER BY ct.ct_when DESC";
        return sqlStatement($sql, array($pid));
    }

    /**
     * TODO: This should go in the ChartTrackerService and doesn't have to be static.
     *
     * @return recordset
     */
    public static function getChartTrackerInformation()
    {
        $sql = "SELECT ct.ct_when,
                   u.username,
                   u.fname AS ufname,
                   u.mname AS umname,
                   u.lname AS ulname,
                   p.pubpid,
                   p.fname,
                   p.mname,
                   p.lname
            FROM chart_tracker AS ct
            JOIN cttemp ON cttemp.ct_pid = ct.ct_pid AND cttemp.ct_when = ct.ct_when
            LEFT OUTER JOIN users AS u ON u.id = ct.ct_userid
            LEFT OUTER JOIN patient_data AS p ON p.pid = ct.ct_pid
            WHERE ct.ct_userid != 0
            ORDER BY p.pubpid";
        return sqlStatement($sql);
    }

    public function getFreshPid()
    {
        $pid = sqlQuery("SELECT MAX(pid)+1 AS pid FROM patient_data");
        return $pid['pid'] === null ? 1 : intval($pid['pid']);
    }

    /**
     * Insert a patient record into the database
     *
     * returns the newly-created patient data array, or false in the case of
     * an error with the sql insert
     *
     * @param $data
     * @return false|int
     */
    public function databaseInsert($data)
    {
        $freshPid = $this->getFreshPid();
        $data['pid'] = $freshPid;
        $data['uuid'] = (new UuidRegistry(['table_name' => 'patient_data']))->createUuid();

        // The 'date' is the updated-date, and 'regdate' is the created-date
        // so set both to the current datetime.
        $data['date'] = date("Y-m-d H:i:s");
        $data['regdate'] = date("Y-m-d H:i:s");
        if (empty($data['pubpid'])) {
            $data['pubpid'] = $freshPid;
        }

        // Before a patient is inserted, fire the "before patient created" event so listeners can do extra processing
        $beforePatientCreatedEvent = new BeforePatientCreatedEvent($data);
        $GLOBALS["kernel"]->getEventDispatcher()->dispatch(BeforePatientCreatedEvent::EVENT_HANDLE, $beforePatientCreatedEvent, 10);
        $data = $beforePatientCreatedEvent->getPatientData();

        $query = $this->buildInsertColumns($data);
        $sql = " INSERT INTO patient_data SET ";
        $sql .= $query['set'];

        $results = sqlInsert(
            $sql,
            $query['bind']
        );

        // Tell subscribers that a new patient has been created
        $patientCreatedEvent = new PatientCreatedEvent($data);
        $GLOBALS["kernel"]->getEventDispatcher()->dispatch(PatientCreatedEvent::EVENT_HANDLE, $patientCreatedEvent, 10);

        // If we have a result-set from our insert, return the PID,
        // otherwise return false
        if ($results) {
            return $data;
        } else {
            return false;
        }
    }

    /**
     * Inserts a new patient record.
     *
     * @param $data The patient fields (array) to insert.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function insert($data)
    {
        $processingResult = $this->patientValidator->validate($data, PatientValidator::DATABASE_INSERT_CONTEXT);

        if (!$processingResult->isValid()) {
            return $processingResult;
        }

        $data = $this->databaseInsert($data);

        if (false !== $data['pid']) {
            $processingResult->addData(array(
                'pid' => $data['pid'],
                'uuid' => UuidRegistry::uuidToString($data['uuid'])
            ));
        } else {
            $processingResult->addInternalError("error processing SQL Insert");
        }

        return $processingResult;
    }

    /**
     * Do a database update using the pid from the input
     * array
     *
     * Return the data that was updated into the database,
     * or false if there was an error with the update
     *
     * @param array $data
     * @return mixed
     */
    public function databaseUpdate($data)
    {
        // Get the data before update to send to the event listener
        $dataBeforeUpdate = $this->findByPid($data['pid']);

        // The `date` column is treated as an updated_date
        $data['date'] = date("Y-m-d H:i:s");
        $table = PatientService::TABLE_NAME;

        // Fire the "before patient updated" event so listeners can do extra processing before data is updated
        $beforePatientUpdatedEvent = new BeforePatientUpdatedEvent($data);
        $GLOBALS["kernel"]->getEventDispatcher()->dispatch(BeforePatientUpdatedEvent::EVENT_HANDLE, $beforePatientUpdatedEvent, 10);
        $data = $beforePatientUpdatedEvent->getPatientData();

        $query = $this->buildUpdateColumns($data);
        $sql = " UPDATE $table SET ";
        $sql .= $query['set'];
        $sql .= " WHERE `pid` = ?";

        array_push($query['bind'], $data['pid']);
        $sqlResult = sqlStatement($sql, $query['bind']);

        if (
            $dataBeforeUpdate['care_team_provider'] != $data['care_team_provider']
            || $dataBeforeUpdate['care_team_facility'] != $data['care_team_facility']
        ) {
            // need to save off our care team
            $this->saveCareTeamHistory($data, $dataBeforeUpdate['care_team_provider'], $dataBeforeUpdate['care_team_facility']);
        }

        if ($sqlResult) {
            // Tell subscribers that a new patient has been updated
            $patientUpdatedEvent = new PatientUpdatedEvent($dataBeforeUpdate, $data);
            $GLOBALS["kernel"]->getEventDispatcher()->dispatch(PatientUpdatedEvent::EVENT_HANDLE, $patientUpdatedEvent, 10);

            return $data;
        } else {
            return false;
        }
    }

    /**
     * Updates an existing patient record.
     *
     * @param $puuidString - The patient uuid identifier in string format used for update.
     * @param $data - The updated patient data fields
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function update($puuidString, $data)
    {
        $data["uuid"] = $puuidString;
        $processingResult = $this->patientValidator->validate($data, PatientValidator::DATABASE_UPDATE_CONTEXT);
        if (!$processingResult->isValid()) {
            return $processingResult;
        }

        // Get the data before update to send to the event listener
        $dataBeforeUpdate = $this->getOne($puuidString);

        // The `date` column is treated as an updated_date
        $data['date'] = date("Y-m-d H:i:s");

        // Fire the "before patient updated" event so listeners can do extra processing before data is updated
        $beforePatientUpdatedEvent = new BeforePatientUpdatedEvent($data);
        $GLOBALS["kernel"]->getEventDispatcher()->dispatch(BeforePatientUpdatedEvent::EVENT_HANDLE, $beforePatientUpdatedEvent, 10);
        $data = $beforePatientUpdatedEvent->getPatientData();

        $query = $this->buildUpdateColumns($data);
        $sql = " UPDATE patient_data SET ";
        $sql .= $query['set'];
        $sql .= " WHERE `uuid` = ?";

        $puuidBinary = UuidRegistry::uuidToBytes($puuidString);
        array_push($query['bind'], $puuidBinary);
        $sqlResult = sqlStatement($sql, $query['bind']);

        if (!$sqlResult) {
            $processingResult->addErrorMessage("error processing SQL Update");
        } else {
            $processingResult = $this->getOne($puuidString);
            // Tell subscribers that a new patient has been updated
            // We have to do this here and in the databaseUpdate() because this lookup is
            // by uuid where the databseUpdate updates by pid.
            $patientUpdatedEvent = new PatientUpdatedEvent($dataBeforeUpdate, $processingResult->getData());
            $GLOBALS["kernel"]->getEventDispatcher()->dispatch(PatientUpdatedEvent::EVENT_HANDLE, $patientUpdatedEvent, 10);
        }
        return $processingResult;
    }

    protected function createResultRecordFromDatabaseResult($record)
    {
        if (!empty($record['uuid'])) {
            $record['uuid'] = UuidRegistry::uuidToString($record['uuid']);
        }

        return $record;
    }


    /**
     * Returns a list of patients matching optional search criteria.
     * Search criteria is conveyed by array where key = field/column name, value = field value.
     * If no search criteria is provided, all records are returned.
     *
     * @param  $search search array parameters
     * @param  $isAndCondition specifies if AND condition is used for multiple criteria. Defaults to true.
     * @param $puuidBind - Optional variable to only allow visibility of the patient with this puuid.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getAll($search = array(), $isAndCondition = true, $puuidBind = null)
    {
        $querySearch = [];
        if (!empty($search)) {
            if (isset($puuidBind)) {
                $querySearch['uuid'] = new TokenSearchField('uuid', $puuidBind);
            } else if (isset($search['uuid'])) {
                $querySearch['uuid'] = new TokenSearchField('uuid', $search['uuid']);
            }
            $wildcardFields = array('fname', 'mname', 'lname', 'street', 'city', 'state','postal_code','title');
            foreach ($wildcardFields as $field) {
                if (isset($search[$field])) {
                    $querySearch[$field] = new StringSearchField($field, $search[$field], SearchModifier::CONTAINS, $isAndCondition);
                }
            }
        }
        return $this->search($querySearch, $isAndCondition);
    }

    public function search($search, $isAndCondition = true)
    {
        $sql = "SELECT 
                    patient_data.*
                    ,patient_history_type_key
                    ,previous_name_first
                    ,previous_name_prefix
                    ,previous_name_first
                    ,previous_name_middle
                    ,previous_name_last
                    ,previous_name_suffix
                    ,previous_name_enddate
                FROM patient_data
                LEFT JOIN (
                    SELECT 
                    pid AS patient_history_pid
                    ,history_type_key AS patient_history_type_key
                    ,previous_name_prefix
                    ,previous_name_first
                    ,previous_name_middle
                    ,previous_name_last
                    ,previous_name_suffix
                    ,previous_name_enddate
                    ,`date` AS previous_creation_date
                    ,uuid AS patient_history_uuid
                    FROM patient_history
                ) patient_history ON patient_data.pid = patient_history.patient_history_pid";
        $whereClause = FhirSearchWhereClauseBuilder::build($search, $isAndCondition);

        $sql .= $whereClause->getFragment();
        $sqlBindArray = $whereClause->getBoundValues();
        $statementResults =  QueryUtils::sqlStatementThrowException($sql, $sqlBindArray);

        $processingResult = $this->hydrateSearchResultsFromQueryResource($statementResults);
        return $processingResult;
    }

    private function hydrateSearchResultsFromQueryResource($queryResource)
    {
        $processingResult = new ProcessingResult();
        $patientsByUuid = [];
        $patientFields = array_combine($this->getFields(), $this->getFields());
        $previousNameColumns = ['previous_name_prefix', 'previous_name_first', 'previous_name_middle'
            , 'previous_name_last', 'previous_name_suffix', 'previous_name_enddate'];
        $previousNamesFields = array_combine($previousNameColumns, $previousNameColumns);
        $patientOrderedList = [];
        while ($row = sqlFetchArray($queryResource)) {
            $record = $this->createResultRecordFromDatabaseResult($row);
            $patientUuid = $record['uuid'];
            if (!isset($patientsByUuid[$patientUuid])) {
                $patient = array_intersect_key($record, $patientFields);
                $patient['suffix'] = $this->parseSuffixForPatientRecord($patient);
                $patient['previous_names'] = [];
                $patientOrderedList[] = $patientUuid;
            } else {
                $patient = $patientsByUuid[$patientUuid];
            }
            if (!empty($record['patient_history_type_key'])) {
                if ($record['patient_history_type_key'] == 'name_history') {
                    $previousName = array_intersect_key($record, $previousNamesFields);
                    $previousName['formatted_name'] = $this->formatPreviousName($previousName);
                    $patient['previous_names'][] = $previousName;
                }
            }

            // now let's grab our history
            $patientsByUuid[$patientUuid] = $patient;
        }
        foreach ($patientOrderedList as $uuid) {
            $patient = $patientsByUuid[$uuid];
            $processingResult->addData($patient);
        }
        return $processingResult;
    }

    /**
     * Returns a single patient record by patient id.
     * @param $puuidString - The patient uuid identifier in string format.
     * @return ProcessingResult which contains validation messages, internal error messages, and the data
     * payload.
     */
    public function getOne($puuidString)
    {
        $processingResult = new ProcessingResult();

        $isValid = $this->patientValidator->isExistingUuid($puuidString);

        if (!$isValid) {
            $validationMessages = [
                'uuid' => ["invalid or nonexisting value" => " value " . $puuidString]
            ];
            $processingResult->setValidationMessages($validationMessages);
            return $processingResult;
        }

        return $this->search(['uuid' => new TokenSearchField('uuid', [$puuidString], true)]);
    }

    /**
     * Given a pid, find the patient record
     *
     * @param $pid
     */
    public function findByPid($pid)
    {
        $table = PatientService::TABLE_NAME;
        $patientRow = self::selectHelper("SELECT * FROM `$table`", [
            'where' => 'WHERE pid = ?',
            'limit' => 1,
            'data' => [$pid]
        ]);

        return $patientRow;
    }

    /**
     * @return number
     */
    public function getPatientPictureDocumentId($pid)
    {
        $sql = "SELECT doc.id AS id
                 FROM documents doc
                 JOIN categories_to_documents cate_to_doc
                   ON doc.id = cate_to_doc.document_id
                 JOIN categories cate
                   ON cate.id = cate_to_doc.category_id
                WHERE cate.name LIKE ? and doc.foreign_id = ?";

        $result = sqlQuery($sql, array($GLOBALS['patient_photo_category_name'], $pid));

        if (empty($result) || empty($result['id'])) {
            return $this->patient_picture_fallback_id;
        }

        return $result['id'];
    }

    /**
     * Fetch UUID for the patient id
     *
     * @param string $id                - ID of Patient
     * @return false if nothing found otherwise return UUID
     */
    public function getUuid($pid)
    {
        return self::getUuidById($pid, self::TABLE_NAME, 'pid');
    }

    private function saveCareTeamHistory($patientData, $oldProviders, $oldFacilities)
    {
        $careTeamService = new CareTeamService();
        $careTeamService->createCareTeamHistory($patientData['pid'], $oldProviders, $oldFacilities);
    }

    public function getPatientNameHistory($pid)
    {
        $sql = "SELECT pid,
            id,
            previous_name_prefix,
            previous_name_first,
            previous_name_middle,
            previous_name_last,
            previous_name_suffix,
            previous_name_enddate
            FROM patient_history
            WHERE pid = ? AND history_type_key = ?";
        $results =  QueryUtils::sqlStatementThrowException($sql, array($pid, 'name_history'));
        $rows = [];
        while ($row = sqlFetchArray($results)) {
            $row['formatted_name'] = $this->formatPreviousName($row);
            $rows[] = $row;
        }

        return $rows;
    }

    public function deletePatientNameHistoryById($id)
    {
        $sql = "DELETE FROM patient_history WHERE id = ?";
        return sqlQuery($sql, array($id));
    }

    public function getPatientNameHistoryById($pid, $id)
    {
        $sql = "SELECT pid,
            id,
            previous_name_prefix,
            previous_name_first,
            previous_name_middle,
            previous_name_last,
            previous_name_suffix,
            previous_name_enddate
            FROM patient_history
            WHERE pid = ? AND id = ? AND history_type_key = ?";
        $result =  sqlQuery($sql, array($pid, $id, 'name_history'));
        $result['formatted_name'] = $this->formatPreviousName($result);

        return $result;
    }

    /**
     * Create a previous patient name history
     * Updates not allowed for this history feature.
     *
     * @param string $pid patient internal id
     * @param array $record array values to insert
     * @return int | false new id or false if name already exist
     */
    public function createPatientNameHistory($pid, $record)
    {
        $insertData = [
            'pid' => $pid,
            'history_type_key' => 'name_history',
            'previous_name_prefix' => $record['previous_name_prefix'],
            'previous_name_first' => $record['previous_name_first'],
            'previous_name_middle' => $record['previous_name_middle'],
            'previous_name_last' => $record['previous_name_last'],
            'previous_name_suffix' => $record['previous_name_suffix'],
            'previous_name_enddate' => $record['previous_name_enddate']
        ];
        $sql = "SELECT pid FROM " . self::PATIENT_HISTORY_TABLE . " WHERE
            pid = ? AND
            history_type_key = ? AND
            previous_name_prefix = ? AND
            previous_name_first = ? AND
            previous_name_middle = ? AND
            previous_name_last = ? AND
            previous_name_suffix = ? AND
            previous_name_enddate = ?
        ";
        $go_flag = QueryUtils::fetchSingleValue($sql, 'pid', $insertData);
        // return false which calling routine should understand as existing name record
        if (!empty($go_flag)) {
            return false;
        }
        // finish up the insert
        $insertData['uuid'] = UuidRegistry::getRegistryForTable(self::PATIENT_HISTORY_TABLE)->createUuid();
        $insert = $this->buildInsertColumns($insertData);
        $sql = "INSERT INTO " . self::PATIENT_HISTORY_TABLE . " SET " . $insert['set'];

        return QueryUtils::sqlInsert($sql, $insert['bind']);
    }

    public function formatPreviousName($item)
    {
        if (
            $item['previous_name_enddate'] === '0000-00-00'
            || $item['previous_name_enddate'] === '00/00/0000'
        ) {
            $item['previous_name_enddate'] = '';
        }
        $item['previous_name_enddate'] = oeFormatShortDate($item['previous_name_enddate']);
        $name = ($item['previous_name_prefix'] ? $item['previous_name_prefix'] . " " : "") .
            $item['previous_name_first'] .
            ($item['previous_name_middle'] ? " " . $item['previous_name_middle'] . " " : " ") .
            $item['previous_name_last'] .
            ($item['previous_name_suffix'] ? " " . $item['previous_name_suffix'] : "") .
            ($item['previous_name_enddate'] ? " " . $item['previous_name_enddate'] : "");

        return text($name);
    }

    private function parseSuffixForPatientRecord($patientRecord)
    {
        // parse suffix from last name. saves messing with LBF
        $suffixes = $this->getPatientSuffixKeys();
        $suffix = null;
        foreach ($suffixes as $s) {
            if (stripos($patientRecord['lname'], $s) !== false) {
                $suffix = $s;
                $result['lname'] = trim(str_replace($s, '', $patientRecord['lname']));
                break;
            }
        }
        return $suffix;
    }

    private function getPatientSuffixKeys()
    {
        if (!isset($this->patientSuffixKeys)) {
            $this->patientSuffixKeys = array(xl('Jr.'), xl(' Jr'), xl('Sr.'), xl(' Sr'), xl('II'), xl('III'), xl('IV'));
        }
        return $this->patientSuffixKeys;
    }
}
