<?php

namespace Roost\CLI;

/**
 * Gets and validates the command line arguments on instantiation.
 *
 * Completely self contained.
 * Will throw if invalid commands are pressent.  Will throw if invalid value is presented.
 *
 * Current supported types:  Boolean and string.
 *
 * Creator: Blue Nest
 * Company: Blue Nest Digital, LLC
 * License: (Blue Nest Digital LLC, All rights reserved)
 * Copyright: Copyright 2017 Blue Nest Digital LLC
 */
class CommandLineArgs {
    /**
     * provides the validation data on the required arguments.
     *
     * Provides validation information to enable the app to throw if invalid or missing arguments are present.
     * Missing arguments on the required list will result in error.
     */
    private $requiredArgs = array();
    /**
     * provides the validation data on the optional arguments.
     *
     * Provides validation information to enable the app to throw if invalid arguments are present.
     */
    private $optionalArgs = array();

    /**
     * all valid options.
     *
     * @var array
     */
    private $typeMap = [];

    /**
     * all values.
     *
     * @var array
     */
    private $parsedOptions;

    private $defaultsMap = [];

    private $longOptions;

    /**
     * Load and Validates user included command line arguments into $parsedOptions.
     *
     * Validates if the required arguments are not present, or an invalid optional requirement is given.
     */
    function __construct($requiredArgs = array(), $optionalArgs = array(), $parseArgumentsNow = true) {
        $this->requiredArgs = $requiredArgs;
        $this->optionalArgs = $optionalArgs;
        $this->registerArguments();

        if($parseArgumentsNow) {
            $this->parseArguments();
        }
    }

    public static function build($requiredArgs = array(), $optionalArgs = array()) {
        return new CommandLineArgs($requiredArgs, $optionalArgs);
    }

    public function registerArguments() {
        $longOptions = [];

        //Add optional arguments to longOptions.
        foreach($this->optionalArgs as $opt) {
            $argName = $opt["arg"];

            $longOptions[] = $argName . "::";

            if(array_key_exists("enum", $opt)) {
                $this->typeMap[$argName] = $opt["enum"];
            } else {
                $this->typeMap[$argName] = $opt["type"];
            }
            if(array_key_exists("def", $opt)) {
                $this->defaultsMap[$argName] = $opt["def"];
            }
        }

        //Add required arguments to longOptions.
        foreach($this->requiredArgs as $opt) {
            $argName = $opt["arg"];

            $longOptions[] = $argName . ":";

            if(array_key_exists("enum", $opt)) {
                $this->typeMap[$argName] = $opt["enum"];
            } else {
                $this->typeMap[$argName] = $opt["type"];
            }
        }

        $this->longOptions = $longOptions;
    }

    public function specified($argumentName) {
        return array_key_exists($argumentName, $this->parsedOptions);
    }

    public function parseArguments() {
        $this->parseProgramArgs("", $this->longOptions);
    }

    public function parseProgramArgs($shortOptions, $longOptions) {
        //Get the arguments.  Short Options and Long Options.
        $this->parsedOptions = getopt($shortOptions, $longOptions);

        //Find any missing arguments.
        $missingArguments = [];
        foreach($this->typeMap as $option => $type) {
            if(!array_key_exists($option, $this->parsedOptions) && ($this->possibleOptionalArgType($option) === null)) {
                $missingArguments[] = $option;
            }
        }

        // Throw if the required arguments are not included.
        if(!empty($missingArguments)) {
            throw new \Exception("Missing argument(s): " . implode(",", $missingArguments));
        }

        // Validate the arguments we have.
        foreach($this->parsedOptions as $option => &$val) {
            if(!array_key_exists($option, $this->typeMap)) {
                throw new \LogicException("Code error parsing options: " . $option);
            }
            if(is_array($val)) {
                throw new \Exception("Array found for argument " . $option . ", did you accidentally specify it more than once?");
            }
            $optionType = $this->typeMap[$option];

            if(is_array($optionType)) {
                $actualOptionType = "enum";
            } else {
                $actualOptionType = $optionType;
            }

            // Cast and validate args according to type
            switch($actualOptionType) {
                case "enum":
                    $foundEnum = false;
                    foreach($optionType as $possibleEnum) {
                        if($val == $possibleEnum) {
                            $foundEnum = true;
                            break;
                        }
                    }

                    if(!$foundEnum) {
                        throw new \Exception("Argument " . $option . " must be one of these values: " . print_r($optionType, true));
                    }
                    break;
                case "boolean":
                    $val = $this->argStringToBool($val);
                    break;
                case "string":
                case "regex":
                    if(!is_string($val)) {
                        throw new \Exception("Argument is not in string format: " . $option);
                    }
                    break;
                case "int":
                    if(is_int($val)) {
                        throw new \Exception("Argument is not in int format: " . $option);
                    }
                    $val = intval($val);
                    break;
                case "csv":
                    $val = explode(",", $val);
                    break;
                default:
                    throw new \Exception("Unhandled argument type: " . $optionType);
            }
        }
    }

    /**
     * Gets a value from the argument list with the name $key.
     *
     * This code will provide a value given the arguments name ($key).  If the value is not found, it will check the key
     * validity and then return the default value.  We save any valid arguments for speed on repetitive usages.
     *
     * @param $key
     * @return string|bool|null
     * @throws \Exception
     */
    public function getValue($key, $default = null) {
        if(array_key_exists($key, $this->parsedOptions))
            return $this->parsedOptions[$key];
        else {
            //Find the key in possibles, Return and Save Default value.
            $possibleType = $this->possibleOptionalArgType($key);
            if(!array_key_exists($key, $this->typeMap)) {
                throw new \Exception("Attempting to use unrecognized argument: " . $key . ". Recognized arguments are: " . implode(", ", array_keys($this->parsedOptions)));
            }

            if($default !== null) {
                return $default;
            } else if(array_key_exists($key, $this->defaultsMap)) {
                return $this->defaultsMap[$key];
            } else {
                throw new \Exception("No default value for option: " . $key);
            }
        }
        //No known argument found.  Invalid key.  Error:
    }

    /**
     * Search optionalArgs for match based on name (first index), and return
     * the type for that name (second index)
     *
     * @param $key
     * @return string|null
     */
    private function possibleOptionalArgType($key) {
        foreach($this->optionalArgs as $argValue) {
            if($key == $argValue["arg"]) {
                return $argValue["type"];
            }
        }
        return null;
    }

    /**
     * function to convert various forms of acceptable boolean strings into actual booleans.
     *
     * @param $strArg
     * @return bool
     * @throws \Exception
     */
    private function argStringToBool($strArg) {
        switch(strtolower($strArg)) {
            case "1":
            case "true":
            case "t":
                return true;
            case "0":
            case "false":
            case "f":
                return false;
            default:
                throw new \Exception("Unhandled boolean argument type: " . $strArg);
        }
    }

}