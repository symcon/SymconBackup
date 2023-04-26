<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';
use phpseclib3\Net\SFTP;

class SymconBackup extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterPropertyString('Mode', 'FullBackup');
        $this->RegisterPropertyString('TargetDir', '');
        $this->RegisterPropertyString('DailyUpdateTime', '{"hour":3, "minute": 0, "second": 0}');
        $this->RegisterPropertyBoolean('EnableTimer', false);
        $this->RegisterPropertyString('FilterDirectory', '');

        if (!IPS_VariableProfileExists('Megabytes.Backup')) {
            //Profil erstellen
            IPS_CreateVariableProfile('Megabytes.Backup', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('Megabytes.Backup', 2);
            IPS_SetVariableProfileText('Megabytes.Backup', '', ' MB');
        }

        $this->RegisterVariableInteger('LastFinishedBackup', $this->Translate('Last Finished Backup'), '~UnixTimestamp', 0);
        $this->RegisterVariableFloat('TransferredMegabytes', $this->Translate('Transferred Megabytes'), 'Megabytes.Backup', 0);

        $this->RegisterTimer('UpdateBackup', 0, 'SB_CreateBackup($_IPS[\'TARGET\'])');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->setNewTimer();
    }

    public function CreateBackup()
    {
        if (IPS_SemaphoreEnter('CreateBackup', 1000)) {
            $server = $this->ReadPropertyString('Host');
            $username = $this->ReadPropertyString('Username');
            $password = $this->ReadPropertyString('Password');

            //create Connection
            $sftp = new SFTP($server);
            if (!$sftp->login($username, $password)) {
                $this->SetStatus(201);
                IPS_SemaphoreLeave('CreateBackup');
                return;
            }

            //Set the base direction on the server
            $baseDir = $this->ReadPropertyString('TargetDir');
            if ($baseDir != '') {
                $sftp->chdir($baseDir);
                if (ltrim($sftp->pwd(), '/') != $baseDir) {
                    $this->SetStatus(202);
                    IPS_SemaphoreLeave('CreateBackup');
                    return;
                }
            }
            $this->SetStatus(102);
            $this->SetBuffer('LastUpdateFormField', microtime(true));
            $this->UpdateFormField('Progress', 'visible', true);
            $this->UpdateFormField('Progress', 'caption', IPS_GetKernelDir());

            $mode = $this->ReadPropertyString('Mode');
            if ($mode == 'FullBackup') {
                $backupName = date('Y-m-d-H-i-s');
                $sftp->mkdir($backupName);
                $sftp->chdir($backupName);
            }
            //get recursive through the dirs and files and copy from local to remote
            $transferred = 0;
            
            if ($this->copyLocalToServer(IPS_GetKernelDir(), $sftp, $mode, $transferred)) {
                IPS_SemaphoreLeave('CreateBackup');
                return false;
            } else {
                $this->SetValue('TransferredMegabytes', $transferred);
            }

            if ($mode == 'IncrementalBackup') {
                //compare the local files to the server and delete serverfiles if it hasn't a local file
                if (!$this->compareFilesServerToLocal($sftp->pwd(), $sftp, '')) {
                    IPS_SemaphoreLeave('CreateBackup');
                    return false;
                }
            }
            $this->UpdateFormField('Progress', 'visible', false);
            $this->SetValue('LastFinishedBackup', time());
            $this->setNewTimer();

            IPS_SemaphoreLeave('CreateBackup');
        } else {
            echo $this->Translate('An other Backup is create');
        }
    }

    public function UIEnableTimer(bool $value)
    {
        $this->UpdateFormField('DailyUpdateTime', 'visible', $value);
    }

    public function GetConfigurationForm()
    {
        $json = json_decode(file_get_contents(__DIR__ . '/form.json', true), true);
        $json['elements'][3]['visible'] = $this->ReadPropertyBoolean('EnableTimer');

        return json_encode($json);
    }

    private function copyLocalToServer(string $dir, SFTP $sftp, string $mode, &$transferred)
    {

        //get the local files
        $files = scandir($dir);
        $files = array_diff($files, ['..', '.']);

        foreach ($files as $file) {
            $this->SendDebug('File', $dir.'/'.$file, 0);
            if ($this->fileFilter($file)) {
                continue;
            }
            if (is_dir($dir . '/' . $file)) {
                //Create and go to the deeper dir on server
                $sftp->mkdir($file);
                $sftp->chdir($file);
                //go deeper
                $deeper = $this->copyLocalToServer($dir . '/' . $file, $sftp, $mode, $transferred);
                if ($deeper === false) {
                    return false;
                }
                $sftp->chdir('..');
            } else {
                $this->updateFormFieldByTime($dir . '/' . $file);
                switch ($mode) {
                    case 'FullBackup':
                        //copy the files
                        try {
                            $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                            $transferred += $sftp->filesize($file);
                        } catch (\Throwable $th) {
                            return false;
                        }
                        break;
                    case 'IncrementalBackup':
                        if (!$sftp->file_exists($sftp->pwd() . '/' . $file)) {
                            try {
                                $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                                $transferred += $sftp->filesize($file);
                            } catch (\Throwable $th) {
                                return false;
                            }
                        } else {
                            if (filemtime($dir . '/' . $file) > $sftp->filemtime($file)) {
                                try {
                                    $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                                    $transferred += $sftp->filesize($file);
                                } catch (\Throwable $th) {
                                    return false;
                                }
                            }
                        }
                        break;
                }
            }
        }
        return true;
    }

    private function compareFilesServerToLocal($dir, $sftp, string $slug)
    {
        $serverList = $sftp->rawlist($dir, false);

        foreach ($serverList as $key => $file) {
            if ($key != '.' && $key != '..') {
                if ($sftp->is_dir($dir . '/' . $file['filename'])) {
                    //Create and go to the deeper dir on server
                    $sftp->chdir($file['filename']);
                    //go deeper
                    if (!$this->compareFilesServerToLocal($sftp->pwd(), $sftp, $slug . '/' . $file['filename'])) {
                        return false;
                    }
                } else {
                    //Its a file
                    $this->updateFormFieldByTime($dir . '/' . $file['filename']);
                    if (!file_exists(IPS_GetKernelDir() . '/' . $slug . '/' . $file['filename'])) {
                        //delete file that is not on the local system
                        return $sftp->delete($dir . '/' . $file['filename']);
                    }
                }
            }
        }
        $sftp->chdir('..');
        return true;
    }

    private function updateFormFieldByTime(string $dir)
    {
        $lastBuffer = $this->GetBuffer('LastUpdateFormField');
        if (microtime(true) - $lastBuffer > 0.500) {
            $this->UpdateFormField('Progress', 'caption', $dir);
            $this->SetBuffer('LastUpdateFormField', microtime(true));
        }
    }

    private function setNewTimer()
    {
        if ($this->ReadPropertyBoolean('EnableTimer')) {
            //Time for the next update
            $time = json_decode($this->ReadPropertyString('DailyUpdateTime'), true);
            if ($time) {
                $next = strtotime('tomorrow ' . $time['hour'] . ':' . $time['minute'] . ':' . $time['second']);
                $this->SetTimerInterval('UpdateBackup', ($next - time()) * 1000);
            }
        } else {
            $this->SetTimerInterval('UpdateBackup', 0);
        }
    }

    private function fileFilter(string $file)
    {

        //Any non UTF-8 filename will break everything. Therefore we need to filter them
        //See: https://stackoverflow.com/a/1523574/10288655 (Regex seems to be faster than mb_check_encoding)
        if (!preg_match('%^(?:
                [\x09\x0A\x0D\x20-\x7E]            # ASCII
              | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
              | \xE0[\xA0-\xBF][\x80-\xBF]         # excluding overlongs
              | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
              | \xED[\x80-\x9F][\x80-\xBF]         # excluding surrogates
              | \xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
              | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
              | \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
              )*$%xs', $file)) {
            return true;
        }

        //Always compare lower case
        $file = mb_strtolower($file);

        //Check against file filter
        $filters = json_decode($this->ReadPropertyString('FilterDirectory'), true);
        $filters = array_column($filters, 'Directory');
        if (count($filters) != 0) {
            foreach ($filters as $filter) {
                if ($file == $filter) {
                    return true;
                }
            }
        }
        return false;
    }
}