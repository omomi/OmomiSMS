<?php
// ******************************* INFORMATION ***************************//
//  
// ** sendsms.php - This is a sample of the php script used to send SMS to mothers of children from ages 1 to 3 years

// ** @author   ofure <for mobicure>
// ** @date 22 Apr 2016
// ** @params Built to work with infobip.com/docs/api/Infobip_HTTP_API_and_SMPP_specification.pdf
// ** @output  File writes  to a log file logs/logSentMessages.txt
//      
// ***********************************************************************//

// ********************************** START ******************************//  
// ** prevent this script from timing out
set_time_limit(0);
ignore_user_abort(1); 

// ** Server Parameters
define(HOST, "localhost");
define(DATABASE, "database_name");
define(USERNAME, "database_user");
define(PASSWORD, "database_password");
date_default_timezone_set ("Africa/Lagos");

// ** Infobip SMS parameters
define(INFOBIP_USERNAME, "InfobipUsername");
define(INFOBIP_PASSWORD, "InfobipPassword");
define(INFOBIP_SENDER, "YourSenderName");

// ** Connect to database or die
$con = mysqli_connect(HOST, USERNAME, PASSWORD, DATABASE);
if (mysqli_connect_errno()){
	  die("Failed to connect to MySQL: " . mysqli_connect_error());
}
	


$count = 0; //count for log purposes

// ** Fetch all SMS data from  txtMotherData1to3. 
// ** Save result in multidimensional array
// ** Single arrayRow = [messageId] [weekOfPregnancy] [messageToBeSent]

$mother1to3SmsData = array(); //contains SMS from database. Same messages are stored contained in json file: mother_of_child_0_to_3.json

$motherSMS1to3Query = "SELECT * FROM txtMotherData1to3";
$motherSMS1to3Result = mysqli_query($con, $motherSMS1to3Query);

while ($mother1to3SmsRow = mysqli_fetch_assoc($motherSMS1to3Result)){
	$id = $mother1to3SmsRow['id'];
	$week = $mother1to3SmsRow['week']; 
	$message = $mother1to3SmsRow['message']; 

	array_push($mother1to3SmsData, array($id, $week, $message) );
}
$extraLog = "";

// ** Loop through active tbl_mothers users. 
// ** Fish out users due for deactivation. 
// ** Map appropriate message to user based on lastMessageReceived and childAge

// ** childAge is calculated by TIMESTAMPDIFF( WEEK, date, CURDATE() ) 
// ** which is difference in WEEKS from today to the child's birthday
// ** child's birthday is saved in the database as 'date' field
$motherUserQuery = "SELECT number, TIMESTAMPDIFF( WEEK, date, CURDATE( ) ) AS childAge, 
					 lastMessageId, date FROM tbl_mothers 
					 WHERE active='1' ORDER BY childAge";

$motherUserResult = mysqli_query($con, $motherUserQuery);

while ($motherUserRow = mysqli_fetch_assoc($motherUserResult)) {
 	 $childAge = $motherUserRow['childAge'];
 	 $lastMessageId = $motherUserRow['lastMessageId'];
 	 $phoneNumber = $motherUserRow['number'];
 	 $dob = $motherUserRow['date'];

 	if($childAge > 251){ //Child is above 5 years. Deactivate mother.
 		$deactivateUserQuery = "UPDATE tbl_mothers SET active =  '0' 
 						WHERE  number = $phoneNumber"; 
 		mysqli_query($con,$deactivateUserQuery);
 		continue;
 	}
 	else if ($childAge <= 51 or $childAge >= 156){ 
 		//Child is not within 1 to 3. Ignore.
 		continue;
 	}

 	else{
 		//Child is from 1-3 years. Send message from txtMotherData1to3 table
 			$updatedMessageId = $lastMessageId + 1; 
 			//increment her lastMessageId. So she gets updated SMS
 		
 			if ($lastMessageId == 0){
 				// Mother is new on platform. Has not received any SMS yet
	 			
	 			$updatedMessageId = ($childAge - 52)* 2 + 1; 
	 			//because the entry of weeks in db starts at 5
	 		}
	 		
 			 $ageRelatedToMessageId = $mother1to3SmsData[$updatedMessageId-1][1]; 
 			 //Check if child age matches this message. array index needs to be offset by 1

 			 if ($ageRelatedToMessageId != $childAge){ 
 			 //childAge does not match age related to this message. Update to the right message
 			 	 $updatedMessageId = ($childAge - 52)* 2 + 1; 
			 }

			$messageToSend = $mother1to3SmsData[$updatedMessageId-1][2]; 
			//array index needs to be offset by 1

			if ($messageToSend == '' or $messageToSend == NULL){ 
			//improper registration. Write to anomaly log.
				$extraLog .= "Phone Number:".$phoneNumber." DOB: ".$dob ." \n";
		 		continue;
			}
			
	 		// ** Build XML file
	 		// ** Connection successful
			// ***** Begin XML *****//
			$xmlData = "<SMS>
			<authentication>
			<username>InfobipUsername</username>
			<password>InfobipPassword</password>
			</authentication>";

	 		$xmlData .= "<message>
	 		<sender>YourSenderName</sender>
	 		<text>".$messageToSend."</text>
	 		<type>longSMS</type>
	 		<recipients>
	 		<gsm>".$phoneNumber."</gsm>
	 		</recipients>
	 		</message>";

	 		/***** Final block to end XML *****/
			$xmlData .= "</SMS>";
			/***** End XML *****/

			// ** Infobip params
			$url = "http://api.infobip.com/api/v3/sendsms/xml"; 
			$header  = array(
			 "Host: api.infobip.com",
			 "Content-type: text/xml", 
			 "Content-length: ".strlen($xmlData),
			 "Content-transfer-encoding: text"
			);
				
			// ** CURL Request
			$ch = curl_init();
			curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); 
			curl_setopt($ch, CURLOPT_URL,$url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 4);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $xmlData);

			$data = curl_exec($ch); 

			// Display Results
			if (curl_errno($ch)){
			    echo "Error: ".curl_error($ch); 
			}
			else { 
			    curl_close($ch); 
			} 

	 		
	 		$count ++; //increase count for log purposes    
			
 		//Update Mother's ID in DB
 		$updatedMessageIdQuery = "UPDATE tbl_mothers SET  lastMessageId =  '".$updatedMessageId."' WHERE  number =".$phoneNumber;	 
		mysqli_query($con,$updatedMessageIdQuery);
	}
}



//Append log file to hold valaues of how many numbers were saved. sent. etc
$logData = "\nLog data for ".date('Y-m-d H:i:s').". ".$count." mother users from 1-3 years received SMS\n";

$extraLogFile = "logs/anomalyLog.txt"; //log of improper registration
file_put_contents($extraLogFile, $extraLog, FILE_APPEND | LOCK_EX);


$logFile = 'logs/logSentMessages.txt';
file_put_contents($logFile, $logData, FILE_APPEND | LOCK_EX);
?>