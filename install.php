<?php

include_once('installer/DatabaseUtils.class.php');
include_once('installer/OsUtils.class.php');
include_once('installer/Log.php');
include_once('installer/InstallReport.class.php');
include_once('installer/AppConfig.class.php');
include_once('installer/Installer.class.php');
include_once('installer/Validator.class.php');
include_once('installer/InputValidator.class.php');
include_once('installer/phpmailer/class.phpmailer.php');

// installation might take a few minutes
ini_set('max_execution_time', 0);
ini_set('memory_limit', -1);
ini_set('max_input_time ', 0);

date_default_timezone_set(@date_default_timezone_get());

$options = getopt('hsC:p:');
if(isset($options['h']))
{
	echo 'Usage is php ' . __FILE__ . ' [arguments]'.PHP_EOL;
	echo "-h - Show this help." . PHP_EOL;
	echo "-s - Silent mode, no questions will be asked." . PHP_EOL;
	echo "-p - Package XML path or URL." . PHP_EOL;
	echo "-C - Comma seperated components list (api,db,sphinx,batch,dwh,admin,var,apps,red5,ssl)." . PHP_EOL;
	echo PHP_EOL;
	echo "Examples:" . PHP_EOL;
	echo 'php ' . __FILE__ . ' -s' . PHP_EOL;
	echo 'php ' . __FILE__ . ' -C api,db,sphinx' . PHP_EOL;

	exit(0);
}

// start the log
Logger::init(__DIR__ . '/install.' . date("d.m.Y_H.i.s") . '.log');
Logger::logColorMessage(Logger::COLOR_YELLOW, Logger::LEVEL_INFO, "Installation started");

$silentRun = isset($options['s']);

$packageDir = realpath(__DIR__ . '/../package');
if($packageDir)
	AppConfig::init($packageDir);

AppConfig::configure($silentRun);

OsUtils::setLogPath(__DIR__ . '/install.' . date("d.m.Y_H.i.s") . '.details.log');

if(isset($options['p']))
{
	$xmlUri = $options['p'];
	if(parse_url($xmlUri, PHP_URL_SCHEME))
	{
		$tmp = tempnam(sys_get_temp_dir(), 'dirs.');
		file_put_contents($tmp, file_get_contents($xmlUri));
		$xmlUri = $tmp;
	}

	$attributes = array(
		'xml.uri' => $xmlUri,
	);

	Logger::logMessage(Logger::LEVEL_USER, "Downloading Kaltura server...", false);
	if(!OsUtils::phing(__DIR__ . '/directoryConstructor', 'Construct', $attributes))
	{
		Logger::logColorMessage(Logger::COLOR_RED, Logger::LEVEL_USER, " failed.", true, 3);
		exit(-1);
	}
	Logger::logColorMessage(Logger::COLOR_GREEN, Logger::LEVEL_USER, " - done.", true, 3);
	echo PHP_EOL;
}

$components = null;
if(isset($options['C']))
	$components = explode(',', $options['C']);
else
	$components = AppConfig::getCurrentMachineComponents();

Logger::logColorMessage(Logger::COLOR_YELLOW, Logger::LEVEL_INFO, "Installing Kaltura " . AppConfig::get(AppConfigAttribute::KALTURA_VERSION));
if (AppConfig::get(AppConfigAttribute::KALTURA_VERSION_TYPE) == AppConfig::K_CE_TYPE) {
	Logger::logMessage(Logger::LEVEL_USER, "Thank you for installing Kaltura Video Platform - Community Edition");
} else {
	Logger::logMessage(Logger::LEVEL_USER, "Thank you for installing Kaltura Video Platform");
}
echo PHP_EOL;



$report = null;
// if user wants or have to report
if (AppConfig::get(AppConfigAttribute::KALTURA_VERSION_TYPE) == AppConfig::K_TM_TYPE ||
	AppConfig::getTrueFalse(null, "In order to improve Kaltura Community Edition, we would like your permission to send system data to Kaltura.\nThis information will be used exclusively for improving our software and our service quality. I agree", 'y'))
{
	$report_message = "If you wish, please provide your email address so that we can offer you future assistance (leave empty to pass)";
	$report_error_message = "Email must be in a valid email format";
	$report_validator = InputValidator::createEmailValidator(true);

	$email = AppConfig::getInput(AppConfigAttribute::REPORT_ADMIN_EMAIL, $report_message, $report_error_message, $report_validator, null);
	if($email)
	{
		AppConfig::set(AppConfigAttribute::REPORT_ADMIN_EMAIL, $email);
		AppConfig::set(AppConfigAttribute::TRACK_KDPWRAPPER, 'true');
		AppConfig::set(AppConfigAttribute::USAGE_TRACKING_OPTIN, 'true');
		$report = new InstallReport($email, AppConfig::get(AppConfigAttribute::KALTURA_VERSION), AppConfig::get(AppConfigAttribute::INSTALLATION_SEQUENCE_UID), AppConfig::get(AppConfigAttribute::INSTALLATION_UID));
		$report->reportInstallationStart();
	}
}

// verify prerequisites
echo PHP_EOL;
Logger::logColorMessage(Logger::COLOR_YELLOW, Logger::LEVEL_USER, "Verifing prerequisites");

$validator = new Validator($components);
$prerequisites = $validator->validate();

if (count($prerequisites))
{
	$description = "One or more prerequisites required to install Kaltura failed:\n" . implode("\n", $prerequisites);

	if ($report)
		$report->reportInstallationFailed($description);

	Logger::logColorMessage(Logger::COLOR_LIGHT_RED, Logger::LEVEL_USER, $description);
	Logger::logMessage(Logger::LEVEL_USER, "Please resolve the issues and run the installation again.");
	exit(-1);
}

// verify that there are no leftovers from previous installations
echo PHP_EOL;
Logger::logColorMessage(Logger::COLOR_YELLOW, Logger::LEVEL_USER, "Checking for leftovers from a previous installation");

$installer = new Installer($components);
$leftovers = $installer->detectLeftovers(true);
if (isset($leftovers)) {
	Logger::logMessage(Logger::LEVEL_USER, $leftovers);
	if (AppConfig::getTrueFalse(null, "Leftovers from a previouse Kaltura installation have been detected. In order to continue with the current installation these leftovers must be removed. Do you wish to remove them now?", 'n')) {
		$installer->detectLeftovers(false);
	} else {

		$description = "Installation cannot continue because a previous installation of Kaltura was detected.\n" . $leftovers;
		if ($report)
			$report->reportInstallationFailed($description);

		Logger::logColorMessage(Logger::COLOR_LIGHT_RED, Logger::LEVEL_USER, "Please manually uninstall Kaltura before running the installation or select yes to remove the leftovers.");
		exit(-2);
	}
}

// run the installation
$install_output = $installer->install($packageDir);
if ($install_output !== null)
{
	$description = "Installation failed.";
	Logger::logColorMessage(Logger::COLOR_RED, Logger::LEVEL_USER, $install_output);

	if ($report)
		$report->reportInstallationFailed($description);

	$leftovers = $installer->detectLeftovers(true);
	if (isset($leftovers) && AppConfig::getTrueFalse(null, "Do you want to cleanup?", 'n')) {
		$installer->detectLeftovers(false);
	}

	if (AppConfig::get(AppConfigAttribute::KALTURA_VERSION_TYPE) == AppConfig::K_CE_TYPE)
		Logger::logMessage(Logger::LEVEL_USER, "For assistance, please upload the installation log file to the Kaltura CE forum at kaltura.org");
	else
		Logger::logMessage(Logger::LEVEL_USER, "For assistance, please contant the support team at support@kaltura.com with the installation log attached");

	exit(1);
}

// send settings mail if possible
$msg = sprintf("Thank you for installing the Kaltura Video Platform\n\nTo get started, please browse to your kaltura start page at:\nhttp://%s/start\n\nYour ".AppConfig::get(AppConfigAttribute::KALTURA_VERSION_TYPE)." administration console can be accessed at:\nhttp://%s/admin_console\n\nYour Admin Console credentials are:\nSystem admin user: %s\nSystem admin password: %s\n\nPlease keep this information for future use.\n\nThank you for choosing Kaltura!", AppConfig::get(AppConfigAttribute::KALTURA_VIRTUAL_HOST_NAME), AppConfig::get(AppConfigAttribute::KALTURA_VIRTUAL_HOST_NAME), AppConfig::get(AppConfigAttribute::ADMIN_CONSOLE_ADMIN_MAIL), AppConfig::get(AppConfigAttribute::ADMIN_CONSOLE_PASSWORD)).PHP_EOL;
$mailer = new PHPMailer();
$mailer->CharSet = 'utf-8';
$mailer->IsHTML(false);
$mailer->AddAddress(AppConfig::get(AppConfigAttribute::ADMIN_CONSOLE_ADMIN_MAIL));
$mailer->Sender = "installation_confirmation@".AppConfig::get(AppConfigAttribute::KALTURA_VIRTUAL_HOST_NAME);
$mailer->From = "installation_confirmation@".AppConfig::get(AppConfigAttribute::KALTURA_VIRTUAL_HOST_NAME);
$mailer->FromName = AppConfig::get(AppConfigAttribute::ENVIRONMENT_NAME);
$mailer->Subject = 'Kaltura Installation Settings';
$mailer->Body = $msg;

if ($mailer->Send()) {
	Logger::logColorMessage(Logger::COLOR_LIGHT_RED, Logger::LEVEL_USER, "Post installation email cannot be sent");
} else {
	Logger::logColorMessage(Logger::COLOR_LIGHT_GREEN, Logger::LEVEL_USER, "Sent post installation settings email to ".AppConfig::get(AppConfigAttribute::ADMIN_CONSOLE_ADMIN_MAIL));
}

// print after installation instructions
echo PHP_EOL;
Logger::logColorMessage(Logger::COLOR_LIGHT_GREEN, Logger::LEVEL_USER, "Installation Completed Successfully.");

Logger::logMessage(Logger::LEVEL_USER, sprintf(
	"Your Kaltura Admin Console credentials:\n" .
	"System Admin user: %s\n" .
	"Please keep this information for future use.\n",

	AppConfig::get(AppConfigAttribute::ADMIN_CONSOLE_ADMIN_MAIL)
));

$virtualHostName = AppConfig::get(AppConfigAttribute::KALTURA_VIRTUAL_HOST_NAME);
$appDir = realpath(AppConfig::get(AppConfigAttribute::APP_DIR));

Logger::logMessage(Logger::LEVEL_USER,
	"To start using Kaltura, please complete the following steps:\n" .
	"1. Add the following line to your /etc/hosts file:\n" .
		"\t127.0.0.1 $virtualHostName\n" .
	"2. Browse to your Kaltura start page at: http://$virtualHostName/start\n"
);

if ($report)
	$report->reportInstallationSuccess();

exit(0);
