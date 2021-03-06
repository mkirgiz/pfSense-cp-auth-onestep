﻿<?php
define("APP_BUILD", "OZY's CAPTIVE PORTAL FOR RADIUS/MySQL authentication v0.42 2016051901");
/*********************************************************************/
/* Workflow:                                                         */
/*                                                                   */
/* WelcomePage() --submit--> Create / Update RADIUS user --> Login() */
/*********************************************************************/

// global is used because pfSense php interpreter doesn't take variable definitions in functions
global $brand, $hotelName, $hotelSite, $identificator;
global $today, $build, $userName, $password;
global $confirmationCode;
global $language;

global $emailAddress, $roomNumber, $familyName, $surName, $code;
global $zone, $redirurl;

global $askForRoomNumber, $askForEmailAddress, $askForFamilyName, $askForSurName, $askForNewsletter, $askForTermsOfUse;

global $UPDATE;

// Config file
include "captiveportal-config.php";

// Get IP and mac address
$ipAddress=$_SERVER['REMOTE_ADDR'];
#run the external command, break output into lines
$arp=`arp $ipAddress`;
$lines = explode(" ", $arp);
if (!empty($lines[3]))
	$macAddress = $lines[3]; // Works on FreeBSD
else
	$macAddress = "fa:ke:ma:c:ad:dr"; // Fake MAC on dev station

// Clean input function
function cleanInput($input) {
	$search = array(
	'@<script[^>]*?>.*?</script>@si',   /* strip out javascript */
	'@<[\/\!]*?[^<>]*?>@si',            /* strip out HTML tags */
	'@<style[^>]*?>.*?</style>@siU',    /* strip style tags properly */
	'@<![\s\S]*?--[ \t\n\r]*>@'         /* strip multi-line comments */
	);

	$output = preg_replace($search, '', $input);
	return $output;
}

function slog($string) {
	print "<p style=color:red>$string</p>";
}


// pfSense 2.3 fix, see https://forum.pfsense.org/index.php?topic=105567.0
if(isset($_GET['zone']))
	$zone = cleanInput($_GET["zone"]);

if(isset($_GET['redirurl']))
	$redirurl = cleanInput($_GET["redirurl"]);

if(isset($_POST["termsOfUse"]))
{
	if (strlen($confirmationCode) > 0)
	{
		if (isset($_POST["code"]))
		{
			$code = cleanInput($_POST["code"]);
			if ($confirmationCode != $code)
			{
				$checkMessage = t('incorrectConfirmationCode_string');
				$badCheck = true;
			}
		}
		else
		{
			$checkMessage = t('incorrectConfirmationcode_string');
			$badCheck = true;
		}
	}
	if (isset($_POST["familyName"]))
		$familyName = cleanInput($_POST["familyName"]);
	else
		$familyName = false;
	if ((strlen($familyName) < 2) && ($askForFamilyName == true))
	{
		$checkMessage = t('incorrectInput_string');
		$badCheck = true;
	}
	if (isset($_POST["surName"]))
		$surName = cleanInput($_POST["surName"]);
	else
		$surName = false;
	if ((strlen($surName) < 2) && ($askForSurName == true))
	{
		$checkMessage = t('incorrectInput_string');
		$badCheck = true;
	}
	if (isset($_POST["roomNumber"]))
		$roomNumber = cleanInput($_POST["roomNumber"]);
	else
		$roomNumber = false;
	if ((strlen($roomNumber) < 1) && ($askForRoomNumber == true))
	{
		$checkMessage = t('incorrectInput_string');
		$badCheck = true;
	}
	if (isset($_POST["emailAddress"]))
		$emailAddress = cleanInput($_POST["emailAddress"]);
	else
		$emailAddress = false;
	if ((!filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) && ($askForEmailAddress == true))
	{
		$checkMessage = t('incorrectInput_string');
		$badCheck = true;
	}
	$regDate = date("Y-m-d H:i:s");
	if (isset($_POST["newsletter"]))
		$newsletter = 1;
	else
		$newsletter = 0;

	if ($badCheck == true)
	{
		WelcomePage($checkMessage);
		die();
	}

	$con = @mysql_connect(DBHOST,DBUSER,DBPASS);
	if (!$con)
	{
		if (DEBUG == true)
			$error_message = t('databaseConnectErrorMessage_string') . utf8_encode(mysql_error());
		else
			$error_message = t('databaseConnectErrorMessage_string');
		WelcomePage($error_message);
	}
	else
	{
		@mysql_select_db(DBNAME, $con);
		if ($macAddress!=NULL)
		{
			$query = "INSERT INTO reg_users (familyName, surName, roomNumber, emailAddress, macAddress, ipAddress, regDate, identificator, newsletter) VALUES ('$familyName', '$surName', '$roomNumber', '$emailAddress', '$macAddress' , '$ipAddress', '$regDate', '$identificator', '$newsletter');";
			if ($UPDATE == true)
			{
				$check_query = "SELECT * FROM reg_users WHERE macAddress = '$macAddress' AND emailAddress = '$emailAddress';";
				if( !$result = @mysql_query($check_query))
				{
					if (DEBUG == true)
					{
						slog($check_query);
						$error_message = t('databaseCheckErrorMessage_string')." (1) :" . utf8_encode(mysql_error());
					}
					else
						$error_message = t('databaseCheckErrorMessage_string')." (1)";
					WelcomePage($error_message);
					die();
				}
				$numrows = @mysql_num_rows($result);
				if ($numrows != 0)
					$query = "UPDATE reg_users SET familyName = '$familyName', surName = '$surName', roomNumber = '$roomNumber' , ipAddress = '$ipAddress', regDate = '$regDate', identificator = '$identificator', newsletter = '$newsletter' WHERE macAddress = '$macAddress' AND emailAddress = '$emailAddress';";
			}

			if (!@mysql_query($query))
			{
				if (DEBUG == true)
				{
					slog($query);
					$error_message = t('databaseRegisterErrorMessage_string')." (1) :" . utf8_encode(mysql_error());
				}
				else
					$error_message = t('databaseRegisterErrorMessage_string')." (1)";
				WelcomePage($error_message);
				die();
			}

			// User name and password for RADIUS
			$userName = $emailAddress.$roomNumber;
			$password = $familyName.$surName;

			$check_query = "SELECT username FROM radcheck WHERE username = '$userName';";
			if (!$result = @mysql_query($check_query))
			{
				if (DEBUG == true)
				{
					slog($check_query);
					$error_message = t('databaseCheckErrorMessage_string')." (2) :" . utf8_encode(mysql_error());
				}
				else
					$error_message = t('databaseCheckErrorMessage_string')." (2)";
				WelcomePage($error_message);
				die();
			}

			$numrows = @mysql_num_rows($result);
			if ($numrows != 0)
				$query = "UPDATE radcheck SET value = '$password' WHERE username = '$userName';";
			else
				$query = "INSERT INTO radcheck (username, attribute, value) VALUES ('$userName', 'Password', '$password');";

			if (!@mysql_query($query))
			{
				if (DEBUG == true)
				{
					slog($query);
                                        $error_message = t('databaseRegisterErrorMessage_string')." (2) :" . utf8_encode(mysql_error());
				}
                                else
                                       	$error_message = t('databaseRegisterErrorMessage_string')." (2)";
                               	WelcomePage($error_message);
				die();
			}

			$check_query = "SELECT username FROM radusergroup WHERE username = '$userName';";
			if (!$result = @mysql_query($check_query))
			{
				if (DEBUG == true)
				{
					slog($check_query);
					$error_message = t('databaseCheckErrorMessage_string')." (3) :" . utf8_encode(mysql_error());
				}
				else
					$error_message = t('databaseCheckErrorMessage_string')." (3)";
				WelcomePage($error_message);
				die();
			}

			$numrows = @mysql_num_rows($result);
			if ($numrows == 0)
			{
				$query = "INSERT INTO radusergroup (username, groupname) VALUES ('$userName', 'Free');";
				if (!@mysql_query($query))
				{
					if (DEBUG == true)
					{
						slog($query);
                                        	$error_message = t('databaseRegisterErrorMessage_string')." (3) :" . utf8_encode(mysql_error());
					}
                                	else
                                       		$error_message = t('databaseRegisterErrorMessage_string')." (3)";
                               		WelcomePage($error_message);
					die();
				}
			}
			Login();
		}
		else
			WelcomePage(t('macAdressErrorMessage_string'));
	@mysql_close($con);
	}
}
else
	WelcomePage();

function Login()
{
	global $userName;
	global $password;
?>
<!DOCTYPE html>
<html>
	<!-- Do not modify anything in this form as pfSense needs it exactly that way -->
	<body>
		<?php print t('noScript_string'); ?>
		<form name="loginForm" method="post" action="$PORTAL_ACTION$">
			<input name="auth_user" type="hidden" value="<?php echo $userName; ?>">
			<input name="auth_pass" type="hidden" value="<?php echo $password; ?>">
			<input name="zone" type="hidden" value="$PORTAL_ZONE$">
			<input name="redirurl" type="hidden" value="$PORTAL_REDIRURL$">
			<input id="submitbtn" name="accept" type="submit" value="Continue">
		</form>
		<script type="text/javascript">
			document.getElementById("submitbtn").click();
		</script>
	</body>
</html>
<?php
}

function WelcomePage($message = '')
{
	global $brand;
	global $hotelName;
	global $hotelSite;
	global $today;
	global $build;
	global $confirmationCode;
	global $language;

	global $emailAddress, $roomNumber, $familyName, $surName, $code;
	global $zone, $redirurl;

	global $askForRoomNumber, $askForEmailAddress, $askForFamilyName, $askForSurName, $askForNewsletter, $askForTermsOfUse;

?>
<!DOCTYPE html>
<!--<?php echo $build."\n"; ?>-->
<html lang="<?php echo $language; ?>">
	<head>
		<meta charset="utf-8">
		<title><?php echo $brand; ?> - Accès WIFI</title>
		<meta name="viewport" content="width=device-width, initial-scale=1.0">
		<meta name="description" content="<?php echo $brand; ?> - <?php print t('pageTitle_string'); ?>">
		<meta name="author" content="<?php echo $brand; ?>">

		<link href="captiveportal-bootstrap.min.css" media="screen" rel="stylesheet" type="text/css" />

		<style type="text/css">
body {
  background:url('captiveportal-background.jpg') fixed center #2266DD;
  background-repeat: no-repeat;
  background-size: 100%;
}

body, html {
    height: 100%;
}

.btn {
    font-weight: 700;
    height: 36px;
    -moz-user-select: none;
    -webkit-user-select: none;
    user-select: none;
    cursor: default;
}

.form-signin input[type=email],
.form-signin input[type=password],
.form-signin input[type=text],
.form-signin button {
    width: 100%;
    display: block;
    margin-bottom: 10px;
    z-index: 1;
    position: relative;
    -moz-box-sizing: border-box;
    -webkit-box-sizing: border-box;
    box-sizing: border-box;
}

.form-signin .form-control:focus {
    border-color: rgb(104, 145, 162);
    outline: 0;
    -webkit-box-shadow: inset 0 1px 1px rgba(0,0,0,.075),0 0 8px rgb(104, 145, 162);
    box-shadow: inset 0 1px 1px rgba(0,0,0,.075),0 0 8px rgb(104, 145, 162);
}

.btn.btn-signin {
    background-color: #FB0E0E;
    padding: 5px;
    font-weight: 700;
    font-size: 14px;
    height: 36px;
    -moz-border-radius: 3px;
    -webkit-border-radius: 3px;
    border-radius: 3px;
    border: 1px;
    -o-transition: all 0.218s;
    -moz-transition: all 0.218s;
    -webkit-transition: all 0.218s;
    transition: all 0.218s;
	color: #FFFFFF;
}

.btn.btn-signin:hover,
.btn.btn-signin:active,
.btn.btn-signin:focus {
    background-color: #FA6C1D;
}

.martop10p {
	margin-top: 10%;
}

.uppercase {
	text-transform: uppercase;
}

.messagebox {
	/*background-color: #EEEEEE;*/
	background: rgba(238,238,238,0.7);

}

.formulaire {
	color: #FFFFFF;
	background-color: #8BB6C8;
	direction: ltr;
    height: 44px;
    font-size: 16px;
}

.form-control::-webkit-input-placeholder { color: white; }
.form-control:-moz-placeholder { color: white; }
.form-control::-moz-placeholder { color: white; }
.form-control:-ms-input-placeholder { color: white; }
.form-control {
	margin-bottom: 3px;
}

.padding10 {
	padding: 10px;
}

.curpointer {
	cursor: pointer;
}

.logo {
	position: absolute;
	float: left;
	left: 10px;
	opacity: 0.7;
	height: 100%;
}

.vertical-text {
    -moz-transform-origin:0 50%;
    -moz-transform:rotate(-90deg) translate(-50%, 50%);
    -webkit-transform-origin:0 50%;
    -webkit-transform:rotate(-90deg) translate(-50%, 50%);
    -o-transform-origin:0 50%;
    -o-transform:rotate(-90deg) translate(-50%, 50%);
    -ms-transform-origin:0 50%;
    -ms-transform:rotate(-90deg) translate(-50%, 50%);
    transform-origin:0 50%;
    transform:rotate(-90deg) translate(-50%, 50%);
    position:absolute;
    top:0;
    bottom:0;
    left: -50px;
    height:2em; /* line-height of .wrapper div:first-child span */
    margin:auto;
    font-weight:bold;
    line-height:2em; /* Copy to other locations */
    color: #FFF;
    opacity: 0.7;
    font-size: 15vh;
}

.right {
	float: right;
}

label {
	font-weight: normal;
}

/* Nice CSS checkbox */
input[type="checkbox"] {
	opacity: 0.9;
	position: absolute;
	left: -9999px;
}
input[type="checkbox"] + label span {
    display:inline-block;
	left: 14px;
    width:19px;
    height:19px;
    margin:-1px 4px 0 0;
    vertical-align:middle;
    background:url(captiveportal-check_radio_sheet.png) left top no-repeat;
    cursor:pointer;
}
input[type="checkbox"]:checked + label span {
    background:url(captiveportal-check_radio_sheet.png) -19px top no-repeat;
}

.modal .modal-body {
    max-height: 420px;
    overflow-y: auto;
	background-color: #EEEEEE;
}

.modal-content {
	background-color: #FA6C1D;
}

		</style>
		<script type="text/javascript" src="captiveportal-jquery-1.11.3.min.js"></script>
		<script type="text/javascript" src="captiveportal-bootstrap.min.js"></script>
	</head>
	<body>
		<!-- Terms Of Use Modal -->
		<div id="conditions" class="modal fade">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
							<h4 class="modal-title"><?php print t('termsOfUse_string'); ?></h4>
					</div>
						<div class="modal-body">
							<p><?php print t('wifiProvidedBy_string'); ?></p>
							<div class="padding30 grey">
								<?php print t('generalUseMessage_string'); ?>
								<br/>
								<br/>
								<?php include "captiveportal-termsofuse.html"; ?>
								<div class="clearfix"></div>
							</div>
						</div>
					<div class="modal-footer">
				<button type="button" class="btn btn-primary" data-dismiss="modal"><?php print t('termsOfUseRead_string'); ?></button>
					</div>
				</div>
			</div>
		</div>

		<!-- Terms Of Use Error modal -->
		<div id="erreur" class="modal fade">
			<div class="modal-dialog">
				<div class="modal-content">
					<div class="modal-header">
						<button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
							<h4 class="modal-title"><?php print t('error_string'); ?></h4>
					</div>
						<div class="modal-body">
							<div class="padding30 grey">
								<?php echo $message; ?>
								<div class="clearfix"></div>
							</div>
						</div>
					<div class="modal-footer">
				<button type="button" class="btn btn-primary" data-dismiss="modal">Ok</button>
					</div>
				</div>
			</div>
		</div>

		<div class="container">
			<div class="vertical-text"><?php echo $brand; ?></div>
			<!--<img class="logo" src="captiveportal-sidelogo.png"> Obsolete png logo -->
			<div class="col-md-2"></div>
			<div class="col-md-8 martop10p">
				<div class="row messagebox padding10">
					<div class="col-md-6">
						<span class="uppercase"> <?php print t('datePrefix_string'); print " "; print t('today'); ?></span><br/><br/>
						<h2><?php print t('welcome_string'); ?></h2>
						<br/>
						<?php print t('welcomeMessage_string'); ?>
						<br/><br/><br/><br/><br/>
						<?php echo $hotelSite ?>
					</div>
					<div class="col-md-6">
						<form id="enregistrement" method='post' action="?<?php if (isset($zone)) echo "zone=$zone"; if (isset($redirurl)) echo "&redirurl=$redirurl"; ?>">
							<fieldset>
								<?php if ($askForRoomNumber == true) { ?>
								<div class="control-group">
									<div class="controls">
										<input type="text" class="form-control formulaire" id="roomNumber" name="roomNumber" value="<?php echo $roomNumber; ?>" placeholder="<?php print t('roomNumber_string'); ?>">
									</div>
								</div>
								<?php
								}
								if (strlen($confirmationCode) > 0) { ?>
									<div class="control-group">
										<div class="controls">
											<input type="text" class="form-control formulaire" id="code" name="code" value="<?php echo $code; ?>" placeholder="<?php print t('confirmationCode_string'); ?>">
										</div>
									</div>
								<?php
								}
								if ($askForEmailAddress == true) {
								?>
								<div class="control-group">
									<div class="controls">
										<input type="email" class="form-control formulaire" id="emailAddress" name="emailAddress" value="<?php echo $emailAddress; ?>" placeholder="<?php print t('emailAddress_string'); ?>">
									</div>
								</div>
								<?php
								}
								if ($askForFamilyName == true) {
								?>
								<div class="control-group">
									<div class="controls">
										<input type="text" class="form-control formulaire" id="familyName" name="familyName" value="<?php echo $familyName; ?>" placeholder="<?php print t('familyName_string'); ?>">
									</div>
								</div>
								<?php
								}
								if ($askForSurName == true) {
								?>
								<div class="control-group">
									<div class="controls">
										<input type="text" class="form-control formulaire" id="surName" name="surName" value="<?php echo $surName; ?>"  placeholder="<?php print t('surName_string'); ?>">
									</div>
								</div>
								<?php
								}
								if ($askForNewsletter == true) {
								?>
								<div class="control-group">
									<div class="controls">
										<input type="checkbox" name="newsletter" id="newsletter" value="newsletter">
										<label for="newsletter">
											<span></span><?php print t('newsletter_string'); ?>
										</label>
									</div>
								</div>
								<?php
								}
								if ($askForTermsOfUse == true) {
								?>
								<div class="control-group">
									<div class="controls">
										<input type="checkbox" name="termsOfUse" id="termsOfUse" value="termsOfUSe">
										<label for="termsOfUse">
											<span></span><?php print t('termsOfUseAccept_string'); ?>
											<a class="curpointer" data-toggle="modal" data-target="#conditions"><?php print t('termsOfUse_string'); ?></a>
										</label>
									</div>
									<span id="termsOfUseVal"></span>
								</div>
								<?php
								}
								?>
								<div class="control-group">
									<div class="controls">
										<input type="submit" class="btn btn-signin right" name="connecter" value="<?php print t('connect_string'); ?>">
									</div>
								</div>
							</fieldset>
						</form>
					</div>
				</div>
			</div>
			<div class="col-md-2"></div>
		</div>

		<script type="text/javascript" src="captiveportal-jquery.validate.js"></script>
		<!-- Form validation -->
		<script type="text/javascript">
			$(document).ready(function(){
				$('input').hover(function(){
					$(this).popover('show')
				});
				$("#enregistrement").validate({
					rules:{
						roomNumber:"required",
						emailAddress:{
								required:true,
								email: true
							},
						<?php if (strlen($confirmationCode) > 0) { ?>
						code:{
							required:true,
							minlength: 3
						},
						<?php } ?>
						familyName:{
							required:true,
							minlength: 2
						},
						surName:{
							required:true,
							minlength: 2
						},
						termsOfUse:{
							required:true
						}
					},
					messages:{
						roomNumber:"<?php print t('roomNumberValidation_string'); ?>",
						emailAddress:{
							required:"<?php print t('emailAddressValidation_string'); ?>",
							email:"<?php print t('emailAddressValidation_string'); ?>"
						},
						code:{
							required:"<?php print t('confirmationCodeValidation_string'); ?>",
							minlength:"<?php print t('minThreeCharacters_string'); ?>"
						},
						familyName:{
							required:"<?php print t('familyNameValidation_string'); ?>",
							minlength:"<?php print t('minTwoCharacters_string'); ?>"
						},
						surName:{
							required:"<?php print t('surNameValidation_string'); ?>",
							minlength:"<?php print t('minTwoCharacters_string'); ?>"
						},
						termsOfUse:"<?php print t('termsOfUseValidation_string'); ?>"
					},
					errorClass: "help-inline",
					errorElement: "span",
					highlight:function(element, errorClass, validClass) {
						$(element).parents('.control-group').addClass('error');
					},
					unhighlight: function(element, errorClass, validClass) {
						$(element).parents('.control-group').removeClass('error');
						$(element).parents('.control-group').addClass('success');
					},
					// Fix validate CSS checkboxes
					errorPlacement: function (error, element) {
						if (element.is(":checkbox")) {
							//element.closest('.span').append(error)
							$('#termsOfUseVal').append(error);
						} else {
							error.insertAfter(element);
						}
					}
				});

			});
		var build="<?php print APP_BUILD." - ".CONF_BUILD; ?>";
		</script>
		<?php
			// Shows error modal with $message if something didn't work
			if ($message != '')
			{
				echo "
				<script type=\"text/javascript\">
					$('#erreur').modal('show');
				</script>";
			}
		?>
	</body>
</html>
<?php
}
?>
