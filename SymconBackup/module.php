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

        $this->RegisterPropertyString('IPAddress', '');
        $this->RegisterPropertyString('Username', '');
        $this->RegisterPropertyString('Password', '');

        $this->RegisterPropertyString('Mode', 'FullBackup');
        $this->RegisterPropertyString('BaseDirection', '');
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
    }

    public function CreateBackup()
    {
        $server = $this->ReadPropertyString('IPAddress');
        $username = $this->ReadPropertyString('Username');
        $password = $this->ReadPropertyString('Password');

        //create Connection
        $sftp = new SFTP($server);
        if (!$sftp->login($username, $password)) {
            $this->SetStatus(201);
            return;
        }

        //Set the base direction on the server
        $baseDir = $this->ReadPropertyString('BaseDirection');
        if ($baseDir != '') {
            $sftp->chdir($baseDir);
            if ($sftp->pwd() != $baseDir) {
                $this->SetStatus(202);
                return;
            }
        }
        $this->SetStatus(102);
        $dir = IPS_GetKernelDir();
        $this->UpdateFormField('Progress', 'visible', true);
        $this->UpdateFormField('Progress', 'caption', '');

        $mode = $this->ReadPropertyString('Mode');
        if ($mode == 'FullBackup') {
            $backupName = date('d-m-Y-H-i-s');
            $sftp->mkdir($backupName);
            $sftp->chdir($backupName);
        }
        //get recursive through the dirs and files and copy from local to remote
        if (!$this->copyFilesToServer($dir, $sftp, $mode)) {
            return;
        }

        if ($mode == 'UpdateBackup') {
            $sftp->chdir($baseDir);
            //compare the local files to the server and delete serverfiles if it hasn't a local file
            if (!$this->compareFilesServerToLocal($baseDir, $sftp, '')) {
                return false;
            }
        }

        $this->UpdateFormField('Progress', 'visible', false);
    }

    private function copyFilesToServer($dir, $sftp, $mode)
    {

        //get the local files
        $files = scandir($dir);
        $files = array_diff($files, ['..', '.']);
        foreach ($files as $file) {
            if (is_dir($dir . '/' . $file)) {
                //Create and go to the deeper dir on server
                $sftp->mkdir($file);
                $sftp->chdir($file);
                //go deeper
                if (!$this->copyFilesToServer($dir . '/' . $file, $sftp, $mode)) {
                    return false;
                }
                $sftp->chdir('..');
            } else {
                $this->UpdateFormField('Progress', 'caption', $dir . '/' . $file);
                switch ($mode) {
                    case 'FullBackup':
                        //copy the files
                        try {
                            $sftp->put($file, $dir . '/' . $file, SFTP::SOURCE_LOCAL_FILE);
                        } catch (\Throwable $th) {
                            return false;
                        }
                        break;
                    case 'UpdateBackup':
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
                    $this->UpdateFormField('Progress', 'caption', $dir . '/' . $file['filename']);
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
}