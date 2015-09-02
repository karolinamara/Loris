<?php

// Dumps the database instruments and some accompanying information into Excel formatted files.
// For some large tables, this script requires *a lot* of memory.  Modify the `cli` php.ini for > 256M

// Operation:
// Passes the results from one or more SQL queries to the writeExcel function.

// Future improvements:
// The SQL to pull the instrument data rely on some nastry text matching (ie. where c.PSCID not like '1%').  Ideally, this junk could be purged directly from the DB, and the SQL made more plain.

require_once "generic_includes.php";
require_once "Archive/Tar.php";
require_once "Utility.class.inc";
//Configuration variables for this script, possibly installation dependent.
//$dataDir = "dataDump" . date("dMy");
$dumpName = "IBISdataDump" . date("dMy"); // label for dump
$config = NDB_Config::singleton();
$paths = $config->getSetting('paths');
$dataDir = $paths['base'] . "tools/$dumpName/"; //temporary working directory
$destinationDir = $paths['base'] . "htdocs/dataDumps/csv"; //temporary working directory

/*
* Prepare output directory, if needed.
*/
//Create
if(!file_exists($dataDir)) {
	mkdir($dataDir);
}
else
{
    //Delete all previous files.
        $d = dir($dataDir);
        while($entry = $d->read()) {
            if ($entry!= "." && $entry!= "..") {
                unlink($dataDir . "/" . $entry);
            }
        }
        $d->close();
}

//Substites words for number in ProjectID data field
function MapProjectID(&$results){
    $projects = Utility::getProjectList();
    for($i = 0; $i < count($results); $i++){
        $results[$i]["ProjectID"] = $projects[$results[$i]["ProjectID"]];
    }
    return $results;
}
//Substitute words for numbers in Subproject data field
function MapSubprojectID(&$results, NDB_Config $config) {

    $study = $config->getSetting('study');
    $subprojectLookup = array();
    // Look it up from the config file, because it's not stored
    // in the database
    foreach($study["subprojects"]["subproject"] as $subproject) {
	    $subprojectLookup[$subproject["id"]] =  $subproject["title"];
    }

    for ($i = 0; $i < count($results); $i++) {
	    $results[$i]["SubprojectID"] = 
                $subprojectLookup[$results[$i]["SubprojectID"]];
    }
    return $results;
}
/*
* Start with all the instrument tables
*/
//Get the names of all instrument tables
$query = "select * from test_names order by Test_name";
//$query = "select * from test_names where Test_name like 'a%' order by Test_name";  //for rapid testing
$instruments = $DB->pselect($query, array());
if (PEAR::isError($instruments)) {
	PEAR::raiseError("Couldn't get instruments. " . $instruments->getMessage());
}
foreach ($instruments as $instrument) {
	//Query to pull the data from the DB
	$Test_name = $instrument['Test_name'];
    if($Test_name == 'prefrontal_task') {
        
	    $query = "select c.PSCID, c.CandID, s.SubprojectID, s.Visit_label, s.Submitted, s.Current_stage, s.Screening, s.Visit, f.Administration, e.full_name as Examiner_name, f.Data_entry, 'See validity_of_data field' as Validity, i.* from candidate c, session s, flag f, $Test_name i left outer join examiners e on i.Examiner = e.examinerID where c.PSCID not like 'dcc%' and c.PSCID not like '0%' and c.PSCID not like '1%' and c.PSCID not like '2%' and c.PSCID != 'scanner' and i.CommentID not like 'DDE%' and c.CandID = s.CandID and s.ID = f.sessionID and f.CommentID = i.CommentID AND c.Active='Y' AND s.Active='Y' AND c.CenterID IN (2, 3, 4, 5) order by s.Visit_label, c.PSCID";

    } else if ($Test_name == 'radiology_review') {
        $query = "select c.PSCID, c.CandID, s.SubprojectID, s.Visit_label, s.Submitted, s.Current_stage, s.Screening, s.Visit, f.Administration, e.full_name as Examiner_name, f.Data_entry, f.Validity, 'Site review:', i.*, 'Final Review:', COALESCE(fr.Review_Done, 0) as Review_Done, fr.Final_Review_Results, fr.Final_Exclusionary, fr.SAS, fr.PVS, fr.Final_Incidental_Findings, fre.full_name as Final_Examiner_Name, fr.Final_Review_Results2, fre2.full_name as Final_Examiner2_Name, fr.Final_Exclusionary2, COALESCE(fr.Review_Done2, 0) as Review_Done2, fr.SAS2, fr.PVS2, fr.Final_Incidental_Findings2, fr.Finalized from candidate c, session s, flag f, $Test_name i left join final_radiological_review fr ON (fr.CommentID=i.CommentID) left outer join examiners e on (i.Examiner = e.examinerID) left join examiners fre ON (fr.Final_Examiner=fre.examinerID) left join examiners fre2 ON (fre2.examinerID=fr.Final_Examiner2) where c.PSCID not like 'dcc%' and c.PSCID not like '0%' and c.PSCID not like '1%' and c.PSCID not like '2%' and c.PSCID != 'scanner' and i.CommentID not like 'DDE%' and c.CandID = s.CandID and s.ID = f.sessionID and f.CommentID = i.CommentID AND c.Active='Y' AND s.Active='Y' AND c.CenterID IN (2, 3, 4, 5) order by s.Visit_label, c.PSCID";
    } else {
        if (is_file("../project/instruments/NDB_BVL_Instrument_$Test_name.class.inc")) {
            $instrument =& NDB_BVL_Instrument::factory($Test_name, '', false);
            if($instrument->ValidityEnabled == true) {
                $extra_fields = 'f.Validity, ';
            } 
            $NDB_Config = NDB_Config::singleton();
            $ddeInstruments = $NDB_Config->getSetting("DoubleDataEntryInstruments");
            if(in_array($Test_name, $ddeInstruments)) {
                $extra_fields .= "CASE ddef.Data_entry='Complete' WHEN 1 then 'Y' 
                                                                 WHEN  NULL then 'Y' 
                                                                 ELSE 'N' 
                                    END AS DDE_Complete, 
                                 CASE WHEN EXISTS (SELECT 'x' FROM conflicts_unresolved cu WHERE i.CommentID=cu.CommentId1 OR i.CommentID=cu.CommentId2) THEN 'Y' ELSE 'N' END AS conflicts_exist, ";
            }
	        $query = "select c.PSCID, c.CandID, s.SubprojectID, s.Visit_label, s.Submitted, s.Current_stage, s.Screening, s.Visit, f.Administration, e.full_name as Examiner_name, f.Data_entry, $extra_fields i.* from candidate c, session s, flag f, $Test_name i left outer join examiners e on i.Examiner = e.examinerID left join flag ddef ON (ddef.CommentID=CONCAT('DDE_', i.CommentID)) WHERE c.PSCID not like 'dcc%' and c.PSCID not like '0%' and c.PSCID not like '1%' and c.PSCID not like '2%' and c.PSCID != 'scanner' and i.CommentID not like 'DDE%' and c.CandID = s.CandID and s.ID = f.sessionID and f.CommentID = i.CommentID AND c.Active='Y' AND  s.Active='Y' AND c.CenterID IN (2, 3, 4, 5) order by s.Visit_label, c.PSCID";

        } else {
	    $query = "select c.PSCID, c.CandID, s.SubprojectID, s.Visit_label, s.Submitted, s.Current_stage, s.Screening, s.Visit, f.Administration, e.full_name as Examiner_name, f.Data_entry, f.Validity, i.* from candidate c, session s, flag f, $Test_name i left outer join examiners e on i.Examiner = e.examinerID where c.PSCID not like 'dcc%' and c.PSCID not like '0%' and c.PSCID not like '1%' and c.PSCID not like '2%' and c.PSCID != 'scanner' and i.CommentID not like 'DDE%' and c.CandID = s.CandID and s.ID = f.sessionID and f.CommentID = i.CommentID AND c.Active='Y' AND s.Active='Y' AND  c.CenterID IN (2, 3, 4, 5) order by s.Visit_label, c.PSCID";
        }
    }
	$DB->select($query, $instrument_table);
	if(PEAR::isError($instrument_table)) {
		print "Cannot pull instrument table data ".$instrument_table->getMessage()."<br>\n";
		die();
	}
    MapSubprojectID($instrument_table, $config);
	writeCSV($Test_name, $instrument_table, $dataDir);

} //end foreach instrument

/*
* Special figs_year3_relatives query
*/
$Test_name = "figs_year3_relatives";
$query = "select c.PSCID, c.CandID, s.SubprojectID, s.Visit_label, fyr.* from candidate c, session s, flag f, figs_year3_relatives fyr where c.PSCID not like 'dcc%' and fyr.CommentID not like 'DDE%' and c.CandID = s.CandID and s.ID = f.sessionID and f.CommentID = fyr.CommentID AND c.Active='Y' AND s.Active='Y' order by s.Visit_label, c.PSCID";
$DB->select($query, $instrument_table);
if(PEAR::isError($instrument_table)) {
	print "Cannot figs_year3_relatives data ".$instrument_table->getMessage()."<br>\n";
	die();
}
MapSubprojectID($instrument_table, $config);
writeCSV($Test_name, $instrument_table, $dataDir);

/*
* Candidate Information query
*/
$Test_name = "candidate_info";
//this query is a but clunky, but it gets rid of all the crap that would otherwise appear.
$query = "SELECT DISTINCT c.PSCID, c.CandID, c.Gender, c.DoB, s.SubprojectID, c.ProjectID, pc.Value as Plan, c.EDC from candidate c LEFT JOIN session s ON (c.CandID = s.CandID) LEFT JOIN parameter_candidate pc ON (c.CandID = pc.CandID AND pc.ParameterTypeID=73754) WHERE c.CenterID IN (2,3,4,5) AND c.Active='Y'  AND s.Active='Y' ORDER BY c.PSCID";
$DB->select($query, $results);
if (PEAR::isError($results)) {
	PEAR::raiseError("Couldn't get candidate info. " . $results->getMessage());
}

MapProjectID($results);
MapSubprojectID($results, $config);
writeCSV($Test_name, $results, $dataDir);

/*
* Data Dictionary construction
* This relies on the quickform_parser and data_dictionary_builder having being recently run
*/
$Test_name = "IBISDataDictionary";
$query = "select Name, Type, Description, SourceField, SourceFrom from parameter_type where SourceField is not null order by SourceFrom";
$DB->select($query, $dictionary);
if (PEAR::isError($dictionary)) {
	PEAR::raiseError("Could not generate data dictionary. " . $dictionary->getMessage());
}
writeCSV($Test_name, $dictionary, $dataDir);

// Clean up
// tar and gzip the product
$tarFile = $dumpName . ".csv.tgz"; // produced dump file name and extension
$tar = new Archive_Tar($tarFile, "gz");
$tar->add("./$dumpName/")
or die ("Could not add files!");

// mv (or as php calls it, 'rename') to a web-accessible pickup directory
rename("./$tarFile", "$destinationDir/$tarFile"); //change, if not a subdirectory

// rm left-over junk, from all that excel file generation.
//delTree($dataDir);

// Announce completion
echo "$tarFile ready in $destinationDir\n";




/**
 * Takes a 2D array and saves it as a nicely formatted Excel spreadsheet.
 * Metadata columns are preserved, multiple worksheets are used, when appropriate and headers are maintained.
 *
 * @param string	$Test_name	File name to be used.
 * @param unknown_type $instrument_table 	A 2D array of the data to be presented in Excel format
 * @param unknown_type $dataDir The  output directory.
 */
function writeCSV($Test_name, $instrument_table, $dataDir) {

    $maxColsPerFile = 1024;

	//Modifiable parameters
	$junkCols = array("CommentID", "UserID", "Examiner", "Testdate", "Data_entry_completion_status"); //columns to be removed

    //ensure non-empty result set
	if (count($instrument_table) ==0) { //empty result set
		echo "Empty: $Test_name  [Contains no data]\n";
		return; // move on to the next instrument //nothing to save
	}

	//remove any undesired columns that came in from the DB query.
	for ($i = 0; $i < count($instrument_table); $i++) {
		$instrument_table[$i] = array_diff_key($instrument_table[$i], array_flip($junkCols));
	}


    //generate one CSV file with all columns
    $headers = array_keys($instrument_table[0]);
    $fileNamePrefix = $dataDir . "/" . $Test_name;
    $fullFileName = $fileNamePrefix.(count($headers) > $maxColsPerFile ? "_FULL" : "" ).".csv";
    writeOneCSVFile($fullFileName, $headers, $instrument_table);

    //if more columns than $maxColsPerFile
    // also generate multiple CSV files with each $maxColsPerFile
    // numb of columns
    if (count($headers) > $maxColsPerFile) {
        $headerChunks = array_chunk($headers, $maxColsPerFile);

        $dataChunks = array();
        foreach($instrument_table as $rowKey=>$row){
            $rowChunks = array_chunk($row, $maxColsPerFile);
            foreach($rowChunks as $chunkKey => $oneRowChunk){
                $dataChunks[$chunkKey][] = $oneRowChunk;
            }
        }

        foreach($dataChunks as $key=>$oneDataChunk){
            writeOneCSVFile($fileNamePrefix."_part".($key+1).".csv", $headerChunks[$key], $oneDataChunk);
        }
    }

}

/**
 * Writes one CSV file
 *
 * @param string $fileName CSV file path and name
 * @param array $headers list of column names
 * @param array $dataRows 2D array of the data to be written to CSV file
 *
 * @return bool true on success, false on failure
 */
function writeOneCSVFile($fileName, $headers, $dataRows) {
    $fp = fopen($fileName, "w");
    if (!$fp) {
        echo "\n ERROR: Failed creating file ".$fileName."\n";
        return false;
    }

    fputcsv($fp, $headers);

    foreach ($dataRows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);

    return true;
}

/**
 * PHP equivalent of `rm -rf`
 * This function stolen from PHP manual
 * @param string dir Directory to be recursively deleted, ending with a slash
 *
 */
function delTree($dir) {
	$files = glob( $dir . '*', GLOB_MARK );
	foreach( $files as $file ){
		if( substr( $file, -1 ) == '/' ) {
			delTree( $file );
		} else {
			unlink( $file );
		}
	}
	if (is_dir($dir)) rmdir( $dir );
}

?>
