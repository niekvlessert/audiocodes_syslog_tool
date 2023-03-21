<?
// If you have a syslog file instead of the database you can use this to get the data into the database...
$lines = file("AudioCodes_logs.txt");
$dbhost = '127.0.0.1';
$dbname = 'syslog';
$dbuser = 'syslog';
$dbpass = 'syslog';
$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");
foreach ($lines as $line){
	$query = "insert into sbc1_03_07 (SID, Message, OptionsDetected, FromHost, Priority, DeviceReportedTime) values (regexp_matches('$line', E'SID=(.*?)\\]'), '$line', '$line' ~ 'OPTIONS sip:', 'sbc01', 1, '".date('Y-m-d H:i:s')."')";
	echo $query."\n";
	pg_query($db, $query);
}
?>
