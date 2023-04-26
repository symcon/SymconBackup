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

        $this->RegisterTimer('UpdateBackup', 0, 'SB_CreateBackup($_IPS[\'TARGET\']);');
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
            //Create Connection
            $sftp = new SFTP($this->ReadPropertyString('Host'));
            if (!$sftp->login($this->ReadPropertyString('Username'), $this->ReadPropertyString('Password'))) {
                $this->SetStatus(201);
                IPS_SemaphoreLeave('CreateBackup');
                return false;
            }

            //Set the base directory on the remote
            $baseDir = $this->ReadPropertyString('TargetDir');
            if ($baseDir != '') {
                $sftp->chdir($baseDir);
                if (ltrim($sftp->pwd(), '/') != $baseDir) {
                    $this->SetStatus(202);
                    IPS_SemaphoreLeave('CreateBackup');
                    return false;
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

            //Go recursively through the directories and files and copy from local to remote
            $transferred = 0;
            if (!$this->copyLocalToRemote(IPS_GetKernelDir(), $sftp, $mode, $transferred)) {
                IPS_SemaphoreLeave('CreateBackup');
                return false;
            } else {
                $this->SetValue('TransferredMegabytes', $transferred);
            }

            if ($mode == 'IncrementalBackup') {
                //Compare the local files to the remote ones and delete remote files if it hasn't a local file
                if (!$this->compareFilesRemoteToLocal($sftp->pwd(), $sftp, '')) {
                    IPS_SemaphoreLeave('CreateBackup');
                    return false;
                }
            }

            $this->UpdateFormField('Progress', 'visible', false);
            $this->SetValue('LastFinishedBackup', time());
            $this->setNewTimer();

            IPS_SemaphoreLeave('CreateBackup');
        } else {
            echo $this->Translate('An other Backup is already running');
        }

        return true;
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

    private function copyLocalToRemote(string $dir, SFTP $sftp, string $mode, &$transferred)
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
                //Create directory and go to the deeper directory on remote
                $sftp->mkdir($file);
                $sftp->chdir($file);
                if (!$this->copyLocalToRemote($dir . '/' . $file, $sftp, $mode, $transferred)) {
                    return false;
                }
                $sftp->chdir('..');
            } else {
                $this->updateFormFieldByTime($dir . '/' . $file);
                switch ($mode) {
                    case 'FullBackup':
                        try {
                            $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                            $transferred += $sftp->filesize($file);
                        } catch (\Throwable $th) {
                            $this->UpdateFormField('Progress', 'caption', $th->getMessage());
                            return false;
                        }
                        break;
                    case 'IncrementalBackup':
                        if (!$sftp->file_exists($sftp->pwd() . '/' . $file)) {
                            try {
                                $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                                $transferred += $sftp->filesize($file);
                            } catch (\Throwable $th) {
                                $this->UpdateFormField('Progress', 'caption', $th->getMessage());
                                return false;
                            }
                        } else {
                            if (filemtime($dir . '/' . $file) > $sftp->filemtime($file)) {
                                try {
                                    $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                                    $transferred += $sftp->filesize($file);
                                } catch (\Throwable $th) {
                                    $this->UpdateFormField('Progress', 'caption', $th->getMessage());
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

    private function compareFilesRemoteToLocal($dir, $sftp, string $slug)
    {
        $remoteList = $sftp->rawlist($dir, false);
        foreach ($remoteList as $key => $file) {
            if ($key != '.' && $key != '..') {
                if ($sftp->is_dir($dir . '/' . $file['filename'])) {
                    //Go to the deeper directory on the remote
                    $sftp->chdir($file['filename']);
                    if (!$this->compareFilesRemoteToLocal($sftp->pwd(), $sftp, $slug . '/' . $file['filename'])) {
                        return false;
                    }
                } else {
                    //It is a file we need to check
                    $this->updateFormFieldByTime($dir . '/' . $file['filename']);
                    if (!file_exists(IPS_GetKernelDir() . '/' . $slug . '/' . $file['filename'])) {
                        //Delete file that is not on the local system
                        try {
                            $sftp->delete($dir . '/' . $file['filename']);
                        } catch (\Throwable $th) {
                            $this->UpdateFormField('Progress', 'caption', $th->getMessage());
                            return false;
                        }
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