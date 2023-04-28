<?php

declare(strict_types=1);

class FTP
{
    public $connection;

    public function __construct($host, $port)
    {
        $this->connection = ftp_connect($host, $port);
        return $this->connection;
    }

    public function chdir(string $dir): bool
    {
        return ftp_chdir($this->connection, $dir);
    }

    public function rawlist(string $dir = '.'): mixed
    {
        return ftp_rawlist($this->connection, $dir);
    }

    public function delete(string $path): bool
    {
        return ftp_delete($this->connection, $path);
    }

    public function is_dir(string $path): bool
    {
        if(@ftp_chdir($this->connection,$dir)) { 
            ftp_cdup($this->connection); 
            return true; 
        } else { 
            return false; 
        } 
    }

    public function pwd()
    {
        return ftp_pwd($this->connection); 
    }

    public function mkdir(string $dir)
    {
        return ftp_mkdir($this->connection, $dir);
    }

    public function put(string $remote_file, string $data): bool
    {
        return ftp_put($this->connection, $remote_file, $data);
    }

    public function filesize(string $path)
    {
        return ftp_size($this->connection, $path);
    }

    public function filemtime(string $path)
    {
        return ftp_mdtm($this->connection, $path);
    }

    public function login(string $username, string $password)
    {
        return ftp_login($this->connection, $username, $password);
    }

    public function disconnect()
    {
        return ftp_close($this->connection);
    }
}