<?php

/*
* This is a static OS utilities class
*/
class OsUtils {
	const WINDOWS_OS = 'Windows';
	const LINUX_OS   = 'linux';

	private static $log;

	public static function setLogPath($path)
	{
		self::$log = $path;
	}

	// returns true if the user is root, false otherwise
	public static function verifyRootUser() {
		exec('id -u', $output, $result);
		Logger::logMessage(Logger::LEVEL_INFO, "User: $output");
		return (isset($output[0]) && $output[0] == '0' && $result == 0);
	}

	// returns true if the OS is linux, false otherwise
	public static function verifyOS() {
		Logger::logMessage(Logger::LEVEL_INFO, "OS: ".OsUtils::getOsName());
		return (OsUtils::getOsName() === OsUtils::LINUX_OS);
	}

	// returns the computer hostname if found, 'unknown' if not found
	public static function getComputerName() {
		if(isset($_ENV['COMPUTERNAME'])) {
			Logger::logMessage(Logger::LEVEL_INFO, "Host name: ".$_ENV['COMPUTERNAME']);
	    	return $_ENV['COMPUTERNAME'];
		} else if (isset($_ENV['HOSTNAME'])) {
			Logger::logMessage(Logger::LEVEL_INFO, "Host name: ".$_ENV['HOSTNAME']);
			return $_ENV['HOSTNAME'];
		} else if (function_exists('gethostname')) {
			Logger::logMessage(Logger::LEVEL_INFO, "Host name: ".gethostname());
			return gethostname();
		} else {
			Logger::logMessage(Logger::LEVEL_WARNING, "Host name unkown");
			return 'unknown';
		}
	}

	// returns the OS name or empty string if not recognized
	public static function getOsName() {
		if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
			return self::WINDOWS_OS;
		} else if (strtoupper(substr(PHP_OS, 0, 5)) === 'LINUX') {
			return self::LINUX_OS;
		} else {
			Logger::logMessage(Logger::LEVEL_WARNING, "OS not recognized: ".PHP_OS);
			return "";
		}
	}

	// returns the linux distribution
	public static function getOsLsb() {
		$dist = OsUtils::executeReturnOutput("lsb_release -d");
		if($dist)
		{
			$dist = implode('\n', $dist);
		}
		else
		{
			$dist = PHP_OS;
		}
		Logger::logMessage(Logger::LEVEL_INFO, "Distribution: ".$dist);
		return $dist;
	}

	// returns '32bit'/'64bit' according to current system architecture - if not found, default is 32bit
	public static function getSystemArchitecture() {
		$arch = php_uname('m');
		Logger::logMessage(Logger::LEVEL_INFO, "OS architecture: ".$arch);
		if ($arch && (stristr($arch, 'x86_64') || stristr($arch, 'amd64'))) {
			return '64bit';
		} else {
			// stristr($arch, 'i386') || stristr($arch, 'i486') || stristr($arch, 'i586') || stristr($arch, 'i686') ||
			// return 32bit as default when not recognized
			return '32bit';
		}
	}

	// Write $config to ini $filename key = value
	public static function writeConfigToFile($config, $filename)
	{
		Logger::logMessage(Logger::LEVEL_INFO, "Writing config to file $filename");
		$data = '';
		$sections = array();
		foreach ($config as $key => $value)
		{
			if(is_array($value))
			{
				$sections[$key] = $value;
			}
			else
			{
				$data .= "$key=$value" . PHP_EOL;
			}
		}

		foreach ($sections as $section => $sectionsConfig)
		{
			$data .= PHP_EOL;
			$data .= "[$section]" . PHP_EOL;

			foreach ($sectionsConfig as $key => $value)
				$data .= "$key=$value" . PHP_EOL;
		}
		return file_put_contents($filename, $data);
	}

	// executes the phing and returns true/false according to the execution return value
	public static function phing($dir, $target = '', array $attributes = array())
	{
		$propertyFile = AppConfig::getFilePath();
		$options = array();
		foreach($attributes as $attribute => $value)
		{
			if(OsUtils::getOsName() == OsUtils::WINDOWS_OS)
				$value = "\"$value\"";
			else
				$value = "'$value'";

			$options[] = "-D{$attribute}={$value}";
		}
		$options = implode(' ', $options);


		$originalDir = getcwd();
		chdir($dir);
		$command = "phing -verbose -logger phing.listener.AnsiColorLogger -propertyfile $propertyFile $options $target >> " . self::$log . " 2>&1";
		Logger::logMessage(Logger::LEVEL_INFO, "Executing $command");
		$returnedValue = null;
		passthru($command, $returnedValue);
		chdir($originalDir);

		if($returnedValue != 0)
			return false;

		return true;
	}

	public static function startService($service, $alwaysStartAutomtically = true)
	{
		if($alwaysStartAutomtically)
			OsUtils::execute("chkconfig $service on");

		return self::execute("/etc/init.d/$service restart");
	}

	public static function stopService($service, $neverStartAutomtically = true)
	{
		if($neverStartAutomtically)
			OsUtils::executeInBackground("chkconfig $service off");

		return self::execute("/etc/init.d/$service stop");
	}

	// executes the shell $commands and returns true/false according to the execution return value
	public static function execute($command) {
		Logger::logMessage(Logger::LEVEL_INFO, "Executing $command");
		exec($command . ' >> ' . self::$log .' 2>&1 ', $output, $return_var);
		if ($return_var === 0) {
			return true;
		} else {
			Logger::logMessage(Logger::LEVEL_ERROR, "Executing command failed: $command");
			Logger::logMessage(Logger::LEVEL_ERROR, "Output from command is: ");
			while( list(,$row) = each($output) ){
				Logger::logMessage(Logger::LEVEL_ERROR, "$row");
			}
			Logger::logMessage(Logger::LEVEL_ERROR, "End of Output");
			return false;
		}
	}

	public static function executeWithOutput($command) {
		Logger::logMessage(Logger::LEVEL_INFO, "Executing $command");
		exec($command . ' 2>&1', $output, $return_var);
		$scriptOutput = $output;
		if ($return_var === 0) {
			return $output;
		} else {
			Logger::logMessage(Logger::LEVEL_ERROR, "Executing command failed: $command");
			Logger::logMessage(Logger::LEVEL_ERROR, "Output from command is: ");
			while( list(,$row) = each($output) ){
				Logger::logMessage(Logger::LEVEL_ERROR, "$row");
			}
			Logger::logMessage(Logger::LEVEL_ERROR, "End of Output");
			return false;
		}
	}

	public static function executeInBackground($command) {
		Logger::logMessage(Logger::LEVEL_INFO, "Executing in background $command");
		print("Executing in background $command \n");
		exec($command. ' >> ' . self::$log . ' 2>&1 &', $output, $return_var);
	}

	// Execute 'which' on each of the given $file_name (array or string) and returns the first one found (null if not found)
	public static function findBinary($file_name) {
		if (!is_array($file_name)) {
			$file_name = array ($file_name);
		}

		foreach ($file_name as $file) {

			if(OsUtils::getOsName() == OsUtils::WINDOWS_OS)
				$which_path = OsUtils::executeReturnOutput("dir /s /b $file 2>&1");
			else
				$which_path = OsUtils::executeReturnOutput("which $file 2>&1");

			if (isset($which_path[0]) && (trim($which_path[0]) != '') && (substr($which_path[0],0,1) == "/")) {
				return $which_path[0];
			}
		}

		return null;
	}

	// execute the given $cmd, returning the output
	public static function executeReturnOutput($cmd) {
		// 2>&1 is needed so the output will not display on the screen
		exec($cmd . ' 2>&1', $output, $ret);
		if($ret !== 0)
			return null;

		return $output;
	}

	// full copy $source to $target and return true/false according to success
	public static function fullCopy($source, $target) {
		return self::execute("cp -r $source $target");
	}

	// full copy $source to $target and return true/false according to success
	public static function rsync($source, $target, $options = "") {
		return self::execute("rsync -r $options $source $target");
	}

	// recursive delete the $path and return true/false according to success
	public static function recursiveDelete($path) {
		return self::execute("rm -rf $path");
    }

	/**
	 * Function receives an .ini file path and an array of values and writes the array into the file.
	 * @param string $file
	 * @param array $valuesArray
	 */
	public static function writeToIniFile ($file, $valuesArray)
	{
		$res = array();
		foreach($valuesArray as $key => $val)
	    {
	        if(is_array($val))
	        {
	            $res[] = "[$key]";
	            foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
	        }
	        else $res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
	    }
		file_put_contents($file, implode("\r\n", $res));
	}
}