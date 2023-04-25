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
        $this->RegisterPropertyString('DailyUpdateTime', '[]');
        $this->RegisterPropertyString('FilterDirectory', '');

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
        $server = $this->ReadPropertyString('Host');
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        //create Connection
        $sftp = new SFTP($server);
        if (!$sftp->login($username, $password)) {
            $this->SetStatus(201);
            return;
        }

        //Set the base direction on the server
        $baseDir = $this->ReadPropertyString('TargetDir');
        if ($baseDir != '') {
            $sftp->chdir($baseDir);
            if (ltrim($sftp->pwd(), '/') != $baseDir) {
                $this->SetStatus(202);
                return;
            }
        }
        $this->SetStatus(102);
        $this->SetBuffer('LastUpdateFormField', time());
        $this->SendDebug('Buffer', $this->GetBuffer('LastUpdateFormField'), 0);
        $this->UpdateFormField('Progress', 'visible', true);
        $this->UpdateFormField('Progress', 'caption', IPS_GetKernelDir());

        $mode = $this->ReadPropertyString('Mode');
        if ($mode == 'FullBackup') {
            $backupName = date('Y-m-d-H-i-s');
            $sftp->mkdir($backupName);
            $sftp->chdir($backupName);
        }
        //get recursive through the dirs and files and copy from local to remote
        if (!$this->copyLocalToServer(IPS_GetKernelDir(), $sftp, $mode)) {
            return;
        }

        if ($mode == 'IncrementalBackup') {
            //compare the local files to the server and delete serverfiles if it hasn't a local file
            if (!$this->compareFilesServerToLocal($sftp->pwd(), $sftp, '')) {
                return false;
            }
        }

        $this->UpdateFormField('Progress', 'visible', false);
        $this->setNewTimer();
    }

    private function copyLocalToServer($dir, $sftp, $mode)
    {

        //get the local files
        $files = scandir($dir);
        $files = array_diff($files, ['..', '.']);
        foreach ($files as $file) {
            if ($this->fileFilter($file)) {
                continue;
            }
            if (is_dir($dir . '/' . $file)) {
                //Create and go to the deeper dir on server
                $sftp->mkdir($file);
                $sftp->chdir($file);
                //go deeper
                if (!$this->copyLocalToServer($dir . '/' . $file, $sftp, $mode)) {
                    return false;
                }
                $sftp->chdir('..');
            } else {
                $this->UpdateFormFieldByTime($dir . '/' . $file);
                switch ($mode) {
                    case 'FullBackup':
                        //copy the files
                        try {
                            $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                        } catch (\Throwable $th) {
                            return false;
                        }
                        break;
                    case 'IncrementalBackup':
                        if (!$sftp->file_exists($sftp->pwd() . '/' . $file)) {
                            try {
                                $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                            } catch (\Throwable $th) {
                                return false;
                            }
                        } else {
                            if (filemtime($dir . '/' . $file) > $sftp->filemtime($file)) {
                                try {
                                    $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
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
                    $this->UpdateFormFieldByTime($dir . '/' . $file['filename']);
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

    private function UpdateFormFieldByTime(string $dir)
    {
        $lastBuffer = $this->GetBuffer('LastUpdateFormField');
        if (time() - $lastBuffer > 2) {
            $this->UpdateFormField('Progress', 'caption', $dir);
            $this->SetBuffer('LastUpdateFormField', time());
        }
    }

    private function setNewTimer()
    {
        //Time for the next update
        $time = json_decode($this->ReadPropertyString('DailyUpdateTime'), true);
        if ($time) {
            $next = strtotime('tomorrow ' . $time['hour'] . ':' . $time['minute'] . ':' . $time['second']);
            $this->SetTimerInterval('UpdateBackup', ($next - time()) * 1000);
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