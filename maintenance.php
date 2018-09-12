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
	$date = date("Ymd",strtotime("-1 days"));
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $name => $ip){
		$result = pg_query($db, "begin");
		$result = pg_query($db, "create table systemevents_".$name."_new (like systemevents_$name);");
		$result = pg_query($db, "alter table systemevents_$name rename to systemevents_".$name."_".$date);
		$result = pg_query($db, "alter table systemevents_".$name."_new rename to systemevents_".$name);
		$result = pg_query($db, "commit");
		sleep (2);
		$result = pg_query($db, "alter table systemevents_".$name." alter column id set default nextval('systemevents_".$name."_id_seq')"); //auto nextval doesn't come along..
		echo "systemevents_$name rotated.\n";
	}
}

function vacuumCurrentTables(){
	global $devices_to_log;
	global $dbhost, $dbname, $dbuser, $dbpass;
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $name => $ip) {
		$result = pg_query($db, "vacuum systemevents_$name");
		echo "systemevents_$name vacuum complete.\n";
	}
}

function deleteOptionRecords(){
        global $devices_to_log;
	global $dbhost, $dbname, $dbuser, $dbpass;
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $name => $ip) {
		$result = pg_query($db, "vacuum systemevents_$name");
		#$query=pg_escape_string("select substring(main.message from E'\\[[^]]*\\]') as number from systemevents_$name main, systemevents_$name secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%OPTIONS sip%'");
		#$result = pg_prepare($db, "my_query", "select substring(main.message from $1) as number from systemevents_$name main, systemevents_$name secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%OPTIONS sip%'");
		#$result = pg_execute($db, "my_query", array("E'\\[[^]]*\\]'"));
		#$query = "select secondary.message from systemevents_$name main, systemevents_$name secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%OPTIONS sip%'";
		$query = "select message from systemevents_$name where message like '%OPTIONS sip%';";
		$result = pg_query($db, $query);
		echo "Options records to delete for $name: ".pg_num_rows($result)."\n";
		$a = 0;
		echo "Every dot is 100 OPTIONS SIDs deleted.\n";
		while ($row = pg_fetch_row($result)){
			//var_dump($row[0]);
			$message = preg_match("/\[(.*)\]/",$row[0], $match);
			$query2 = "delete from systemevents_$name where message like '%$match[1]%'";
			$a++;
			if ($a==100) { 
				echo ".";
				$a = 0;
			}
			//echo $query2."\n";
			$result2 = pg_query($db, $query2);
		}
		echo "\n";
		$result = pg_query($db, "vacuum systemevents_$name");
	}
}

function generateRsyslogConfig(){
        global $devices_to_log;
	$filename = "00_audiocodes.conf";

	if (file_exists($filename)) unlink($filename);

	$data = "\$ModLoad imudp\n";
	$data .= "\$UDPServerRun 514\n";
	$data .= "\$ModLoad ompgsql\n";
	$data .= "\$WorkDirectory /var/tmp\n";
	$data .= "\$ActionQueueType LinkedList # use asynchronous processing\n";
	$data .= "\$ActionQueueFileName dbq    # set file name, also enables disk mode\n";
	$data .= "\$ActionResumeRetryCount -1   # infinite retries on insert failure\n";

	foreach ($devices_to_log as $name => $ip) {
		$data.="\$template $name"."_cdr,\"insert into SystemEvents_$name"."_cdr (Message, Facility, FromHost, Priority, DeviceReportedTime, ReceivedAt, InfoUnitID, SysLogTag) values ('%msg%', %syslogfacility%, '%HOSTNAME%', %syslogpriority%, '%timereported:::date-pgsql%', '%timegenerated:::date-pgsql%', %iut%, '%syslogtag%')\",STDSQL\n";
		$data.="\$template $name,\"insert into SystemEvents_$name (Message, Facility, FromHost, Priority, DeviceReportedTime, ReceivedAt, InfoUnitID, SysLogTag) values ('%msg%', %syslogfacility%, '%HOSTNAME%', %syslogpriority%, '%timereported:::date-pgsql%', '%timegenerated:::date-pgsql%', %iut%, '%syslogtag%')\",STDSQL\n";
		$data.="if \$fromhost-ip startswith '$ip' and \$syslogfacility-text == 'local1' then :ompgsql:127.0.0.1,syslog,syslog,syslog;$name"."_cdr\n";
		$data.="& ~\n";
		$data.="if \$fromhost-ip startswith '$ip' then :ompgsql:127.0.0.1,syslog,syslog,syslog;$name\n";
		$data.="& ~\n";
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

	$result = @pg_query("create LANGUAGE plpgsql;");

	pg_close($db);
	
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	echo "\n";

	foreach ($devices_to_log as $name => $device) {
		$query="CREATE TABLE SystemEvents_$name ( ID serial not null primary key, CustomerID bigint, ReceivedAt timestamp without time zone NULL, DeviceReportedTime timestamp without time zone NULL, Facility smallint NULL, Priority smallint NULL, FromHost varchar(60) NULL, Message text, NTSeverity int NULL, Importance int NULL, EventSource varchar(60), EventUser varchar(60) NULL, EventCategory int NULL, EventID int NULL, EventBinaryData text NULL, MaxAvailable int NULL, CurrUsage int NULL, MinUsage int NULL, MaxUsage int NULL, InfoUnitID int NULL , SysLogTag varchar(60), EventLogType varchar(60), GenericFileName VarChar(60), SystemID int NULL );";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table Systemevents_$name already exists...\n"; else echo "Table Systemevents_$name created.\n";

		$query="CREATE TABLE SystemEvents_".$name."_cdr ( ID serial not null primary key, CustomerID bigint, ReceivedAt timestamp without time zone NULL, DeviceReportedTime timestamp without time zone NULL, Facility smallint NULL, Priority smallint NULL, FromHost varchar(60) NULL, Message text, NTSeverity int NULL, Importance int NULL, EventSource varchar(60), EventUser varchar(60) NULL, EventCategory int NULL, EventID int NULL, EventBinaryData text NULL, MaxAvailable int NULL, CurrUsage int NULL, MinUsage int NULL, MaxUsage int NULL, InfoUnitID int NULL , SysLogTag varchar(60), EventLogType varchar(60), GenericFileName VarChar(60), SystemID int NULL );";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table Systemevents_".$name."_cdr already exists...\n"; else echo "Table Systemevents_".$name."_cdr created.\n";
		$result = pg_query($db, "alter table systemevents_".$name."_cdr alter column id set default nextval('systemevents_".$name."_cdr_id_seq')"); //auto nextval doesn't come along..

		$query= "CREATE TABLE systemevents_$name"."_cdr_formatted( id SERIAL PRIMARY KEY, SBCReportType VARCHAR, EPTyp VARCHAR, SIPCallId VARCHAR, SessionId VARCHAR, Orig VARCHAR, SourceIp VARCHAR, SourcePort VARCHAR, DestIp VARCHAR, DestPort VARCHAR, TransportType VARCHAR, SrcURI VARCHAR, SrcURIBeforeMap VARCHAR, DstURI VARCHAR, DstURIBeforeMap VARCHAR, Durat VARCHAR, TrmSd VARCHAR, TrmReason VARCHAR, TrmReasonCategory VARCHAR, SetupTime VARCHAR, ConnectTime VARCHAR, ReleaseTime VARCHAR, RedirectReason VARCHAR, RedirectUR VARCHAR, INum VARCHAR, RedirectURINumBeforeMap VARCHAR, TxSigIPDiffServ VARCHAR, IPGroup VARCHAR, SrdId VARCHAR, SIPInterfaceId VARCHAR, ProxySetId VARCHAR, IpProfileId VARCHAR, MediaRealmId VARCHAR, DirectMedia VARCHAR, SIPTrmReason VARCHAR, SIPTermDesc VARCHAR, Caller VARCHAR, Callee VARCHAR, Trigger VARCHAR, LegId VARCHAR);";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table Systemevents_".$name."_cdr_formatted already exists...\n"; else echo "Table Systemevents_".$name."_cdr_formatted created.\n";

$query= "CREATE FUNCTION insert_systemevents_$name"."_cdr_reformat() RETURNS trigger AS
$$
DECLARE
    a text[];
BEGIN
IF (SELECT NEW.message LIKE '%CALL_END%') THEN
        a := string_to_array(NEW.message,'|');
        INSERT INTO systemevents_$name"."_cdr_formatted (SBCReportType, EPTyp, SIPCallId, SessionId, Orig, SourceIp, SourcePort, DestIp, DestPort, TransportType, SrcURI, SrcURIBeforeMap, DstURI, DstURIBeforeMap, Durat, TrmSd, TrmReason, TrmReasonCategory, SetupTime, ConnectTime, ReleaseTime, RedirectReason, RedirectUR, INum, RedirectURINumBeforeMap, TxSigIPDiffServ, IPGroup, SrdId, SIPInterfaceId, ProxySetId, IpProfileId, MediaRealmId, DirectMedia, SIPTrmReason, SIPTermDesc, Caller, Callee, Trigger, LegId) VALUES (a[2], a[3], a[4], a[5], a[6], a[7], a[8], a[9], a[10], a[11], a[12], a[13], a[14], a[15], a[16], a[17], a[18], a[19], a[20], a[21], a[22], a[23], a[24], a[0], a[25], a[26], a[27], a[28], a[29], a[30], a[31], a[32], a[33], a[34], a[35], a[36], a[37], a[38], a[1]);
END IF;
RETURN null;
END
$$
  LANGUAGE 'plpgsql';";

		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Function insert_Systemevents_".$name."_cdr_formatted already exists...\n"; else echo "Function insert_Systemevents_".$name."_cdr_formatted created.\n";

		$query = "CREATE TRIGGER systemevents_$name"."_cdr_trigger AFTER INSERT ON systemevents_$name"."_cdr FOR EACH ROW EXECUTE PROCEDURE insert_systemevents_$name"."_cdr_reformat();";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Trigger Systemevents_".$name."_cdr_trigger already exists...\n"; else echo "Trigger Systemevents_".$name."_cdr_trigger created.\n";

		$query="CREATE TABLE SystemEventsProperties_$name ( ID serial not null primary key, SystemEventID int NULL , ParamName varchar(255) NULL , ParamValue text NULL);";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table SystemeventsProperties_$name already exists...\n"; else echo "Table SystemeventsProperties_$name created.\n";

		$result = pg_query($db, "alter table systemevents_".$name." alter column id set default nextval('systemevents_".$name."_id_seq')"); //auto nextval doesn't come along..
		echo "\n";
	}
}
?>
