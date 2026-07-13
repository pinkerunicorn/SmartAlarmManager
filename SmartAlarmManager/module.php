<?php

declare(strict_types=1);

class SmartAlarmManager extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString("MonitoredVariables", "[]");
        $this->RegisterPropertyString("ActionProfiles", "[]");
        $this->RegisterPropertyInteger("EscalationTimeLvl2", 300);
        $this->RegisterPropertyInteger("EscalationTimeLvl3", 900);
        $this->RegisterPropertyInteger("TargetWebFront", 0);
        $this->RegisterPropertyInteger("TargetSMTP", 0);
        $this->RegisterPropertyInteger("TargetVestaboard", 0);
        $this->RegisterPropertyInteger("TargetSonos", 0);
        $this->RegisterPropertyInteger("TargetHmIP_MP3", 0);
        $this->RegisterPropertyString("TargetHmIP_LEDs", "[]");
        $this->RegisterPropertyString("TargetHmIP_Sirens", "[]");
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

    private function GetActionProfile($profileID)
    {
        $profiles = json_decode($this->ReadPropertyString("ActionProfiles"), true);
        if (is_array($profiles)) {
            foreach ($profiles as $p) {
                if (($p['ProfileID'] ?? '') === $profileID) {
                    return $p;
                }
            }
        }
        return [];
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
                    
                    // Also turn off LEDs and Sirens if it was an Info or Alarm that just got resolved by the sensor
                    $profile = $this->GetActionProfile($item['ProfileID'] ?? '');
                    $this->TriggerHomematicLEDs($profile, true);
                    $this->TriggerHomematicSirens($profile, true);
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
        $profile = $this->GetActionProfile($item['ProfileID'] ?? '');

        if ($type == 1) {
            // Info / Doorbell (Fire and Forget)
            $this->LogMessage("Info/Event ausgelöst: " . $msg, KL_NOTIFY);
            $this->SendDebug("Trigger", "Info/Event: " . $msg, 0);
            $this->TriggerInfo($profile, $msg);
            
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
                    "item" => $item,
                    "profile" => $profile
                ];
                $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                
                $this->LogMessage("ALARM ausgelöst (Stufe 1): " . $msg, KL_WARNING);
                $this->SendDebug("Trigger", "Alarm Stufe 1: " . $msg, 0);
                
                $this->TriggerLevel1($profile, $msg);
                
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
                    $profile = $alarms[$vid]['profile'] ?? [];
                    $this->TriggerHomematicLEDs($profile, true); // Turn off LEDs
                    $this->TriggerHomematicSirens($profile, true); // Turn off Sirens
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
                    $profile = $alarm['profile'] ?? [];
                    $this->TriggerHomematicLEDs($profile, true); // Turn off LEDs
                    $this->TriggerHomematicSirens($profile, true); // Turn off Sirens
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
            return;
        }

        $now = time();
        $changed = false;
        $lvl2_time = $this->ReadPropertyInteger("EscalationTimeLvl2");
        $lvl3_time = $this->ReadPropertyInteger("EscalationTimeLvl3");

        foreach ($alarms as $vid => &$alarm) {
            $elapsed = $now - $alarm['timestamp'];
            $msg = $alarm['item']['Message'] ?? "Alarm";
            $profile = $alarm['profile'] ?? [];

            if ($alarm['level'] == 1 && $elapsed >= $lvl2_time) {
                $alarm['level'] = 2;
                $changed = true;
                $this->LogMessage("Alarm ESKALATION (Stufe 2): " . $msg, KL_WARNING);
                $this->SendDebug("Escalation", "Stufe 2: " . $msg, 0);
                $this->TriggerLevel2($profile, $msg);
            }

            if ($alarm['level'] == 2 && $elapsed >= $lvl3_time) {
                $alarm['level'] = 3;
                $changed = true;
                $this->LogMessage("VOLLALARM (Stufe 3): " . $msg, KL_ERROR);
                $this->SendDebug("Escalation", "Stufe 3: " . $msg, 0);
                $this->TriggerLevel3($profile, $msg);
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
            // Keep status at 1 if Info was triggered recently, else 0
            if ($this->GetValue("SystemStatus") > 1) {
                $this->SetValue("SystemStatus", 0);
            }
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

    private function TriggerLevel1($profile, $message)
    {
        if ($profile['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "Alarm!", $message, "", 0);
                @WFC_SendNotification($webfront, "Alarm!", $message, "Warning", 0);
            }
        }
        
        if ($profile['UseSonos'] ?? true) {
            $this->TriggerSonos($message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
    }

    private function TriggerLevel2($profile, $message)
    {
        if ($profile['UseVestaboard'] ?? true) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    @VESTA_SendMessage($vesta, "ALARM:\n" . $message);
                }
            }
        }

        if ($profile['UseEmail'] ?? true) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    $this->SendDebug("Email", "Versuche E-Mail zu senden an: " . $email, 0);
                    $result = @SMTP_SendMailEx($smtp, $email, "SmartHome Alarm Stufe 2", "Folgender Alarm wurde ausgelöst und noch nicht quittiert:\n\n" . $message);
                    if ($result === false) {
                        $this->LogMessage("Fehler beim E-Mail Versand! Bitte prüfe die Einstellungen der SMTP-Instanz #$smtp", KL_ERROR);
                    }
                }
            }
        }
        
        if ($profile['UseSonos'] ?? true) {
            $this->TriggerSonos("Achtung, Alarm: " . $message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
    }

    private function TriggerLevel3($profile, $message)
    {
        if ($profile['UseVestaboard'] ?? true) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    @VESTA_SendMessage($vesta, "!!! VOLLALARM !!!\n" . $message);
                }
            }
        }
        
        if ($profile['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "VOLLALARM", $message, "", 0);
                @WFC_SendNotification($webfront, "VOLLALARM", $message, "Alert", 0);
            }
        }
        
        if ($profile['UseSonos'] ?? true) {
            $this->TriggerSonos("Vollalarm: " . $message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
    }

    private function TriggerInfo($profile, $message)
    {
        if ($profile['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "Info", $message, "", 0);
                @WFC_SendNotification($webfront, "Info", $message, "Information", 0);
            }
        }
        
        if ($profile['UseVestaboard'] ?? true) {
            $vesta = $this->ReadPropertyInteger("TargetVestaboard");
            if ($vesta > 0 && IPS_InstanceExists($vesta)) {
                if (function_exists('VESTA_SendMessage')) {
                    @VESTA_SendMessage($vesta, $message);
                }
            }
        }
        
        if ($profile['UseEmail'] ?? true) {
            $smtp = $this->ReadPropertyInteger("TargetSMTP");
            $email = trim($this->ReadPropertyString("EmailAddress"));
            if ($smtp > 0 && IPS_InstanceExists($smtp)) {
                if ($email != "") {
                    @SMTP_SendMailEx($smtp, $email, "SmartHome Info / Event", $message);
                }
            }
        }
        
        if ($profile['UseSonos'] ?? true) {
            $this->TriggerSonos($message);
        }
        
        $this->TriggerHomematicMP3($profile);
        $this->TriggerHomematicLEDs($profile);
        $this->TriggerHomematicSirens($profile);
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
            }
        }
    }

    private function TriggerHomematicMP3($profile)
    {
        if (!($profile['UseHmIP_MP3'] ?? false)) return;
        
        $mp3 = $this->ReadPropertyInteger("TargetHmIP_MP3");
        if ($mp3 > 0 && IPS_InstanceExists($mp3)) {
            $soundStr = $profile['MP3_Sounds'] ?? "1";
            $vol = $profile['MP3_Volume'] ?? 100;
            $rep = $profile['MP3_Repeat'] ?? 0;
            // L=vol, DU=0 (Sekunden), DV=0, RTU=0, RTV=0, R=rep, SL=soundStr
            $string = "L=$vol,DU=0,DV=0,RTU=0,RTV=0,R=$rep,SL=" . $soundStr;
            $this->SendDebug("HmIP-MP3", "Spiele Sounds $soundStr auf Instanz $mp3", 0);
            @HM_WriteValueString($mp3, 'COMBINED_PARAMETER', $string);
        }
    }

    private function TriggerHomematicLEDs($profile, $turnOff = false)
    {
        if (!$turnOff && !($profile['UseHmIP_LED'] ?? false)) return;
        
        $leds = json_decode($this->ReadPropertyString("TargetHmIP_LEDs"), true);
        if (!is_array($leds) || count($leds) == 0) return;

        $color = $profile['LED_Color'] ?? 4; 
        $mode = $profile['LED_Mode'] ?? 1; 
        $bright = $profile['LED_Brightness'] ?? 100;

        foreach ($leds as $led) {
            $instId = $led['InstanceID'] ?? 0;
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                $isMP3P = $led['IsMP3P'] ?? false;
                
                if ($isMP3P) {
                    if ($turnOff) {
                        $string = 'L=100,DV=10,DU=0,RTV=0,RTU=1,C=0';
                    } else {
                        $string = "L=$bright,DV=31,DU=2,RTV=0,RTU=1,C=$color";
                    }
                } else {
                    if ($turnOff) {
                        $string = 'L=0,DV=31,DU=2,RTV=0,RTU=0,C=0,CB=0,RTTOV=0,RTTOU=3';
                    } else {
                        $string = "L=$bright,DV=31,DU=2,RTV=0,RTU=0,C=$color,CB=$mode,RTTOV=0,RTTOU=3";
                    }
                }

                $this->SendDebug("HmIP-LED", "Sende $string an LED Instanz $instId", 0);
                @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
            }
        }
    }

    private function TriggerHomematicSirens($profile, $turnOff = false)
    {
        if (!$turnOff && !($profile['UseHmIP_Siren'] ?? false)) return;
        
        $sirens = json_decode($this->ReadPropertyString("TargetHmIP_Sirens"), true);
        if (!is_array($sirens) || count($sirens) == 0) return;

        $ac = $profile['Siren_Acoustic'] ?? 1;
        $opt = $profile['Siren_Optical'] ?? 1;

        // ASIR-O uses Acoustic (A), Optical (O), Duration Value (DV), Duration Unit (DU)
        // O=0 (Off), 1=Blink, 2=Flash
        // A=0 (Off), 1=Freq rising, 2=Freq falling, 3=rising/falling etc.
        // Actually for ASIR, it's L=... DU=... DV=... for combined but ASIR has dedicated COMBINED_PARAMETER fields:
        // Wait, ASIR-O COMBINED_PARAMETER is typically "O=1,A=1,DV=...". 
        // Let's use the standard ASIR combined param: O=$opt, A=$ac, DV=31, DU=2
        // If turnOff is true, O=0, A=0, DV=31, DU=2

        if ($turnOff) {
            $string = "O=0,A=0,DV=31,DU=2";
        } else {
            $string = "O=$opt,A=$ac,DV=31,DU=2";
        }

        foreach ($sirens as $s) {
            $instId = $s['InstanceID'] ?? 0;
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                $this->SendDebug("HmIP-Siren", "Sende $string an Sirenen Instanz $instId", 0);
                @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
            }
        }
    }

    public function TestHmIP_MP3(string $soundStr, int $vol, int $rep)
    {
        $mp3 = $this->ReadPropertyInteger("TargetHmIP_MP3");
        if ($mp3 > 0 && IPS_InstanceExists($mp3)) {
            $string = "L=$vol,DU=0,DV=0,RTU=0,RTV=0,R=$rep,SL=" . $soundStr;
            $this->SendDebug("HmIP-MP3-Test", "Spiele Sounds $soundStr auf Instanz $mp3", 0);
            @HM_WriteValueString($mp3, 'COMBINED_PARAMETER', $string);
            echo "Sound $soundStr wurde an Instanz $mp3 gesendet.";
        } else {
            echo "Fehler: Keine gültige MP3-Gong Instanz ausgewählt!";
        }
    }

    public function TestHmIP_LED(int $color, int $bright, int $durationSeconds)
    {
        $leds = json_decode($this->ReadPropertyString("TargetHmIP_LEDs"), true);
        if (!is_array($leds) || count($leds) == 0) {
            echo "Fehler: Keine LED-Instanzen ausgewählt!";
            return;
        }

        $count = 0;
        foreach ($leds as $led) {
            $instId = $led['InstanceID'] ?? 0;
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                $isMP3P = $led['IsMP3P'] ?? false;
                
                if ($isMP3P) {
                    $string = "L=$bright,DV=$durationSeconds,DU=0,RTV=0,RTU=1,C=$color";
                } else {
                    $string = "L=$bright,DV=$durationSeconds,DU=0,RTV=0,RTU=0,C=$color,CB=1,RTTOV=0,RTTOU=3";
                }
                
                $this->SendDebug("HmIP-LED-Test", "Sende $string an LED Instanz $instId", 0);
                @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
                $count++;
            }
        }
        echo "LED Test-Signal (Farbe $color, Helligkeit $bright, $durationSeconds Sekunden) an $count Instanz(en) gesendet.";
    }

    public function TestHmIP_Siren(int $ac, int $opt, int $durationSeconds)
    {
        $sirens = json_decode($this->ReadPropertyString("TargetHmIP_Sirens"), true);
        if (!is_array($sirens) || count($sirens) == 0) {
            echo "Fehler: Keine Sirenen-Instanzen ausgewählt!";
            return;
        }

        $string = "O=$opt,A=$ac,DV=$durationSeconds,DU=0";

        $count = 0;
        foreach ($sirens as $s) {
            $instId = $s['InstanceID'] ?? 0;
            if ($instId > 0 && IPS_InstanceExists($instId)) {
                $this->SendDebug("HmIP-Siren-Test", "Sende $string an Sirenen Instanz $instId", 0);
                @HM_WriteValueString($instId, 'COMBINED_PARAMETER', $string);
                $count++;
            }
        }
        echo "Sirenen Test-Signal (A=$ac, O=$opt, $durationSeconds Sekunden) an $count Instanz(en) gesendet.";
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
