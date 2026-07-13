<?php

declare(strict_types=1);

class SmartAlarmManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("MonitoredVariables", "[]");
        $this->RegisterPropertyInteger("EscalationTimeLvl2", 300);
        $this->RegisterPropertyInteger("EscalationTimeLvl3", 900);
        $this->RegisterPropertyInteger("TargetWebFront", 0);
        $this->RegisterPropertyInteger("TargetSMTP", 0);
        $this->RegisterPropertyInteger("TargetVestaboard", 0);
        $this->RegisterPropertyInteger("TargetSonos", 0);
        $this->RegisterPropertyInteger("TargetHmIP_MP3", 0);
        $this->RegisterPropertyString("TargetHmIP_LEDs", "[]");
        $this->RegisterPropertyString("EmailAddress", "");
        $this->RegisterTimer("EscalationTimer", 0, 'SAM_CheckEscalation($_IPS[\'TARGET\']);');
        $this->RegisterTimer("DelayTimer", 0, 'SAM_HandleDelays($_IPS[\'TARGET\']);');
        
        $this->SetBuffer("ActiveAlarms", "{}");
        $this->SetBuffer("ActiveDelays", "{}");

        // Profiles for Tile UI
        if (!IPS_VariableProfileExists("SAM.SystemStatus")) {
            IPS_CreateVariableProfile("SAM.SystemStatus", 1);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 0, "Alles OK", "Ok", 0x00FF00);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 1, "Info / Hinweis", "Information", 0xFFFF00);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 2, "ALARM!", "Warning", 0xFF0000);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 3, "ESKALATION", "Warning", 0xFF0000);
            IPS_SetVariableProfileAssociation("SAM.SystemStatus", 4, "VOLLALARM", "Alert", 0xFF0000);
        }

        // Summary Variables for Tile UI
        $this->RegisterVariableInteger("SystemStatus", "System Status", "SAM.SystemStatus", 1);
        $this->RegisterVariableInteger("ActiveAlarmsCount", "Aktive Alarme", "", 2);
        $this->RegisterVariableString("LastEvent", "Letztes Ereignis", "", 3);
        $this->RegisterVariableBoolean("AcknowledgeAll", "Alle Alarme quittieren", "~Switch", 4);
        $this->EnableAction("AcknowledgeAll");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Unregister all old messages
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (!is_array($monitored)) $monitored = [];

        $activeIdents = [];

        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                
                // If it's an Alarm type (0), create an Acknowledge Variable
                if (($item['AlarmType'] ?? 0) == 0) {
                    $ident = "Alarm_" . $vid;
                    $activeIdents[] = $ident;
                    $this->MaintainVariable($ident, "Status: " . ($item['Message'] ?? 'Alarm'), 0, "~Alert", 0, true);
                    $this->EnableAction($ident);
                }
            }
        }
        
        // Remove variables that are no longer configured
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $ident = IPS_GetObject($childID)['ObjectIdent'];
            if (strpos($ident, "Alarm_") === 0) {
                if (!in_array($ident, $activeIdents)) {
                    $this->MaintainVariable($ident, "", 0, "", 0, false);
                }
            }
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $monitored = json_decode($this->ReadPropertyString("MonitoredVariables"), true);
        if (!is_array($monitored)) return;

        $currentVal = $Data[0]; // New value
        
        foreach ($monitored as $item) {
            $vid = $item['VariableID'] ?? 0;
            if ($vid == $SenderID) {
                $triggerVal = $item['TriggerValue'] ?? 'true';
                if ($this->IsTriggered($currentVal, $triggerVal)) {
                    $delay = $item['DelaySeconds'] ?? 0;
                    if ($delay > 0) {
                        $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
                        if (!isset($delays[$vid])) {
                            $delays[$vid] = [
                                "triggerTime" => time() + $delay,
                                "item" => $item
                            ];
                            $this->SetBuffer("ActiveDelays", json_encode($delays));
                            $this->SetTimerInterval("DelayTimer", 1000); // Check every second
                        }
                    } else {
                        $this->HandleTrigger($item);
                    }
                } else {
                    // Condition not met, cancel delay if active
                    $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
                    if (isset($delays[$vid])) {
                        unset($delays[$vid]);
                        $this->SetBuffer("ActiveDelays", json_encode($delays));
                        if (empty($delays)) {
                            $this->SetTimerInterval("DelayTimer", 0);
                        }
                    }
                    
                    // Also turn off LEDs if it was an Info or Alarm that just got resolved by the sensor
                    $this->TriggerHomematicLEDs($item, true);
                }
            }
        }
    }

    public function HandleDelays()
    {
        $delays = json_decode($this->GetBuffer("ActiveDelays"), true) ?: [];
        if (empty($delays)) {
            $this->SetTimerInterval("DelayTimer", 0);
            return;
        }

        $now = time();
        $changed = false;

        foreach ($delays as $vid => $delayObj) {
            if ($now >= $delayObj['triggerTime']) {
                $this->HandleTrigger($delayObj['item']);
                unset($delays[$vid]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveDelays", json_encode($delays));
            if (empty($delays)) {
                $this->SetTimerInterval("DelayTimer", 0);
            }
        }
    }

    private function HandleTrigger($item)
    {
        $type = $item['AlarmType'] ?? 0;
        $msg = $item['Message'] ?? "Alarm ausgelöst";
        $vid = $item['VariableID'];

        if ($type == 1) {
            // Info / Doorbell (Fire and Forget)
            $this->LogMessage("Info/Event ausgelöst: " . $msg, KL_NOTIFY);
            $this->SendDebug("Trigger", "Info/Event: " . $msg, 0);
            $this->TriggerInfo($item);
            
            $this->SetValue("LastEvent", date("d.m.Y H:i:s") . " - " . $msg);
            // Info pulse for status (only if nothing worse is active)
            if ($this->GetValue("SystemStatus") == 0) {
                $this->SetValue("SystemStatus", 1);
                IPS_Sleep(3000); // Pulse visual state
                $this->UpdateStatusVariables(); // Re-evaluates based on active alarms
            }
        } else {
            // Alarm with Escalation
            $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
            
            if (!isset($alarms[$vid])) {
                $alarms[$vid] = [
                    "timestamp" => time(),
                    "level" => 1,
                    "item" => $item
                ];
                $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                
                $this->LogMessage("ALARM ausgelöst (Stufe 1): " . $msg, KL_WARNING);
                $this->SendDebug("Trigger", "Alarm Stufe 1: " . $msg, 0);
                
                $this->TriggerLevel1($item);
                
                $ident = "Alarm_" . $vid;
                if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                    $this->SetValue($ident, true);
                }
                
                $this->SetValue("LastEvent", date("d.m.Y H:i:s") . " - ALARM: " . $msg);
                
                // Ensure Escalation Timer runs
                $this->SetTimerInterval("EscalationTimer", 10000);
                
                $this->UpdateStatusVariables();
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if (strpos($Ident, "Alarm_") === 0) {
            // User acknowledges the alarm
            if ($Value == false) {
                $this->SetValue($Ident, false);
                $this->LogMessage("Alarm quittiert: " . $Ident, KL_NOTIFY);
                $this->SendDebug("Acknowledge", "Quittiert: " . $Ident, 0);
                
                $vid = substr($Ident, 6);
                $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                if (isset($alarms[$vid])) {
                    $this->TriggerHomematicLEDs($alarms[$vid]['item'], true); // Turn off LEDs
                    unset($alarms[$vid]);
                    $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                }
                
                if (empty($alarms)) {
                    $this->SetTimerInterval("EscalationTimer", 0);
                }
                $this->UpdateStatusVariables();
            }
        } elseif ($Ident === "AcknowledgeAll") {
            if ($Value == true) {
                $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
                foreach ($alarms as $vid => $alarm) {
                    $ident = "Alarm_" . $vid;
                    if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID)) {
                        $this->SetValue($ident, false);
                    }
                    $this->TriggerHomematicLEDs($alarm['item'], true); // Turn off LEDs
                }
                $this->SetBuffer("ActiveAlarms", "{}");
                $this->SetTimerInterval("EscalationTimer", 0);
                $this->UpdateStatusVariables();
                $this->LogMessage("Alle Alarme quittiert.", KL_NOTIFY);
                $this->SetValue("LastEvent", date("d.m.Y H:i:s") . " - Alle Alarme quittiert");
                
                // Reset button instantly
                $this->SetValue("AcknowledgeAll", false);
            }
        } else {
            throw new Exception("Invalid Ident");
        }
    }

    public function CheckEscalation()
    {
        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
        if (empty($alarms)) {
            $this->SetTimerInterval("EscalationTimer", 0);
            $this->UpdateStatusVariables();
            return;
        }

        $changed = false;
        $lvl2Time = $this->ReadPropertyInteger("EscalationTimeLvl2");
        $lvl3Time = $this->ReadPropertyInteger("EscalationTimeLvl3");
        $now = time();

        foreach ($alarms as $vid => &$alarm) {
            $elapsed = $now - $alarm['timestamp'];

            if ($alarm['level'] == 1 && $elapsed >= $lvl2Time) {
                $alarm['level'] = 2;
                $changed = true;
                $this->LogMessage("Alarm Eskalation (Stufe 2): " . $alarm['item']['Message'], KL_WARNING);
                $this->SendDebug("Escalation", "Stufe 2: " . $alarm['item']['Message'], 0);
                $this->TriggerLevel2($alarm['item']);
            }

            if ($alarm['level'] == 2 && $elapsed >= $lvl3Time) {
                $alarm['level'] = 3;
                $changed = true;
                $this->LogMessage("VOLLALARM Eskalation (Stufe 3): " . $alarm['item']['Message'], KL_ERROR);
                $this->SendDebug("Escalation", "Stufe 3 (VOLLALARM): " . $alarm['item']['Message'], 0);
                $this->TriggerLevel3($alarm['item']);
            }
        }

        if ($changed) {
            $this->SetBuffer("ActiveAlarms", json_encode($alarms));
            $this->UpdateStatusVariables();
        }
    }

    private function UpdateStatusVariables()
    {
        $alarms = json_decode($this->GetBuffer("ActiveAlarms"), true) ?: [];
        $count = count($alarms);
        $this->SetValue("ActiveAlarmsCount", $count);

        if ($count == 0) {
            $this->SetValue("SystemStatus", 0); // Alles OK
        } else {
            $maxLevel = 1;
            foreach ($alarms as $alarm) {
                if ($alarm['level'] > $maxLevel) {
                    $maxLevel = $alarm['level'];
                }
            }
            // Level 1 = 2 (ALARM!), Level 2 = 3 (ESKALATION), Level 3 = 4 (VOLLALARM)
            $this->SetValue("SystemStatus", $maxLevel + 1);
        }
    }

    private function TriggerLevel1($item)
    {
        $message = $item['Message'] ?? "Alarm";
        if ($item['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "Alarm!", $message, "", 0);
                @WFC_SendNotification($webfront, "Alarm!", $message, "Warning", 0);
            }
        }
        
        if ($item['UseSonos'] ?? true) {
            $this->TriggerSonos($message);
        }
        
        $this->TriggerHomematicMP3($item);
        $this->TriggerHomematicLEDs($item);
    }

    private function TriggerLevel2($item)
    {
        $message = $item['Message'] ?? "Alarm";
        
        if ($item['UseVestaboard'] ?? true) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    @VESTA_SendMessage($vesta, "ALARM:\n" . $message);
                }
            }
        }

        if ($item['UseEmail'] ?? true) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    $this->SendDebug("Email", "Versuche E-Mail zu senden an: " . $email, 0);
                    $result = @SMTP_SendMailEx($smtp, $email, "SmartHome Alarm Stufe 2", "Folgender Alarm wurde ausgelöst und noch nicht quittiert:\n\n" . $message);
                    if ($result === false) {
                        $this->LogMessage("Fehler beim E-Mail Versand! Bitte prüfe die Einstellungen der SMTP-Instanz #$smtp", KL_ERROR);
                    } else {
                        $this->SendDebug("Email", "E-Mail erfolgreich versendet.", 0);
                    }
                } else {
                    $this->LogMessage("E-Mail nicht gesendet: Es ist keine E-Mail Adresse in der Konfiguration hinterlegt.", KL_WARNING);
                }
            } else {
                $this->LogMessage("E-Mail nicht gesendet: Es ist keine gültige SMTP-Instanz ausgewählt.", KL_WARNING);
            }
        }
        
        if ($item['UseSonos'] ?? true) {
            $this->TriggerSonos("Achtung, Alarm: " . $message);
        }
        
        $this->TriggerHomematicMP3($item);
        $this->TriggerHomematicLEDs($item);
    }

    private function TriggerLevel3($item)
    {
        $message = $item['Message'] ?? "Alarm";
        
        if ($item['UseVestaboard'] ?? true) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    @VESTA_SendMessage($vesta, "!!! VOLLALARM !!!\n" . $message);
                }
            }
        }
        
        if ($item['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "VOLLALARM", $message, "", 0);
                @WFC_SendNotification($webfront, "VOLLALARM", $message, "Alert", 0);
            }
        }
        
        if ($item['UseSonos'] ?? true) {
            $this->TriggerSonos("Vollalarm: " . $message);
        }
        
        $this->TriggerHomematicMP3($item);
        $this->TriggerHomematicLEDs($item);
    }

    private function TriggerInfo($item)
    {
        $message = $item['Message'] ?? "Info";
        
        if ($item['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "Info", $message, "", 0);
                @WFC_SendNotification($webfront, "Info", $message, "Information", 0);
            }
        }
        
        if ($item['UseVestaboard'] ?? true) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    @VESTA_SendMessage($vesta, $message);
                }
            }
        }
        
        if ($item['UseEmail'] ?? true) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    $this->SendDebug("Email", "Versuche Info-E-Mail zu senden an: " . $email, 0);
                    $result = @SMTP_SendMailEx($smtp, $email, "SmartHome Info / Event", $message);
                    if ($result === false) {
                        $this->LogMessage("Fehler beim E-Mail Versand! Bitte prüfe die Einstellungen der SMTP-Instanz #$smtp", KL_ERROR);
                    } else {
                        $this->SendDebug("Email", "Info-E-Mail erfolgreich versendet.", 0);
                    }
                }
            }
        }
        
        if ($item['UseSonos'] ?? true) {
            $this->TriggerSonos($message);
        }
        
        $this->TriggerHomematicMP3($item);
        $this->TriggerHomematicLEDs($item);
    }
    
    private function TriggerSonos($message)
    {
        $sonos = $this->ReadPropertyInteger("TargetSonos");
        if ($sonos > 0 && IPS_InstanceExists($sonos)) {
            // Check for known Google TTS / Sonos functions
            if (function_exists('GSTTS_PlayMessage')) {
                $this->SendDebug("Sonos", "GSTTS_PlayMessage: " . $message, 0);
                @GSTTS_PlayMessage($sonos, $message);
            } elseif (function_exists('SNS_PlayText')) {
                $this->SendDebug("Sonos", "SNS_PlayText: " . $message, 0);
                @SNS_PlayText($sonos, $message);
            } else {
                $this->LogMessage("Sonos nicht angesteuert: Weder GSTTS_PlayMessage noch SNS_PlayText Funktion gefunden.", KL_WARNING);
            }
        }
    }

    private function TriggerHomematicMP3($item)
    {
        if (!($item['UseHmIP_MP3'] ?? false)) return;
        
        $mp3 = $this->ReadPropertyInteger("TargetHmIP_MP3");
        if ($mp3 > 0 && IPS_InstanceExists($mp3)) {
            $soundID = $item['HmIP_MP3_SoundID'] ?? 0;
            // L=100, DU=0 (Sekunden), DV=5 (Dauer), RTU=0, RTV=0, R=0 (Repeat), SL=SoundID
            $string = "L=100,DU=0,DV=5,RTU=0,RTV=0,R=0,SL=" . $soundID;
            $this->SendDebug("HmIP-MP3", "Spiele Sound $soundID auf Instanz $mp3", 0);
            @HM_WriteValueString($mp3, 'COMBINED_PARAMETER', $string);
        }
    }

    private function TriggerHomematicLEDs($item, $turnOff = false)
    {
        if (!($item['UseHmIP_LED'] ?? false)) return;
        
        $leds = json_decode($this->ReadPropertyString("TargetHmIP_LEDs"), true);
        if (!is_array($leds) || count($leds) == 0) return;

        if ($turnOff) {
            $string = 'L=0,DV=31,DU=2,RTV=0,RTU=0,C=0,CB=0,RTTOV=0,RTTOU=3';
        } else {
            $color = $item['HmIP_LED_Color'] ?? 4; 
            $mode = $item['HmIP_LED_Mode'] ?? 1; 
            $string = "L=100,DV=31,DU=2,RTV=0,RTU=0,C=$color,CB=$mode,RTTOV=0,RTTOU=3";
        }

        foreach ($leds as $led) {
            $instId = $led['InstanceID'] ?? 0;
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                $this->SendDebug("HmIP-LED", "Sende $string an LED Instanz $instId", 0);
                @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
            }
        }
    }

    public function TestHmIP_MP3(int $soundID)
    {
        $mp3 = $this->ReadPropertyInteger("TargetHmIP_MP3");
        if ($mp3 > 0 && IPS_InstanceExists($mp3)) {
            $string = "L=100,DU=0,DV=5,RTU=0,RTV=0,R=0,SL=" . $soundID;
            $this->SendDebug("HmIP-MP3-Test", "Spiele Sound $soundID auf Instanz $mp3", 0);
            @HM_WriteValueString($mp3, 'COMBINED_PARAMETER', $string);
            echo "Sound $soundID wurde an Instanz $mp3 gesendet.";
        } else {
            echo "Fehler: Keine gültige MP3-Gong Instanz ausgewählt!";
        }
    }

    public function TestHmIP_LED(int $color, int $durationSeconds)
    {
        $leds = json_decode($this->ReadPropertyString("TargetHmIP_LEDs"), true);
        if (!is_array($leds) || count($leds) == 0) {
            echo "Fehler: Keine LED-Instanzen ausgewählt!";
            return;
        }

        $string = "L=100,DV=$durationSeconds,DU=0,RTV=0,RTU=0,C=$color,CB=1,RTTOV=0,RTTOU=3";
        
        $count = 0;
        foreach ($leds as $led) {
            $instId = $led['InstanceID'] ?? 0;
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                $this->SendDebug("HmIP-LED-Test", "Sende $string an LED Instanz $instId", 0);
                @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
                $count++;
            }
        }
        echo "LED Test-Signal (Farbe $color, $durationSeconds Sekunden) an $count Instanz(en) gesendet.";
    }

    private function IsTriggered($currentVal, $triggerValStr)
    {
        if (is_bool($currentVal)) {
            $t = strtolower(trim((string)$triggerValStr));
            $target = ($t === 'true' || $t === '1' || $t === 'wahr');
            return $currentVal === $target;
        }
        return (string)$currentVal === (string)$triggerValStr;
    }
}
