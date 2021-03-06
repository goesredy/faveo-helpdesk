<?php

class Bugsnag_Error
{
    private static $VALID_SEVERITIES = array(
        'error',
        'warning',
        'info',
    );

    public $name;
    public $payloadVersion = '2';
    public $message;
    public $severity = 'warning';
    /** @var Bugsnag_Stacktrace */
    public $stacktrace;
    public $metaData = array();
    public $config;
    public $diagnostics;
    /** @var Bugsnag_Error|null */
    public $previous;
    public $groupingHash;

    // Static error creation methods, to ensure that Error object is always complete
    public static function fromPHPError(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics, $code, $message, $file, $line, $fatal = false)
    {
        $error = new self($config, $diagnostics);
        $error->setPHPError($code, $message, $file, $line, $fatal);

        return $error;
    }

    public static function fromPHPThrowable(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics, $throwable)
    {
        $error = new self($config, $diagnostics);
        $error->setPHPException($throwable);

        return $error;
    }

    public static function fromNamedError(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics, $name, $message = null)
    {
        $error = new self($config, $diagnostics);
        $error->setName($name)
              ->setMessage($message)
              ->setStacktrace(Bugsnag_Stacktrace::generate($config));

        return $error;
    }

    // Private constructor (for use only by the static methods above)
    private function __construct(Bugsnag_Configuration $config, Bugsnag_Diagnostics $diagnostics)
    {
        $this->config = $config;
        $this->diagnostics = $diagnostics;
    }

    public function setName($name)
    {
        if (is_scalar($name) || method_exists($name, '__toString')) {
            $this->name = (string) $name;
        } else {
            throw new InvalidArgumentException('Name must be a string.');
        }

        return $this;
    }

    public function setMessage($message)
    {
        if ($message === null) {
            $this->message = null;
        } elseif (is_scalar($message) || method_exists($message, '__toString')) {
            $this->message = (string) $message;
        } else {
            throw new InvalidArgumentException('Message must be a string.');
        }

        return $this;
    }

    public function setGroupingHash($groupingHash)
    {
        $this->groupingHash = $groupingHash;

        return $this;
    }

    public function setStacktrace(Bugsnag_Stacktrace $stacktrace)
    {
        $this->stacktrace = $stacktrace;

        return $this;
    }

    public function setSeverity($severity)
    {
        if (!is_null($severity)) {
            if (in_array($severity, self::$VALID_SEVERITIES)) {
                $this->severity = $severity;
            } else {
                error_log('Bugsnag Warning: Tried to set error severity to '.$severity.' which is not allowed.');
            }
        }

        return $this;
    }

    public function setPHPException($exception)
    {
        if (version_compare(PHP_VERSION, '7.0.0', '>=')) {
            if (!$exception instanceof Throwable) {
                error_log('Bugsnag Warning: The exception must implement Throwable.');

                return;
            }
        } else {
            if (!$exception instanceof Exception) {
                error_log('Bugsnag Warning: The exception must be an Exception.');

                return;
            }
        }

        $this->setName(get_class($exception))
             ->setMessage($exception->getMessage())
             ->setStacktrace(Bugsnag_Stacktrace::fromBacktrace($this->config, $exception->getTrace(), $exception->getFile(), $exception->getLine()));

        if (method_exists($exception, 'getPrevious')) {
            $this->setPrevious($exception->getPrevious());
        }

        return $this;
    }

    public function setPHPError($code, $message, $file, $line, $fatal = false)
    {
        if ($fatal) {
            // Generating stacktrace for PHP fatal errors is not possible,
            // since this code executes when the PHP process shuts down,
            // rather than at the time of the crash.
            //
            // In these situations, we generate a "stacktrace" containing only
            // the line and file number where the crash occurred.
            $stacktrace = Bugsnag_Stacktrace::fromFrame($this->config, $file, $line);
        } else {
            $stacktrace = Bugsnag_Stacktrace::generate($this->config);
        }

        $this->setName(Bugsnag_ErrorTypes::getName($code))
             ->setMessage($message)
             ->setSeverity(Bugsnag_ErrorTypes::getSeverity($code))
             ->setStacktrace($stacktrace);

        return $this;
    }

    public function setMetaData($metaData)
    {
        if (is_array($metaData)) {
            $this->metaData = array_merge_recursive($this->metaData, $metaData);
        }

        return $this;
    }

    public function setPrevious($exception)
    {
        if ($exception) {
            $this->previous = self::fromPHPThrowable($this->config, $this->diagnostics, $exception);
        }

        return $this;
    }

    public function toArray()
    {
        $errorArray = array(
            'app' => $this->diagnostics->getAppData(),
            'device' => $this->diagnostics->getDeviceData(),
            'user' => $this->diagnostics->getUser(),
            'context' => $this->diagnostics->getContext(),
            'payloadVersion' => $this->payloadVersion,
            'severity' => $this->severity,
            'exceptions' => $this->exceptionArray(),
            'metaData' => $this->cleanupObj($this->metaData, true),
        );

        if (isset($this->groupingHash)) {
            $errorArray['groupingHash'] = $this->groupingHash;
        }

        return $errorArray;
    }

    public function exceptionArray()
    {
        if ($this->previous) {
            $exceptionArray = $this->previous->exceptionArray();
        } else {
            $exceptionArray = array();
        }

        $exceptionArray[] = array(
            'errorClass' => $this->name,
            'message' => $this->message,
            'stacktrace' => $this->stacktrace->toArray(),
        );

        return $this->cleanupObj($exceptionArray, false);
    }

    private function cleanupObj($obj, $isMetaData)
    {
        if (is_null($obj)) {
            return;
        }

        if (is_array($obj)) {
            $cleanArray = array();
            foreach ($obj as $key => $value) {
                // Check if this key should be filtered
                $shouldFilter = false;

                // Apply filters to metadata if required
                if ($isMetaData && is_array($this->config->filters)) {
                    foreach ($this->config->filters as $filter) {
                        if (strpos($key, $filter) !== false) {
                            $shouldFilter = true;
                            break;
                        }
                    }
                }
                // Apply filter
                if ($shouldFilter) {
                    $cleanArray[$key] = '[FILTERED]';
                } else {
                    $cleanArray[$key] = $this->cleanupObj($value, $isMetaData);
                }
            }

            return $cleanArray;
        } elseif (is_string($obj)) {
            // UTF8-encode if not already encoded
            if (function_exists('mb_detect_encoding') && !mb_detect_encoding($obj, 'UTF-8', true)) {
                return utf8_encode($obj);
            } else {
                return $obj;
            }
        } elseif (is_object($obj)) {
            // json_encode -> json_decode trick turns an object into an array
            return $this->cleanupObj(json_decode(json_encode($obj), true), $isMetaData);
        } else {
            return $obj;
        }
    }
}
