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
        $this->RegisterPropertyString("EmailAddress", "");
        
        $this->RegisterTimer("EscalationTimer", 0, 'SAM_CheckEscalation($_IPS[\'TARGET\']);');
        
        $this->SetBuffer("ActiveAlarms", "{}");
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
            if (($item['VariableID'] ?? 0) == $SenderID) {
                $triggerVal = $item['TriggerValue'] ?? 'true';
                if ($this->IsTriggered($currentVal, $triggerVal)) {
                    $this->HandleTrigger($item);
                }
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
                
                // Ensure Escalation Timer runs
                $this->SetTimerInterval("EscalationTimer", 10000);
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
                    unset($alarms[$vid]);
                    $this->SetBuffer("ActiveAlarms", json_encode($alarms));
                }
                
                if (empty($alarms)) {
                    $this->SetTimerInterval("EscalationTimer", 0);
                }
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
        }
    }

    private function TriggerLevel1($item)
    {
        $message = $item['Message'] ?? "Alarm";
        if ($item['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "Alarm!", $message, "", 0);
            }
        }
        
        if ($item['UseSonos'] ?? true) {
            $this->TriggerSonos($message);
        }
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
            }
        }
        
        if ($item['UseSonos'] ?? true) {
            $this->TriggerSonos("Vollalarm: " . $message);
        }
    }

    private function TriggerInfo($item)
    {
        $message = $item['Message'] ?? "Info";
        
        if ($item['UseWebFront'] ?? true) {
            $webfront = $this->ReadPropertyInteger("TargetWebFront");
            if ($webfront > 0 && IPS_InstanceExists($webfront)) {
                @WFC_PushNotification($webfront, "Info", $message, "", 0);
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
    }
    
    private function TriggerSonos($message)
    {
        $sonos = $this->ReadPropertyInteger("TargetSonos");
        if ($sonos > 0 && IPS_InstanceExists($sonos)) {
            // Check for known Google TTS / Sonos functions
            if (function_exists('GSTTS_PlayText')) {
                $this->SendDebug("Sonos", "GSTTS_PlayText: " . $message, 0);
                @GSTTS_PlayText($sonos, $message);
            } elseif (function_exists('SNS_PlayText')) {
                $this->SendDebug("Sonos", "SNS_PlayText: " . $message, 0);
                @SNS_PlayText($sonos, $message);
            } else {
                $this->LogMessage("Sonos nicht angesteuert: Weder GSTTS_PlayText noch SNS_PlayText Funktion gefunden.", KL_WARNING);
            }
        }
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
