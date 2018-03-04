#!/usr/bin/php
<?php  
// This php program reads data from a ducobox silent (and maybe others)
// 

include(realpath(dirname(__FILE__))."/../PHP-Serial/src/PhpSerial.php");
require(realpath(dirname(__FILE__))."/../phpMQTT/phpMQTT.php");


$serialdevice = "/dev/ttyUSB1";
$server = "192.168.2.1";     // change if necessary
$port = 1883;                     // change if necessary
$username = "";                   // set your username
$password = "";                   // set your password
$client_id = uniqid("ducobox_");; // make sure this is unique for connecting to sever - you could use uniqid()



echo ("DucoBox MQTT publisher started...\n"); 
$mqttTopicPrefix = "home/ducobox/";



$iniarray = parse_ini_file("ducoboxMQTT.ini",true);

if (($tmp = $iniarray["ducobox"]["serialdevice"]) != "") $serialdevice = $tmp;  
if (($tmp = $iniarray["ducobox"]["mqttserver"]) != "") $server = $tmp;
if (($tmp = $iniarray["ducobox"]["mqttport"]) != "") $tcpport = $tmp;
if (($tmp = $iniarray["ducobox"]["mqttusername"]) != "") $username = $tmp;
if (($tmp = $iniarray["ducobox"]["mqttpassword"]) != "") $password = $tmp;


$ducodata["ducobox"]  = array();

exec ('stty -F '.$serialdevice.' 1:0:18b2:0:3:1c:7f:15:4:5:1:0:11:13:1a:0:12:f:17:16:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0:0', $execresult);


$serial = new PhpSerial;


$mqtt = new phpMQTT($server, $port, $client_id);
$mqtt->connect(true, NULL, $username, $password);

// First we must specify the device. This works on both linux and windows (if
// your linux serial device is /dev/ttyS0 for COM1, etc)
$sendtimer = 0;
$dataready = 0;
$ducodatatimeout = array();
$requesteditem = ""; 
$requestitemid = 0;
$requestednodeid = ""; 
$message=""; 
while(1)
{
 if ($serial->_dState != SERIAL_DEVICE_OPENED)
 {
   echo "Opening Serial Port '".$serialdevice."'...\n";

   // First we must specify the device. This works on both linux and windows (if
   // your linux serial device is /dev/ttyS0 for COM1, etc)
   $serial->deviceSet($serialdevice);

   // We can change the baud rate, parity, length, stop bits, flow control
   $serial->confBaudRate(115200);
   $serial->confParity("none");
   $serial->confCharacterLength(8);
   $serial->confStopBits(1);
   $serial->confFlowControl("none");
   
   if (!$serial->deviceOpen())
   {
     echo ("Serial Port could not be opened...\n");
   }
   else
   {

    echo "Opened Serial Port.\n";
//    writeserial($serial, "\r\n");
    $sendtimer = 0;
    }
 }

        $readmask = array();
        array_push($readmask, $serial->_dHandle);
        $writemask = NULL;
        $errormask = NULL;
        $nroffd = stream_select($readmask, $writemask, $errormask, 1);

        $mqtt->proc();

        if ($nroffd == 0)
        {
          if ($sendtimer == 0)
          {
            $sendtimer = 0;
            $requestitemid = 0;
            writeserial ($serial, "fanspeed\r\n");
            $requesteditem = "fanspeed";
            $requestednodeid = 1;
          }
          $sendtimer++;
          // If 30 seconds are past retry...
          if ($sendtimer > 30) $sendtimer = 0;
        }

        foreach ($readmask as $i) 
        {
           if ($i == $serial->_dHandle)
           {
              $message .= str_replace(array("\r", "\n"), "\n", $serial->readPort());  
//              echo $message;
             

              if (strlen($message) > 0)
              {
               while (strpos($message, "\n") !== FALSE)
               {
                 $firstmessage = strtok ($message, "\n");
                 // Remove first message from serial data
                 $message = substr($message, strlen($firstmessage) + 1);
                 echo ("Message='".$firstmessage."'\n");
                 if  (strpos($firstmessage, "  -->") === 0)
                 {
                  $ducodata["ducobox"][$requestednodeid][$requesteditem] =  substr($firstmessage, 6);
                  $ducodatatimeout["ducobox"][$requestednodeid][$requesteditem] = 0;
                  publishmqtt($requestednodeid."/".$requesteditem, substr($firstmessage, 6));
                 }
                 
                 if  (strpos($firstmessage, "  FanSpeed:") === 0)
                 {
                  $ducodata["ducobox"][$requestednodeid][$requesteditem] = explode(" ",$firstmessage)[8];
                  $ducodatatimeout["ducobox"][$requestednodeid][$requesteditem] = 0;
                  publishmqtt($requestednodeid."/".$requesteditem, explode(" ",$firstmessage)[8]);
                 }

                 if (!isset($ducodata["ducobox"][$requestednodeid][$requesteditem]))
                 {
                  $ducodata["ducobox"][$requestednodeid][$requesteditem] = null;
                 }

                 if ((strpos($firstmessage, "  Failed") !== FALSE) && (strpos($message, "  Failed") == 0))
                 {
                  // After 10 retries make data invalid
                  if (isset($ducodatatimeout["ducobox"][$requestednodeid][$requesteditem]))
                  {
                    if ($ducodatatimeout["ducobox"][$requestednodeid][$requesteditem] > 2)
                    {
                      $ducodata["ducobox"][$requestednodeid][$requesteditem] = null;
                      publishmqtt($requestednodeid."/".$requesteditem,  "");
                    }
                    else $ducodatatimeout["ducobox"][$requestednodeid][$requesteditem]++;
                  }
                  else 
                  {
                   $ducodatatimeout["ducobox"][$requestednodeid][$requesteditem] = 1;
                  }
                  echo "Errorcount=".$ducodatatimeout["ducobox"][$requestednodeid][$requesteditem]."\n";
                 }
                 
               }  

                 if ((strpos($message, ">") !== FALSE) && (strpos($message, ">") == 0))
                 {
                   $message = substr($message, 2);

                  echo "Ducobox ready for next command.\n";
                  switch ($requestitemid) 
                  {
                   case 0:
                    writeserial ($serial, "nodeparaget 2 73\r\n");
                    $requesteditem = "temperature" ;
                    $requestednodeid = 2;
                   break;
                   case 1:
                    writeserial ($serial, "nodeparaget 2 74\r\n");
                    $requesteditem = "co2";
                    $requestednodeid = 2;
                   break;
                   case 2:
                    writeserial ($serial, "nodeparaget 2 75\r\n");
                    $requesteditem = "rh" ;
                    $requestednodeid = 2;
                   break;
                   case 3:
                    echo "Waiting for next query...\n";
                    $sendtimer = -1; // Wait 1 seconds before next query
                   break;
                  }
                  $requestitemid++;
                 }
              }

            }
          }

}

$serial->deviceClose();
exit(1);

function publishmqtt ($topic, $msg)
{
        global $mqtt;
        global $mqttTopicPrefix;
        echo ($topic.": ".$msg."\n");
        $mqtt->publishwhenchanged($mqttTopicPrefix.$topic,$msg,0,1);
}

function writeserial ($serial, $message)
{
 $pos = 0;
 while ($pos < strlen($message))
 {
   $serial->sendMessage($message[$pos], 0);
   echo $message[$pos];
   $pos++;
   usleep(10000);
 }
 
}


?>  

