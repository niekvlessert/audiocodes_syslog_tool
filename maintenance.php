<?php
require "config.php";

@$script = $argv[0];
@$action = $argv[1];

if (!$action) stop();

switch ($action) {
case "tableRotate":
	tableRotate();
	break;
case "vacuumCurrentTables":
	vacuumCurrentTables();
	break;
case "deleteOptionRecords":
	deleteOptionRecords();
	break;
case "initializeDatabase":
	initializeDatabase();
	break;
case "generateRsyslogConfig":
	generateRsyslogConfig();
	break;
default:
	stop();
}

function stop(){
	global $script;

	echo "Usage: php $script tableRotate|vacuumCurrentTables|deleteOptionRecords|initializeDatabase|generateRsyslogConfig\n\n";
	exit;
}

function tableRotate(){
	global $devices_to_log;
	global $dbhost, $dbname, $dbuser, $dbpass;
	$date = date("Ymd");
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $device){
		$result = pg_query($db, "begin");
		$result = pg_query($db, "create table systemevents_".$device."_new (like systemevents_$device);");
		$result = pg_query($db, "alter table systemevents_$device rename to systemevents_".$device."_".$date);
		$result = pg_query($db, "alter table systemevents_".$device."_new rename to systemevents_".$device);
		$result = pg_query($db, "commit");
		echo "systemevents_$device rotated.\n";
	}
}

function vacuumCurrentTables(){
	global $devices_to_log;
	global $dbhost, $dbname, $dbuser, $dbpass;
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $device) {
		$result = pg_query($db, "vacuum systemevents_$device");
		echo "systemevents_$device vacuum complete.\n";
	}
}

// delete options
function deleteOptionRecords(){}
//do this query
//select substring(main.message from E'\\[[^]]*\\]') as number from systemevents main, systemevents secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%OPTIONS sip%';
//foreach delete where like;

function generateRsyslogConfig(){
        global $devices_to_log;
	$filename = "00_audiocodes.conf";
	$data = "";

	if (file_exists($filename)) unlink($filename);

	foreach ($devices_to_log as $name => $ip) {
		$data.="\$template $name,\"insert into SystemEvents_$name (Message, Facility, FromHost, Priority, DeviceReportedTime, ReceivedAt, InfoUnitID, SysLogTag) values ('%msg%', %syslogfacility%, '%HOSTNAME%', %syslogpriority%, '%timereported:::date-pgsql%', '%timegenerated:::date-pgsql%', %iut%, '%syslogtag%')\",STDSQL\n";
		$data.="if \$fromhost-ip startswith '$ip' then :ompgsql:127.0.0.1,syslog,syslog,syslog;$name\n";
	}
	file_put_contents($filename, $data);
}

function initializeDatabase(){
	global $devices_to_log;
	global $dbhost, $dbname, $dbuser, $dbpass;

	// create the default things as user postgres, then swap to default role

	$db = pg_connect("host=$dbhost port=5432 user=postgres password=") or die("error");

	// create role syslog if it doesn't exist already
	// create database Syslog as user postgres and change owner to syslog if it doesn't exist already

	$result = @pg_query("create database $dbname");
	if (strpos(pg_last_error($db), "already exists")>0) echo "Primary database $dbname already exists...\n";
	$result = @pg_query("create role $dbuser with login encrypted password '$dbpass';");
	if (strpos(pg_last_error($db), "already exists")>0) echo "Primary role $dbuser already exists...\n";
	$result = @pg_query("alter database $dbname owner to $dbuser");
	
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $key => $device) {
		$query="CREATE TABLE SystemEvents_$key ( ID serial not null primary key, CustomerID bigint, ReceivedAt timestamp without time zone NULL, DeviceReportedTime timestamp without time zone NULL, Facility smallint NULL, Priority smallint NULL, FromHost varchar(60) NULL, Message text, NTSeverity int NULL, Importance int NULL, EventSource varchar(60), EventUser varchar(60) NULL, EventCategory int NULL, EventID int NULL, EventBinaryData text NULL, MaxAvailable int NULL, CurrUsage int NULL, MinUsage int NULL, MaxUsage int NULL, InfoUnitID int NULL , SysLogTag varchar(60), EventLogType varchar(60), GenericFileName VarChar(60), SystemID int NULL );";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table Systemevents_$key already exists...\n";

		$query="CREATE TABLE SystemEventsProperties_$key ( ID serial not null primary key, SystemEventID int NULL , ParamName varchar(255) NULL , ParamValue text NULL);";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table SystemeventsProperties_$key already exists...\n";
	}
}
?>
