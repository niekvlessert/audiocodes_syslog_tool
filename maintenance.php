#!/usr/bin/php
<?php
#require "config.php";
date_default_timezone_set(@date_default_timezone_get());

parseIniFile();

@$script = $argv[0];
@$action = $argv[1];
@$argument = $argv[2];

if (!$action) stop();

$today = date("_m_d");
$yesterday = date("_m_d",strtotime("-1 days"));
$tomorrow = date("_m_d",strtotime("tomorrow"));

switch ($action) {
case "tableRotate":
	tableRotate();
	break;
case "createDbTomorrow":
	initializeDatabase($tomorrow);
case "deleteOptionsRecords":
	deleteOptionsRecords($today);
	break;
case "initializeDatabase":
	initializeDatabase($today);
	break;
case "generateRsyslogConfig":
	generateRsyslogConfig();
	break;
case "deleteOldData":
	deleteOldData($argument);
	break;
case "install":
	install();
	break;
default:
	stop();
}

function stop(){
	global $script;

	echo "Usage: php $script install|tableRotate|deleteOptionsRecords|initializeDatabase|createDbTomorrow|generateRsyslogConfig|deleteOldData\n\n";
	exit;
}

function install(){
	global $today;

	exec("pwd", $output);
	if ($output[0]!="/opt/ast") {
		echo "Installing...\n";
		exec ("mkdir -p /opt/ast");
		exec ("cp maintenance.php /opt/ast/ast_maintenance");
		exec ("chmod +x /opt/ast/ast_maintenance");
		if (!file_exists ("/opt/ast/settings.ini")) exec ("cp settings.ini /opt/ast/");
		else echo "settings.ini file in /opt/ast already existed... leaving it intact...\n";

		exec ("mkdir -p /var/www/html/ast");
		exec ("cp index.php /var/www/html/ast/");
		echo "Done... now go to /opt/ast and edit settings.ini... when done run ./ast_maintenance install\n";
	} else {
		generateRsyslogConfig();
		initializeDatabase($today);
		generateConfigPhp();
		generateCronEntries();

		echo "\nAudiocodes Syslog Tool is installed now... configure your SBC to log to the IP address of the server and visit the server using Chrome or Firefox. The tool is in /ast/\n";
		echo "In the GUI go to Troubleshoot/Logging/Syslog Settings\n";
		echo "Enable Syslog, Syslog CPU protection, Syslog Optimization, set Debug Level to Detailed and set the Syslog Server IP\n";
	}
}

function generateConfigPhp(){
	global $devices_to_log, $devices_to_log_ip_interfaces, $related_devices;
	global $dbhost, $dbname, $dbuser, $dbpass;

	$filename="config.php";

	file_put_contents($filename, "<?php\n");
	file_put_contents($filename, '$dbhost = ' . var_export($dbhost, true) . ";\n",FILE_APPEND);
	file_put_contents($filename, '$dbname = ' . var_export($dbname, true) . ";\n",FILE_APPEND);
	file_put_contents($filename, '$dbuser = ' . var_export($dbuser, true) . ";\n",FILE_APPEND);
	file_put_contents($filename, '$dbpass = ' . var_export($dbpass, true) . ";\n",FILE_APPEND);
	file_put_contents($filename, '$devices_to_log = ' . var_export($devices_to_log, true) . ";\n",FILE_APPEND);
	file_put_contents($filename, '$devices_to_log_ip_interfaces = ' . var_export($devices_to_log_ip_interfaces, true) . ";\n",FILE_APPEND);
	file_put_contents($filename, '$none_sbc_devices = ' . var_export($related_devices, true) . ";\n",FILE_APPEND);
	file_put_contents($filename, "?>\n",FILE_APPEND);

	exec ("cp config.php /var/www/html/ast/");

	echo "config.php created...\n";
}

/*function tableRotate(){
	global $devices_to_log;
	global $dbhost, $dbname, $dbuser, $dbpass;
	$date = date("Ymd",strtotime("-1 days"));
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $name => $ip){
		$result = pg_query($db, "begin");
		$result = pg_query($db, "create table ".$name."_new (like $name);");
		$result = pg_query($db, "alter table $name rename to ".$name."_".$date);
		$result = pg_query($db, "alter table ".$name."_new rename to ".$name);
		$result = pg_query($db, "commit");
		sleep (2);
		$result = pg_query($db, "alter table ".$name." alter column id set default nextval('".$name."_id_seq')"); //auto nextval doesn't come along..
		echo "$name rotated.\n";
	}
}*/

function deleteOptionsRecords($date){
	global $devices_to_log;
	global $dbhost, $dbname, $dbuser, $dbpass;
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	foreach ($devices_to_log as $name => $ip) {
		$name.=$date;
		$query="delete from $name where SID in (select sid from $name where optionsdetected=true)";
		$result = pg_query($db, $query);
	}

	/*
		$result = pg_query($db, "vacuum $name");
		#$query=pg_escape_string("select substring(main.message from E'\\[[^]]*\\]') as number from $name main, $name secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%OPTIONS sip%'");
		#$result = pg_prepare($db, "my_query", "select substring(main.message from $1) as number from $name main, $name secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%OPTIONS sip%'");
		#$result = pg_execute($db, "my_query", array("E'\\[[^]]*\\]'"));
		#$query = "select secondary.message from $name main, $name secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%OPTIONS sip%'";
		$query = "select message from $name where message like '%OPTIONS sip%';";
		$result = pg_query($db, $query);
		echo "Options records to delete for $name: ".pg_num_rows($result)."\n";
		$a = 0;
		echo "Every dot is 100 OPTIONS SIDs deleted.\n";
		while ($row = pg_fetch_row($result)){
			//var_dump($row[0]);
			$message = preg_match("/\[(.*)\]/",$row[0], $match);
			$query2 = "delete from $name where message like '%$match[1]%'";
			$a++;
			if ($a==100) { 
				echo ".";
				$a = 0;
			}
			//echo $query2."\n";
			$result2 = pg_query($db, $query2);
		}
		echo "\n";
		$result = pg_query($db, "vacuum $name");
	}*/

	pg_close($db);
}

function deleteOldData($days){
	if (!$days) $days = 7;
	$date = date("_m_d",strtotime("-".$days." days"));
        global $devices_to_log, $one_week_ago;
        global $dbhost, $dbname, $dbuser, $dbpass;
        $db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");
        foreach ($devices_to_log as $name => $ip) {
                $query="drop table $name"."$date";
                @$result = pg_query($db, $query);
        }

        pg_close($db);

}

function generateRsyslogConfig(){
        global $devices_to_log;

	$filename = "00_audiocodes.conf";

	if (file_exists($filename)) unlink($filename);

	$data = "\$ModLoad imudp\n";
	$data .= "\$UDPServerRun 514\n";
	$data .= "\$ModLoad ompgsql\n";
	#$data .= "\$SystemLogRateLimitInterval 0\n";
	#$data .= "\$SystemLogRateLimitBurst 0\n";
	$data .= "\$WorkDirectory /var/tmp\n";

	$data .= "\$ActionQueueType LinkedList # use asynchronous processing\n";
	#$data .= "\$ActionQueueFileName dbq    # set file name, also enables disk mode\n";
	$data .= "\$ActionResumeRetryCount -1   # infinite retries on insert failure\n";
	foreach ($devices_to_log as $name => $ip) {
		$data.="\$template $name"."_cdr,\"insert into $name"."_%\$MONTH%_%\$DAY%_cdr (Message, Facility, FromHost, Priority, DeviceReportedTime, ReceivedAt, InfoUnitID, SysLogTag) values ('%msg%', %syslogfacility%, '%HOSTNAME%', %syslogpriority%, '%timereported:::date-pgsql%', '%timegenerated:::date-pgsql%', %iut%, '%syslogtag%')\",STDSQL\n";
		#$data.="\$template $name"."_errors,\"insert into $name"."_%\$MONTH%_%\$DAY%_errors (SID, Message, OptionsDetected, Facility, FromHost, Priority, DeviceReportedTime, ReceivedAt, InfoUnitID, SysLogTag) values (regexp_matches('%msg%', E'SID=(.*?)\\\]'), '%msg%', '%msg%' ~ 'OPTIONS sip:', %syslogfacility%, '%HOSTNAME%', %syslogpriority%, '%timereported:::date-pgsql%', '%timegenerated:::date-pgsql%', %iut%, '%syslogtag%')\",STDSQL\n";
		$data.="\$template $name,\"insert into $name"."_%\$MONTH%_%\$DAY% (SID, Message, OptionsDetected, Facility, FromHost, Priority, DeviceReportedTime, ReceivedAt, InfoUnitID, SysLogTag) values (regexp_matches('%msg%', E'SID=(.*?)\\\]'), '%msg%', '%msg%' ~ 'OPTIONS sip:', %syslogfacility%, '%HOSTNAME%', %syslogpriority%, '%timereported:::date-pgsql%', '%timegenerated:::date-pgsql%', %iut%, '%syslogtag%')\",STDSQL\n";
		#$data.="if \$fromhost-ip startswith '$ip' and \$syslogseverity-text != '5' then :ompgsql:127.0.0.1,syslog,syslog,syslog;$name"."_errors\n";
		$data.="if \$fromhost-ip startswith '$ip' and \$syslogfacility-text == 'local1' then :ompgsql:127.0.0.1,syslog,syslog,syslog;$name"."_cdr\n";
		$data.="& ~\n";
		$data.="if \$fromhost-ip startswith '$ip' then :ompgsql:127.0.0.1,syslog,syslog,syslog;$name\n";
		$data.="& ~\n";
	}
	file_put_contents($filename, $data);

	exec("cp $filename /etc/rsyslog.d/");

	echo "Config written, restarting Rsyslog..\n";

	exec ("/etc/init.d/rsyslog restart 2> /dev/null ||systemctl restart rsyslog");
}

function generateCronEntries(){
	$filename = "cron_ast";

	$data="0 23 * * * root /opt/ast/ast_maintenance createDbTomorrow\n";
	#$data.="0 1 * * * root /opt/ast/ast_maintenance deleteOptionsRecords\n";
	$data.="30 23 * * * root /opt/ast/ast_maintenance deleteOldData\n";
	file_put_contents($filename, $data);

	exec ("cp $filename /etc/cron.d");

	echo "\ncron configuration created...\n";
}

function initializeDatabase($date){
	global $devices_to_log;
	global $today;
	global $dbhost, $dbname, $dbuser, $dbpass;

	if ($date == $today) {
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

		exec("createlang -U postgres -d syslog plpgsql");
	}
	
	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");

	echo "\n";

	foreach ($devices_to_log as $name => $device) {
		$name.=$date;

		$query="CREATE TABLE $name ( ID serial not null primary key, CustomerID bigint, ReceivedAt timestamp without time zone NULL, DeviceReportedTime timestamp without time zone NULL, Facility smallint NULL, Priority smallint NULL, FromHost varchar(60) NULL, SID varchar(30), Message text, OptionsDetected boolean, NTSeverity int NULL, Importance int NULL, EventSource varchar(60), EventUser varchar(60) NULL, EventCategory int NULL, EventID int NULL, EventBinaryData text NULL, MaxAvailable int NULL, CurrUsage int NULL, MinUsage int NULL, MaxUsage int NULL, InfoUnitID int NULL , SysLogTag varchar(60), EventLogType varchar(60), GenericFileName VarChar(60), SystemID int NULL );";
		//echo $query."\n";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table $name already exists...\n"; else echo "Table $name created.\n";

		$query="CREATE TABLE $name"."_errors ( ID serial not null primary key, CustomerID bigint, ReceivedAt timestamp without time zone NULL, DeviceReportedTime timestamp without time zone NULL, Facility smallint NULL, Priority smallint NULL, FromHost varchar(60) NULL, SID varchar(30), Message text, OptionsDetected boolean, NTSeverity int NULL, Importance int NULL, EventSource varchar(60), EventUser varchar(60) NULL, EventCategory int NULL, EventID int NULL, EventBinaryData text NULL, MaxAvailable int NULL, CurrUsage int NULL, MinUsage int NULL, MaxUsage int NULL, InfoUnitID int NULL , SysLogTag varchar(60), EventLogType varchar(60), GenericFileName VarChar(60), SystemID int NULL );";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table $name"."_errors already exists...\n"; else echo "Table $name"."_errors created.\n";

		$query="CREATE INDEX $name"."_sid_index ON $name ( sid);";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Index on SID in $name already exists...\n"; else echo "Index on SID on $name created.\n";

		$query="CREATE TABLE ".$name."_cdr ( ID serial not null primary key, CustomerID bigint, ReceivedAt timestamp without time zone NULL, DeviceReportedTime timestamp without time zone NULL, Facility smallint NULL, Priority smallint NULL, FromHost varchar(60) NULL, Message text, NTSeverity int NULL, Importance int NULL, EventSource varchar(60), EventUser varchar(60) NULL, EventCategory int NULL, EventID int NULL, EventBinaryData text NULL, MaxAvailable int NULL, CurrUsage int NULL, MinUsage int NULL, MaxUsage int NULL, InfoUnitID int NULL , SysLogTag varchar(60), EventLogType varchar(60), GenericFileName VarChar(60), SystemID int NULL );";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table ".$name."_cdr already exists...\n"; else echo "Table ".$name."_cdr created.\n";
		$result = pg_query($db, "alter table ".$name."_cdr alter column id set default nextval('".$name."_cdr_id_seq')"); //auto nextval doesn't come along..

		$query= "CREATE TABLE $name"."_cdr_formatted( id SERIAL PRIMARY KEY, SBCReportType VARCHAR, EPTyp VARCHAR, SIPCallId VARCHAR, SessionId VARCHAR, Orig VARCHAR, SourceIp VARCHAR, SourcePort VARCHAR, DestIp VARCHAR, DestPort VARCHAR, TransportType VARCHAR, SrcURI VARCHAR, SrcURIBeforeMap VARCHAR, DstURI VARCHAR, DstURIBeforeMap VARCHAR, Durat VARCHAR, TrmSd VARCHAR, TrmReason VARCHAR, TrmReasonCategory VARCHAR, SetupTime VARCHAR, ConnectTime VARCHAR, ReleaseTime VARCHAR, RedirectReason VARCHAR, RedirectUR VARCHAR, INum VARCHAR, RedirectURINumBeforeMap VARCHAR, TxSigIPDiffServ VARCHAR, IPGroup VARCHAR, SrdId VARCHAR, SIPInterfaceId VARCHAR, ProxySetId VARCHAR, IpProfileId VARCHAR, MediaRealmId VARCHAR, DirectMedia VARCHAR, SIPTrmReason VARCHAR, SIPTermDesc VARCHAR, Caller VARCHAR, Callee VARCHAR, Trigger VARCHAR, LegId VARCHAR);";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Table ".$name."_cdr_formatted already exists...\n"; else echo "Table ".$name."_cdr_formatted created.\n";

		$query="CREATE INDEX $name"."_cdr_formatted_to_index ON $name ( sid);";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Index on SID in $name already exists...\n"; else echo "Index on SID on $name created.\n";

$query= "CREATE OR REPLACE FUNCTION insert_$name"."_cdr_reformat() RETURNS trigger AS
$$
DECLARE
    a text[];
BEGIN
IF (SELECT NEW.message LIKE '%CALL_END%') THEN
        a := string_to_array(NEW.message,'|');
        INSERT INTO $name"."_cdr_formatted (SBCReportType, EPTyp, SIPCallId, SessionId, Orig, SourceIp, SourcePort, DestIp, DestPort, TransportType, SrcURI, SrcURIBeforeMap, DstURI, DstURIBeforeMap, Durat, TrmSd, TrmReason, TrmReasonCategory, SetupTime, ConnectTime, ReleaseTime, RedirectReason, RedirectUR, INum, RedirectURINumBeforeMap, TxSigIPDiffServ, IPGroup, SrdId, SIPInterfaceId, ProxySetId, IpProfileId, MediaRealmId, DirectMedia, SIPTrmReason, SIPTermDesc, Caller, Callee, Trigger, LegId) VALUES (a[2], a[3], a[4], a[5], a[6], a[7], a[8], a[9], a[10], a[11], a[12], a[13], a[14], a[15], a[16], a[17], a[18], a[19], a[20], a[21], a[22], a[23], a[24], a[0], a[25], a[26], a[27], a[28], a[29], a[30], a[31], a[32], a[33], a[34], a[35], a[36], a[37], a[38], a[1]);
END IF;
RETURN null;
END
$$
  LANGUAGE 'plpgsql';";

		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Function insert_".$name."_cdr_formatted already exists...\n"; else echo "Function insert_".$name."_cdr_formatted created or replaced.\n";

		$query = "DROP TRIGGER $name"."_cdr_trigger ON $name"."_cdr";
		$result = @pg_query($query);
		$query = "CREATE TRIGGER $name"."_cdr_trigger AFTER INSERT ON $name"."_cdr FOR EACH ROW EXECUTE PROCEDURE insert_$name"."_cdr_reformat();";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Trigger ".$name."_cdr_trigger already exists...\n"; else echo "Trigger ".$name."_cdr_trigger created.\n";

$query="CREATE OR REPLACE FUNCTION $name"."_copy_error() RETURNS trigger AS
$$
BEGIN
IF NEW.priority<5 THEN
INSERT INTO $name"."_errors(id, customerid, receivedat, devicereportedtime, facility, priority, fromhost, message, ntseverity, importance, eventsource, eventuser, eventcategory, eventid, eventbinarydata, maxavailable, currusage, minusage, maxusage, infounitid, syslogtag, eventlogtype, genericfilename, systemid) VALUES(NEW.id, NEW.customerid, NEW.receivedat, NEW.devicereportedtime, NEW.facility, NEW.priority, NEW.fromhost, NEW.message, NEW.ntseverity, NEW.importance, NEW.eventsource, NEW.eventuser, NEW.eventcategory, NEW.eventid, NEW.eventbinarydata, NEW.maxavailable, NEW.currusage, NEW.minusage, NEW.maxusage, NEW.infounitid, NEW.syslogtag, NEW.eventlogtype, NEW.genericfilename, NEW.systemid);
END IF;
RETURN NEW;
END;
$$
LANGUAGE plpgsql VOLATILE
COST 100;
";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Function insert_".$name."_errors already exists...\n"; else echo "Function insert_".$name."_errors created or replaced.\n";

		$query = "DROP TRIGGER $name"."_error_written ON $name";
		$result = @pg_query($query);
		$query = "CREATE TRIGGER $name"."_error_written AFTER INSERT ON $name FOR EACH ROW EXECUTE PROCEDURE $name"."_copy_error();";
		$result = @pg_query($query);
		if (strpos(pg_last_error($db), "already exists")>0) echo "Trigger ".$name."_error_written already exists...\n"; else echo "Trigger ".$name."_error_written created.\n";

		#$query="CREATE TABLE SystemEventsProperties_$name ( ID serial not null primary key, SystemEventID int NULL , ParamName varchar(255) NULL , ParamValue text NULL);";
		#$result = @pg_query($query);
		#if (strpos(pg_last_error($db), "already exists")>0) echo "Table SystemeventsProperties_$name already exists...\n"; else echo "Table SystemeventsProperties_$name created.\n";

		#$result = pg_query($db, "alter table ".$name." alter column id set default nextval('".$name."_id_seq')"); //auto nextval doesn't come along..
		echo "\n";
	}
}

function parseIniFile(){
	$ini_array = parse_ini_file("settings.ini", true);

	$GLOBALS["dbname"] = $ini_array["database"]["dbname"];
	$GLOBALS["dbhost"] = $ini_array["database"]["dbhost"];
	$GLOBALS["dbuser"] = $ini_array["database"]["dbuser"];
	$GLOBALS["dbpass"] = $ini_array["database"]["dbpass"];

	unset($ini_array["database"]);

	$GLOBALS["related_devices|"] = array();

	foreach($ini_array["related_devices"] as $device_name => $ip_address) {
		$GLOBALS["related_devices"]["$device_name"] = "$ip_address";
	}

	unset($ini_array["related_devices"]);

	$GLOBALS["devices_to_log"] = array();
	$GLOBALS["devices_to_log_ip_interfaces"] = array();

	foreach($ini_array as $sbc_device_name => $sbc_device){
		$GLOBALS["devices_to_log"]["$sbc_device_name"] = $sbc_device["syslog_src_ip_address"];
		unset($sbc_device["syslog_src_ip_address"]);
		foreach($sbc_device as $ip_interface_name => $ip_interface_ip){
			$GLOBALS["devices_to_log_ip_interfaces"]["$sbc_device_name"]["$ip_interface_name"] = "$ip_interface_ip";
		}
	}
	#var_dump($GLOBALS["devices_to_log"]);
	#var_dump($GLOBALS["devices_to_log_ip_interfaces"]);
}
?>
