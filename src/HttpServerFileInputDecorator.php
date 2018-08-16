<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2015 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace Zend\InputFilter;

use Zend\Validator\File\UploadFile as UploadValidator;

/**
 * FileInput is a special Input type for handling uploaded files.
 *
 * It differs from Input in a few ways:
 *
 * 1. It expects the raw value to be in the $_FILES array format.
 *
 * 2. The validators are run **before** the filters (the opposite behavior of Input).
 *    This is so is_uploaded_file() validation can be run prior to any filters that
 *    may rename/move/modify the file.
 *
 * 3. Instead of adding a NotEmpty validator, it will (by default) automatically add
 *    a Zend\Validator\File\Upload validator.
 */
class HttpServerFileInputDecorator extends FileInput implements FileInputDecoratorInterface
{
    /** @var FileInput */
    private $subject;

    public function __construct(FileInput $subject)
    {
        $this->subject = $subject;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        $value = $this->subject->value;
        if ($this->subject->isValid && is_array($value)) {
            // Run filters ~after~ validation, so that is_uploaded_file()
            // validation is not affected by filters.
            $filter = $this->subject->getFilterChain();
            if (isset($value['tmp_name'])) {
                // Single file input
                $value = $filter->filter($value);
            } else {
                // Multi file input (multiple attribute set)
                $newValue = [];
                foreach ($value as $fileData) {
                    if (is_array($fileData) && isset($fileData['tmp_name'])) {
                        $newValue[] = $filter->filter($fileData);
                    }
                }
                $value = $newValue;
            }
        }

        return $value;
    }

    /**
     * Checks if the raw input value is an empty file input eg: no file was uploaded
     *
     * @param $rawValue
     * @return bool
     */
    public static function isEmptyFileDecorator($rawValue)
    {
        if (! is_array($rawValue)) {
            return true;
        }

        if (isset($rawValue['error']) && $rawValue['error'] === UPLOAD_ERR_NO_FILE) {
            return true;
        }

        if (count($rawValue) === 1 && isset($rawValue[0])) {
            return self::isEmptyFileDecorator($rawValue[0]);
        }

        return false;
    }

    /**
     * @param  mixed $context Extra "context" to provide the validator
     * @return bool
     */
    public function isValid($context = null)
    {
        $rawValue        = $this->subject->getRawValue();
        $validator       = $this->subject->getValidatorChain();
        $this->injectUploadValidator();

        //$value   = $this->getValue(); // Do not run the filters yet for File uploads (see getValue())

        if (! is_array($rawValue)) {
            // This can happen in an AJAX POST, where the input comes across as a string
            $rawValue = [
                'tmp_name' => $rawValue,
                'name'     => $rawValue,
                'size'     => 0,
                'type'     => '',
                'error'    => UPLOAD_ERR_NO_FILE,
            ];
        }
        if (is_array($rawValue) && isset($rawValue['tmp_name'])) {
            // Single file input
            $this->subject->isValid = $validator->isValid($rawValue, $context);
        } elseif (is_array($rawValue) && isset($rawValue[0]['tmp_name'])) {
            // Multi file input (multiple attribute set)
            $this->subject->isValid = true;
            foreach ($rawValue as $value) {
                if (! $validator->isValid($value, $context)) {
                    $this->subject->isValid = false;
                    break; // Do not continue processing files if validation fails
                }
            }
        }

        return $this->subject->isValid;
    }

    /**
     * @return void
     */
    protected function injectUploadValidator()
    {
        if (! $this->subject->autoPrependUploadValidator) {
            return;
        }
        $chain = $this->subject->getValidatorChain();

        // Check if Upload validator is already first in chain
        $validators = $chain->getValidators();
        if (isset($validators[0]['instance'])
            && $validators[0]['instance'] instanceof UploadValidator
        ) {
            $this->subject->autoPrependUploadValidator = false;
            return;
        }

        $chain->prependByName('fileuploadfile', [], true);
        $this->subject->autoPrependUploadValidator = false;
    }
}
