<!DOCTYPE html>
<html>
<style>
.sip_message { 
  pointer-events: none;
  cursor: default;
  text-decoration: none;
  color: green;
}
a { 
  cursor: default;
  text-decoration: none;
  color: green;
}
html, body { width:90%; height:100%; } 

hr {
  width:70%;
  margin-left:0;  
  text-align:left;
}
canvas {
    padding: 0;
    /*margin: auto;
    display: block;*/
}
.sip_canvas {
height:1500px;
}

#call_info1 {
    display: inline-block;
}

#call_info2 {
    display: inline-block;
    vertical-align: top;
}

.a { display: inline-block; width:100px; }

</style>
<body>
<?
date_default_timezone_set(@date_default_timezone_get());

require "config.php";

ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);

@$number = $_GET['number'];
@$sid = $_GET['SID'];
@$device = $_GET['device'];
@$history = $_GET['history'];

?>

<center><h1>Audiocodes Syslog Tool</h2></center>

<?
echo"$history"; 
?>

<div id="number_form" class="number_form">
<div id="call_info1">
<form id='form' method=get>
<table>
<tr><td>(Part of) involved phone number:</td><td><input id='number' name='number' type=text id='number'></td></tr>
<tr><td>Device:</td><td><select name=device>
<?
foreach ($devices_to_log as $name => $ip) {
	echo "<option value=\"$name\"";
	if (@$device == $name) echo " selected";
	echo ">$name</option>";
}
?>
</select></td></tr>

<tr><td>Latest call only:</td><td><input id='last_one_only' name='last_one_only' type=checkbox></td></tr>
<tr><td colspan=2>Click <a href=index.php?history=1>here</a> to search data from previous days if available...</td></tr>
</table>
<input type="submit" style="position: absolute; left: -9999px"/>
</form> 
</div>
<div id="call_info2">
</div>
</div>
</table>

<div id="options">
</div>

<div id="basic_call_info">
</div>

<div id="sip_canvas">
</div>

<div id="callog">
</div>

<script>
//<pre>
var callog = [];
var sip_dialog_information = [];
var sip_dialog_position = 0;
var sip_stack_message_found = false;
var sip_message_found = false;
var sid;
var latest_call_only = false;
var sid_available = false;
var ctx;
var vertical_lines = [];
var horizontal_lines = [];
var vertical_line_length;
var vertical_position = 40;
var horizontal_position = 100;
var distance_between_vertical_lines = 220;
var sip_dialog_displayed = false;
var element_id = 1;
var hidden_message_types = [];

var number = false;

var device_name;
var device_ip;
var device_data_bundle = [];
var none_sbc_device_data_bundle = [];
var shown_id = 0;


var keys = [];
window.addEventListener("keydown",
	function(e){
		keys[e.keyCode] = true;
		checkCombinations(e);
	},
	false);

window.addEventListener('keyup',
	function(e){
		keys[e.keyCode] = false;
	},
	false);

function checkCombinations(e){
	if (e.key === "s" || e.key === "S") {
		/*if (sip_dialog_displayed) {
			console.log("hide dialog");
			sip_dialog_displayed = false;
		} else {
			console.log("show dialog");
			sip_dialog_displayed = true;
		}*/
		window.scrollTo(0, 0);
		shown_id = 0;
	}
	if ((e.key === "n" || e.key === "N") && shown_id < horizontal_lines.length) jumpTo(++shown_id);
	if ((e.key === "p" || e.key === "P") && shown_id>1) jumpTo(--shown_id);
	if ((e.key === "p" || e.key === "P") && shown_id<=1) window.scrollTo(0, 0);;
}

<?

// If there's a number in the input field or a SID available, do this
if (strlen($number)>0 || $sid) {
	echo "number = \"$number\";\n";
	$latest_call_only = false;
	if (@$_GET['last_one_only'] == "on") {
		$latest_call_only = true;
		echo "latest_call_only = true\n";
	}
	if ($sid) {
		echo "sid_available = true\n";
	}

	$db = pg_connect("host=$dbhost port=5432 dbname=$dbname user=$dbuser password=$dbpass") or die("error");
	$query = NULL;

	$date = date("_m_d");
	// $date = date("_03_07"); // force date if needed for testing

	// find SID length
	$result = pg_fetch_row(pg_query($db, "select message from $device"."$date where message like '%[SID=%' limit 1"));
	preg_match_all('/\[SID=(.*?)\]/i', $result[0], $sid_data);
	$sid_length = strlen($sid_data[1][0]);
	//echo "console.log('found sid length: $sid_length');\n";

	if ($latest_call_only) $query = "select main.message, main.devicereportedtime, main.fromhost, main.id, main.priority FROM $device"."$date main WHERE main.sid = (select '{' || trim(secondary.sessionid::text) || '}' from $device"."$date"."_cdr_formatted secondary where secondary.dsturibeforemap like '%$number%' or secondary.srcuri like '%$number%' or secondary.dsturi like '%$number%' order by secondary.sessionid desc limit 1) order by main.id;";
	if ($sid) $query = "select main.message, main.devicereportedtime, main.fromhost, main.id, main.priority FROM $device"."$date main WHERE sid like '%$sid%' order by main.id;";
	// If no SID or latest_call_only use the number. So priority is on SID and latest_call_only
	if (!$query) $query = "select distinct on (sessionid) dsturibeforemap, sessionid, setuptime, id from $device"."$date"."_cdr_formatted where dsturibeforemap like '%$number%' or srcuri like '%$number%' or dsturi like '%$number%' order by sessionid desc limit 20";

/*
	#if ($sid) $query = "select main.message, main.devicereportedtime, main.fromhost, main.id FROM $device main WHERE substring(main.message from 13 for $sid_length) = (select substring(secondary.message from 13 for $sid_length) from $device secondary where secondary.message LIKE '%$sid%' and secondary.message LIKE '%INVITE sip:%' order by secondary.id desc limit 1) order by main.id;";
	//echo "console.log('$query');\n";
	#if (!$query) $query = "select distinct on (substring(message from 3 for $sid_length)) substring(message from 3 for $sid_length) as sid, substring(message, 'sip:([^@]+)@') as number, devicereportedtime as time from $device where message like '%INVITE sip:%$number%' order by substring(message from 3 for $sid_length), id;";
	#if (!$query) $query = "select distinct on (substring(message from 3 for $sid_length)) substring(message from 3 for $sid_length) as sid, substring(message, 'sip:([^@]+)@') as number, devicereportedtime as time from $device where message ~ E'INVITE sip:\\d{0,10}".$number."\\d{0,10}' order by substring(message from 3 for $sid_length), id;";
	#if (!$query) $query = "select sessionid, dsturibeforemap, setuptime from $device"."_cdr_formatted where dsturi like '%$number%'";
	
	#if (!$query) $query = "select distinct on (substring(message from 3 for 20)) substring(message from 3 for 20) as sid, substring(message, 'sip:([^@]+)@') as number, substring(message, '\[Time:(.*)\]') as time from $device where message like '%INVITE sip:$number%' order by substring(message from 3 for 20), id;";
	//$query = "select distinct(main.message), main.devicereportedtime, main.fromhost, main.id FROM systemevents main, systemevents secondary WHERE substring(main.message from 3 for 20) = substring(secondary.message from 3 for 20) AND secondary.message LIKE '%INVITE sip:$number%' order by id asc;";
	//echo "console.log('".addslashes($query)."');\n";
*/

	echo "console.log(\"$query\");\n";
        $result = pg_query($db, $query);
	 echo "console.log(\"".pg_last_error($db)."\");\n";

        $sid_logged = false;
	$dont_log = false;
	$previous_message_type = "";
	$a=0;

	if ($latest_call_only || $sid) {
		$sbc_sip_interface_ip = "";

		if ($devices_to_log[$device]) {
			$device_ip = $devices_to_log[$device];
			echo "device_name = '$device';\n";
			echo "device_ip = '$device_ip';\n";
		}
		foreach ($devices_to_log as $device_name => $device_ip) {
			echo "var device_data = {};\n";
			echo "device_data.ip = '$device_ip';\n";
			echo "device_data.name = '$device_name';\n";
			echo "device_data_bundle.push(device_data);\n";
		}
		foreach ($none_sbc_devices as $device_name => $device_ip) {
			echo "var device_data = {};\n";
			echo "device_data.ip = '$device_ip';\n";
			echo "device_data.name = '$device_name';\n";
			echo "none_sbc_device_data_bundle.push(device_data);\n";
		}

		//echo "console.log('".pg_num_rows($result)."');\n";
		while($row = pg_fetch_assoc($result)) {
			$messages_splitted = array();
			if (!$sid_logged) {
				preg_match('/=(.*?)]/',$row['message'], $sid);
				echo "sid =\"".$sid[1]."\";\n";
				$sid_logged = true;
			}
			$message = substr($row['message'], strpos($row['message'], "]")+1);
			//echo "-----------------------------------------------<br/>";
			//print_r($message);
			//$message = substr($message, strpos($message, "]")+4); //this is sometimes need and the +2 might differ depending on (I think) firmware version
			$message = str_replace("#012(", "#012((", $message);
			$messages = explode("#012(", $message);
			foreach ($messages as $key => $message) {
				$pos = strpos($message, "---- #012");
				if($pos != 0) {
					array_push($messages_splitted, substr($message,0,$pos));
					array_push($messages_splitted, substr($message,$pos+9));
					//echo "----------------------------------------------------------------------------------------------\n";
				} else {
					//echo"console.log('$message');\n";
					array_push($messages_splitted, $message);
				}
			}

			//print_r($messages_splitted);
			foreach ($messages_splitted as $message_splitted) {
				// Strip SID etc. until actual message
				if (preg_match('/\((.*?)\)/',substr($message_splitted,0,100), $message_type)) {
					$message = substr($message_splitted, strpos($message_splitted, ")")+1);
					preg_match('/[a-zA-Z]+/',$message,$temp_message);
					$message_type[1] = $temp_message[0];
				} else {
					// Nothing to strip, must be something else then usual (SIP data, --- Incoming SIP message, etc.), so better don't use any type: -null-
					$message_type[1]="-null-";
					$message = $message_splitted;
					$message = substr($message, strpos($message,"]")+1);
					// Or some other types, just other
					if (strpos($message, 'RTP packets reorder') != 0) $message_type[1]="other";
					if (strlen($message)<100) $message_type[1]="other";
				}

				// Sometimes more counters in there, strip them as well
				if (preg_match('/\((.*?)\)/',substr($message,0,20), $message_id)) {
					$message = substr($message, strpos($message, ")")+1);
				} else {
					// why this?? I forgot... research later...
					$message_id[1]="";
				}
				if ($previous_message_type == "-null-" && $message_type[1] == "-null-") {
					// If this happens it must be SIP information spread over multiple lines, merge this with the previous
					echo "var siddata = callog.pop();\n";
					echo "siddata.message += \"".trim(addslashes($message))."\";\n";
				} else {
					// Otherwise the data is complete now, push the data
					echo "var siddata = {};\n";
					echo "siddata.priority = \"".$row['priority']."\";\n";
					echo "siddata.devicereportedtime = \"".$row['devicereportedtime']."\";\n";
					echo "siddata.fromhost = \"".$row['fromhost']."\";\n";
					echo "siddata.messagetype = \"".trim($message_type[1])."\";\n";
					echo "siddata.messageid = \"".trim($message_id[1])."\";\n";
					//echo "siddata.message = \"".trim(addslashes($row['message']))."\";\n";
					echo "siddata.message = \"".trim(addslashes($message))."\";\n";
					// See if the syslog goes to the correct SBC, if so fill the variable
					if ($sbc_sip_interface_ip == "") {
						if (strpos($message,"Incoming SIP Message from")!=0) {
							preg_match('/(\()(.*?)(\))/',$message, $resultt);
							foreach ($devices_to_log_ip_interfaces[$device] as $interface_name => $ip) {
								//Previously checked IP address, but hostname can be in here as well, so just skip, not sure if that's perfect
								//if ($resultt[2] == $interface_name) { 
									$sbc_sip_interface_ip = $ip;
									echo "sbc_ip_used = '$ip';\n";
								//}
							}
						}
					}
				}
				echo "callog.push(siddata);\n";

			}
		}
	} else {
		$result_amount = pg_num_rows($result);
		if (pg_num_rows($result)>0) {
			echo "</script>";
			echo "Calls found, please select:<br/><br/>";
			echo "<form>";
			while ($row = pg_fetch_assoc($result)) {
				//var_dump($row);
				preg_match('/(.*?)@/', $row['dsturibeforemap'], $search_result);
				echo "<span class=\"a\"><a target=\"_blank\" href='index.php?SID=".trim($row['sessionid'])."&device=".$device."'>".trim($search_result[0],"@")."</span> at ".$row['setuptime']."</a><br/>";
				//echo "console.log(\""+$row['number']+"\");";
			}
			echo "</form>";
			echo "<script>";
		}
	}
}
?>

</script>

<hr/>
<script>
if (latest_call_only) document.getElementById("last_one_only").checked = true;
if (number) document.getElementById("number").value = number;

function addRow(i) {
        var div = document.createElement('div');
	var message = callog[i].message;
	//console.log(callog[i].message.replace(/#012/g,"\n"));
	//console.log("----------------------------------------------------------------");
	var display_this_item = false;

        switch (callog[i].messagetype) {
	case "-null-":
                //var message_array = i.message.split(" ");
                div.style.color = "green";
		//console.log(message);
                //div.innerHTML+="<br/><br/>"+message_array[0]+"<br/><br/>";
                message = message.replace(/#012/g,"<br/>");
                message = message.replace(/\[(.*?)\]/g,"");
		div.innerHTML += "<a class='sip_message' href='#' id='"+element_id+"'>" + message + "</a>";
		element_id++;

		//ugly fixes for ugly syslogging... not in use now
		/*if ((callog[i+2].message.indexOf("#012") !== -1) && (callog[i+1].message.indexOf("#012") == -1)) {
				var tmp = callog[i+1];
				callog[i+1] = callog[i+2];
				callog[i+2] = tmp;
		}
		if ((callog[i+3].message.indexOf("#012") !== -1) && (callog[i+2].message.indexOf("#012") == -1) && (callog[i+1].message.indexOf("#012") == -1)) {
				var tmp = callog[i+1];
				callog[i+1] = callog[i+2];
				callog[i+2] = callog[i+3];
				callog[i+3] = tmp;
		}*/

		//if (callog[i+1].message.indexOf("#012") == -1) div.innerHTML += "<hr/>";

		display_this_item = true;
                break;
        case "SIPLadder":
                var message_array = callog[i].message.split(" ");
                if (message_array[0] === "----") {
                        div.style.color = "green";
                        message = message.replace("----","");
                        message = message.replace("----","<br/>");
			div.innerHTML += "<hr><b>"+message+"</b><br/><br/>";

			display_this_item = true;
                } else{
			div.innerHTML = "<b>"+callog[i].messagetype+"</b> - ";
                        div.innerHTML += message;
                }
                break;
        default:
		if (callog[i].priority==="4") div.style.color = "red";
                if (callog[i].messagetype !== "other") div.innerHTML = "<b>"+callog[i].messagetype+"</b> - ";
                message = message.replace('[ManSet','ManSet');
                message = message.replace(/\[(.*?)\]/g,"");
		div.innerHTML += message;

		if ((callog[i].message.indexOf("CallAdmission") !== -1) && (callog[i].message.indexOf("Direction") !== -1)) {
			display_this_item = true;
		} else { 
			if ((callog[i].message.indexOf("SBCRoutesIterator") !== -1) && (callog[i].message.indexOf("Next") !== -1)) {
				display_this_item = true; 
			} else {
				if (callog[i].message.indexOf("ManSet") !== -1) display_this_item = true;
			}
		}
                break;
	}

	div.className = callog[i].messagetype;

	if (document.getElementById(callog[i].messagetype).checked) document.getElementById("callog").appendChild(div);
}

var message_types = [];

async function addToMessageTypes(item) {
        if (message_types.indexOf(item.messagetype) === -1) {
                message_types.push(item.messagetype);
        }
}

async function updateScreen(classToHide) {
	if (!classToHide) {
		// default buildup
		var callog_div = document.getElementById("callog");
		for (var i = 0; i < callog.length; i++) {
			addRow(i);
		}
	} else {
		if (classToHide !== "disableAll" && classToHide !== "enableAll" ) {
			// hiding divs..
			var elements = document.getElementsByClassName(classToHide);
			console.log(hidden_message_types);
			if (hidden_message_types.indexOf(classToHide) == -1) {
				displayStyle = 'none';
				hidden_message_types.push(classToHide);
			} else {
				displayStyle = 'block';
				hidden_message_types.remove(classToHide);
			}
			for (var i = 0; i < elements.length; i++){
				elements[i].style.display = displayStyle;
			}
		} else {
			if (classToHide === "disableAll") displayStyle = 'none';
			else displayStyle = 'block';
			console.log(message_types);
			for (var item of message_types) {
				var elements = document.getElementsByClassName(item);
				for (var i = 0; i < elements.length; i++){
					elements[i].style.display = displayStyle;
				}
			}
		}
	}
}

Array.prototype.remove = function() {
	var what, a = arguments, L = a.length, ax;
	while (L && this.length) {
		what = a[--L];
		while ((ax = this.indexOf(what)) !== -1) {
			this.splice(ax, 1);
		}
	}
	return this;
};

function setAllMessageTypes(value) {
        for (var item of message_types) {
                document.getElementById(item).checked = value;
        }
	if (value === false) updateScreen("disableAll");
	else updateScreen("enableAll");
}

async function drawSip() {
	// fetch all SIP data
	var sip_message = {};
	sip_message.direction = "";
	callog.forEach(function(item) {
		// if "-null-" it's SIP most of the time
		if (item.messagetype === "-null-") {
			if (sip_message_found) {
				sip_message.message += item.message;
				//console.log("sip message related to previous one... content added."+sip_message.message);
			} else {
				var split_message = item.message.split("#012");
				var split_action = split_message[0].split(" ");
				//console.log("split!!");
				//console.log(split_action);
				//split_action.shift();
				//split_action.shift();
				//console.log(split_action);
				switch (split_action[0]) { 
				case "INVITE":
					//console.log(split_action);
					if (!sip_message.dst) {
						if (split_action[1].lastIndexOf(";") !== -1) {
							sip_message.dst = split_action[1].substring(split_action[1].lastIndexOf("@")+1, split_action[1].lastIndexOf(";"));
						} else {
							if (split_action[1].lastIndexOf("@") !== -1) sip_message.dst = split_action[1].substring(split_action[1].lastIndexOf("@")+1);
							else sip_message.dst_number = ""; //fix
						}
					}
					if (split_action[1].lastIndexOf("@") !== -1) sip_message.dst_number = split_action[1].substring(split_action[1].indexOf(":")+1, split_action[1].indexOf("@"));
					else sip_message.dst_number = "";
					if (sip_message.dst.indexOf(":") !== -1) sip_message.dst = sip_message.dst.slice(0,sip_message.dst.indexOf(":"));
					//console.log("INVITE detected to " + sip_message.dst + " to number " + sip_message.dst_number);
					//console.log(split_message);
					for (var i = 0; i < split_message.length; i++) {
						if (split_message[i].indexOf('User-Agent')>-1) sip_message.user_agent = split_message[i].substring(split_message[i].indexOf(":")).slice(2,-1);
						if (split_message[i].indexOf('Via')>-1) sip_message.original_src = split_message[i].match(/ ([^ ]+);/)[0].slice(1, -1);
						//if (split_message[i].indexOf('From')>-1) sip_message.from_name = split_message[i].match(/"(.*)"/)[0].slice(1, -1); // gaat soms fout...........
						if (split_message[i].indexOf('Proxy-Authorization')>-1) sip_message.from_number = split_message[i].match(/"(.*)\//)[0].slice(1, -1);
						//if (split_message[i].indexOf('Time')>-1) sip_message.time = split_message[i].match(/ \[Time(.*)/g)[0].slice(7,-1);
					}
					//console.log(sip_message.from_number);
					//split_message.forEach(function(a){if (a.indexOf('User-Agent')>-1) console.log(a.substring(a.indexOf(":")+2));});// gewone for loop van maken...
					//	{ sip_message.user-agent = a.substring(a.indexOf(":")); }})
					//
					//	de from ook pakken
					
					sip_message.type = split_action[0];
					break;
				case "SIP/2.0":
					sip_message.response_code = split_action[1];
					sip_message.type = sip_message.response_code + " " + split_action[2];
					if (sip_message.response_code === "183" || sip_message.response_code === "404" || sip_message.response_code === "487" || sip_message.response_code === "302") sip_message.type += " " + split_action[3];
					if (sip_message.response_code === "500") sip_message.type += " " + split_action[3] + " " + split_action[4];
					if (sip_message.response_code === "481") sip_message.type += " " + split_action[3] + " " + split_action[4] + " " + split_action[5];
					break;
				case "ACK":
				case "PRACK":
				case "BYE":
				case "CANCEL":
				case "INFO":
					sip_message.type = split_action[0];
					break;
				}
				sip_message.message = item.message;
				sip_message.time = item.devicereportedtime.slice(item.devicereportedtime.indexOf(" "));
				//console.log("type: " + sip_message.type);
				//console.log(sip_message);
				//sip_message_found = true;
			}
		}
		//if (item.messagetype === "SIPLadder") {
			if (item.message.split(" ")[0] === "----") {
				if (sip_message.direction !== "") {
					//console.log("------------------------------------------------------");
					sip_dialog_information.push(sip_message);
					//console.log(sip_message);
					sip_message = {};
					split_action = [];
					split_message = [];
				}
				var split_message = item.message.split(" ");
				sip_message.direction = split_message[1];
				if (sip_message.direction === "Outgoing") sip_message.dst = split_message[5].slice(0,split_message[5].lastIndexOf(":"));
				else sip_message.src = split_message[5].slice(0,split_message[5].lastIndexOf(":"));
				//console.log("dst found: " + sip_message.dst);
				//console.log("sip stack msg... direction: " +sip_message.direction);
		 		//if (sip_message.src) console.log("src: " + sip_message.src); else console.log("dst: "+ sip_message.dst);
				sip_message_found = false;
			}
		//}
	});

	// nasty fix to avoid last item not being added to the array of dialog messages...
	sip_dialog_information.push(sip_message);

	// draw the stuff
	//
	// init
	var canvas = document.createElement('canvas');
	canvas.id     = "CursorLayer";
	canvas.width  = window.innerWidth;
	canvas.height = 1500;
	canvas.style.zIndex   = 8;
	//canvas.style.position = "center";
	//canvas.style.border   = "1px solid";

	ctx=canvas.getContext("2d");
	ctx.textAlign="center"; 
	ctx.font = "13px Arial";

	// calling device/service information

	//ctx.fillText(sip_dialog_information[0].from_number + " - " + sip_dialog_information[0].from_name,100,vertical_position-10);
	//ctx.fillText(sip_dialog_information[0].user_agent,100,vertical_position+3);
	//ctx.fillText("Time: " + sip_dialog_information[0].time,100,vertical_position+26);
	//ctx.fillText("SID: " + sid,100,vertical_position+39);
	
	//ctx.fillText(sip_dialog_information[0].user_agent,100,vertical_position+3);
	/*var img = new Image;
	img.onload = function(){
		  ctx.drawImage(img,-10,46); // Or at whatever offset you like
	};
	img.src = "http://static.themoneyedge.com.au/uploads/2015/10/telephone-300x202.jpg";
	 */

	// vertical lines
	//
	vertical_line_length = (sip_dialog_information.length*30)+20;
	for (var i = 0; i < sip_dialog_information.length; i++) {
		// initial SIP message, take info from both legs..
		if (i === 0) { 
			var vertical_line = {};

			vertical_line.ip_address = sip_dialog_information[i].src;
			//console.log("Frommmmm: "+ sip_dialog_information[i].src);
			var ip_address;
			if (sip_dialog_information[i].src) ip_address = sip_dialog_information[i].src;
			else ip_address = "";
			var device = device_data_bundle.filter(function(device){return device.ip === ip_address;});
			if (device[0]) vertical_line.device_name = device[0].name.toUpperCase();
			else {
				//ip_address = ip_address.split(":")[0];
				var device = none_sbc_device_data_bundle.filter(function(device){return device.ip === ip_address;});
				if (device[0]) vertical_line.device_name = device[0].name.toUpperCase();
			}

			vertical_line.horizontal_position = horizontal_position;
			horizontal_position+=distance_between_vertical_lines;

			await drawVerticalLine(vertical_line);

			vertical_lines.push(vertical_line);

			var vertical_line2 = {};
			
			/*if (!device_ip ) vertical_line2.ip_address = sip_dialog_information[i].dst;
			else { 
				vertical_line2.ip_address = device_ip;
				vertical_line2.device_name = device_name.toUpperCase();
			}*/

			//vertical_line2.ip_address = sip_dialog_information[i].dst;
			vertical_line2.ip_address = sbc_ip_used; // ip fetched from config file, request uri can't be trusted..

			vertical_line2.device_name = device_name.toUpperCase();

			vertical_line2.horizontal_position = horizontal_position;
			horizontal_position+=distance_between_vertical_lines;

			await drawVerticalLine(vertical_line2);

			vertical_lines.push(vertical_line2);
		} else {
			var found_src = false;
			var found_dst = false;
			//check if the source aleady exists, if not add a line
			if (sip_dialog_information[i].src) {
				for(var j = 0; j < vertical_lines.length; j++) {
					if (vertical_lines[j].ip_address == sip_dialog_information[i].src) {
						found_src = true;
						break;
					}
				}
				if (!found_src) {
					var vertical_line = {};

					vertical_line.ip_address = sip_dialog_information[i].src;
					vertical_line.horizontal_position = horizontal_position;
					horizontal_position+=distance_between_vertical_lines;

					await drawVerticalLine(vertical_line);
					
					vertical_lines.push(vertical_line);
				}
			}

			//check if the destination aleady exists, if not add a line
			if (sip_dialog_information[i].dst) {
				//console.log(sip_dialog_information[i]);
				for(var j = 0; j < vertical_lines.length; j++) {
					if (vertical_lines[j].ip_address == sip_dialog_information[i].dst) {
						found_dst = true;
						//console.log("found_dst!!");
						break;
					}
				}
				// Don't forget the SBC IP from the config file...
				if (sbc_ip_used === sip_dialog_information[i].dst) {
					found_dst = true;
				}

				if (!found_dst) {
					var vertical_line = {};

					vertical_line.ip_address = sip_dialog_information[i].dst;
					//console.log(vertical_line.ip_address);

					//var ip_address = sip_dialog_information[i].dst.slice(5,sip_dialog_information[i].dst.indexOf(":"));
					var ip_address = vertical_line.ip_address;
					var device = device_data_bundle.filter(function(device){return device.ip === ip_address;});
					if (device[0]) vertical_line.device_name = device[0].name;
					else {
						ip_address = ip_address.split(":")[0];
						//console.log(none_sbc_device_data_bundle);
						var device = none_sbc_device_data_bundle.filter(function(device){return device.ip === ip_address;});
						if (device[0]) vertical_line.device_name = device[0].name.toUpperCase();
					}

					//if (device_date_bundle.ip.contains == slice(vertical_line.ip_address)){
						//vertical_line.device_name = device_data_bundle.name;
					//}
					vertical_line.horizontal_position = horizontal_position;
					horizontal_position+=distance_between_vertical_lines;
					
					await drawVerticalLine(vertical_line);
					
					vertical_lines.push(vertical_line);
				}
			}
		}
	}

	//console.log(sip_dialog_information);

	// horizontal lines

	for (var i = 0; i < sip_dialog_information.length; i++) {
		vertical_position+=30;
		var horizontal_line = {};
		//console.log(sip_dialog_information[i]);
		if (i === 0) {
			ctx.beginPath();
			ctx.moveTo(vertical_lines[0].horizontal_position, vertical_position);
			ctx.lineTo(vertical_lines[1].horizontal_position, vertical_position);
			ctx.stroke();
			ctx.fillText(sip_dialog_information[i].type + " " + sip_dialog_information[i].dst_number, (((vertical_lines[1].horizontal_position-vertical_lines[0].horizontal_position)/2)+vertical_lines[0].horizontal_position), vertical_position-10);
			await canvas_arrow(ctx, vertical_lines[1].horizontal_position-6, vertical_position, vertical_lines[1].horizontal_position-6, vertical_position, 7);
			
			document.getElementById('call_info2').innerHTML='Destination number: '+sip_dialog_information[i].dst_number+"<br/>Source number: "+sip_dialog_information[i].from_name;
		} else {
			var src_horizontal_position;
			var dst_horizontal_position;
			if (!sip_dialog_information[i].src) {
				src_horizontal_position = vertical_lines[1].horizontal_position; 
			} else {
				for (var j = 0; j < vertical_lines.length; j++) {
					if (sip_dialog_information[i].src === vertical_lines[j].ip_address) {
						src_horizontal_position = vertical_lines[j].horizontal_position;
					}
				}
			}
			if (!sip_dialog_information[i].dst) {
				dst_horizontal_position = vertical_lines[1].horizontal_position; 
			} else {
				for (var j = 0; j < vertical_lines.length; j++) {
					if (sip_dialog_information[i].dst === vertical_lines[j].ip_address) {
						dst_horizontal_position = vertical_lines[j].horizontal_position;
					}
				}
			}
			ctx.beginPath();
			ctx.moveTo(src_horizontal_position, vertical_position);
			ctx.lineTo(dst_horizontal_position, vertical_position);
			ctx.stroke();
			var text;
			if (sip_dialog_information[i].type === "INVITE") text = sip_dialog_information[i].type + " " +sip_dialog_information[i].dst_number;
			else text = sip_dialog_information[i].type;
			if (src_horizontal_position < dst_horizontal_position) {
				ctx.fillText(text, dst_horizontal_position - (distance_between_vertical_lines/2), vertical_position-10);
				await canvas_arrow(ctx, dst_horizontal_position-6, vertical_position, dst_horizontal_position-6, vertical_position, 7);
			} else {
				ctx.fillText(text, src_horizontal_position-(distance_between_vertical_lines/2), vertical_position-10);
				await canvas_arrow(ctx, dst_horizontal_position+50, vertical_position, dst_horizontal_position+7, vertical_position, 7);
			}
		}
		//console.log(sip_dialog_information);
		ctx.fillText(sip_dialog_information[i].time, 25, vertical_position);

		horizontal_line.vertical_position = vertical_position;
		horizontal_lines.push(horizontal_line);
	}
	
	var oldCanvas = canvas.toDataURL("image/png");
	var img = new Image();
	img.src = oldCanvas;
	img.onload = function (){
		    canvas.height = vertical_position+100;
		    ctx.drawImage(img, 0, 0);
	}
	document.getElementById('sip_canvas').appendChild(canvas);
	document.getElementById('sip_canvas').style.height = vertical_position+100+'px';
	
	canvas.addEventListener('click', (e) => {
		var mousePos = {
			x: e.clientX - canvas.offsetLeft,
			y: e.clientY - canvas.offsetTop + window.pageYOffset
		};
		console.log("x: " + mousePos.x + ",y: " + mousePos.y);
		for (var k = 0 ; k < horizontal_lines.length; k++) {
			//console.log(horizontal_lines[k].vertical_position);
			var position = horizontal_lines[k].vertical_position;
			if (mousePos.y > (position-20) && mousePos.y < position+2) {
				//console.log(sip_dialog_information[k]);
				jumpTo(k+1);
			}
		}
		//const pixel = ctx.getImageData(mousePos.x, mousePos.y, 1, 1).data;
		//console.log(pixel)
	});
}

async function drawVerticalLine(vertical_line) {
	return new Promise((resolve, reject) => {
		ctx.beginPath();
		ctx.moveTo(vertical_line.horizontal_position, vertical_position);
		ctx.lineTo(vertical_line.horizontal_position, vertical_line_length+vertical_position);
		ctx.stroke();
		if (vertical_line.device_name) ctx.fillText(vertical_line.device_name, vertical_line.horizontal_position,vertical_position-23);
		ctx.fillText(vertical_line.ip_address, vertical_line.horizontal_position,vertical_position-10);
		resolve("success");
	})
}

async function canvas_arrow(context, fromx, fromy, tox, toy, r){
	var x_center = tox;
	var y_center = toy;

	var angle;
	var x;
	var y;

	context.beginPath();

	angle = Math.atan2(toy-fromy,tox-fromx);
	x = r*Math.cos(angle) + x_center;
	y = r*Math.sin(angle) + y_center;

	context.moveTo(x, y);

	angle += (1/3)*(2*Math.PI);
	x = r*Math.cos(angle) + x_center;
	y = r*Math.sin(angle) + y_center;

	context.lineTo(x, y);

	angle += (1/3)*(2*Math.PI);
	x = r*Math.cos(angle) + x_center;
	y = r*Math.sin(angle) + y_center;

	context.lineTo(x, y);

	context.closePath();

	context.fill();
}

async function processMessageTypes() {
	var div = document.createElement('div');
	
	for (const item of callog) {
		await addToMessageTypes(item);
	}

	div.innerHTML += "Select types of messages to display:";
	for (var item of message_types) {
		if (item === "") item = "-null-";
		if (item === "-null-") {
			div.innerHTML += "<input type='checkbox' id='"+item+"' onchange='updateScreen(\""+item+"\")'checked />SIP | ";
		} else {
			div.innerHTML += "<input type='checkbox' id='"+item+"' onchange='updateScreen(\""+item+"\")'checked />"+item+" | ";
		}
	}
	div.innerHTML += "<input type='button' onclick='setAllMessageTypes(false)' value='Disable all'/>";
	div.innerHTML += " | <input type='button' onclick='setAllMessageTypes(true)' value='Enable all'/>";
	div.innerHTML += "<hr/>";
	document.getElementById("options").appendChild(div);

	await updateScreen();
	await drawSip();
}

function getPosition(element){
	var e = document.getElementById(element);
	var left = 0;
	var top = 0;

	do{
		left += e.offsetLeft;
		top += e.offsetTop;
	}while(e = e.offsetParent);

	//console.log(left);
	//console.log(top);

	return [left, top];
}

function jumpTo(id){    
	var a = getPosition(id);
	window.scrollTo(0, a[1]-55);
	//window.scrollTo(0,0);
	//console.log("scroll...");
}

if (latest_call_only || sid_available) processMessageTypes();

</script>
</body>
</html>
