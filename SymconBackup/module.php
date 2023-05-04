<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/vendor/autoload.php';
include_once __DIR__ . '/../libs/FTP.php';
include_once __DIR__ . '/../libs/FTPS.php';
use phpseclib3\Net\SFTP;

class SymconBackup extends IPSModule
{
    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('ConnectionType', 'SFTP');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 22);
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterPropertyString('Mode', 'FullBackup');
        $this->RegisterPropertyString('TargetDir', '');
        $this->RegisterPropertyString('DailyUpdateTime', '{"hour":3, "minute": 0, "second": 0}');
        $this->RegisterPropertyBoolean('EnableTimer', false);

        //Expert options
        $this->RegisterPropertyString('FilterDirectory', '');
        $this->RegisterPropertyInteger('SizeLimit', 20);

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
            $connection = $this->createConnection();
            if ($connection === false) {
                IPS_SemaphoreLeave('CreateBackup');
                return false;
            }

            //Set the base directory on the remote
            $baseDir = $this->ReadPropertyString('TargetDir');
            if ($baseDir != '') {
                $connection->chdir($baseDir);
                if (ltrim($connection->pwd(), '/') != $baseDir) {
                    $this->SendDebug('Remote dir', $connection->pwd(), 0);
                    $this->SendDebug('Custom dir', $baseDir, 0);
                    $this->SetStatus(202);
                    IPS_SemaphoreLeave('CreateBackup');
                    return false;
                }
            }
            $this->SetStatus(102);
            $this->SetBuffer('LastUpdateFormField', microtime(true));
            $this->UpdateFormField('Progress', 'visible', true);

            $dir = str_replace('\\', '/', IPS_GetKernelDir());
            $dir = rtrim($dir, '/');
            $this->UpdateFormField('Progress', 'caption', $dir);

            $mode = $this->ReadPropertyString('Mode');
            if ($mode == 'FullBackup') {
                $backupName = date('Y-m-d-H-i-s');
                $connection->mkdir($backupName);
                $connection->chdir($backupName);
            }

            //Go recursively through the directories and files and copy from local to remote
            $transferred = 0;
            if (!$this->copyLocalToRemote($dir, $connection, $mode, $transferred)) {
                IPS_SemaphoreLeave('CreateBackup');
                return false;
            } else {
                $this->SetValue('TransferredMegabytes', $transferred / 1024 / 1024);
            }

            if ($mode == 'IncrementalBackup') {
                //Compare the local files to the remote ones and delete remote files if it hasn't a local file
                if (!$this->compareFilesRemoteToLocal($connection->pwd(), $connection, '')) {
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

    public function UIChangePort(string $value)
    {
        if ($this->ReadPropertyInteger('Port') == 21 || $this->ReadPropertyInteger('Port') == 22) {
            switch ($value) {
                case 'SFTP':
                    $value = 22;
                    break;
                case 'FTP':
                case 'FTPS':
                    $value = 21;
                    break;
                default:
                    # code...
                    break;
            }
            $this->UpdateFormField('Port', 'value', $value);
        }
    }

    public function GetConfigurationForm()
    {
        $json = json_decode(file_get_contents(__DIR__ . '/form.json', true), true);
        $json['elements'][4]['visible'] = $this->ReadPropertyBoolean('EnableTimer');

        return json_encode($json);
    }

    public function UITestConnection()
    {
        $connection = $this->createConnection();
        if ($connection !== false) {
            echo $this->Translate('Connection is valid');
            $connection->disconnect();
            $this->UpdateFormField('Progress', 'visible', false);
            $this->SetStatus(102);
        }
    }

    private function copyLocalToRemote(string $dir, $connection, string $mode, & $transferred)
    {

        //get the local files
        $files = scandir($dir);
        $files = array_diff($files, ['..', '.']);

        foreach ($files as $file) {
            if ($this->fileFilter($file)) {
                continue;
            }
            if (is_dir($dir . '/' . $file)) {
                //Create directory and go to the deeper directory on remote
                $connection->mkdir($file);
                $connection->chdir($file);
                if (!$this->copyLocalToRemote($dir . '/' . $file, $connection, $mode, $transferred)) {
                    return false;
                }
                $connection->chdir('..');
            } else {
                $this->updateFormFieldByTime($dir . '/' . $file);

                //check if the file size is higher than the php_memory limit
                $filesize = filesize($dir . '/' . $file);
                if ($filesize > $this->ReadPropertyInteger('SizeLimit') * 1024 * 1024) {
                    $this->SendDebug('Index', sprintf('Skipping too big file... %s. Size: %s', $dir . '/' . $file, $this->formatBytes($filesize)), 0);
                }

                switch ($mode) {
                    case 'FullBackup':
                        try {
                            $connection->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                            $transferred += $connection->filesize($file);
                        } catch (\Throwable $th) {
                            $this->UpdateFormField('Progress', 'caption', $th->getMessage());
                            return false;
                        }
                        break;
                    case 'IncrementalBackup':
                        if (!$connection->file_exists($connection->pwd() . '/' . $file)) {
                            try {
                                $connection->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                                $transferred += $connection->filesize($file);
                            } catch (\Throwable $th) {
                                $this->UpdateFormField('Progress', 'caption', $th->getMessage());
                                return false;
                            }
                        } else {
                            if (filemtime($dir . '/' . $file) > $connection->filemtime($file)) {
                                try {
                                    $connection->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                                    $transferred += $connection->filesize($file);
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

    //Source: https://stackoverflow.com/questions/2510434/format-bytes-to-kilobytes-megabytes-gigabytes
    private function formatBytes($size, $precision = 2)
    {
        $base = log($size, 1024);
        $suffixes = ['B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];

        if ($size == 0) {
            return '0 B';
        } else {
            return round(pow(1024, $base - floor($base)), $precision) . ' ' . $suffixes[floor($base)];
        }
    }

    private function compareFilesRemoteToLocal($dir, $connection, string $slug)
    {
        $remoteList = $connection->rawlist($dir, false);
        foreach ($remoteList as $key => $file) {
            if ($key != '.' && $key != '..') {
                if ($connection->is_dir($dir . '/' . $file['filename'])) {
                    //Go to the deeper directory on the remote
                    $connection->chdir($file['filename']);
                    if (!$this->compareFilesRemoteToLocal($connection->pwd(), $connection, $slug . '/' . $file['filename'])) {
                        return false;
                    }
                } else {
                    //It is a file we need to check
                    $this->updateFormFieldByTime($dir . '/' . $file['filename']);
                    if (!file_exists(IPS_GetKernelDir() . '/' . $slug . '/' . $file['filename'])) {
                        //Delete file that is not on the local system
                        try {
                            $connection->delete($dir . '/' . $file['filename']);
                        } catch (\Throwable $th) {
                            $this->UpdateFormField('Progress', 'caption', $th->getMessage());
                            return false;
                        }
                    }
                }
            }
        }
        $connection->chdir('..');
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

    private function createConnection()
    {
        //Create Connection
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');
        $host = $this->ReadPropertyString('Host');
        $port = $this->ReadPropertyInteger('Port');
        $this->UpdateFormField('Progress', 'visible', true);
        $this->UpdateFormField('Progress', 'caption', $this->Translate('Wait on connection'));
        try {
            switch ($this->ReadPropertyString('ConnectionType')) {
                case 'SFTP':
                    $connection = new SFTP($host, $port);
                    break;
                case 'FTP':
                    $connection = new FTP($host, $port);
                    break;
                case 'FTPS':
                    $connection = new FTPS($host, $port);
                    break;
                default:
                    echo $this->Translate('The Connection Type is undefine');
                    $this->SetStatus(201);
                    break;
            }
        } catch (\Throwable $th) {
            //Throw than the initial of FTP or FTPS connection failed
            $this->UpdateFormField('Progress', 'caption', $this->Translate($th->getMessage()));
            echo $this->Translate($th->getMessage());
            $this->SetStatus(203);
            return false;
        }
        if ($connection->login($username, $password) === false) {
            echo $this->Translate('Connection is invalid.') . "\n" . $this->Translate('Username/Password is invalid');
            $this->SetStatus(201);
            return false;
        }
        return $connection;
    }
}