<?php

declare(strict_types=1);

class FTP
{
    public $connection;

    public function __construct($host, $port)
    {
        $this->connection = ftp_connect($host, $port);
        if (!$this->connection) {
            throw new ErrorException('Error: Host/Port is invalid', 10060);
        }
    }

    public function chdir(string $dir): bool
    {
        $result = @ftp_chdir($this->connection, $dir);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function rawlist(string $dir = '.'): mixed
    {
        $result = @ftp_rawlist($this->connection, $dir);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function delete(string $path): bool
    {
        $result = @ftp_delete($this->connection, $path);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function is_dir(string $path): bool
    {
        //Found there: https://gist.github.com/Dare-NZ/5523650#file-is_dir-php-L37
        if (@ftp_chdir($this->connection, $dir)) {
            ftp_cdup($this->connection);
            return true;
        } else {
            return false;
        }
    }

    public function pwd()
    {
        $result = @ftp_pwd($this->connection);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function mkdir(string $dir)
    {
        $result = @ftp_mkdir($this->connection, $dir);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function put(string $remote_file, string $data): bool
    {
        $result = @ftp_put($this->connection, $remote_file, $data);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function filesize(string $path)
    {
        $result = @ftp_size($this->connection, $path);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function filemtime(string $path)
    {
        $result = @ftp_mdtm($this->connection, $path);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function login(string $username, string $password)
    {
        $result = @ftp_login($this->connection, $username, $password);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }

    public function disconnect()
    {
        $result = @ftp_close($this->connection);
        if ($result === false) {
            throw new Exception(error_get_last()['message']);
        }
        return $result;
    }
}