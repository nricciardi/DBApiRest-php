<?php

class DBApiRest {

    // array of options
    private $options = array(
        "PROCESSES_FK" => true,      // if it is true, parse fk id in object
        "SAVE_PROCESSES_FK_ID" => false         // doens't remove the fk id during parse fk id
    );

    private $pdoConnection = null;
    private $connection_options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH
    ];
    private $database_name;

    // feedback message
    private $PROCESSING_FAILED = array("result" => false, "description" => "Processing failed");
    private $CONNESSION_ERROR = array("result" => false, "description" => "Database connection error");
    private $MISSING_DATA = array("result" => false, "description" => "Missing data");
    private $SUCCESSFUL = array("result" => true, "description" => "Successful");

    /**
     * Class constructor.
     */
    public function __construct($hostname = null, $database_name = null, $username = null, $password = null) {
        try {
            // check parameters to create PDO connection
            if($hostname === null || $database_name === null || $username === null || $password === null) {
                throw new Exception('You must use correct settings');
            } else {
                // create connection
                $this->pdoConnection = new PDO("mysql:host=$hostname;dbname=$database_name", $username, $password, $this->connection_options);
                $this->database_name = $database_name;
            }
        } catch (Exception $e) {
            throw new Exception('Error during connection to dabase');
        }
        
    }

    /**
     * Public method
     */
    // set settings
    public function use($key = null, $value = null) {
        
        if($key == null || $value == null)
            return json_encode($this->MISSING_DATA);

        if(!is_bool($value)) {
            return json_encode($this->PROCESSING_FAILED);
        }

        if(array_key_exists($key, $this->options))
            $this->options[$key] = $value;
        else
            return json_encode($this->PROCESSING_FAILED);

        return json_encode($this->SUCCESSFUL);

    }


    // create a new record
    public function create($table_name = null, $new_record = null) {
        
        try {
            $create_query = "INSERT INTO `" . $this->sanitizeField($table_name) . "`(";
            $value_of_query = "(";
            $value_of_execute_binding = array();
            $not_null_fields = $this->getFieldNamesList($table_name, true);         // take not null fields
            if($not_null_fields == false)
                throw new Exception("Submethod error");

            // check not null field
            foreach ($not_null_fields as $key => $value) {
                if(!array_key_exists($value, $new_record))
                    return $this->MISSING_DATA;
            }

            // add field values
            $fields_list = $this->getFieldNamesList($table_name);
            if($fields_list == false)
                throw new Exception("Submethod error");
                
            foreach ($new_record as $key => $value) {

                // check if there is a key not in $fields_list
                if(in_array($key, $fields_list)) {
                    $create_query .= "`" . $key . "`,";
                    $value_of_query .= "?,";        // insert placeholder

                    array_push($value_of_execute_binding, $value);

                }
            }

            // remove the last ,
            if($create_query[strlen($create_query)-1] == ",")
                $create_query = substr($create_query, 0, strlen($create_query)-1);

            if($value_of_query[strlen($value_of_query)-1] == ",")
                $value_of_query = substr($value_of_query, 0, strlen($value_of_query)-1);

            // assembly the query
            $create_query = $create_query . ") VALUES " . $value_of_query . ")";

            // execute query
            $sth = $this->pdoConnection->prepare($create_query);
            $result = $sth->execute($value_of_execute_binding);
            if($result == false)
                return json_encode($this->PROCESSING_FAILED);
            else
                return json_encode($this->SUCCESSFUL);

        } catch (Exception $e) {
            echo $e->getMessage();
            return json_encode($this->CONNESSION_ERROR);
        }
    }

    // read the record of table (with id = $id)
    public function read($table_name = null, $id = null) {

        try {

            $pk_name = $this->getPkName($table_name);

            if($pk_name == false)
                throw new Exception("Submethod error");

            $select_query = "SELECT * FROM " . $this->sanitizeField($table_name);

            // insert where condition if there is an id
            if(is_int($id)) {
                $select_query .= " WHERE `" . $pk_name . "`=?"; 
            } elseif (is_array($id)) {
                $select_query .= " WHERE";      // add where clause
                foreach ($id as $key => $value) {
                    $select_query .= " `" . $pk_name . "`=? OR";
                }

                // remove the last OR
                if(($select_query[strlen($select_query)-2] + $select_query[strlen($select_query)-1]) == "OR")
                    $select_query = substr($select_query, 0, strlen($select_query)-2);


                
            } else {
                return json_encode($this->PROCESSING_FAILED);
            }
                
            // execute the query
            $sth = $this->pdoConnection->prepare($select_query);
            $result = $sth->execute([$id]);

            if($result == false)        // check the result of execute
                return json_encode($this->PROCESSING_FAILED);

            $records = $sth->fetchAll();
            unset($sth);

            // process fk if option is true
            if(isset($this->options["PROCESSES_FK"]) && $this->options["PROCESSES_FK"] == true) {

                $replace_data = array();        // value to use to replace fk to data

                $fk_list = $this->getFkInformation($table_name);

                if(!is_array($fk_list) && $fk_list == false)
                    throw new Exception("Submethod error");

                // for each fk, insert an object
                for ($fk=0; $fk < count($fk_list); $fk++) { 
                    // get all id to use
                    $all_id = array();
                    for ($index=0; $index < count($records); $index++) { 
                        $record = $records[$index];

                        if(!isset($fk_list[$fk]["COLUMN_NAME"]))
                            throw new Exception("Subprocess error");
                            
                        $record_id = $record[$fk_list[$fk]["COLUMN_NAME"]];    // take id of all record
                        array_push($all_id, $record_id);
                    }

                    // remove duplicates
                    $all_id = array_unique($all_id);

                    if(!isset($fk_list[$fk]["REFERENCED_TABLE_SCHEMA"]) || !isset($fk_list[$fk]["REFERENCED_TABLE_NAME"]) || !isset($fk_list[$fk]["REFERENCED_COLUMN_NAME"]))
                        throw new Exception("Subprocess error");

                    $select_records_query = "SELECT * FROM " . $fk_list[$fk]["REFERENCED_TABLE_SCHEMA"] . "." . $fk_list[$fk]["REFERENCED_TABLE_NAME"] . " WHERE";

                    // for each fk id append where condition
                    for ($id=0; $id < count($all_id); $id++) {
                        $select_records_query .= " `" . $fk_list[$fk]["REFERENCED_COLUMN_NAME"] . "`=?";

                        // append OR
                        if($id < count($all_id) -1)
                            $select_records_query .= " OR";
                    }

                    // execute query to find object of fk id
                    $sth = $this->pdoConnection->prepare($select_records_query);
                    $result = $sth->execute($all_id);
                    if($result == false)
                        return json_encode($this->PROCESSING_FAILED);
                    else
                        $fk_data = $sth->fetchAll();       // take all possible data to replace

                    // convert fetchAll in object key-value. Ex. id => array(...)
                    foreach ($fk_data as $key => $value) {
                        $replace_data[$value[$pk_name]] = $value;
                    }

                    // for each record replace fk data
                    for ($record=0; $record < count($records); $record++) { 
                        $fk_col_name = $fk_list[$fk]["COLUMN_NAME"];
                        $records[$record][$fk_list[$fk]["REFERENCED_TABLE_NAME"]] = $replace_data[$records[$record][$fk_col_name]];
                    
                        if(isset($this->options["SAVE_PROCESSES_FK_ID"]) && $this->options["SAVE_PROCESSES_FK_ID"] == false)
                            unset($records[$record][$fk_col_name]);
                    }
                }    
            }

            return json_encode(array("result" => $records, "description" => "Successful"));
            
        } catch (Exception $e) {
            // echo $e->getMessage();
            return json_encode($this->CONNESSION_ERROR);
        }

    }

    // update an existing record
    public function update($table_name = null, $new_data = null, $id = null) {
        try {
            $update_query = "UPDATE " . $this->sanitizeField($table_name) . " SET ";
            $value_of_execute_binding = array();

            // check input
            if($id == null || $new_data == null) {
                return json_encode($this->MISSING_DATA);
            }

            // append data to the query
            $fields_list = $this->getFieldNamesList($table_name);
            if($fields_list == false)
                throw new Exception("Submethod error");

            $can_execute = false;       // can execute query if there is at least one data
            foreach ($new_data as $key => $value) {
                if(in_array($key, $fields_list)) {
                    $update_query .= "`$key`=?,";
                    array_push($value_of_execute_binding, $value);
                    $can_execute = true;
                }
            }

            // check if can execute query
            if($can_execute == false)
                return json_encode($this->MISSING_DATA);

            // remove the last ,
            if($update_query[strlen($update_query)-1] == ",")
                $update_query = substr($update_query, 0, strlen($update_query)-1);
        
            // append WHERE
            $pk_name = $this->getPkName($table_name);
            if($pk_name == false)
                return json_encode($this->PROCESSING_FAILED);
            else
                $update_query .= " WHERE `" . $pk_name . "`=?";
            array_push($value_of_execute_binding, $id);

            // execute query
            $sth = $this->pdoConnection->prepare($update_query);
            $result = $sth->execute($value_of_execute_binding);
            if($result == false)
                return json_encode($this->PROCESSING_FAILED);
            else
                return json_encode($this->SUCCESSFUL);

        } catch (Exception $e) {
            // echo $e->getMessage();
            return json_encode($this->CONNESSION_ERROR);
        }
    }

    // delete a resource
    public function delete($table_name = null, $id = null) {
        try {
            
            // check input
            if($table_name == null || $id == null)
                return json_encode($this->MISSING_DATA);
            
            // create the delete query
            $delete_query = "DELETE FROM `" . $this->sanitizeField($table_name) . "` WHERE";

            // insert where condition
            if (is_array($id)) {        // if there are more ids
                foreach ($id as $key => $value) {
                    $delete_query .= " `" . $this->getPkName($table_name) . "`=? OR";
                }

                // remove the last OR
                if(($delete_query[strlen($delete_query)-2] + $delete_query[strlen($delete_query)-1]) == "OR")
                    $delete_query = substr($delete_query, 0, strlen($delete_query)-2);

            } elseif (is_int($id)) {
                $delete_query .= " `" . $this->getPkName($table_name) . "`=?";
                $id = array($id);       // convert an int to array to use it in execute
            } else {
                return json_encode($this->PROCESSING_FAILED);
            }

            $sth = $this->pdoConnection->prepare($delete_query);
            $result = $sth->execute($id);
            
            // check result
            if($result == false)
                return json_encode($this->PROCESSING_FAILED);
            else
                return json_encode($this->SUCCESSFUL);
            
                

        } catch (Exception $e) {
            echo $e->getMessage();
            return json_encode($this->CONNESSION_ERROR);
        }
    }

    /**
     * Private method.
     */
    // check if there is the table in the database
    private function checkTable($table_name = null) {

        try {
            // get the list of table names
            $result = $this->pdoConnection->query("SHOW TABLES");

            // check result
            if($result == false)
                return false;

            $records = $result->fetchAll();

            // check if there is the table
            foreach ($records as $key => $value) {
                if($value[0] == $table_name)
                    return true;
            }

            return false;       // if there isn't table return false

        } catch (Exception $e) {
            return false;
        }
    }

    // return the table fields information of table_name
    private function getInformationOfTableFields($table_name = null) {
        try {
            $describe_query = "DESCRIBE " . $this->sanitizeField($table_name);
            $result = $this->pdoConnection->query($describe_query);

            // check result
            if($result == false)
                return false;

            $records = $result->fetchAll();

            return $records;

        } catch (Exception $e) {
            // echo $e->getMessage();
            return false;
        }
    }

    // return an array with all field names
    private function getFieldNamesList($table_name = null, $only_not_null = false, $pk_field_is_ai = true) {    // ai = auto_increment
        try {
            $fields = array();

            $fieldsInfo = $this->getInformationOfTableFields($table_name);      // take fields from table
        
            if($fieldsInfo == false) {
                throw new Exception("Incorrect table name");
            }
            // var_dump($fieldsInfo);

            foreach ($fieldsInfo as $key => $value) {
                if(!($only_not_null == true && stripos($value["Null"], "YES"))) {       // check if the field can be null
                    if(!($value["Field"] == $this->getPkName($table_name) && $pk_field_is_ai == true && $only_not_null == true)) {  // if pk field is ai, it is a nullable field
                        array_push($fields, $value["Field"]);
                    }
                }        
            }

            return $fields;

        } catch (Exception $e) {
            // echo $e->getMessage();
            return false;
        }
    }

    // return the field name of PK
    private function getPkName($table_name = null) {
        try {
            $fields = $this->getInformationOfTableFields($table_name);      // take fields from table

            if($fields == false) {
                throw new Exception("Incorrect table name");
            }

            foreach ($fields as $key => $value) {
                if(stripos($value["Key"], "PRI") !== false)
                    return $value["Field"];
            }

            return null;

        } catch (Exception $e) {
            // echo $e->getMessage();
            return false;
        }

    }

    // return the field names of FK
    private function getFkInformation($table_name = null) {
        try {
            $table_name = $this->sanitizeField($table_name);
            $information_fk_query = "SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_NAME='$table_name' AND REFERENCED_TABLE_SCHEMA IS NOT NULL";

            // execute query
            $result = $this->pdoConnection->query($information_fk_query);
            if($result == false)
                throw new Exception("Query error");
            else    
                return $result->fetchAll();

        } catch (Exception $e) {
            // echo $e->getMessage();
            return false;
        }
    }

    // sanitize table name to use in query
    private function sanitizeField($table_name = null) {

        if($table_name === null)
            throw new Exception("Missing table name");
            
        $sanitized_name = $table_name;
        $sanitized_name = filter_var($sanitized_name, FILTER_SANITIZE_URL);
        // $sanitized_name = $this->pdoConnection->filterForSql($sanitized_name);

        return $sanitized_name;
    }
}

define("HOSTNAME", "127.0.0.1");
define("DATABASE_NAME", "ruffolo");
define("USERNAME", "root");
define("PASSWORD", "");

$dbApiRest = new DBApiRest(HOSTNAME, DATABASE_NAME, USERNAME, PASSWORD);
echo $dbApiRest->delete("commission", 1);



?>