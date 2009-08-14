#!/usr/bin/php
<?
	require 'ProwlPHP.php';
	require 'class.googlevoice.php';	// modified google voice class that logs in and grabs sms xml/html data
	require 'config.php';			// contains $gv_user, $gv_pass, $prowl_apikey, and $gv_interval config options

	$sms_db = array();			// holds all SMS messages for *todays* date
	$sms_count = 0;
	$session_sms_count = 0;			// tracks all new sms this session
	$session_sms_size = 0;			// tracks new sms bytes
	$loop_count = 0;			// used to fire off a status message every 30 minutes


	// create google voice object
	$gv = new GoogleVoice($gv_user, $gv_pass);
	
	// get all sms messages for today (dirty method using previously declared array)
	$gvsms_count = get_gv_sms();

	// sort the array using sksort from serpro@gmail.com - http://us2.php.net/manual/en/function.ksort.php#89552
	sksort($sms_db, "time");
	
	// log current sms db count
	$sstr = "";
	if ($gvsms_count > 1) { $sstr = "s"; }
	log_msg("Found ".$gvsms_count." message".$sstr." sent today, monitoring at $gv_interval second intervals");


	// start a loop to monitor incoming sms
	while (1) {
		$newcount = get_gv_sms();
		sksort($sms_db, "timestamp");

		// compare old count to new count and compute the difference
		if ($newcount > $gvsms_count) {
			$difference = $newcount - $gvsms_count;
			$gvsms_count = $newcount;
			
			$sstr = "";
			if ($difference > 1) { $sstr = "s"; }

			log_msg("Found ".$difference." new message".$sstr);
			
			// $difference is number of new messages, send notifications
			for ($a = 0; $a <= $difference-1; $a++) {
				$msg_length = strlen($sms_db[$a]["message"]);
				$session_sms_size = $session_sms_size+$msg_length;
				$session_sms_count++;

				log_msg("Prowling $msg_length bytes from ".$sms_db[$a]["from"]);
				send_prowl($prowl_apikey, "GVSMS", $sms_db[$a]["from"], $sms_db[$a]["message"], 0);
			}
		}
	
		$loop_count++;

		// send status message every 30min
		if ($loop_count == 30) {
			if ($session_sms_count > 0) {
				$sstr = "";
				if ($session_sms_count > 1) { $sstr = "s"; }

				log_msg("[STATUS] Processed $session_sms_count message".$sstr." this session totalling ".number_format($session_sms_size)." bytes");
			
			}
			$loop_count = 0;
		}

		sleep($gv_interval);
	}

	// generic logging function, prints and uses logger for syslogging
	function log_msg($log_text) {
			print date("M")." ".date("d")." ".date("H").":".date("i").":".date("s")." gvsms: $log_text\n";
			system("logger -t \"gvsms\" \"$log_text\"");
	}
		

	// send new notification using php prowl api
	function send_prowl($prowl_key, $application, $event, $description, $priority = 0) {
		$prowl = new Prowl($prowl_key);
		$prowl->push(array(
			'application' => $application,
			'event' => $event, 
			'description' => $description, 
			'priority' => $priority, 
		), true);
	}

	// does all the ugly filtering magic. could be a lot more efficient but i just wanted a quick solution
	// and so far this works well
	function get_gv_sms() {
		global $gv, $sms_db;
		
		$sms_db = array();

		$code_array = array();
		$code_array = $gv->get_sms();
	
		$array_position = 0;
		$time_counter = 0;

		foreach ($code_array as $item) {
			$line = trim($item);
			
			// get the date and time for this conversation
			if (substr($line, 12, 19) == "gc-message-time-row") {
				$conv_datestamp = strip_tags(trim(str_replace(":", "", $code_array[$array_position+1])));
				list($conv_date, $conv_time, $conv_ampm) = split(" ", $conv_datestamp);
				list($conv_month, $conv_day, $conv_year) = split("/", $conv_date);
				list($conv_hour, $conv_min) = split(":", $conv_time);
			}

			// get the details of specific sms in this conversation
			if (substr($line, 12, 18) == "gc-message-sms-row") {
				$from_name = trim(strip_tags(str_replace(":", "", $code_array[$array_position+2])));
				$message = trim(strip_tags($code_array[$array_position+4]));
				$message_time = trim(strip_tags($code_array[$array_position+5]));
				
				// ignore messages from yourself
				if ($from_name != "Me") {
					// extract time of sms and calculate military time for easier sorting
					list($msg_time, $msg_ampm) = split(" ", $message_time);
					list($msg_hour, $msg_min) = split(":", $msg_time);
					if ($msg_ampm == "PM" && $msg_hour < 12) {
						$msg_hour = $msg_hour+12;
					}
					if ($msg_ampm == "AM" && $msg_hour == 12) {
						$msg_hour = 0;
					}

					// if the fille name of the sender is located in the body of the sms, strip it out
					$message = str_replace($from_name." - ", "", $message);

					// generate unix timestamp for this sms using conversation date and sms time
					$timestamp = mktime((int)$msg_hour, (int)$msg_min, 0, (int)$conv_month, (int)$conv_day, (int)$conv_year);

					// figure out todays date and filter only those sms
					$sms_full_date = $conv_month."-".$conv_day."-".$conv_year;
					$todays_full_date = date("n")."-".date("j")."-".date("y");

					if ($sms_full_date == $todays_full_date) {
						array_push($sms_db, array("timestamp" => $timestamp, "date" => $conv_month."-".$conv_day."-".$conv_year, "time" => $msg_hour.":".$msg_min.":".$time_counter, "from" => $from_name, "message" => $message));

						// gv doesnt log seconds with the time on each message in the html
						// so generate this on the fly since sms are filtered in the order received
						// required for sorting by time using sksort()
						$time_counter++;
					}

				}
			}

			$array_position++;
		}

		return count($sms_db);
	}


	// sksort from serpro@gmail.com - http://us2.php.net/manual/en/function.ksort.php#89552
	function sksort(&$array, $subkey = "id", $sort_ascending = false) {
		if (count($array))
        		$temp_array[key($array)] = array_shift($array);

		foreach($array as $key => $val) {
			$offset = 0;
			$found = false;

			foreach($temp_array as $tmp_key => $tmp_val) {
				if (!$found and strtolower($val[$subkey]) > strtolower($tmp_val[$subkey])) {
					$temp_array = array_merge((array)array_slice($temp_array,0,$offset), array($key => $val), array_slice($temp_array,$offset));
					$found = true;
				}
				
				$offset++;
			}

			if (!$found) $temp_array = array_merge($temp_array, array($key => $val));
		}

		if ($sort_ascending) $array = array_reverse($temp_array);
		
		else $array = $temp_array;
	}
?>
