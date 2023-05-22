<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class SymconBackupValidationTest extends TestCaseSymconValidation
{
    public function testValidateSymconBackup(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateSymconBackupModule(): void
    {
        $this->validateModule(__DIR__ . '/../Backup');
    }
}