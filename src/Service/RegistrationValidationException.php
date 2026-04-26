<?php

namespace App\Service;

class RegistrationValidationException extends \RuntimeException
{
    /**
     * @param array<string, string> $fieldErrors
     */
    public function __construct(private readonly array $fieldErrors)
    {
        parent::__construct('Registration data is invalid.');
    }

    /**
     * @return array<string, string>
     */
    public function getFieldErrors(): array
    {
        return $this->fieldErrors;
    }
}